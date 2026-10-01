<?php
/** 프로젝트 기간과 겹치는 점유를 근거로 구성원 참여 가능도를 산출한다. 명세서 §5. */

declare(strict_types=1);

/**
 * 참여 가능도 계산기.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 확정 점유와 추정 점유를 **절대 합쳐서 내보내지 않는다.**           │
 * │                                                                  │
 * │   confirmed  assigned(확정 배정) + manual(휴가·교육 등 사람 입력) │
 * │   inferred   아직 진행 중인 슬랙 건에서 어림한 값                  │
 * │                                                                  │
 * │ 추정은 근거가 약하다. 건당 계수(BS_INFERRED_LOAD_PER_ITEM)는       │
 * │ 실제 소요를 잰 값이 아니라 어림이다. 합쳐서 하나의 숫자로 보여주면  │
 * │ 사람이 그것을 사실로 읽고 배정을 결정한다.                         │
 * │                                                                  │
 * │ 화면은 "가용 62% (확정 25% + 추정 13% 점유)" 처럼 나눠 쓴다.       │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 영업일 기준으로 센다. 주말과 공휴일은 포털 core/board.php 의
 * board_holiday_set() / board_is_workday() 를 그대로 쓴다 — 이미 있는 것을
 * 다시 만들지 않는다(conventions.md §2.4 에서 확인한 함수다).
 */
final class AvailabilityCalculator
{
    /** 같은 요청에서 여러 번 불린다. 공휴일 집합을 한 번만 읽는다. */
    private ?array $holidays = null;

    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 영업일
    // =================================================================

    private function holidays(): array
    {
        if ($this->holidays === null) {
            // core/board.php 가 이미 include 되어 있다(inc/bootstrap.php).
            $this->holidays = function_exists('board_holiday_set') ? board_holiday_set() : [];
        }
        return $this->holidays;
    }

    /**
     * 두 기간이 겹치는 **영업일** 수.
     * 달력일로 세면 주말이 낀 기간이 과대평가된다.
     */
    public function overlapWorkdays(string $aFrom, string $aTo, string $bFrom, string $bTo): int
    {
        $s = max($aFrom, $bFrom);
        $e = min($aTo, $bTo);
        if ($s > $e) {
            return 0;
        }
        return $this->workdays($s, $e);
    }

    /**
     * 자른 뒤의 몫. $raw 가 $capped 로 잘렸을 때 원래 비율대로 나눈다.
     *
     * 자르지 않았으면(대부분의 경우) 그냥 $part 를 돌려준다 — 부동소수점
     * 연산을 한 번도 더 하지 않는다. **R&D 가 없는 사람에게는 이 함수가
     * 값을 바꾸지 않는다.**
     */
    private function share(float $part, float $raw, float $capped): float
    {
        if ($raw <= 0) {
            return 0.0;
        }
        if ($raw <= $capped + 0.0001) {
            return round($part, 4);
        }
        return round($part * ($capped / $raw), 4);
    }

    /** 기간 안의 영업일 수 (양끝 포함). */
    public function workdays(string $from, string $to): int
    {
        if ($from > $to) {
            return 0;
        }
        $h = $this->holidays();
        $n = 0;
        $cur = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        // 1년(약 260영업일)을 넘는 기간은 다루지 않는다 — 무한루프 방지
        $guard = 0;
        while ($cur <= $end && $guard++ < 1100) {
            $ymd = $cur->format('Y-m-d');
            if (function_exists('board_is_workday')) {
                if (board_is_workday($ymd, $h)) {
                    $n++;
                }
            } else {
                $w = (int)$cur->format('N');
                if ($w <= 5) {
                    $n++;
                }
            }
            $cur = $cur->modify('+1 day');
        }
        return $n;
    }

    // =================================================================
    // 가용도
    // =================================================================

    /**
     * 한 사람의 기간 내 참여 가능도.
     *
     * @return array{
     *   member_id:int, base_capacity:float, workdays:int,
     *   confirmed_load:float, inferred_load:float, available:float,
     *   confirmed_pct:int, inferred_pct:int, available_pct:int,
     *   active_items:int, confidence:float,
     *   breakdown:array, inferred_items:array
     * }
     */
    public function forMember(int $memberId, string $from, string $to): array
    {
        $rows = $this->forMembers([$memberId], $from, $to);
        return $rows[$memberId] ?? $this->emptyResult($memberId, $from, $to);
    }

