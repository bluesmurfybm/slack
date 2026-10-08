<?php
/** 확정된 WBS와 구성원 역량·가용도로 배정안을 산출하는 서비스. 명세서 §6. */

declare(strict_types=1);

/**
 * 배정 엔진.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 반드시 지킬 것 (CLAUDE.md)                                        │
 * │                                                                  │
 * │ 1. 배정 결정은 **결정론적 점수식**으로 한다.                       │
 * │    같은 입력이면 같은 결과가 나와야 한다. LLM 에 최종 판단을       │
 * │    맡기지 않는다. **난수를 쓰지 않는다** — 씨앗을 고정하는 것보다   │
 * │    아예 안 쓰는 편이 설명하기 쉽다. 동점은 명시한 기준으로 가른다. │
 * │ 2. 산출 결과는 언제나 "제안" 이다. 여기서 확정하지 않는다.         │
 * │    확정은 AllocationRepo::confirm() 하나뿐이다.                   │
 * │ 3. confirmed=0 인 태스크는 대상에서 제외한다.                      │
 * │    반드시 TaskRepo::confirmedForAllocation() 으로 읽는다.         │
 * │ 4. 모든 배정 항목에 산출 근거(reason_json)를 남긴다.               │
 * └──────────────────────────────────────────────────────────────────┘
 */
final class AllocationEngine
{
    /** 계열 점수가 없는 사람에게 쓸 대체값을 구할 때의 최소 표본 수. */
    private const MEDIAN_MIN_SAMPLE = 3;

    public function __construct(
        private TaskRepo $tasks,
        private MemberRepo $members,
        private AllocationRepo $allocations,
        private AvailabilityCalculator $availability,
        // 프로젝트 기간을 읽는다. bs_db() 를 직접 부르면 시험이 시험 DB 를
        // 못 보게 된다 — WbsExtractor 에서 같은 것에 한 번 막혔다.
        private ?ProjectRepo $projects = null,
    ) {}

    // =================================================================
    // §6.1 적합도
    // =================================================================

    /**
     * 태스크 하나 × 사람 하나의 적합도.
     *
     * 명세서 §6.1 의 식 그대로다. 다만 `w_comm * comm_score` 는 빠졌다 —
     * comm_score 를 산출하지 않기로 이미 정했다(bootstrap.php 의
     * BS_ALLOC_WEIGHTS 주석). 없는 점수를 0 으로 넣으면 모두가 그만큼
     * 깎이므로, **실제로 쓴 가중치의 합으로 나눈다.**
     *
     *   base = Σ(w_i × s_i) / Σ(w_i)
     *   fit  = base − penalty_overload − penalty_concentration + bonus_grouping
     *
     * `$context` 는 지금까지의 배정 상태다. 감점 항목은 이미 누가 무엇을
     * 맡았는지에 따라 달라지므로 문맥 없이는 계산할 수 없다.
     *
     * @return array{
     *   fit_score:float, base:float, parts:array, penalty:float, bonus:float,
     *   evidence:array, flags:array
     * }
     */
    public function fitScore(array $task, array $member, array $context): array
    {
        $w = $context['weights'] ?? BS_ALLOC_WEIGHTS;

        $parts = [];
        $flags = [];

        // --- 분야 적합도 -------------------------------------------------
        $df = $this->domainFit($task, $member, $context);
        if ($df['score'] !== null) {
            $parts['domain'] = $df['score'];
        }
        if ($df['estimated']) {
            // 표본이 없어 중앙값으로 놓았다는 표시. 점수가 낮은 것과 다르다.
            $flags[] = 'domain_estimated';
        }
        if (!$df['matched']) {
            $flags[] = 'domain_unknown';
        }

        // --- 종합 역량 ---------------------------------------------------
        if ($member['cap_score'] !== null) {
            $parts['cap'] = (float)$member['cap_score'];
        } else {
            $flags[] = 'cap_missing';
        }

        // --- 참여 가능도 -------------------------------------------------
        //
        // 후보 리스트(api/candidate.php)에서는 가용도를 적합도에 섞지 않았다.
        // 거기서는 사람이 두 축을 나란히 보고 고르기 때문이다.
        // 여기서는 기계가 고르므로 가용도가 들어가야 한다 — 안 그러면
        // 제일 잘하는 한 사람에게 전부 몰린다(명세서가 w_avail 을 둔 이유).
        // ┌──────────────────────────────────────────────────────────────┐
        // │ 이번 배정에서 이미 준 몫을 빼고 본다                          │
        // │                                                              │
        // │ 전에는 DB 에서 읽은 값을 그대로 썼다. 그러면 배정이 쌓여도    │
        // │ 가용도가 안 줄어, **제일 한가한 사람이 모든 태스크에서 계속   │
        // │ 1등**을 한다. 실제로 후보 8명 중 한 사람이 24건(81%)을        │
        // │ 가져가고 3명은 0건이었다(2026-10-07).                         │
        // │                                                              │
        // │ 감점이 아니라 **점수 자체가 줄어든다.** 감점은 한도를 넘어야  │
        // │ 걸리지만, 이쪽은 한 건을 줄 때마다 즉시 반영돼 자연스럽게     │
        // │ 퍼진다.                                                       │
        // └──────────────────────────────────────────────────────────────┘
        $av = $context['availability'][$member['member_id']] ?? null;
        if ($av !== null) {
            $parts['avail'] = $this->availAfter($member, $context, (float)$av['available_pct']);
        }

        // --- 경력 ---------------------------------------------------------
        if ($member['career_score'] !== null) {
            $parts['career'] = (float)$member['career_score'];
        }

        // --- 성장 기회 (명세서에서 (옵션)) ----------------------------------
        if (($w['growth'] ?? 0) > 0) {
            $parts['growth'] = $this->growthScore($task, $member, $context);
        }

        // --- 가중 평균 ------------------------------------------------------
        $wsum = 0.0;
        $ssum = 0.0;
        foreach ($parts as $k => $v) {
            $wk = (float)($w[$k] ?? 0);
            if ($wk <= 0) {
                continue;
            }
            $wsum += $wk;
            $ssum += $wk * $v;
        }
        // 쓸 수 있는 점수가 하나도 없으면 적합도를 낼 수 없다. 0 이 아니라
        // null 이다 — 0 으로 두면 "최악" 으로 읽혀 영원히 배정되지 않는다.
        if ($wsum <= 0) {
            return ['fit_score' => null, 'base' => null, 'parts' => $parts,
                    'penalty' => 0.0, 'bonus' => 0.0,
                    'evidence' => $df['evidence'], 'flags' => array_merge($flags, ['unscorable'])];
        }
        $base = $ssum / $wsum;

        // --- 감점 -----------------------------------------------------------
        $pOver  = $this->overloadPenalty($task, $member, $context);
        $pConc  = $this->concentrationPenalty($member, $context);
        $bGroup = $this->groupingBonus($task, $member, $context);

        if ($pOver > 0)  { $flags[] = 'overload'; }
        if ($pConc > 0)  { $flags[] = 'concentrated'; }
        if ($bGroup > 0) { $flags[] = 'same_group'; }

        $fit = $base - $pOver - $pConc + $bGroup;
        $fit = max(0.0, min(100.0, $fit));

        return [
            'fit_score' => round($fit, 2),
            'base'      => round($base, 2),
            'parts'     => array_map(static fn($v) => round($v, 2), $parts),
            'penalty'   => round($pOver + $pConc, 2),
            'bonus'     => round($bGroup, 2),
            'evidence'  => $df['evidence'],
            'flags'     => $flags,
        ];
    }

    /**
     * 태스크의 분야와 사람의 계열 역량을 맞춰 본다.
     *
     * 태스크가 여러 분야에 걸리면 bs_task_domain.weight 로 가중 평균한다.
     * 점수는 **계열(category) 단위**로 본다 — 분야 단위로는 표본이 안 찬다
     * (docs/scoring-design.md §1.3).
     *
     * 표본이 없는 사람은 0 이 아니라 **그 계열 점수의 중앙값**으로 놓는다.
     * 0 으로 두면 경험이 없는 사람은 영원히 배정을 못 받아 경험을 쌓을
     * 길이 막힌다. 중앙값은 지어낸 값이 아니라 그 집단의 실제 값이고,
     * 화면에는 '추정' 으로 표시한다.
     *
     * @return array{score:?float,matched:bool,estimated:bool,evidence:array}
     */
    private function domainFit(array $task, array $member, array $context): array
    {
        $doms = $context['task_domains'][$task['id']] ?? [];
        if (!$doms) {
            return ['score' => null, 'matched' => false, 'estimated' => false, 'evidence' => []];
        }

        $byCat = [];   // category => weight 합
        foreach ($doms as $d) {
            $cat = $d['category'];
            if ($cat === null || $cat === '' || in_array($cat, BS_CATEGORY_NOT_SCORED, true)) {
                continue;
            }
            $byCat[$cat] = ($byCat[$cat] ?? 0) + (float)$d['weight'];
        }
        if (!$byCat) {
            // 기획 같은 비채점 계열만 붙어 있는 태스크. 역량으로 가릴 수 없다.
            return ['score' => null, 'matched' => false, 'estimated' => false, 'evidence' => []];
        }

        $scores   = $context['category_scores'][$member['member_id']] ?? [];
        $medians  = $context['category_medians'] ?? [];

        $wsum = 0.0; $ssum = 0.0; $estimated = false; $matched = false;
        $evidence = [];

        foreach ($byCat as $cat => $weight) {
            $row = $scores[$cat] ?? null;
            if ($row !== null && $row['score'] !== null && !$row['insufficient_data']) {
                $s = (float)$row['score'];
                $matched = true;
                $evidence[] = [
                    'category'   => $cat,
                    'label'      => BS_DOMAIN_CATEGORY[$cat]['label'] ?? $cat,
                    'score'      => round($s, 1),
                    'case_count' => (int)$row['case_count'],
                    'estimated'  => false,
                ];
            } elseif (isset($medians[$cat])) {
                $s = (float)$medians[$cat];
                $estimated = true;
                $evidence[] = [
                    'category'   => $cat,
                    'label'      => BS_DOMAIN_CATEGORY[$cat]['label'] ?? $cat,
                    'score'      => round($s, 1),
                    'case_count' => (int)($row['case_count'] ?? 0),
                    'estimated'  => true,
                ];
            } else {
                continue;   // 비교할 집단 자체가 없다
            }
            $wsum += $weight;
            $ssum += $weight * $s;
        }

        if ($wsum <= 0) {
            return ['score' => null, 'matched' => false, 'estimated' => false, 'evidence' => []];
        }
        return [
            'score'     => $ssum / $wsum,
            'matched'   => $matched,
            'estimated' => $estimated,
            'evidence'  => $evidence,
        ];
    }

