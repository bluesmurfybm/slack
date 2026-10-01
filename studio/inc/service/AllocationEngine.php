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
        // 프로젝트 기간을 읽는다. ba_db() 를 직접 부르면 시험이 시험 DB 를
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
     * BA_ALLOC_WEIGHTS 주석). 없는 점수를 0 으로 넣으면 모두가 그만큼
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
        $w = $context['weights'] ?? BA_ALLOC_WEIGHTS;

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
        $av = $context['availability'][$member['member_id']] ?? null;
        if ($av !== null) {
            $parts['avail'] = (float)$av['available_pct'];
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
     * 태스크가 여러 분야에 걸리면 ba_task_domain.weight 로 가중 평균한다.
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
            if ($cat === null || $cat === '' || in_array($cat, BA_CATEGORY_NOT_SCORED, true)) {
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
                    'label'      => BA_DOMAIN_CATEGORY[$cat]['label'] ?? $cat,
                    'score'      => round($s, 1),
                    'case_count' => (int)$row['case_count'],
                    'estimated'  => false,
                ];
            } elseif (isset($medians[$cat])) {
                $s = (float)$medians[$cat];
                $estimated = true;
                $evidence[] = [
                    'category'   => $cat,
                    'label'      => BA_DOMAIN_CATEGORY[$cat]['label'] ?? $cat,
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
    private function overloadPenalty(array $task, array $member, array $context): float
    {
        $mid = $member['member_id'];
        $cap = (float)($context['capacity_md'][$mid] ?? 0);
        if ($cap <= 0) {
            return 50.0;   // 가용 공수가 아예 없는 사람
        }
        $used  = (float)($context['assigned_md'][$mid] ?? 0);
        $after = $used + (float)($task['est_md'] ?? 0);
        if ($after <= $cap) {
            return 0.0;
        }
        $over = ($after - $cap) / $cap;         // 0.2 = 20% 초과
        return min(50.0, $over * 100.0);
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

        // --- 1) 그리디 초기해 -------------------------------------------
        $assign = $this->greedy($ctx);

        // --- 2) 지역 탐색(swap) 개선 --------------------------------------
        [$assign, $passes] = $this->localSearch($assign, $ctx);

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
                'engine_ver'   => BA_ENGINE_VER,
                'eval_ver'     => $ctx['eval_ver'],
                'weights'      => $ctx['weights'],
                'constraints'  => $ctx['constraints'],
                'task_count'   => count($ctx['tasks']),
                'member_count' => count($ctx['members']),
                'passes'       => $passes,
                'period'       => ['from' => $ctx['from'], 'to' => $ctx['to']],
                'workdays'     => $ctx['workdays'],
                // 난수를 쓰지 않으므로 씨앗이 없다. 같은 입력이면 같은 결과다.
                'deterministic' => true,
            ],
        ];
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
        $max    = (int)($ctx['constraints']['max_passes'] ?? BA_ALLOC_MAX_PASSES);

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
     * ba_allocation_item.reason_json 에 그대로 들어간다.
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
            $lines[] = sprintf(
                '기간 내 가용 %d%% (확정 %d%% + 추정 %d%% 점유), 배정 공수 %s / 가용 %s M/D.',
                $av['available_pct'], $av['confirmed_pct'], $av['inferred_pct'],
                $this->num($ctx['assigned_md'][$member['member_id']] ?? 0),
                $this->num($ctx['capacity_md'][$member['member_id']] ?? 0)
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
            'engine_ver' => BA_ENGINE_VER,
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
        $constraints = ($params['constraints'] ?? []) + BA_ALLOC_CONSTRAINTS;

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

        $tasks = [];
        foreach ($all as $t) {
            $id = (int)$t['id'];
            if (isset($hasChild[$id])) {
                continue;
            }
            $tasks[$id] = [
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
            for ($i = 0; $i < BA_TASK_MAX_DEPTH && ($parentOf[$cur] ?? null) !== null; $i++) {
                $cur = $parentOf[$cur];
            }
            $rootOf[$id] = $cur;
        }

        // --- 구성원 ---
        $evalVer = $this->members->latestEvalVer();
        $members = $this->loadMembers($params['member_ids'] ?? [], $evalVer);

        // --- 기간과 가용도 ---
        [$from, $to] = $this->projectWindow($projectId);
        $avail = ($from !== null && $members)
            ? $this->availability->forMembers(array_keys($members), $from, $to)
            : [];
        $workdays = $avail ? (int)(reset($avail)['workdays'] ?? 0) : 0;

        $capacity = [];
        foreach ($members as $mid => $m) {
            $a = $avail[$mid] ?? null;
            // 가용 공수(M/D) = 남은 가용량 × 영업일 × 제약 비율
            $capacity[$mid] = $a
                ? round((float)$a['available'] * $workdays
                        * (float)($constraints['capacity_ratio'] ?? 1.0), 2)
                : 0.0;
        }

        // --- 계열 점수와 중앙값·상위 기준선 ---
        [$catScores, $catMedians, $catCutoff] =
            $this->categoryTables($members, $evalVer, (float)($constraints['top_ratio'] ?? 0.5));

        // --- 태스크 분야 ---
        $taskDomains = $this->tasks->domainsFor(array_keys($tasks));

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
        ];
    }

    /** 가중치를 기본값 위에 얹고 범위를 지킨다. */
    private function mergeWeights(array $given): array
    {
        $out = BA_ALLOC_WEIGHTS;
        foreach ($given as $k => $v) {
            if (!array_key_exists($k, $out)) {
                continue;   // 모르는 가중치는 무시한다(오타로 식이 바뀌면 안 된다)
            }
            if (!is_numeric($v)) {
                throw new InvalidArgumentException("가중치는 숫자여야 합니다: $k");
            }
            $out[$k] = max(0.0, min(BA_ALLOC_WEIGHT_MAX, (float)$v));
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
     * api/candidate.php 의 ba_project_window() 와 같은 규칙이다.
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