    /**
     * 여러 사람을 한 번에. 후보 리스트가 N+1 질의를 내지 않도록.
     *
     * @return array<int, array> member_id => forMember() 와 같은 모양
     */
    public function forMembers(array $memberIds, string $from, string $to): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if (!$memberIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($memberIds), '?'));

        // 기본 가용량
        $st = $this->pdo->prepare(
            "SELECT id, base_capacity FROM bs_member WHERE id IN ($ph)"
        );
        $st->execute($memberIds);
        $cap = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cap[(int)$r['id']] = (float)$r['base_capacity'];
        }

        $totalWorkdays = max(1, $this->workdays($from, $to));

        // ── 확정 점유 (assigned + manual) ─────────────────────────────
        //
        // kind='inferred' 는 **일부러 뺀다.** 추정은 아래에서 bs_work_item 으로
        // 그때그때 다시 계산한다. bs_workload 에 저장된 추정치를 함께 쓰면
        // 언제 갱신됐는지 모르는 값이 섞이고, refreshInferred() 를 돌린 뒤에는
        // 같은 건을 두 번 세게 된다.
        $st = $this->pdo->prepare(
            "SELECT id, member_id, kind, label, ref_type, ref_id,
                    source, source_url, note, created_by, created_by_name,
                    start_date, end_date, load_ratio, confidence
               FROM bs_workload
              WHERE member_id IN ($ph)
                AND kind IN ('assigned', 'manual')
                AND start_date <= ? AND end_date >= ?"
        );
        $st->execute(array_merge($memberIds, [$to, $from]));

        $confirmed = array_fill_keys($memberIds, 0.0);
        $breakdown = array_fill_keys($memberIds, []);

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 분해는 **더하기만** 한다 (P10-2)                              │
        // │                                                              │
        // │ confirmed_load 와 available 을 내는 식은 한 글자도 바꾸지     │
        // │ 않았다. 아래 두 칸은 같은 값을 '어디서 왔는지' 로 나눠 담을   │
        // │ 뿐이다. 합치면 언제나 confirmed 와 같다.                      │
        // │                                                              │
        // │ R&D 참여가 없는 사람은 rnd 가 0 이고, project 가 곧           │
        // │ confirmed 다 — 즉 **이 변경 전과 완전히 같은 수치**가 나온다. │
        // │ dev/rnd_test.php [18] 이 그것을 지킨다.                       │
        // └──────────────────────────────────────────────────────────────┘
        $byProject = array_fill_keys($memberIds, 0.0);
        $byRnd     = array_fill_keys($memberIds, 0.0);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $mid = (int)$w['member_id'];
            $ov  = $this->overlapWorkdays($from, $to, $w['start_date'], $w['end_date']);
            if ($ov <= 0) {
                continue;
            }
            // 프로젝트 기간 전체에 대한 실효 점유율로 환산한다.
            // 2주만 겹치는 100% 점유는 3개월 프로젝트에서 100% 가 아니다.
            $eff = (float)$w['load_ratio'] * ($ov / $totalWorkdays);
            $confirmed[$mid] += $eff;

            if (($w['ref_type'] ?? '') === 'rnd') {
                $byRnd[$mid] += $eff;
            } else {
                $byProject[$mid] += $eff;
            }
            $breakdown[$mid][] = [
                'source'        => 'confirmed',
                'id'            => (int)$w['id'],
                'kind'          => $w['kind'],
                // 어디서 온 점유인지 화면이 구분해 그릴 수 있어야 한다.
                // 배정에서 나온 것과 사람이 적어 넣은 것은 같은 무게가 아니다.
                'kind_label'    => $w['kind'] === 'manual' ? '직접 등록' : '배정',
                'label'         => $w['label'] ?: ($w['kind'] === 'manual' ? '직접 등록' : '확정 배정'),
                'note'          => $w['note'] ?? null,
                'origin'        => $w['source'] ?? null,
                'origin_label'  => (isset($w['source']) && isset(BS_WORKLOAD_SOURCE[$w['source']]))
                                   ? BS_WORKLOAD_SOURCE[$w['source']] : null,
                'origin_url'    => $w['source_url'] ?? null,
                'created_by'      => $w['created_by'] ?? null,
                'created_by_name' => $w['created_by_name'] ?? null,
                'start_date'    => $w['start_date'],
                'end_date'      => $w['end_date'],
                'load_ratio'    => (float)$w['load_ratio'],
                'overlap_days'  => $ov,
                'effective'     => round($eff, 4),
                'ref_type'      => $w['ref_type'],
                'ref_id'        => $w['ref_id'] !== null ? (int)$w['ref_id'] : null,
            ];
        }

        // ── 추정 점유 (진행 중인 슬랙 건) ─────────────────────────────
        $inf = $this->inferFromWorkItems($memberIds);

        // ── 합치기 ────────────────────────────────────────────────────
        $out = [];
        foreach ($memberIds as $mid) {
            $base = $cap[$mid] ?? 1.0;
            $c    = round(min($base, $confirmed[$mid]), 4);

            $rawConfirmed = $confirmed[$mid];
            $projectPct   = (int)round($this->share($byProject[$mid], $rawConfirmed, $c) * 100);
            $i    = round(min(BS_INFERRED_LOAD_MAX, $inf[$mid]['load'] ?? 0.0), 4);
            $avail = max(0.0, $base - $c - $i);

            // 추정 비중이 클수록 이 숫자를 덜 믿어야 한다.
            $used = $c + $i;
            $conf = $used <= 0 ? 1.0
                  : round(($c + $i * BS_INFERRED_CONFIDENCE) / $used, 3);

            $out[$mid] = [
                'member_id'      => $mid,
                'base_capacity'  => $base,
                'workdays'       => $totalWorkdays,
                'confirmed_load' => $c,
                'inferred_load'  => $i,
                'available'      => round($avail, 4),
                // 화면이 바로 쓰도록 % 도 함께. 반올림은 여기서 한 번만 한다.
                'confirmed_pct'  => (int)round($c * 100),
                'inferred_pct'   => (int)round($i * 100),
                'available_pct'  => (int)round($avail * 100),

                // ── 확정 점유의 내역 (P10-2) ──────────────────────────
                //
                // project + rnd = confirmed. 언제나 그렇다.
                //
                // confirmed 는 base 로 한 번 잘린다(min). 잘렸을 때 원래
                // 비율대로 나눠 담는다 — 안 그러면 화면에서 "확정 100%
                // (프로젝트 90% + R&D 50%)" 같은 말이 된다. 자르기 전
                // 날것은 confirmed_raw 로 함께 준다. 숨기지 않는다.
                'project_load'   => $this->share($byProject[$mid], $rawConfirmed, $c),
                'rnd_load'       => $this->share($byRnd[$mid], $rawConfirmed, $c),
                'confirmed_raw'  => round($rawConfirmed, 4),
                'capped'         => $rawConfirmed > $base + 0.0001,
                // %는 정수라 따로 반올림하면 합이 1 어긋난다.
                // 하나를 반올림하고 나머지는 빼서 **합을 반드시 맞춘다.**
                'project_pct'    => $projectPct,
                'rnd_pct'        => (int)round($c * 100) - $projectPct,
                // 기준 근무량. 반일 근무자는 100 이 아니라 50 이다.
                // 이것을 안 주면 화면에서 "가용 50% + 확정 0% + 추정 0%" 가
                // 되어 나머지 50% 가 무엇인지 알 수 없다 — 점유가 아니라
                // 애초에 그만큼만 일하는 사람이다.
                'capacity_pct'   => (int)round($base * 100),
                'active_items'   => $inf[$mid]['count'] ?? 0,
                'confidence'     => $conf,
                'breakdown'      => $breakdown[$mid],
                'inferred_items' => $inf[$mid]['items'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * 진행 중인 슬랙 건에서 점유를 어림한다.
     *
     * '진행 중' 판정은 BS_WORKLOAD_ACTIVE_STATUS 를 따른다. `확인요청(...)` 은
     * 개발자 손을 떠나 고객 회신을 기다리는 상태라 빼야 한다 — 슬랙 취합
     * 시스템 자신도 그렇게 본다(slack/lists.php overdueDays).
     *
     * 기간을 보지 않는다. 지금 열려 있는 건은 앞으로의 가용량을 먹는다고
     * 본다. 언제 끝날지는 알 수 없다(완료 예정일 eta 는 고객 안내용이라
     * 비어 있는 경우가 많다).
     *
     * @return array<int, array{load:float,count:int,items:array}>
     */
    public function inferFromWorkItems(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if (!$memberIds) {
            return [];
        }
        $mph = implode(',', array_fill(0, count($memberIds), '?'));
        $sph = implode(',', array_fill(0, count(BS_WORKLOAD_ACTIVE_STATUS), '?'));

        $st = $this->pdo->prepare(
            "SELECT id, member_id, title, status_raw, difficulty, org_name,
                    source_url, requested_at
               FROM bs_work_item
              WHERE member_id IN ($mph)
                AND closed_at IS NULL
                AND status_raw IN ($sph)
              ORDER BY difficulty DESC, requested_at ASC"
        );
        $st->execute(array_merge($memberIds, BS_WORKLOAD_ACTIVE_STATUS));

        $out = array_fill_keys($memberIds, ['load' => 0.0, 'count' => 0, 'items' => []]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid  = (int)$r['member_id'];
            $diff = (int)($r['difficulty'] ?: 3);
            // 난이도 3 을 기준으로 보정한다. 어려운 건이 더 많이 먹는다.
            $load = BS_INFERRED_LOAD_PER_ITEM * ($diff / 3.0);

            $out[$mid]['load']  += $load;
            $out[$mid]['count'] += 1;
            $out[$mid]['items'][] = [
                'source'     => 'inferred',
                'id'         => (int)$r['id'],
                'title'      => $r['title'],
                'status_raw' => $r['status_raw'],
                'difficulty' => $diff,
                'org_name'   => $r['org_name'],
                'source_url' => $r['source_url'],
                'load'       => round($load, 4),
            ];
        }
        return $out;
    }

    // =================================================================
    // 점유 기록 관리 (bs_workload)
    // =================================================================

    /** 기간이 겹치는 점유 기록. 화면이 내역을 보여줄 때 쓴다. */
    public function workloadOf(int $memberId, string $from, string $to): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_workload
              WHERE member_id = ? AND start_date <= ? AND end_date >= ?
              ORDER BY start_date'
        );
        $st->execute([$memberId, $to, $from]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 확정 배정으로부터 assigned 점유를 다시 만든다.
     * 기존 assigned 기록을 지우고 새로 넣는다(inferred/manual 은 건드리지 않는다).
     */
    public function syncAssignedFrom(int $allocationId): int
    {
        // TODO(P5): bs_allocation_item + bs_task.plan_start/plan_end 로 점유를 만든다.
        //           확정(AllocationRepo::confirm)에서 부른다.
        return 0;
    }

    /**
     * 과배정 여부. 확정 직전에 확인한다.
     *
     * **확정 점유만** 본다. 추정으로 사람을 막으면 안 된다.
     */
    public function overCommitted(array $memberIds, string $from, string $to): array
    {
        $out = [];
        foreach ($this->forMembers($memberIds, $from, $to) as $mid => $a) {
            if ($a['confirmed_load'] > $a['base_capacity']) {
                $out[$mid] = [
                    'member_id' => $mid,
                    'over'      => round($a['confirmed_load'] - $a['base_capacity'], 4),
                ];
            }
        }
        return $out;
    }

    private function emptyResult(int $mid, string $from, string $to): array
    {
        return [
            'member_id' => $mid, 'base_capacity' => 1.0,
            'workdays' => $this->workdays($from, $to),
            'confirmed_load' => 0.0, 'inferred_load' => 0.0, 'available' => 1.0,
            'confirmed_pct' => 0, 'inferred_pct' => 0, 'available_pct' => 100,
            // P10-2 분해. 점유가 없으니 전부 0 이다.
            'project_load' => 0.0, 'rnd_load' => 0.0,
            'confirmed_raw' => 0.0, 'capped' => false,
            'project_pct' => 0, 'rnd_pct' => 0,
            'active_items' => 0, 'confidence' => 1.0,
            'breakdown' => [], 'inferred_items' => [],
        ];
    }
}