    /**
     * 이미 배정된 공수가 가용 공수를 넘은 만큼 감점.
     *
     * 넘긴 비율에 비례해 깎는다. 딱 잘라 막지 않는 이유는, 모두가 꽉 찼을
     * 때 아무도 못 받는 상태가 되면 배정안이 아예 안 나오기 때문이다.
     * 하드 제약은 assign() 쪽에서 따로 본다.
     */
    /**
     * 이번 배정을 반영한 참여 가능도(0~100).
     *
     * DB 의 가용률에서 **이 배정안에서 이미 준 공수만큼** 깎는다. 가용 공수를
     * 모르면(기간 없음 등) 깎을 기준이 없으므로 원값을 그대로 둔다 —
     * 모르는 것을 0 으로 치면 그 사람이 영영 배정되지 않는다.
     */
    private function availAfter(array $member, array $context, float $base): float
    {
        $mid = $member['member_id'];
        $cap = (float)($context['capacity_md'][$mid] ?? 0);
        if ($cap <= 0) {
            return $base;
        }
        $used = (float)($context['assigned_md'][$mid] ?? 0);
        $left = max(0.0, 1.0 - $used / $cap);
        return max(0.0, min(100.0, $base * $left));
    }

    /**
     * 가용 공수를 넘겨 배정한 만큼 감점.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 넘기기 **전부터** 조금씩 깎는다                                │
     * │                                                              │
     * │ 전에는 100% 를 넘겨야 걸렸다. 그래서 0% 든 90% 든 감점이 같아 │
     * │ 한 사람이 가용량을 꽉 채울 때까지 계속 받았다. 가용도 쪽       │
     * │ (availAfter)이 주로 퍼뜨리고, 이쪽은 **끝에서 넘치는 것을     │
     * │ 막는 안전장치**다.                                            │
     * └──────────────────────────────────────────────────────────────┘
     */
    private function overloadPenalty(array $task, array $member, array $context): float
    {
        $mid = $member['member_id'];
        $cap = (float)($context['capacity_md'][$mid] ?? 0);
        if ($cap <= 0) {
            return 50.0;   // 가용 공수가 아예 없는 사람
        }
        $used  = (float)($context['assigned_md'][$mid] ?? 0);
        $after = $used + (float)($task['est_md'] ?? 0);

        if ($after > $cap) {
            $over = ($after - $cap) / $cap;     // 0.2 = 20% 초과
            // 넘긴 뒤에는 세게 깎는다. 아래 경고 구간과 이어지도록 바닥을 둔다.
            return min(50.0, self::SOFT_PENALTY + $over * 100.0);
        }

        // 아직 안 넘겼지만 꽉 차 가는 구간. 0 → SOFT_PENALTY 로 완만히 는다.
        $ratio = $after / $cap;
        if ($ratio <= self::SOFT_FROM) {
            return 0.0;
        }
        return self::SOFT_PENALTY * ($ratio - self::SOFT_FROM) / (1.0 - self::SOFT_FROM);
    }

    /** 한 사람에게 쏠린 만큼 감점. 명세서 §1.1 이 든 문제가 이것이다. */
    private function concentrationPenalty(array $member, array $context): float
    {
        $total = (float)($context['total_md'] ?? 0);
        if ($total <= 0) {
            return 0.0;
        }
        $limit = (float)($context['constraints']['concentration'] ?? 0.4);
        $share = (float)($context['assigned_md'][$member['member_id']] ?? 0) / $total;
        if ($share <= $limit) {
            return 0.0;
        }
        return min(30.0, ($share - $limit) * 100.0);
    }

    /**
     * 같은 대분류를 이미 맡은 사람에게 주는 가산점 (명세서 §6.2).
     * 컨텍스트 스위칭 비용을 줄이려는 것이지 강제가 아니다.
     */
    private function groupingBonus(array $task, array $member, array $context): float
    {
        $root = $context['root_of'][$task['id']] ?? null;
        if ($root === null) {
            return 0.0;
        }
        $owned = $context['group_owner'][$root] ?? [];
        if (!isset($owned[$member['member_id']])) {
            return 0.0;
        }
        return (float)($context['constraints']['group_bonus'] ?? 0);
    }

    /**
     * 성장 기회 점수 (명세서에서 (옵션)).
     *
     * 이 계열을 아직 많이 안 해 본 사람일수록 높다. 기본 가중치가 0 이라
     * 켜지 않으면 결과에 영향이 없다 — 켜는 순간 "잘하는 사람" 대신
     * "배울 사람" 에게 가므로, 사람이 의도해서 올려야 한다.
     */
    private function growthScore(array $task, array $member, array $context): float
    {
        $doms = $context['task_domains'][$task['id']] ?? [];
        if (!$doms) {
            return 0.0;
        }
        $scores = $context['category_scores'][$member['member_id']] ?? [];
        $best   = null;
        foreach ($doms as $d) {
            $row = $scores[$d['category']] ?? null;
            $s   = ($row && $row['score'] !== null) ? (float)$row['score'] : 0.0;
            $best = $best === null ? $s : min($best, $s);
        }
        // 못하는 분야일수록 성장 여지가 크다. 다만 아무 근거가 없는 사람에게
        // 어려운 일을 몰아주지 않도록 난이도로 눌러 둔다.
        $diff = (int)($task['difficulty'] ?? 3);
        $room = 100.0 - ($best ?? 0.0);
        return max(0.0, $room * (1.0 - ($diff - 1) / 4.0));
    }

    // =================================================================
    // §6.2 배정 알고리즘
    // =================================================================

    /**
     * 배정안 산출.
     *
     * 저장하지 않고 결과만 돌려준다. 저장은 호출부가
     * AllocationRepo::createVersion() + saveItems() 로 한다.
     *
     * @param array $params weights / constraints / member_ids / pinned
     * @return array{items:array,summary:array,unassigned:array,meta:array}
     */
    public function propose(int $projectId, array $params): array
    {
        $ctx = $this->buildContext($projectId, $params);

        if (!$ctx['tasks']) {
            throw new DomainException(
                '배정할 태스크가 없습니다. WBS 를 확정해야 배정 대상이 됩니다.');
        }
        if (!$ctx['members']) {
            throw new DomainException(
                '배정할 수 있는 구성원이 없습니다. 후보를 먼저 고르거나 '
                . '구성원의 배정 가능 여부를 확인하세요.');
        }

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 뽑는 자리만 갈아끼운다                                        │
        // │                                                              │
        // │ 무작위든 가중치든 **그 뒤는 똑같이 흐른다** — 적합도를 매기고 │
        // │ 근거를 적고 초과를 경고한다. 무작위라고 그걸 건너뛰면 "이     │
        // │ 사람 과부하" 를 알려 줄 길이 사라진다.                        │
        // │                                                              │
        // │ 적합도는 **계산만 하고 고르는 데는 안 쓴다.** 그 숫자가       │
        // │ 그대로 대조 자료가 된다 — 가중치 배정이 무작위보다 나은지를   │
        // │ 숫자로 볼 수 있어야 한다(명세서 11.3-1).                      │
        // └──────────────────────────────────────────────────────────────┘
        $method = self::methodOf($params['method'] ?? '');

        if ($method === self::M_WEIGHTED) {
            // --- 1) 그리디 초기해 -------------------------------------------
            $assign = $this->greedy($ctx);
            // --- 2) 지역 탐색(swap) 개선 --------------------------------------
            [$assign, $passes] = $this->localSearch($assign, $ctx);
            $seed   = null;
        } else {
            // 씨앗을 안 주면 새로 뽑는다. **어느 쪽이든 반드시 남긴다** —
            // 재현 안 되는 배정안은 "왜 이 사람이죠?" 에 답할 수 없다.
            $seed   = isset($params['seed']) && $params['seed'] !== ''
                    ? max(0, (int)$params['seed'])
                    : random_int(1, 999999);
            $assign = $this->randomAssign($ctx, $method, $seed);
            $passes = 0;
        }

        // --- 2.5) 고른 후보에게 최소 1건 -----------------------------------
        //
        // 반드시 지역 탐색 **뒤**다. 앞에 두면 적합도 합을 올리려고 그 한
        // 건을 도로 가져간다. 무작위 배정에도 똑같이 건다 — 태스크가 사람
        // 수보다 적으면 무작위에서도 0건이 나온다.
        [$assign, $minOne] = $this->ensureEveryPicked($assign, $ctx);

        // --- 3) 결과 만들기 ------------------------------------------------
        $items      = [];
        $unassigned = [];
        foreach ($ctx['tasks'] as $t) {
            $mid = $assign[$t['id']] ?? null;
            if ($mid === null) {
                $unassigned[] = [
                    'task_id' => $t['id'], 'wbs_no' => $t['wbs_no'], 'title' => $t['title'],
                    'reason'  => '적합도를 낼 수 있는 구성원이 없습니다.',
                ];
                continue;
            }
            $member = $ctx['members'][$mid];
            // 이 배정 자신은 부하에서 뺀 상태로 점수를 매긴다 — 그래야
            // 화면에 나오는 적합도가 "이 사람을 여기 붙일 만한가" 가 된다.
            $fit    = $this->fitScore($t, $member, $this->snapshot($ctx, $assign, [$t['id']]));
            $items[] = [
                'task_id'     => $t['id'],
                'member_id'   => $mid,
                'role'        => 'owner',
                'alloc_ratio' => 1.0,
                'fit_score'   => $fit['fit_score'],
                'reason_json' => $this->explain($fit, $t, $member, $ctx),
                'is_manual'   => !empty($ctx['pinned'][$t['id']]),
                'manual_note' => !empty($ctx['pinned'][$t['id']]) ? '이전 안에서 고정된 항목' : null,
            ];
        }

        return [
            'items'      => $items,
            'unassigned' => $unassigned,
            'summary'    => $this->summarize($assign, $ctx),
            'meta'       => [
                'engine_ver'   => BS_ENGINE_VER,
                'eval_ver'     => $ctx['eval_ver'],
                'weights'      => $ctx['weights'],
                'constraints'  => $ctx['constraints'],
                'task_count'   => count($ctx['tasks']),
                'member_count' => count($ctx['members']),
                'passes'       => $passes,
                'period'       => ['from' => $ctx['from'], 'to' => $ctx['to']],
                'workdays'     => $ctx['workdays'],
                'method'       => $method,
                'level'        => $ctx['level'],
                // 자동이 쪼갠 이유. 답할 수 없는 자동은 아무도 안 쓴다.
                'split_reasons' => array_values($ctx['split_reasons']),
                // 씨앗이 있으면 같은 결과를 다시 만들 수 있다. 가중치 배정은
                // 난수를 안 쓰므로 씨앗 없이도 늘 같은 결과다.
                'seed'          => $seed,
                'deterministic' => true,
                // ★4~5 를 그 분야 상위자에게 주는 규칙(§6.2)은 무작위에서
                //   건너뛴다. 의도한 동작이지만 **말해 줘야 한다.**
                'hard_off_top'  => $method === self::M_WEIGHTED
                                 ? 0 : $this->hardOffTop($assign, $ctx),
                // 고른 후보에게 한 건씩 채워 준 기록. 옮긴 것과 **못 옮긴
                // 이유**를 함께 남긴다 — 보장은 못 지킨 자리가 더 중요하다.
                'min_one'       => $minOne,
                'picked'        => $ctx['picked'],
                // 그때 계수가 얼마였는지. 없으면 왜 이 공수가 나왔는지
                // 설명할 수 없다.
                'aidd'          => $ctx['aidd'],
                // 그때 비중이 얼마였는지. 없으면 "왜 이 사람이 적게 받았나" 에
                // 답할 수 없다. 프로젝트 설정을 나중에 바꿔도 이 차수는 설명된다.
                'shares'        => $ctx['shares'],
            ],
        ];
    }

