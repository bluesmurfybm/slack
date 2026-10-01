<?php
/**
 * R&D 자기 신고 점유의 통제 (명세서 §9, P10-1).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 통제가 필요한가                                                │
 * │                                                                  │
 * │ R&D 참여는 가용도를 낮춘다. 의도는 "자기 과제로 바쁜 사람을        │
 * │ 빼주자" 지만, 통제 없이 운영하면 **"하기 싫은 배정을 피하는        │
 * │ 통로"** 가 된다. 과제 세 개에 0.3 씩 걸어 두면 배정 후보에서       │
 * │ 사실상 사라지는데, 아무도 그것을 막지 않는다.                      │
 * │                                                                  │
 * │ 그래서 상한을 두고, **승인 시점에** 본다. 신청은 되고 승인이       │
 * │ 막히는 것이 명세서 §8.4 의 설계다 — 신청에서 막으면 왜 안 되는지   │
 * │ 말해 줄 자리가 없다.                                              │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * **상한값은 전부 bs_setting 에서 읽는다.** 코드에 박지 않는다(§9.2).
 * 박아 두면 조정이 배포가 되고, 배포가 필요하면 아무도 안 고치고 그냥
 * 승인을 우회한다.
 *
 * 이 단계(P10-1)는 **통제만** 한다. bs_workload 적재는 P10-2 다.
 * CLAUDE.md 가 그 순서를 못 박는다 — 뒤집으면 그 사이에 운영 데이터가
 * 오염된다.
 */

declare(strict_types=1);

final class RndLoadService
{
    /** 위반 코드 — 화면이 이 값으로 갈라 다룬다. */
    public const CAP_TOTAL      = 'CAP_TOTAL';
    public const CAP_PROJECT    = 'CAP_PROJECT';
    public const CAP_CONCURRENT = 'CAP_CONCURRENT';

    public function __construct(private PDO $pdo) {}

    /** 지금 걸려 있는 상한값. 화면이 "얼마까지 되는지" 를 보여 줄 때 쓴다. */
    public function limits(): array
    {
        return [
            'total_cap'       => bs_setting_ratio('rnd_total_cap'),
            'per_project_cap' => bs_setting_ratio('rnd_per_project_cap'),
            'concurrent_max'  => bs_setting_int('rnd_concurrent_max'),
            'stale_weeks'     => bs_setting_int('rnd_stale_weeks'),
        ];
    }

    /**
     * 이 사람이 지금 들고 있는 R&D 점유.
     *
     * **승인된 것만 센다.** 신청 중인 것까지 세면, 신청만 여러 개 넣어
     * 두고 "상한이 차서 배정이 안 된다" 고 말할 수 있게 된다.
     *
     * $exceptProjectId 를 주면 그 과제는 뺀다 — 같은 과제의 점유율을
     * 고치는 경우, 기존 값과 새 값을 겹쳐 세면 안 된다.
     */
    public function currentLoad(int $memberId, ?int $exceptProjectId = null): array
    {
        $sql = "SELECT rm.project_id, rm.load_ratio, p.name, p.code, p.status, p.visibility
                  FROM bs_rnd_member rm
                  JOIN bs_project p ON p.id = rm.project_id
                 WHERE rm.member_id = ?
                   AND rm.status = 'approved'
                   AND p.project_type = 'rnd'
                   AND p.deleted_at IS NULL
                   AND p.status IN ('approved', 'running')";
        $p = [$memberId];
        if ($exceptProjectId !== null) {
            $sql .= ' AND rm.project_id <> ?';
            $p[]  = $exceptProjectId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float)$r['load_ratio'];
        }