    // =================================================================
    // 무작위 배정
    //
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 왜 두는가 — 대조군이 필요하다                                 │
    // │                                                              │
    // │ 역량 점수가 배정에 쓸 만큼 정확한지 아직 사람이 검증하지       │
    // │ 않았다(명세서 11.3-1). 무작위 배정이 있으면 **가중치가 제값을 │
    // │ 하는지 숫자로 볼 수 있다** — 평균 적합도와 초과 인원을 나란히 │
    // │ 놓으면 된다. 비슷하게 나오면 가중치가 일을 안 하는 것이다.    │
    // │                                                              │
    // │ 실무적으로도 단순 작업 구간에서는 고르게 나누는 쪽이 맞을 때가│
    // │ 있다.                                                         │
    // │                                                              │
    // │ ⚠ 무작위로 붙인 배정도 확정·완료되면 **실적이 된다.** 역량    │
    // │   점수와 공수 환산표의 근거로 들어간다. 꼭 틀린 것은 아니지만  │
    // │   (실제로 해냈으니) 의도한 배정이 아니었다는 사실은 남아야     │
    // │   하므로, method 를 params_json 에 적어 둔다.                 │
    // └──────────────────────────────────────────────────────────────┘
    // =================================================================

    // =================================================================
    // 배정 단위 — 어느 깊이의 덩어리를 한 사람에게 줄 것인가
    //
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 말단만 배정하면 일이 지나치게 쪼개진다                         │
    // │                                                              │
    // │ WBS 105건이면 최대 105명에게 갈 수 있다. 실무는 그렇지 않다 — │
    // │ 「로그인/회원가입」 은 세션·토큰·화면이 한 덩어리라 쪼개면     │
    // │ 서로를 기다린다. 같은 묶음 가산점이 모아 주긴 하지만 그건     │
    // │ 권유일 뿐 보장이 아니다.                                      │
    // │                                                              │
    // │ ⚠ 가장 위험한 것은 **공수 이중 계산**이다. 상위와 하위를 같이 │
    // │   배정하면 공수가 두 번 잡혀 가용도가 통째로 무너진다. 그래서 │
    // │   말단 하나는 **반드시 단위 하나에만** 속한다 — 아래 매핑이   │
    // │   leaf → unit 한 방향이라 구조적으로 겹칠 수 없다.            │
    // └──────────────────────────────────────────────────────────────┘
    // =================================================================

    /**
     * 가용 공수의 이 비율을 넘으면 과부하 감점이 **미리** 들기 시작한다.
     * 넘긴 뒤에야 걸면 한 사람이 꽉 찰 때까지 계속 받는다.
     */
    private const SOFT_FROM = 0.70;
    /** 가용량을 딱 채웠을 때의 감점. 여기서부터 초과분이 더해진다. */
    private const SOFT_PENALTY = 12.0;

    /** 말단까지. 지금까지의 동작이고 기본값이다 */
    public const L_LEAF = 'leaf';
    /** 대분류 하나를 한 사람에게 */
    public const L_D1   = 'd1';
    /** 중분류까지 내려가서 */
    public const L_D2   = 'd2';
    /** 항목마다 알아서. 왜 그렇게 나눴는지 적는다 */
    public const L_AUTO = 'auto';

    public const LEVEL_LABEL = [
        self::L_LEAF => '말단까지',
        self::L_D1   => '대분류 단위',
        self::L_D2   => '중분류 단위',
        self::L_AUTO => '자동',
    ];

    /** 주 분야가 이 비중에 못 미치면 한 덩어리로 보기 어렵다 */
    private const AUTO_DOMAIN_SHARE = 0.6;
    /** 난이도 편차가 이만큼 벌어지면 어려운 것이 쉬운 것에 묻힌다 */
    private const AUTO_DIFF_SPREAD  = 3;

    /** 모르는 값은 말단까지로 본다. 오타 하나로 배정 단위가 바뀌면 안 된다. */
    public static function levelOf(string $v): string
    {
        return isset(self::LEVEL_LABEL[$v]) ? $v : self::L_LEAF;
    }

    /**
     * 말단들을 배정 단위로 묶는다.
     *
     * 돌려주는 `units` 는 기존 `$tasks` 와 모양이 같아 **엔진의 나머지가
     * 하나도 안 바뀐다.** 달라지는 것은 한 줄이 가리키는 범위뿐이다.
     *
     * @param  array $leaves   말단 태스크 (확정된 것만)
     * @param  array $allRows  프로젝트의 전체 태스크 줄(부모·제목을 찾는다)
     * @param  array $capacity member_id => 가용 공수. '자동' 이 쓴다
     * @param  array $leafDoms 말단별 분야
     * @return array{units:array, domains:array, members_of:array, reasons:array}
     */
    private function cutUnits(array $leaves, array $allRows, string $level,
                              array $capacity, array $leafDoms): array
    {
        $row = [];
        foreach ($allRows as $t) { $row[(int)$t['id']] = $t; }

        // 말단 → 그 말단이 속할 단위. **한 방향 매핑이라 겹칠 수 없다.**
        $unitOf  = [];
        $reasons = [];

        if ($level === self::L_LEAF) {
            foreach ($leaves as $id => $_) { $unitOf[$id] = $id; }
        } elseif ($level === self::L_AUTO) {
            [$unitOf, $reasons] = $this->autoCut($leaves, $row, $capacity, $leafDoms);
        } else {
            $maxDepth = $level === self::L_D1 ? 1 : 2;
            foreach ($leaves as $id => $leaf) {
                $cur = $id;
                // 정한 깊이보다 깊으면 올라간다. 이미 얕으면 자기 자신이다 —
                // 하위 없는 대분류를 억지로 끌어올릴 곳은 없다.
                for ($i = 0; $i < BS_TASK_MAX_DEPTH; $i++) {
                    $d = (int)($row[$cur]['depth'] ?? 1);
                    $p = $row[$cur]['parent_id'] ?? null;
                    if ($d <= $maxDepth || $p === null) { break; }
                    $cur = (int)$p;
                }
                $unitOf[$id] = $cur;
            }
        }

        return $this->mergeUnits($leaves, $row, $unitOf, $leafDoms) + ['reasons' => $reasons];
    }

    /**
     * 말단을 모아 단위 한 줄로 만든다.
     *
     * 합치는 규칙 셋 — 전부 **틀리면 조용히 잘못된 일정이 나오는** 자리다.
     *   · 공수 = 확정된 말단의 **합**. 상위 자신의 est_md 는 무시한다
     *     (화면이 '무시됨' 으로 보여 주는 그 값이다)
     *   · 난이도 = **최대값**. 평균을 쓰면 ★5 하나가 ★1 열 개에 묻혀
     *     "어려운 건 상위자에게" 규칙이 안 걸린다
     *   · 분야 = 하위 분야를 **공수로 가중**해 합친다. 그냥 합치면 30분짜리
     *     퍼블리싱이 5일짜리 SSO 와 같은 무게가 된다
     *
     * @return array{units:array, domains:array, members_of:array}
     */
    private function mergeUnits(array $leaves, array $row, array $unitOf, array $leafDoms): array
    {
        $units = [];
        $doms  = [];
        $of    = [];     // unit_id => [leaf_id, ...]  근거에 쓴다

        foreach ($leaves as $leafId => $leaf) {
            $uid = $unitOf[$leafId];
            $of[$uid][] = $leafId;

            if (!isset($units[$uid])) {
                $r = $row[$uid] ?? $leaf;
                $units[$uid] = [
                    'id'         => $uid,
                    'wbs_no'     => $r['wbs_no'] ?? $leaf['wbs_no'],
                    'title'      => $r['title'] ?? $leaf['title'],
                    'parent_id'  => isset($r['parent_id']) && $r['parent_id'] !== null
                                  ? (int)$r['parent_id'] : null,
                    'depth'      => (int)($r['depth'] ?? $leaf['depth']),
                    'seq'        => (int)($r['seq'] ?? $leaf['seq']),
                    'est_md'     => 0.0,
                    'difficulty' => null,
                    'plan_start' => null,
                    'plan_end'   => null,
                    'leaf_count' => 0,
                    'hardest'    => null,
                ];
            }
            $u =& $units[$uid];

            $u['est_md']    += $leaf['est_md'];
            $u['leaf_count']++;

            // 가장 어려운 하위가 무엇인지 남긴다. 근거에 적어야 사람이
            // "왜 ★5 지?" 에 답할 수 있다.
            if ($leaf['difficulty'] !== null
                && ($u['difficulty'] === null || $leaf['difficulty'] > $u['difficulty'])) {
                $u['difficulty'] = $leaf['difficulty'];
                $u['hardest']    = $leaf['title'];
            }
            // 기간은 가장 이른 시작 ~ 가장 늦은 종료
            if ($leaf['plan_start'] !== null
                && ($u['plan_start'] === null || $leaf['plan_start'] < $u['plan_start'])) {
                $u['plan_start'] = $leaf['plan_start'];
            }
            if ($leaf['plan_end'] !== null
                && ($u['plan_end'] === null || $leaf['plan_end'] > $u['plan_end'])) {
                $u['plan_end'] = $leaf['plan_end'];
            }
            unset($u);

            // 분야 — 공수로 가중. 공수가 0 이어도 사라지면 안 되므로 바닥을 둔다.
            $mult = max(0.1, $leaf['est_md']);
            foreach (($leafDoms[$leafId] ?? []) as $d) {
                $key = (int)$d['domain_id'];
                if (!isset($doms[$uid][$key])) {
                    $doms[$uid][$key] = $d;
                    $doms[$uid][$key]['weight'] = 0.0;
                }
                $doms[$uid][$key]['weight'] += (float)$d['weight'] * $mult;
            }
        }

        // domainFit 이 받는 모양(번호 없는 목록)으로 되돌린다.
        foreach ($doms as $uid => $list) { $doms[$uid] = array_values($list); }

        return ['units' => $units, 'domains' => $doms, 'members_of' => $of];
    }

    /**
     * 항목마다 알아서 나눈다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 왜 그렇게 나눴는지 반드시 적는다                               │
     * │                                                              │
     * │ 자동이 왜 이렇게 했는지 답할 수 없으면 아무도 안 쓴다. 쪼갠    │
     * │ 이유를 사람 말로 남겨 화면에 그대로 보여 준다.                 │
     * └──────────────────────────────────────────────────────────────┘
     *
     * 대분류부터 보고, 셋 중 하나라도 걸리면 한 단계 내려간다.
     *   ① 공수가 한 사람 가용량을 넘는다      → 혼자 못 한다
     *   ② 분야가 갈린다                       → 한 사람이 다 잘하기 어렵다
     *   ③ 난이도 편차가 크다                  → 어려운 것이 묻힌다
     *
     * @return array{0:array, 1:array} unitOf, reasons
     */
    private function autoCut(array $leaves, array $row, array $capacity, array $leafDoms): array
    {
        // 혼자 맡을 수 있는 최대치. 가용도를 모르면 이 조건은 안 본다.
        $maxCap = $capacity ? max($capacity) : 0.0;

        // 단위 후보(상위 노드) 아래 말단들을 미리 모아 둔다.
        $under = [];
        foreach ($leaves as $id => $_) {
            $cur = $id;
            for ($i = 0; $i < BS_TASK_MAX_DEPTH + 1; $i++) {
                $under[$cur][] = $id;
                $p = $row[$cur]['parent_id'] ?? null;
                if ($p === null) { break; }
                $cur = (int)$p;
            }
        }

        $unitOf  = [];
        $reasons = [];

        // 깊이 1 부터 내려가며 "통째로 둘까, 쪼갤까" 를 정한다.
        $decide = function (int $nodeId) use (&$decide, &$unitOf, &$reasons, $leaves,
                                              $row, $under, $leafDoms, $maxCap): void {
            $mine = $under[$nodeId] ?? [];
            if (!$mine) { return; }

            // 더 쪼갤 수 없으면(자기가 말단) 여기서 끝
            if (count($mine) === 1 && $mine[0] === $nodeId) {
                $unitOf[$nodeId] = $nodeId;
                return;
            }

            $why = $this->splitReason($mine, $leaves, $leafDoms, $maxCap);
            if ($why === null) {
                foreach ($mine as $lid) { $unitOf[$lid] = $nodeId; }
                return;
            }
            $reasons[$nodeId] = ['title' => (string)($row[$nodeId]['title'] ?? ''), 'why' => $why];

            // 한 단계 아래 자식들로 내려간다.
            $kids = [];
            foreach ($mine as $lid) {
                $cur = $lid;
                while (($row[$cur]['parent_id'] ?? null) !== null
                       && (int)$row[$cur]['parent_id'] !== $nodeId) {
                    $cur = (int)$row[$cur]['parent_id'];
                }
                $kids[$cur] = true;
            }
            foreach (array_keys($kids) as $kid) { $decide((int)$kid); }
        };

        foreach ($leaves as $id => $_) {
            $cur = $id;
            while (($row[$cur]['parent_id'] ?? null) !== null) { $cur = (int)$row[$cur]['parent_id']; }
            if (!isset($unitOf[$id])) { $decide($cur); }
        }

        // 어떤 길로도 안 잡힌 말단은 자기 자신이 단위다(혹시 모를 구멍 막기).
        foreach ($leaves as $id => $_) {
            if (!isset($unitOf[$id])) { $unitOf[$id] = $id; }
        }
        return [$unitOf, $reasons];
    }

    /** 이 말단 묶음을 한 사람에게 주기 어려운 이유. 괜찮으면 null. */
    private function splitReason(array $leafIds, array $leaves, array $leafDoms, float $maxCap): ?string
    {
        $md = 0.0; $hi = null; $lo = null; $byCat = [];
        foreach ($leafIds as $lid) {
            $l = $leaves[$lid] ?? null;
            if ($l === null) { continue; }
            $md += $l['est_md'];
            if ($l['difficulty'] !== null) {
                $hi = $hi === null ? $l['difficulty'] : max($hi, $l['difficulty']);
                $lo = $lo === null ? $l['difficulty'] : min($lo, $l['difficulty']);
            }
            $mult = max(0.1, $l['est_md']);
            foreach (($leafDoms[$lid] ?? []) as $d) {
                $c = (string)($d['category'] ?? '');
                if ($c === '') { continue; }
                $byCat[$c] = ($byCat[$c] ?? 0) + (float)$d['weight'] * $mult;
            }
        }

        if ($maxCap > 0 && $md > $maxCap) {
            return sprintf('공수 %s M/D 로 혼자 맡기 어려워 나눴습니다(가용 최대 %s M/D).',
                           $this->num($md), $this->num($maxCap));
        }
        if ($hi !== null && $lo !== null && ($hi - $lo) >= self::AUTO_DIFF_SPREAD) {
            return sprintf('난이도가 ★%d~★%d 로 벌어져 나눴습니다 — 어려운 쪽이 묻힙니다.', $lo, $hi);
        }
        $sum = array_sum($byCat);
        if ($sum > 0) {
            $share = max($byCat) / $sum;
            if ($share < self::AUTO_DOMAIN_SHARE) {
                return sprintf('분야가 갈려 나눴습니다(주 분야 비중 %d%%).', (int)round($share * 100));
            }
        }
        return null;
    }

    public const M_WEIGHTED = 'weighted';
    /** 건수를 고르게. 사람 순서를 섞고 차례대로 돌린다 */
    public const M_RANDOM_EVEN = 'random_even';
    /** 태스크마다 따로 뽑는다. 한 사람에게 몰릴 수 있다 */
    public const M_RANDOM_PURE = 'random_pure';

    public const METHOD_LABEL = [
        self::M_WEIGHTED    => '가중치',
        self::M_RANDOM_EVEN => '무작위 — 고르게',
        self::M_RANDOM_PURE => '무작위 — 완전 무작위',
    ];

    /** 모르는 값은 가중치로 본다. 오타 하나로 배정 방식이 바뀌면 안 된다. */
    public static function methodOf(string $v): string
    {
        return isset(self::METHOD_LABEL[$v]) ? $v : self::M_WEIGHTED;
    }

    /**
     * 적합도를 보지 않고 뽑는다.
     *
     * 지키는 것은 둘뿐이다.
     *   · **배정 후보 안에서만** 뽑는다. ctx['members'] 가 이미 걸러져 있다
     *   · **고정 항목은 건드리지 않는다**. 사람이 정한 것이 우선이다
     *
     * @return array<int,int> task_id => member_id
     */
    private function randomAssign(array $ctx, string $mode, int $seed): array
    {
        $assign = [];
        foreach ($ctx['pinned'] as $taskId => $memberId) {
            if (isset($ctx['members'][$memberId])) {
                $assign[$taskId] = $memberId;
            }
        }

        // mt_srand 로 씨앗을 고정한다. random_int 는 씨앗을 못 받아
        // 재현이 안 된다 — 여기서는 암호학적 무작위가 필요 없고,
        // **같은 씨앗이면 같은 결과**인 쪽이 훨씬 중요하다.
        mt_srand($seed);

        // 사람 순서를 섞는다. 이것이 "누가 먼저냐" 를 무작위로 만든다.
        // 안 섞으면 늘 같은 사람이 1번을 받아 고르게가 아니라 '늘 그 순서'다.
        $pool = $ctx['member_order'];
        self::shuffleWithSeed($pool);

        $i = 0;
        foreach ($ctx['order'] as $taskId) {
            if (isset($assign[$taskId])) {
                continue;               // 고정 항목
            }
            $assign[$taskId] = $mode === self::M_RANDOM_PURE
                ? $pool[mt_rand(0, count($pool) - 1)]
                : $pool[$i++ % count($pool)];
        }

        mt_srand();                      // 씨앗을 풀어 둔다. 전역 상태다
        return $assign;
    }