        return [
            'total'      => round($sum, 3),
            'count'      => count($rows),
            'projects'   => array_map(static fn(array $r) => [
                'project_id' => (int)$r['project_id'],
                'code'       => $r['code'],
                'name'       => $r['name'],
                'load_ratio' => (float)$r['load_ratio'],
                'visibility' => $r['visibility'],
            ], $rows),
        ];
    }

    /**
     * 내보내기 전에 **못 볼 과제의 이름을 가린다**.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 상한 계산과 화면 표시는 보는 범위가 다르다                     │
     * │                                                              │
     * │ currentLoad() 는 **가시성을 걸지 않는다.** 걸면 안 된다 —      │
     * │ 안 보이는 과제의 점유도 상한에 들어가야 하기 때문이다. 비공개  │
     * │ 과제로 0.3 을 채운 사람이 상한에 안 걸리면 통제가 무의미하다.  │
     * │                                                              │
     * │ 그래서 숫자는 전부 세되, **내보낼 때 이름만 가린다.** 점유율과 │
     * │ 건수는 그대로라 합계가 맞고, 비공개 과제가 무엇인지는 모른다.  │
     * └──────────────────────────────────────────────────────────────┘
     */
    public function maskInvisible(array $projects): array
    {
        $isAdmin = bs_is_admin();
        $me      = (string)(bs_current_user()['id'] ?? '');

        if ($isAdmin) {
            return array_map(static function (array $p) {
                unset($p['visibility']);
                return $p;
            }, $projects);
        }

        // 비공개 과제 중 내가 발의한 것만 이름을 보여 준다.
        $mineIds = [];
        $priv = array_values(array_filter($projects,
            static fn(array $p) => ($p['visibility'] ?? '') === 'private'));
        if ($priv && $me !== '') {
            $ph = implode(',', array_fill(0, count($priv), '?'));
            $st = $this->pdo->prepare(
                "SELECT id FROM bs_project
                  WHERE id IN ($ph) AND proposer_id = ?"
            );
            $st->execute(array_merge(array_column($priv, 'project_id'), [$me]));
            $mineIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }

        return array_map(static function (array $p) use ($mineIds) {
            $vis = $p['visibility'] ?? '';
            unset($p['visibility']);
            if ($vis !== 'private' || in_array($p['project_id'], $mineIds, true)) {
                return $p;
            }
            // 이름도 코드도 주지 않는다. 몇 번에 무엇이 있는지조차 흘리지 않는다.
            $p['project_id'] = null;
            $p['code']       = null;
            $p['name']       = '비공개 과제';
            $p['masked']     = true;
            return $p;
        }, $projects);
    }

    /**
     * 상한을 넘는가.
     *
     * 세 가지를 **모두** 본다. 하나 걸렸다고 멈추지 않는다 — 사람이 한 번에
     * 무엇을 고쳐야 하는지 알아야 한다. 총량만 알려 주고 승인했더니 이번엔
     * 동시 건수에 걸리는 식이면 두 번 헛걸음한다.
     *
     * @return array{ok:bool, violations:array, current:array, limit:array}
     */
    public function checkCap(int $memberId, int $projectId, float $loadRatio): array
    {
        $lim     = $this->limits();
        $cur     = $this->currentLoad($memberId, $projectId);
        $willBe  = round($cur['total'] + $loadRatio, 3);
        $willCnt = $cur['count'] + 1;

        $v = [];

        // ① 과제 하나당 상한 — 이 과제 하나만 봐도 넘는가
        if ($loadRatio > $lim['per_project_cap']) {
            $v[] = [
                'code'    => self::CAP_PROJECT,
                'message' => sprintf(
                    '한 과제에 걸 수 있는 점유율은 %s 까지입니다. 신청값은 %s 입니다.',
                    $this->pct($lim['per_project_cap']), $this->pct($loadRatio)
                ),
                'current' => $loadRatio,
                'limit'   => $lim['per_project_cap'],
            ];
        }

        // ② 1인 합계 상한
        if ($willBe > $lim['total_cap']) {
            $v[] = [
                'code'    => self::CAP_TOTAL,
                'message' => sprintf(
                    'R&D 점유 합계가 %s 를 넘습니다. 지금 %s 에 이번 %s 를 더하면 %s 입니다.',
                    $this->pct($lim['total_cap']), $this->pct($cur['total']),
                    $this->pct($loadRatio), $this->pct($willBe)
                ),
                'current' => $willBe,
                'limit'   => $lim['total_cap'],
            ];
        }

        // ③ 동시 참여 과제 수
        if ($willCnt > $lim['concurrent_max']) {
            $v[] = [
                'code'    => self::CAP_CONCURRENT,
                'message' => sprintf(
                    '동시에 참여할 수 있는 과제는 %d건까지입니다. 이미 %d건에 참여 중입니다.',
                    $lim['concurrent_max'], $cur['count']
                ),
                'current' => $willCnt,
                'limit'   => $lim['concurrent_max'],
            ];
        }

        return [
            'ok'         => $v === [],
            'violations' => $v,
            'current'    => [
                'total'           => $cur['total'],
                'count'           => $cur['count'],
                'projects'        => $cur['projects'],
                'requested_ratio' => $loadRatio,
                'would_be_total'  => $willBe,
                'would_be_count'  => $willCnt,
            ],
            'limit'      => $lim,
        ];
    }

    /**
     * 정체 — 마지막 진행 기록이 설정된 기간 넘게 없는가 (명세서 §9, CLAUDE.md 2항).
     *
     * 기록이 하나도 없으면 승인 시각을 기준으로 본다. 아직 승인 전이거나
     * 이미 끝난 과제는 정체가 아니다 — 돌아가야 할 것이 안 도는 상태만
     * 정체다.
     */
    public function isStale(array $rnd): bool
    {
        if (!in_array((string)($rnd['status'] ?? ''), ['approved', 'running'], true)) {
            return false;
        }
        $last = $rnd['last_log_at'] ?? $rnd['approved_at'] ?? null;
        if ($last === null) {
            return false;
        }
        $weeks = bs_setting_int('rnd_stale_weeks');
        return strtotime((string)$last) < strtotime('-' . ($weeks * 7) . ' day');
    }

    /** 이 사람이 들고 있는 정체 과제. 승인할 때 함께 보여 준다. */
    public function staleProjectsOf(int $memberId): array
    {
        $weeks = bs_setting_int('rnd_stale_weeks');

        $st = $this->pdo->prepare(
            "SELECT p.id, p.code, p.name, p.visibility, p.approved_at,
                    (SELECT MAX(l.created_at) FROM bs_rnd_log l WHERE l.project_id = p.id) AS last_log_at
               FROM bs_rnd_member rm
               JOIN bs_project p ON p.id = rm.project_id
              WHERE rm.member_id = ?
                AND rm.status = 'approved'
                AND p.project_type = 'rnd'
                AND p.deleted_at IS NULL
                AND p.status IN ('approved', 'running')
              HAVING COALESCE(last_log_at, p.approved_at) < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $st->execute([$memberId, $weeks * 7]);

        return $this->maskInvisible(array_map(static fn(array $r) => [
            'project_id'  => (int)$r['id'],
            'code'        => $r['code'],
            'name'        => $r['name'],
            'visibility'  => $r['visibility'],
            'last_log_at' => bs_date($r['last_log_at'] ?? $r['approved_at']),
        ], $st->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * 발의 제한 — 직전 2건이 연속으로 중단(dropped)됐는가 (명세서 §9).
     *
     * **막지 않는다. 알린다.** 중단 자체가 잘못이 아니다 — 해 보고 아니면
     * 접는 것이 R&D 다. 다만 연속으로 접힌 뒤 또 올리면, 승인하는 사람이
     * 그 사실을 알고 판단해야 한다.
     *
     * 발의자에게는 경고로, 승인하는 사람에게는 과제에 붙은 표시로 간다.
     *
     * @return array|null 경고가 있으면 내용, 없으면 null
     */
    public function proposalWarning(string $userId, ?int $exceptProjectId = null): ?array
    {
        if ($userId === '') {
            return null;
        }

        // 끝난 과제만 본다. 진행 중인 것은 아직 결과를 모른다.
        // 지금 보고 있는 과제는 뺀다 — 자기 자신을 근거로 자기를 경고하면
        // 중단된 과제를 열 때마다 "연속 중단" 이 뜬다.
        $sql = "SELECT code, name, status
                  FROM bs_project
                 WHERE project_type = 'rnd' AND proposer_id = ?
                   AND deleted_at IS NULL
                   AND status IN ('done', 'dropped')";
        $p = [$userId];
        if ($exceptProjectId !== null) {
            $sql .= ' AND id <> ?';
            $p[]  = $exceptProjectId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 2';

        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) < 2) {
            return null;
        }
        foreach ($rows as $r) {
            if ((string)$r['status'] !== 'dropped') {
                return null;
            }
        }

        return [
            'code'    => 'RND_RECENT_DROPS',
            'message' => '최근 발의한 과제 2건이 연속으로 중단됐습니다. '
                       . '이번 과제는 승인하는 사람이 그 사실을 함께 보게 됩니다.',
            'dropped' => array_map(static fn(array $r) => [
                'code' => $r['code'], 'name' => $r['name'],
            ], $rows),
        ];
    }

    // =================================================================
    // 적재 (P10-2)
    // =================================================================

    /**
     * 승인된 참여를 bs_workload 에 올린다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 과제가 승인되지 않았으면 **한 줄도 올리지 않는다** (§9.1)     │
     * │                                                              │
     * │ 참여만 승인하고 과제는 아직 proposed 인 상태가 있을 수 있다.  │
     * │ 그때 점유를 올리면 "승인 없이는 반영되지 않는다" 가 뚫린다.   │
     * │ 그래서 여기서 과제의 approved_at 을 **다시** 본다 — 부르는    │
     * │ 쪽을 믿지 않는다.                                            │
     * └──────────────────────────────────────────────────────────────┘
     *
     * 규약 — kind='assigned', ref_type='rnd', ref_id=**과제 번호**.
     * 한 사람이 한 과제에 갖는 점유 행은 하나다(있으면 갱신).
     *
     * @return int 올리거나 고친 행 수
     */
    public function syncWorkload(int $projectId, ?int $memberId = null): int
    {
        $st = $this->pdo->prepare(
            "SELECT id, name, code, status, approved_at, dev_start, dev_end
               FROM bs_project
              WHERE id = ? AND project_type = 'rnd' AND deleted_at IS NULL"
        );
        $st->execute([$projectId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        if (!$p || $p['approved_at'] === null
            || !in_array((string)$p['status'], ['approved', 'running'], true)) {
            return 0;   // 승인 전이거나 이미 끝난 과제 — 올리지 않는다
        }

        [$from, $to] = $this->window($p);
        $conf = $this->isStale([
            'status'      => $p['status'],
            'approved_at' => $p['approved_at'],
            'last_log_at' => $this->lastLogAt($projectId),
        ]) ? 0.500 : 1.000;

        $sql = "SELECT rm.member_id, rm.load_ratio
                  FROM bs_rnd_member rm
                 WHERE rm.project_id = ? AND rm.status = 'approved'";
        $args = [$projectId];
        if ($memberId !== null) {
            $sql .= ' AND rm.member_id = ?';
            $args[] = $memberId;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $label = ($p['code'] ? $p['code'] . ' ' : '') . $p['name'];
        $n = 0;

        foreach ($rows as $r) {
            // 같은 (구성원, 과제) 점유가 이미 있으면 갈아 끼운다.
            // 유니크 키가 없는 표라 직접 찾아 고친다.
            $ex = $this->pdo->prepare(
                "SELECT id FROM bs_workload
                  WHERE member_id = ? AND ref_type = 'rnd' AND ref_id = ? AND kind = 'assigned'"
            );
            $ex->execute([(int)$r['member_id'], $projectId]);
            $id = $ex->fetchColumn();

            if ($id !== false) {
                $this->pdo->prepare(
                    "UPDATE bs_workload
                        SET label = ?, start_date = ?, end_date = ?,
                            load_ratio = ?, confidence = ?
                      WHERE id = ?"
                )->execute([$label, $from, $to, (float)$r['load_ratio'], $conf, (int)$id]);
            } else {
                $this->pdo->prepare(
                    "INSERT INTO bs_workload
                        (member_id, kind, ref_type, ref_id, label,
                         start_date, end_date, load_ratio, confidence)
                     VALUES (?, 'assigned', 'rnd', ?, ?, ?, ?, ?, ?)"
                )->execute([
                    (int)$r['member_id'], $projectId, $label,
                    $from, $to, (float)$r['load_ratio'], $conf,
                ]);
            }
            $n++;
        }

        // 정체는 시간이 지나면서 생긴다. 적재 시점에 한 번 정하고 끝내면
        // 그 뒤 조용해진 과제가 1.0 인 채로 남는다. 쓰는 김에 함께 맞춘다.
        //
        // **이것만으로는 모자란다.** 아무도 승인·합류를 하지 않는 동안에는
        // 돌지 않으므로, 주기적으로 부르는 자리가 따로 있어야 한다.
        // TODO(P12): 배치에서 하루 한 번 refreshConfidence() 를 돌린다.
        $this->refreshConfidence();

        return $n;
    }

    /**
     * 정체 과제의 점유 신뢰도를 0.5 로 내린다 (CLAUDE.md 2항).
     *
     * 적재할 때 한 번 정하고 끝내면, 적재 뒤에 조용해진 과제는 1.0 인 채로
     * 남는다. 보드를 그릴 때 함께 돌려 맞춘다.
     *
     * **점유량(load_ratio)은 건드리지 않는다.** 신고한 양은 그대로이고,
     * 그 숫자를 얼마나 믿을지만 내린다.
     */
    public function refreshConfidence(): int
    {
        $weeks = bs_setting_int('rnd_stale_weeks');

        $st = $this->pdo->prepare(
            "UPDATE bs_workload w
               JOIN bs_project p ON p.id = w.ref_id AND p.project_type = 'rnd'
                SET w.confidence = CASE
                      WHEN COALESCE(
                             (SELECT MAX(l.created_at) FROM bs_rnd_log l WHERE l.project_id = p.id),
                             p.approved_at
                           ) < DATE_SUB(NOW(), INTERVAL ? DAY)
                      THEN 0.500 ELSE 1.000 END
              WHERE w.ref_type = 'rnd' AND w.kind = 'assigned'"
        );
        $st->execute([$weeks * 7]);
        return $st->rowCount();
    }

    /** 이 과제가 점유하는 기간. 프로젝트 기간이 없으면 승인일부터 1년. */
    private function window(array $p): array
    {
        $from = $p['dev_start'] ?: date('Y-m-d', strtotime((string)$p['approved_at']));
        $to   = $p['dev_end'] ?: date('Y-m-d', strtotime($from . ' +1 year'));
        if ($from > $to) {
            $to = $from;
        }
        return [$from, $to];
    }

    private function lastLogAt(int $projectId): ?string
    {
        $st = $this->pdo->prepare('SELECT MAX(created_at) FROM bs_rnd_log WHERE project_id = ?');
        $st->execute([$projectId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    /** 0.25 → '25%'. 사람이 읽는 자리에만 쓴다. */
    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v * 100, 1, '.', ''), '0'), '.') . '%';
    }
}