    /**
     * mt_rand 만 써서 섞는다(피셔-예이츠).
     *
     * shuffle() 은 씨앗을 안 따르는 별도 난수를 쓰는 환경이 있어 재현이
     * 깨진다. 재현이 이 기능의 전부라 직접 섞는다.
     *
     * @param list<int> $a
     */
    private static function shuffleWithSeed(array &$a): void
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
    }

    /**
     * 어려운 태스크(★4~5)가 그 분야 상위자가 아닌 사람에게 간 건수.
     *
     * 무작위는 §6.2 의 "어려운 것은 상위자에게" 규칙을 건너뛴다. 의도한
     * 동작이지만 **몇 건이 그렇게 됐는지는 말해 줘야** 사람이 판단한다.
     */
    private function hardOffTop(array $assign, array $ctx): int
    {
        $hard = (int)($ctx['constraints']['hard_difficulty'] ?? 4);
        $n    = 0;
        foreach ($assign as $taskId => $memberId) {
            $t = $ctx['tasks'][$taskId] ?? null;
            $m = $ctx['members'][$memberId] ?? null;
            if ($t === null || $m === null || (int)($t['difficulty'] ?? 0) < $hard) {
                continue;
            }
            if (!$this->isTopInDomain($t, $m, $ctx)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * 그리디 초기해.
     *
     * 태스크를 **고정된 순서**로 돌면서 그때그때 가장 좋은 사람을 붙인다.
     * 순서는 난이도 내림 → 공수 내림 → wbs_no 오름이다. 어려운 일을 먼저
     * 놓아야 좋은 사람이 쉬운 일에 먼저 묶이지 않는다.
     */
    private function greedy(array $ctx): array
    {
        $assign = [];

        // 이전 안에서 고정된 항목은 그대로 둔다.
        foreach ($ctx['pinned'] as $taskId => $memberId) {
            if (isset($ctx['members'][$memberId])) {
                $assign[$taskId] = $memberId;
            }
        }

        foreach ($ctx['order'] as $taskId) {
            if (isset($assign[$taskId])) {
                continue;
            }
            $t    = $ctx['tasks'][$taskId];
            $snap = $this->snapshot($ctx, $assign);
            $best = $this->bestMemberFor($t, $ctx, $snap);
            if ($best !== null) {
                $assign[$taskId] = $best;
            }
        }
        return $assign;
    }

    /**
     * 이 태스크에 가장 맞는 사람.
     *
     * 동점은 **구성원 번호가 작은 쪽**으로 가른다. 무엇으로 가르든 상관없지만
     * 정해 두지 않으면 같은 입력에 다른 답이 나온다.
     */
    private function bestMemberFor(array $task, array $ctx, array $snap): ?int
    {
        $hard = (int)($ctx['constraints']['hard_difficulty'] ?? 4);
        $isHard = (int)($task['difficulty'] ?? 0) >= $hard;

        $best = null; $bestScore = null;
        $fallback = null; $fallbackScore = null;

        foreach ($ctx['member_order'] as $mid) {
            $m   = $ctx['members'][$mid];
            $fit = $this->fitScore($task, $m, $snap);
            if ($fit['fit_score'] === null) {
                continue;
            }
            $s = $fit['fit_score'];

            // 난이도 4~5 는 해당 분야 상위자 우선(명세서 §6.2).
            // 상위자가 아무도 없으면 막히므로 차선을 따로 들고 간다.
            $isTop = !$isHard || $this->isTopInDomain($task, $m, $ctx);

            if ($isTop) {
                if ($bestScore === null || $s > $bestScore) {
                    $bestScore = $s; $best = $mid;
                }
            } else {
                if ($fallbackScore === null || $s > $fallbackScore) {
                    $fallbackScore = $s; $fallback = $mid;
                }
            }
        }
        return $best ?? $fallback;
    }

    /** 이 태스크의 계열에서 상위권인가. 상위 기준은 top_ratio(기본 중앙값 이상). */
    private function isTopInDomain(array $task, array $member, array $ctx): bool
    {
        $doms = $ctx['task_domains'][$task['id']] ?? [];
        if (!$doms) {
            return true;    // 분야를 모르면 가릴 수 없다. 막지 않는다.
        }
        $scores = $ctx['category_scores'][$member['member_id']] ?? [];
        foreach ($doms as $d) {
            $cat = $d['category'];
            $cut = $ctx['category_cutoff'][$cat] ?? null;
            if ($cut === null) {
                continue;
            }
            $row = $scores[$cat] ?? null;
            if ($row === null || $row['score'] === null || $row['insufficient_data']) {
                continue;   // 표본 없는 사람은 '상위' 로 치지 않는다
            }
            if ((float)$row['score'] >= $cut) {
                return true;
            }
        }
        return false;
    }

    /**
     * 지역 탐색 — 두 태스크의 담당자를 맞바꿔 총점이 오르면 받아들인다.
     *
     * 규모가 작으므로(태스크 수십, 인원 십수 명) 헝가리안까지 갈 필요 없다
     * (명세서 §6.2-3).
     *
     * **결정론을 지키는 방법**: 태스크를 언제나 같은 순서로 돌고, 개선이
     * 있을 때만, 그것도 **엄격히 클 때만** 받아들인다. 같으면 바꾸지 않는다 —
     * 같은 점수끼리 계속 맞바꾸면 끝나지 않는다. 왕복 횟수에도 상한을 둔다.
     *
     * @return array{0:array,1:int} [배정, 돈 횟수]
     */
    private function localSearch(array $assign, array $ctx): array
    {
        $order  = $ctx['order'];
        $n      = count($order);
        $passes = 0;
        $max    = (int)($ctx['constraints']['max_passes'] ?? BS_ALLOC_MAX_PASSES);

        for ($pass = 0; $pass < $max; $pass++) {
            $passes++;
            $improved = false;

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $order[$i];
                    $b = $order[$j];
                    $ma = $assign[$a] ?? null;
                    $mb = $assign[$b] ?? null;
                    if ($ma === null || $mb === null || $ma === $mb) {
                        continue;
                    }
                    // 고정된 항목은 건드리지 않는다.
                    if (isset($ctx['pinned'][$a]) || isset($ctx['pinned'][$b])) {
                        continue;
                    }

                    $before = $this->pairScore($a, $ma, $b, $mb, $assign, $ctx);
                    $after  = $this->pairScore($a, $mb, $b, $ma, $assign, $ctx);

                    // 부동소수 비교라 아주 작은 차이는 개선으로 치지 않는다.
                    if ($after > $before + 1e-9) {
                        $assign[$a] = $mb;
                        $assign[$b] = $ma;
                        $improved = true;
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }
        return [$assign, $passes];
    }

    // =================================================================
    // 최소 1건 보장
    //
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 이것은 점수 문제가 아니라 제약 문제다                          │
    // │                                                              │
    // │ "고른 후보는 가중치를 어떻게 맞춰도 한 건은 받게 하라" 는 요구 │
    // │ 를 **정규화로 풀려고 하면 안 된다.** 정규화는 축의 눈금만      │
    // │ 바꾼다. 모든 태스크에 같은 변환을 걸면 사람 사이의 순위가 그대 │
    // │ 로라 그리디는 똑같은 답을 낸다. 사람마다 다른 변환(z-score 류) │
    // │ 을 걸면 순위는 바뀌지만, 그때부터 화면의 "적합도 44" 가 실제   │
    // │ 44 가 아니게 된다 — 근거 추적이 통째로 깨진다.                 │
    // │                                                              │
    // │ 그래서 점수는 손대지 않고, 배정이 끝난 **뒤에** 제약으로 고친다.│
    // │ 0건인 후보에게 손실이 가장 작은 한 건을 옮겨 준다. 옮긴 사실과 │
    // │ 못 옮긴 이유를 둘 다 남긴다.                                   │
    // │                                                              │
    // │ 반드시 localSearch **뒤에** 돈다. 앞에 두면 지역 탐색이 적합도 │
    // │ 합을 올리려고 그 한 건을 도로 가져간다.                        │
    // └──────────────────────────────────────────────────────────────┘
    // =================================================================

    /**
     * 고른 후보 가운데 0건인 사람에게 한 건씩 넘긴다.
     *
     * @return array{0:array<int,int>, 1:array} [배정, 기록]
     */
    private function ensureEveryPicked(array $assign, array $ctx): array
    {
        $report = ['enabled' => true, 'moved' => [], 'failed' => []];
        if (empty($ctx['min_one'])) {
            return [$assign, ['enabled' => false, 'moved' => [], 'failed' => []]];
        }

        // 대상은 고른 후보. 안 골랐으면 배정 대상 전원이 후보다.
        $targets = $ctx['picked'] ?: $ctx['member_order'];

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 「적게 주라」 를 보장이 뒤집으면 안 된다 (2026-10-08)         │
        // │                                                              │
        // │ 비중을 0.2 로 낮춘 사람에게 보장이 억지로 1건을 떠안기면      │
        // │ 사람의 지시와 기계의 규칙이 맞선다. 비중을 낮춘 것 자체가     │
        // │ **"덜 주라"** 는 뜻이므로 보장 대상에서 뺀다.                 │
        // │                                                              │
        // │ 아예 빼고 싶으면 후보에서 빼면 된다 — 그 길은 따로 있다.      │
        // └──────────────────────────────────────────────────────────────┘
        $lowered = [];
        foreach (($ctx['shares'] ?? []) as $mid => $sh) {
            if ((float)($sh['share'] ?? 1.0) < 1.0 - 0.0001) { $lowered[(int)$mid] = true; }
        }
        $targets = array_values(array_filter($targets,
            static fn($mid) => !isset($lowered[$mid])));

        $counts = array_fill_keys(array_keys($ctx['members']), 0);
        foreach ($assign as $mid) { $counts[$mid] = ($counts[$mid] ?? 0) + 1; }

        // 0건인 사람을 **구성원 번호 순**으로 돈다. 결정론의 뿌리다 —
        // 순서를 안 정하면 같은 입력에 다른 답이 나온다.
        $zero = [];
        foreach ($ctx['member_order'] as $mid) {
            if (in_array($mid, $targets, true) && ($counts[$mid] ?? 0) === 0) { $zero[] = $mid; }
        }

        foreach ($zero as $mid) {
            $take = $this->cheapestDonation($mid, $assign, $counts, $ctx);
            if ($take === null) {
                $report['failed'][] = [
                    'member_id' => $mid,
                    'emp_name'  => $ctx['members'][$mid]['emp_name'],
                    'reason'    => $this->whyNoDonation($mid, $assign, $counts, $ctx),
                ];
                continue;
            }
            [$taskId, $from, $loss] = $take;   // $loss 가 null 이면 점수 없이 준 것
            $assign[$taskId] = $mid;
            $counts[$from]--;
            $counts[$mid]++;
            $report['moved'][] = [
                'task_id'   => $taskId,
                'wbs_no'    => $ctx['tasks'][$taskId]['wbs_no'],
                'title'     => $ctx['tasks'][$taskId]['title'],
                'from_id'   => $from,
                'from_name' => $ctx['members'][$from]['emp_name'],
                'to_id'     => $mid,
                'to_name'   => $ctx['members'][$mid]['emp_name'],
                // null 이면 **적합도를 못 낸 채** 준 것이다. 숨기면 화면이
                // "손실 0" 으로 읽어 잘 맞는 배정처럼 보인다.
                'fit_loss'  => $loss === null ? null : round($loss, 2),
                'blind'     => $loss === null,
            ];
        }
        return [$assign, $report];
    }

    /**
     * 이 사람에게 넘길 수 있는 태스크 중 **적합도 손실이 가장 작은** 것.
     *
     * 손실이 음수일 수도 있다(원래 사람보다 더 맞는 경우). 그러면 더 좋다 —
     * 그리디가 먼저 집어간 탓에 놓쳤던 짝이다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 점수를 낼 수 없는 사람도 받는다 — 다만 맨 나중에               │
     * │                                                              │
     * │ 가중치를 한 항목에 몰면(예: 처리량 1, 나머지 0) 그 점수가 없는│
     * │ 사람은 **적합도가 아예 null** 이 된다. 그런 사람을 건너뛰면    │
     * │ "어떤 조정값 조합에서도 고른 사람은 받는다" 는 약속이 바로 그  │
     * │ 조합에서 깨진다.                                              │
     * │                                                              │
     * │ 그래서 두 단계로 본다. ① 점수가 나오는 짝 중 손실 최소        │
     * │ ② 하나도 없으면 공수가 가장 작은 것. ②로 간 사실은 기록에     │
     * │ 남는다 — 신호가 없어서 그냥 준 것이지 맞아서 준 것이 아니다.  │
     * └──────────────────────────────────────────────────────────────┘
     *
     * @return array{0:int,1:int,2:?float}|null [태스크, 내주는 사람, 손실]
     */
    private function cheapestDonation(int $mid, array $assign, array $counts, array $ctx): ?array
    {
        $best = null; $blind = null;
        foreach ($ctx['order'] as $taskId) {
            $from = $assign[$taskId] ?? null;
            if ($from === null || $from === $mid) { continue; }
            // 내주고 나면 그 사람이 0건이 된다. 돌려막기가 된다.
            if (($counts[$from] ?? 0) <= 1)        { continue; }
            // 사람이 손으로 고정한 것은 건드리지 않는다.
            if (isset($ctx['pinned'][$taskId]))    { continue; }
            if (!$this->canTake($taskId, $mid, $ctx)) { continue; }

            $md   = $ctx['tasks'][$taskId]['est_md'];
            $snap = $this->snapshot($ctx, $assign, [$taskId]);
            $new  = $this->fitScore($ctx['tasks'][$taskId], $ctx['members'][$mid], $snap);
            if ($new['fit_score'] === null) {
                // ② 점수가 없는 짝. 공수가 가장 작은 것만 들고 간다.
                $k = [$md, $taskId];
                if ($blind === null || $k < $blind[0]) { $blind = [$k, $taskId, $from]; }
                continue;
            }
            $old  = $this->fitScore($ctx['tasks'][$taskId], $ctx['members'][$from], $snap);
            $loss = (float)($old['fit_score'] ?? 0) - (float)$new['fit_score'];

            // 동점이면 **공수가 작은 것**을 옮긴다. 큰 덩어리를 옮기면 한
            // 건 주려다 양쪽 부하가 통째로 뒤집힌다.
            $key = [$loss, $md, $taskId];
            if ($best === null || $key < $best[0]) { $best = [$key, $taskId, $from, $loss]; }
        }
        if ($best !== null) {
            return [$best[1], $best[2], $best[3]];
        }
        return $blind === null ? null : [$blind[1], $blind[2], null];
    }

    /** 난이도 상위자 규칙(§6.2)을 깨지 않는 선에서 받을 수 있는가. */
    private function canTake(int $taskId, int $mid, array $ctx): bool
    {
        $task = $ctx['tasks'][$taskId];
        $hard = (int)($ctx['constraints']['hard_difficulty'] ?? 4);
        if ((int)($task['difficulty'] ?? 0) < $hard) {
            return true;
        }
        // ★4~5 를 그 분야 상위자가 아닌 사람에게 떠넘기지 않는다. 한 건
        // 채우자고 어려운 일을 못 하는 사람에게 주면 보장이 해가 된다.
        return $this->isTopInDomain($task, $ctx['members'][$mid], $ctx);
    }

    /** 왜 한 건도 못 줬는지. 답할 수 없는 보장은 아무도 안 믿는다. */
    private function whyNoDonation(int $mid, array $assign, array $counts, array $ctx): string
    {
        $others = 0;
        foreach ($counts as $k => $n) { if ($k !== $mid && $n > 1) { $others++; } }
        if (count($assign) < count($ctx['member_order'])) {
            return '태스크(' . count($assign) . '건)가 후보 수보다 적습니다.';
        }
        if ($others === 0) {
            return '다른 사람도 모두 1건뿐이라 내줄 사람이 없습니다.';
        }
        $scored = false;
        foreach ($ctx['order'] as $taskId) {
            $snap = $this->snapshot($ctx, $assign, [$taskId]);
            $f = $this->fitScore($ctx['tasks'][$taskId], $ctx['members'][$mid], $snap);
            if ($f['fit_score'] !== null) { $scored = true; break; }
        }
        if (!$scored) {
            return '적합도를 낼 수 없습니다 — 역량 판정 표본이 없습니다.';
        }
        return '남은 태스크가 모두 난이도 ' .
               (int)($ctx['constraints']['hard_difficulty'] ?? 4) .
               ' 이상이라 해당 분야 상위자에게만 갈 수 있습니다.';
    }

    /** 두 (태스크, 사람) 짝의 적합도 합. swap 이 이득인지 보는 데만 쓴다. */
    private function pairScore(int $t1, int $m1, int $t2, int $m2, array $assign, array $ctx): float
    {
        // 맞바꾸려는 두 태스크는 부하에서 빼고 본다. 넣어 두면 양쪽 다
        // 자기 무게에 눌려 비교가 흐려진다.
        $snap = $this->snapshot($ctx, $assign, [$t1, $t2]);
        $a = $this->fitScore($ctx['tasks'][$t1], $ctx['members'][$m1], $snap);
        $b = $this->fitScore($ctx['tasks'][$t2], $ctx['members'][$m2], $snap);
        return (float)($a['fit_score'] ?? 0) + (float)($b['fit_score'] ?? 0);
    }

    /**
     * 수동 조정을 반영해 다시 계산한다.
     * 사람이 고정한 항목(is_manual=1)은 그대로 두고 나머지만 다시 짠다.
     */
    public function reproposeWithPinned(int $allocationId, array $params): array
    {
        $a = $this->allocations->find($allocationId);
        if (!$a) {
            throw new DomainException('배정안을 찾을 수 없습니다.');
        }
        $pinned = [];
        foreach ($this->allocations->items($allocationId) as $it) {
            if ($it['is_manual'] && $it['role'] === 'owner') {
                $pinned[(int)$it['task_id']] = (int)$it['member_id'];
            }
        }
        return $this->propose((int)$a['project_id'], $params + ['pinned' => $pinned]);
    }

    /**
     * 산출 근거를 사람이 읽을 수 있는 모양으로.
     * bs_allocation_item.reason_json 에 그대로 들어간다.
     *
     * 이 값이 비면 "왜 이 사람인가" 에 답할 수 없다. 반드시 채운다.
     * 명세서 §6.2-4 가 요구하는 "근거 3줄" 을 지킨다.
     */
    public function explain(array $fit, array $task, array $member, array $ctx): array
    {
        $lines = [];

        // 1줄: 분야
        $ev = $fit['evidence'];
        if ($ev) {
            $names = [];
            foreach ($ev as $e) {
                $names[] = $e['label'] . ' ' . $e['score'] . '점'
                         . ($e['estimated'] ? '(추정)' : '(' . $e['case_count'] . '건)');
            }
            $lines[] = '이 태스크의 계열 처리량: ' . implode(', ', $names) . '.';
        } else {
            $lines[] = '이 태스크에 붙은 분야가 없어 계열 처리량으로 가리지 못했습니다.';
        }

        // 2줄: 가용도
        $av = $ctx['availability'][$member['member_id']] ?? null;
        if ($av) {
            // 적합도에 쓴 값은 **이번 배정을 반영한 뒤**의 참여 가능도다.
            // 원래 가용률만 적으면 "가용 95% 인데 점수가 왜 28 이지" 가 된다.
            $used  = $this->num($ctx['assigned_md'][$member['member_id']] ?? 0);
            $capMd = $this->num($ctx['capacity_md'][$member['member_id']] ?? 0);
            $after = $fit['parts']['avail'] ?? null;

            $lines[] = sprintf(
                '기간 내 가용 %d%% (확정 %d%% + 추정 %d%% 점유), 배정 공수 %s / 가용 %s M/D.%s',
                $av['available_pct'], $av['confirmed_pct'], $av['inferred_pct'],
                $used, $capMd,
                $after !== null && abs($after - (float)$av['available_pct']) >= 0.5
                    ? sprintf(' 이번 배정을 반영한 참여 가능은 %s 로 보고 점수를 냈습니다.',
                              $this->num($after))
                    : ''
            );
        } else {
            $lines[] = '가용도를 계산하지 못했습니다(기간 정보 부족).';
        }

        // 3줄: 점수 구성과 조정
        $bits = [];
        foreach ($fit['parts'] as $k => $v) {
            $bits[] = ($this->partLabel($k)) . ' ' . $v;
        }
        $adj = [];
        if ($fit['penalty'] > 0) { $adj[] = '감점 ' . $this->num($fit['penalty']); }
        if ($fit['bonus'] > 0)   { $adj[] = '가산 ' . $this->num($fit['bonus']); }
        $lines[] = '적합도 ' . $this->num($fit['fit_score'])
                 . ' = ' . implode(' / ', $bits) . ' 가중평균'
                 . ($adj ? ' · ' . implode(', ', $adj) : '') . '.';

        // 사람이 알아야 할 단서를 말로 덧붙인다. 깃발만 두면 화면마다
        // 해석이 갈린다.
        $notes = [];

        // 여러 말단을 묶은 단위라면 **무엇을 묶었는지** 밝힌다. 안 적으면
        // 「로그인/회원가입 · 18 M/D」 가 어디서 나온 숫자인지 알 수 없다.
        $n = (int)($task['leaf_count'] ?? 1);
        if ($n > 1) {
            $notes[] = sprintf(
                '하위 %d건을 묶어 한 사람에게 배정했습니다 — 공수 %s M/D 는 그 합계입니다.%s',
                $n, $this->num($task['est_md']),
                $task['hardest'] !== null && $task['difficulty'] !== null
                    ? sprintf(' 난이도 ★%d 는 가장 어려운 하위「%s」 기준입니다.',
                              $task['difficulty'], $task['hardest'])
                    : ''
            );
        }
        foreach ($fit['flags'] as $f) {
            $n = $this->flagNote($f);
            if ($n !== null) { $notes[] = $n; }
        }

        // 점수는 "29건" 이라는데 링크가 하나도 안 나오는 경우가 있다.
        // 점수를 낸 뒤 원천 데이터가 다시 적재되면 그렇게 된다.
        // 숫자만 믿게 두지 않고 어긋났다는 사실을 적는다.
        $items  = $this->evidenceItems($member, $fit['evidence'], $ctx);
        $claimed = 0;
        foreach ($fit['evidence'] as $e) {
            if (!$e['estimated']) { $claimed += (int)$e['case_count']; }
        }
        if ($claimed > 0 && !$items) {
            $notes[] = '점수의 근거가 된 처리 건을 지금 찾을 수 없습니다('
                     . $claimed . '건으로 집계됐으나 0건 조회). '
                     . '점수를 낸 뒤 업무 이력이 다시 적재됐을 수 있습니다 — 재판정이 필요합니다.';
        }

        return [
            'lines'      => $lines,
            'notes'      => $notes,
            'flags'      => $fit['flags'],
            'parts'      => $fit['parts'],
            'base'       => $fit['base'],
            'penalty'    => $fit['penalty'],
            'bonus'      => $fit['bonus'],
            'evidence'   => $fit['evidence'],
            // 근거 건 링크 — 이 계열에서 실제로 처리한 일들.
            'work_items' => $items,
            'engine_ver' => BS_ENGINE_VER,
            'eval_ver'   => $ctx['eval_ver'],
        ];
    }

    /**
     * 근거가 된 실제 처리 건 몇 개. 슬랙 링크까지 담는다.
     * 점수만 보여 주면 "그래서 뭘 했길래" 에 답할 수 없다(CLAUDE.md 역추적).
     */
    private function evidenceItems(array $member, array $evidence, array $ctx): array
    {
        $cats = array_values(array_filter(array_map(
            static fn($e) => $e['estimated'] ? null : $e['category'], $evidence
        )));
        if (!$cats || $ctx['eval_ver'] === null) {
            return [];
        }
        $out = [];
        foreach ($cats as $cat) {
            foreach ($this->members->evidence((int)$member['member_id'], $cat, null,
                                              $ctx['eval_ver']) as $w) {
                $out[] = [
                    'id'         => (int)$w['id'],
                    'title'      => $w['title'],
                    'source_url' => $w['source_url'],
                    'org_name'   => $w['org_name'],
                    'difficulty' => $w['difficulty'] !== null ? (int)$w['difficulty'] : null,
                    'closed_at'  => $w['closed_at'],
                ];
                if (count($out) >= 5) {
                    return $out;
                }
            }
        }
        return $out;
    }

    private function partLabel(string $k): string
    {
        return ['domain' => '분야', 'cap' => '처리량', 'avail' => '가용',
                'career' => '경력', 'growth' => '성장'][$k] ?? $k;
    }

    private function flagNote(string $f): ?string
    {
        return [
            'domain_estimated' => '이 계열의 처리 이력이 없어 동료들의 중앙값으로 놓았습니다. 실제 실력은 다를 수 있습니다.',
            'domain_unknown'   => '태스크에 붙은 분야로는 처리 이력을 가리지 못했습니다.',
            'cap_missing'      => '처리량 점수가 없습니다(판정 대상에서 빠졌거나 표본 부족).',
            'overload'         => '가용 공수를 넘겨 배정했습니다. 기간이나 인원을 손봐야 합니다.',
            'concentrated'     => '이 사람에게 일이 몰려 있습니다.',
            'same_group'       => '같은 대분류를 이미 맡고 있어 가산점을 줬습니다.',
            'unscorable'       => '점수를 낼 수 없어 배정하지 못했습니다.',
        ][$f] ?? null;
    }

    private function num(float|int|null $v): string
    {
        if ($v === null) {
            return '-';
        }
        return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') ?: '0';
    }

    // =================================================================
    // 문맥 만들기
    // =================================================================

    /**
     * 산출에 필요한 것을 한 번에 모은다.
     *
     * 태스크마다 DB 를 다시 타면 수십 번 왕복한다. 한 번에 읽어 두고
     * 배열로만 계산한다 — 그래야 지역 탐색이 수천 번 돌아도 버틴다.
     */
    private function buildContext(int $projectId, array $params): array
    {
        $weights     = $this->mergeWeights($params['weights'] ?? []);
        $constraints = ($params['constraints'] ?? []) + BS_ALLOC_CONSTRAINTS;

        // --- 태스크: 확정된 것만. 이 경로 말고 다른 조회를 쓰지 말 것 ---
        $all = $this->tasks->confirmedForAllocation($projectId);

        // 말단만 배정한다. 상위 태스크는 하위의 묶음이라, 같이 배정하면
        // 공수가 이중으로 잡힌다(TaskRepo::toTree 의 롤업 규칙과 같은 이유).
        // 트리 전체는 한 번만 읽는다. 아래에서 부모 관계에도 같이 쓴다.
        $allRows  = $this->tasks->allByProject($projectId);
        $hasChild = [];
        $parentOf = [];
        foreach ($allRows as $t) {
            $parentOf[(int)$t['id']] = $t['parent_id'] !== null ? (int)$t['parent_id'] : null;
            if ($t['parent_id'] !== null) {
                $hasChild[(int)$t['parent_id']] = true;
            }
        }

        // 확정된 **말단**이 일의 최소 단위다. 여기까지는 늘 같다.
        $leaves = [];
        foreach ($all as $t) {
            $id = (int)$t['id'];
            if (isset($hasChild[$id])) {
                continue;
            }
            $leaves[$id] = [
                'id'         => $id,
                'wbs_no'     => $t['wbs_no'],
                'title'      => $t['title'],
                'parent_id'  => $t['parent_id'] !== null ? (int)$t['parent_id'] : null,
                'depth'      => (int)$t['depth'],
                'seq'        => (int)$t['seq'],
                'est_md'     => $t['est_md'] !== null ? (float)$t['est_md'] : 0.0,
                'difficulty' => $t['difficulty'] !== null ? (int)$t['difficulty'] : null,
                'plan_start' => $t['plan_start'],
                'plan_end'   => $t['plan_end'],
            ];
        }

        // 그 말단들을 어떤 덩어리로 묶어 배정할지 정한다.
        // --- 구성원 ---
        $evalVer = $this->members->latestEvalVer();
        $members = $this->loadMembers($params['member_ids'] ?? [], $evalVer);

        // 고른 후보 가운데 **실제로 배정 대상이 된** 사람. 배정 제외자나
        // 없는 번호를 골랐어도 여기서 걸러진다 — 못 지킬 약속을 만들지 않는다.
        $picked = [];
        foreach (($params['member_ids'] ?? []) as $mid) {
            if (isset($members[(int)$mid])) { $picked[] = (int)$mid; }
        }
        // 기본값은 **후보를 고른 경우에만 켜짐**. 명시로 주면 그대로 따른다.
        $minOne = array_key_exists('min_one', $params)
                ? (bool)$params['min_one'] : (bool)$picked;

        // --- 기간과 가용도 ---
        //
        // ┌──────────────────────────────────────────────────────────────┐
        // │ AIDD 는 프로젝트가 원본, 배정안이 사본 (2026-10-08)           │
        // │                                                              │
        // │ 보정이 3단계(공수)와 4단계(배정)에 걸쳐 있어 params_json 만   │
        // │ 으로는 3단계가 못 본다. 그래서 프로젝트를 원본으로 두고,      │
        // │ 여기서 산출 시점 값을 **복사해 meta 에 남긴다** — 나중에      │
        // │ "그때 계수가 얼마였나" 에 답해야 한다.                        │
        // │                                                              │
        // │ 차수마다 끌 수 있다(params['aidd'] = false). 그래야 [차수     │
        // │ 비교] 로 켠 안과 끈 안을 나란히 놓고 견줄 수 있다.            │
        // └──────────────────────────────────────────────────────────────┘
        $aidd = bs_aidd_of($this->projects?->find($projectId));
        if (array_key_exists('aidd', $params) && !$params['aidd']) {
            $aidd = ['enabled' => false, 'effort' => 1.0, 'load' => 1.0];
        }

        [$from, $to] = $this->projectWindow($projectId);
        $avail = ($from !== null && $members)
            ? $this->availability->forMembers(array_keys($members), $from, $to, $aidd['load'])
            : [];
        $workdays = $avail ? (int)(reset($avail)['workdays'] ?? 0) : 0;

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 점수를 깎지 않고 **그릇**을 줄인다 (2026-10-08)               │
        // │                                                              │
        // │ 「요즘 컨디션이 안 좋다」 「곧 다른 데로 빠진다」 는 재지지    │
        // │ 않는다. 그런 판단을 적합도에 곱하면 화면의 "적합도 47" 이     │
        // │ 실제 47 이 아니게 된다 — 최소 1건 보장을 정규화로 풀지 않은   │
        // │ 바로 그 이유다.                                               │
        // │                                                              │
        // │ 가용 공수를 줄이면 적합도는 실제 값 그대로 두고, 가용도 축과  │
        // │ 과부하 감점이 저절로 반응해 배정량이 준다. 그리고 "이 사람은  │
        // │ 이 프로젝트에 절반만" 이라는 사람의 말과 1:1로 맞는다.        │
        // │                                                              │
        // │ 줄이 없는 사람은 1.0 이라 **이 변경 전과 완전히 같은 값**이다.│
        // └──────────────────────────────────────────────────────────────┘
        $shares = $this->projects?->memberShares($projectId) ?? [];

        $capacity = [];
        foreach ($members as $mid => $m) {
            $a = $avail[$mid] ?? null;
            // 가용 공수(M/D) = 남은 가용량 × 영업일 × 제약 비율 × 참여 비중
            //
            // AIDD 를 켜면 available_aidd(= available + 할인분)를 쓴다.
            // 계수가 1.00 이면 둘이 같아 **이 변경 전과 완전히 같은 값**이다.
            $capacity[$mid] = $a
                ? round((float)($a['available_aidd'] ?? $a['available']) * $workdays
                        * (float)($constraints['capacity_ratio'] ?? 1.0)
                        * (float)($shares[$mid]['share'] ?? 1.0), 2)
                : 0.0;
        }

        // --- 계열 점수와 중앙값·상위 기준선 ---
        [$catScores, $catMedians, $catCutoff] =
            $this->categoryTables($members, $evalVer, (float)($constraints['top_ratio'] ?? 0.5));

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 배정 단위를 정하는 자리                                       │
        // │                                                              │
        // │ 가용 공수를 알아야 '자동' 이 "혼자 맡기 버거운가" 를 판단할   │
        // │ 수 있어, 구성원·가용도를 읽은 뒤에 온다.                      │
        // └──────────────────────────────────────────────────────────────┘
        $level = self::levelOf($params['level'] ?? '');
        $cut   = $this->cutUnits($leaves, $allRows, $level, $capacity,
                                 $this->tasks->domainsFor(array_keys($leaves)));
        $tasks       = $cut['units'];
        $taskDomains = $cut['domains'];

        // 태스크를 도는 순서를 고정한다. 결정론의 뿌리다.
        $order = array_keys($tasks);
        usort($order, function (int $a, int $b) use ($tasks): int {
            $ta = $tasks[$a]; $tb = $tasks[$b];
            return (($tb['difficulty'] ?? 0) <=> ($ta['difficulty'] ?? 0))
                ?: ($tb['est_md'] <=> $ta['est_md'])
                ?: strnatcmp((string)$ta['wbs_no'], (string)$tb['wbs_no'])
                ?: ($a <=> $b);
        });

        // --- 대분류(depth=1) 뿌리 ---
        $rootOf = [];
        foreach ($tasks as $id => $_) {
            $cur = $id;
            for ($i = 0; $i < BS_TASK_MAX_DEPTH && ($parentOf[$cur] ?? null) !== null; $i++) {
                $cur = $parentOf[$cur];
            }
            $rootOf[$id] = $cur;
        }

        $totalMd = 0.0;
        foreach ($tasks as $t) { $totalMd += $t['est_md']; }

        $pinned = [];
        foreach (($params['pinned'] ?? []) as $tid => $mid) {
            $tid = (int)$tid; $mid = (int)$mid;
            if (isset($tasks[$tid]) && isset($members[$mid])) {
                $pinned[$tid] = $mid;
            }
        }

        return [
            'tasks'            => $tasks,
            'order'            => $order,
            'root_of'          => $rootOf,
            'members'          => $members,
            'member_order'     => array_keys($members),
            'availability'     => $avail,
            'capacity_md'      => $capacity,
            'assigned_md'      => [],
            'group_owner'      => [],
            'total_md'         => $totalMd,
            'category_scores'  => $catScores,
            'category_medians' => $catMedians,
            'category_cutoff'  => $catCutoff,
            'task_domains'     => $taskDomains,
            'weights'          => $weights,
            'constraints'      => $constraints,
            'eval_ver'         => $evalVer,
            'from'             => $from,
            'to'               => $to,
            'workdays'         => $workdays,
            'pinned'           => $pinned,
            'level'            => $level,
            // 명시적으로 고른 후보. 안 골랐으면 빈 배열이다 — '전원 대상'
            // 과 '이 사람들로 하자' 는 다른 말이고, 최소 보장은 **뒤쪽에만**
            // 건다. 전원 대상에서 20명 중 10건을 나누면 반은 반드시 0건이다.
            'picked'           => $picked,
            'min_one'          => $minOne,
            'aidd'             => $aidd,
            // 사람이 낮춰 둔 비중. 배정 대상인 사람 것만 들고 간다.
            'shares'           => array_intersect_key($shares, $members),
            // 자동이 왜 그렇게 나눴는지. 화면이 그대로 보여 준다.
            'split_reasons'    => $cut['reasons'],
            // 단위 하나에 말단이 몇 건 들었는지. 근거에 쓴다.
            'leaves_of'        => $cut['members_of'],
        ];
    }

    /** 가중치를 기본값 위에 얹고 범위를 지킨다. */
    private function mergeWeights(array $given): array
    {
        $out = BS_ALLOC_WEIGHTS;
        foreach ($given as $k => $v) {
            if (!array_key_exists($k, $out)) {
                continue;   // 모르는 가중치는 무시한다(오타로 식이 바뀌면 안 된다)
            }
            if (!is_numeric($v)) {
                throw new InvalidArgumentException("가중치는 숫자여야 합니다: $k");
            }
            $out[$k] = max(0.0, min(BS_ALLOC_WEIGHT_MAX, (float)$v));
        }
        if (array_sum($out) <= 0) {
            throw new InvalidArgumentException(
                '가중치가 전부 0 입니다. 하나 이상은 0 보다 커야 합니다.');
        }
        return $out;
    }

    /** 배정 대상 구성원. member_ids 를 주면 그 안에서만 고른다. */
    private function loadMembers(array $memberIds, ?int $evalVer): array
    {
        $rows = $this->members->assignable();
        $only = array_flip(array_map('intval', $memberIds));

        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ($only && !isset($only[$id])) {
                continue;
            }
            $metric = $evalVer !== null ? $this->members->metric($id, $evalVer) : null;
            $out[$id] = [
                'member_id'    => $id,
                'emp_name'     => $r['emp_name'],
                'role_label'   => $r['role_label'],
                'team'         => $r['team'],
                'base_capacity' => (float)$r['base_capacity'],
                'cap_score'    => isset($metric['cap_score']) && $metric['cap_score'] !== null
                                  ? (float)$metric['cap_score'] : null,
                'career_score' => isset($metric['career_score']) && $metric['career_score'] !== null
                                  ? (float)$metric['career_score'] : null,
                'insufficient_data' => (int)($metric['insufficient_data'] ?? 1) === 1,
            ];
        }
        // 구성원 순서도 고정한다(번호 오름). 동점을 가르는 기준이 된다.
        ksort($out);
        return $out;
    }

    /**
     * 계열 점수표 + 중앙값 + 상위 기준선.
     *
     * 중앙값은 **점수가 있는 사람들** 로만 낸다. 표본 없는 사람을 0 으로
     * 넣고 중앙값을 내면 기준선 자체가 내려앉는다.
     *
     * @return array{0:array,1:array,2:array}
     */
    private function categoryTables(array $members, ?int $evalVer, float $topRatio = 0.5): array
    {
        $scores = [];
        $byCat  = [];
        if ($evalVer !== null) {
            foreach ($members as $mid => $_) {
                foreach ($this->members->categoryScores($mid, $evalVer) as $c) {
                    $cat = $c['category'];
                    $scores[$mid][$cat] = [
                        'score' => $c['score'] !== null ? (float)$c['score'] : null,
                        'case_count' => (int)($c['case_count'] ?? 0),
                        'insufficient_data' => !empty($c['insufficient_data']),
                    ];
                    if ($c['score'] !== null && empty($c['insufficient_data'])) {
                        $byCat[$cat][] = (float)$c['score'];
                    }
                }
            }
        }

        $medians = [];
        $cutoff  = [];
        foreach ($byCat as $cat => $vals) {
            if (count($vals) < self::MEDIAN_MIN_SAMPLE) {
                // 비교 집단이 너무 작으면 중앙값이 한 사람에 좌우된다.
                // 그럴 바에는 대체값을 만들지 않는다.
                continue;
            }
            sort($vals);
            $medians[$cat] = $this->percentile($vals, 0.5);
            // top_ratio=0.5 면 중앙값 이상이 '상위자'. 0.3 이면 상위 30% 다.
            $cutoff[$cat]  = $this->percentile($vals, max(0.0, min(1.0, 1.0 - $topRatio)));
        }
        return [$scores, $medians, $cutoff];
    }

    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $i = ($n - 1) * $p;
        $lo = (int)floor($i);
        $hi = (int)ceil($i);
        if ($lo === $hi) {
            return (float)$sorted[$lo];
        }
        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($i - $lo);
    }

    /**
     * 가용도를 볼 기간. 개발 기간이 기본이고, 비면 테스트·배포일로 넓힌다.
     * api/candidate.php 의 bs_project_window() 와 같은 규칙이다.
     */
    private function projectWindow(int $projectId): array
    {
        $p = $this->projects?->find($projectId);
        if (!$p) {
            return [null, null];
        }
        $from = $p['dev_start'] ?: ($p['test_start'] ?: null);
        $to   = $p['deploy_date'] ?: ($p['test_end'] ?: ($p['dev_end'] ?: null));
        if ($from === null || $to === null || $from > $to) {
            return [null, null];
        }
        return [$from, $to];
    }

    /**
     * 지금까지의 배정 상태를 fitScore() 가 쓸 모양으로.
     *
     * `$except` 에 든 태스크는 **빼고** 센다. 어떤 배정의 점수를 매길 때
     * 그 배정 자신을 부하에 넣으면 스스로를 깎는다 — 처음에 그렇게 짰다가
     * "일이 몰려 있습니다" 가 첫 배정부터 뜨는 것을 보고 알았다.
     * 같은 이유로 대분류 가산점도 자기가 만든 것을 자기가 받게 된다.
     *
     * @param int[] $except
     */
    private function snapshot(array $ctx, array $assign, array $except = []): array
    {
        $skip  = array_flip($except);
        $md    = [];
        $group = [];
        foreach ($assign as $taskId => $mid) {
            if (isset($skip[$taskId])) {
                continue;
            }
            $t = $ctx['tasks'][$taskId] ?? null;
            if ($t === null) {
                continue;
            }
            $md[$mid] = ($md[$mid] ?? 0) + $t['est_md'];
            $root = $ctx['root_of'][$taskId] ?? null;
            if ($root !== null) {
                $group[$root][$mid] = true;
            }
        }
        $ctx['assigned_md'] = $md;
        $ctx['group_owner'] = $group;
        return $ctx;
    }

    private function summarize(array $assign, array $ctx): array
    {
        $snap = $this->snapshot($ctx, $assign);
        $rows = [];
        foreach ($ctx['members'] as $mid => $m) {
            $md  = (float)($snap['assigned_md'][$mid] ?? 0);
            $cap = (float)($ctx['capacity_md'][$mid] ?? 0);
            $n   = 0;
            foreach ($assign as $x) { if ($x === $mid) { $n++; } }
            $rows[] = [
                'member_id'   => $mid,
                'emp_name'    => $m['emp_name'],
                'role_label'  => $m['role_label'],
                'assigned_md' => round($md, 2),
                'capacity_md' => round($cap, 2),
                'load_pct'    => $cap > 0 ? (int)round($md / $cap * 100) : null,
                'task_count'  => $n,
                'over'        => $cap > 0 && $md > $cap,
            ];
        }
        usort($rows, static fn($a, $b) => ($b['assigned_md'] <=> $a['assigned_md'])
                                       ?: ($a['member_id'] <=> $b['member_id']));
        return [
            'by_member'  => $rows,
            'total_md'   => round((float)$ctx['total_md'], 2),
            'assigned'   => count($assign),
            'task_count' => count($ctx['tasks']),
        ];
    }

    // =================================================================
    // §6.3 LLM 활용 지점 (제한적)
    // =================================================================

    /**
     * 배정안에 대한 설명문 초안.
     *
     * LLM 은 **이미 정해진 결과를 글로 풀어 쓰는 데까지만** 쓴다.
     * 누구를 배정할지 고르게 하지 않는다(CLAUDE.md).
     * spec §11-5(반출 정책) 미결 상태에서는 부르지 않는다.
     */
    public function draftNarrative(array $allocation): string
    {
        // TODO(P7): 반출 정책이 정해진 뒤에 착수.
        return '';
    }
}
