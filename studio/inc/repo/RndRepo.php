<?php
/**
 * R&D 과제 저장소 (명세서 §3.4 · §8).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 가시성을 여기서 강제한다 (명세서 §8.6)                            │
 * │                                                                  │
 * │ 모든 읽기가 bs_rnd_visible_sql() 을 붙인다. 못 볼 과제는 **쿼리가 │
 * │ 애초에 집어 오지 않는다.** 화면에서 거르면 API 를 직접 불러 뚫린다│
 * │ — 후보 목록에서 실제로 겪은 일이다(api_test [M] 주석).           │
 * │                                                                  │
 * │ 쓰기는 한 번 더 본다. 읽기로 집은 뒤 고치는 경로라도, 그 사이에   │
 * │ 공개 범위가 바뀌었을 수 있다.                                     │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * project_type='rnd' 조건도 같은 함수가 함께 붙인다. 이 Repo 로는
 * 지시형 프로젝트를 집을 수 없다.
 */

declare(strict_types=1);

require_once __DIR__ . '/../presenter.php';
require_once __DIR__ . '/../service/RndLoadService.php';

/**
 * 산출물 없이 종료하려 할 때 (명세서 §8.5 — `RND_NO_OUTPUT`).
 *
 * DomainException 을 갈라 두는 이유는 **화면이 이 경우만 다르게 다뤄야** 하기
 * 때문이다. 다른 오류는 그냥 알리면 되지만, 이것은 "산출물을 먼저 등록하라"
 * 는 다음 행동으로 이어 줘야 한다. api/rnd.php 가 전용 코드로 바꿔 내보낸다.
 */
final class RndNoOutputException extends DomainException {}

/**
 * 점유 상한을 넘겨 합류를 승인할 수 없을 때 (명세서 §9.2).
 *
 * **위반 내역을 들고 다닌다.** "안 됩니다" 만 돌려주면 승인하는 사람이
 * 무엇을 어떻게 고쳐야 하는지 모른다. 지금 점유가 얼마이고 상한이
 * 얼마인지, 어느 과제들이 그 점유를 쓰고 있는지까지 화면으로 넘긴다.
 */
final class RndCapExceededException extends DomainException
{
    public function __construct(public readonly array $check, string $who = '')
    {
        $msgs = array_column($check['violations'] ?? [], 'message');
        parent::__construct(
            ($who !== '' ? $who . ' 님의 ' : '')
            . "점유 상한에 걸려 승인할 수 없습니다.\n · " . implode("\n · ", $msgs)
        );
    }
}

final class RndRepo
{
    private const COLS = 'p.id, p.code, p.name, p.summary, p.notes, p.extra,
        p.status, p.visibility, p.rnd_category,
        p.dev_start, p.dev_end,
        p.owner_id, p.owner_name,
        p.proposer_id, p.proposer_name,
        p.approved_by, p.approved_by_name, p.approved_at,
        p.load_cap, p.recruiting,
        p.created_at, p.updated_at';

    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 조회
    // =================================================================

    /**
     * 한 건. **볼 수 없으면 null** — 없는 것과 같게 다룬다.
     *
     * "권한이 없습니다" 와 "없습니다" 를 가르면, 비공개 과제가 **존재한다는
     * 사실**이 샌다. 제목은 못 봐도 몇 번에 무엇이 있는지는 알게 된다.
     */
    public function find(int $id): ?array
    {
        [$vis, $visParams] = bs_rnd_visible_sql('p');

        // 내 참여 상태를 함께 집어 온다. 화면이 "신청" 단추를 그릴지,
        // "기록 남기기" 를 열지 여기서 갈린다 — 따로 묻지 않게 한다.
        $me = (string)(bs_current_user()['id'] ?? '');

        $st = $this->pdo->prepare(
            'SELECT ' . self::COLS . ',
                    (SELECT COUNT(*) FROM bs_rnd_member m
                      WHERE m.project_id = p.id AND m.status = \'approved\') AS member_count,
                    (SELECT COUNT(*) FROM bs_rnd_output o WHERE o.project_id = p.id) AS output_count,
                    (SELECT COUNT(*) FROM bs_rnd_log    l WHERE l.project_id = p.id) AS log_count,
                    (SELECT COUNT(*) FROM bs_rnd_interest i WHERE i.project_id = p.id) AS interest_count,
                    (SELECT MAX(l2.created_at) FROM bs_rnd_log l2 WHERE l2.project_id = p.id) AS last_log_at,
                    (SELECT rm.status FROM bs_rnd_member rm
                       JOIN bs_member mm ON mm.id = rm.member_id
                      WHERE rm.project_id = p.id AND mm.user_id = ?) AS my_status,
                    (SELECT rm.role FROM bs_rnd_member rm
                       JOIN bs_member mm ON mm.id = rm.member_id
                      WHERE rm.project_id = p.id AND mm.user_id = ?) AS my_role,
                    (SELECT COUNT(*) FROM bs_rnd_interest ri
                       JOIN bs_member mi ON mi.id = ri.member_id
                      WHERE ri.project_id = p.id AND mi.user_id = ?) AS my_interest
               FROM bs_project p
              WHERE p.id = ? AND p.deleted_at IS NULL AND ' . $vis
        );
        $st->execute(array_merge([$me, $me, $me, $id], $visParams));
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    }

    /**
     * 보드 목록 (명세서 §8.1).
     *
     * @param array $f
     *   category    rnd_category 하나
     *   status      상태 하나 또는 배열
     *   recruiting  '1' 이면 모집 중만
     *   mine        '1' 이면 내가 발의했거나 참여 중인 것만
     *   keyword     코드·과제명·개요
     *   sort        recent(기본) | active | recruiting
     *   page, size
     */
    public function board(array $f): array
    {
        [$where, $params] = $this->buildWhere($f);

        $page = max(1, (int)($f['page'] ?? 1));
        $size = max(1, min(100, (int)($f['size'] ?? 24)));

        $total = (int)$this->scalar(
            'SELECT COUNT(*) FROM bs_project p WHERE ' . $where,
            $params
        );
        $pages  = $total > 0 ? (int)ceil($total / $size) : 1;
        $page   = min($page, $pages);
        $offset = ($page - 1) * $size;

        // ORDER BY 는 바인딩할 수 없으므로 화이트리스트로만 고른다.
        $order = match ((string)($f['sort'] ?? 'recent')) {
            // 활동 많은 순 — 진행 기록이 많은 과제가 위로.
            'active'     => 'log_count DESC, p.id DESC',
            // 모집중 우선 — 사람을 찾고 있는 과제를 먼저 보여 준다.
            'recruiting' => 'p.recruiting DESC, p.id DESC',
            default      => 'p.id DESC',
        };

        $sql = 'SELECT ' . self::COLS . ',
                       (SELECT COUNT(*) FROM bs_rnd_member m
                         WHERE m.project_id = p.id AND m.status = \'approved\') AS member_count,
                       (SELECT COUNT(*) FROM bs_rnd_output o WHERE o.project_id = p.id) AS output_count,
                       (SELECT COUNT(*) FROM bs_rnd_log    l WHERE l.project_id = p.id) AS log_count,
                       (SELECT COUNT(*) FROM bs_rnd_interest i WHERE i.project_id = p.id) AS interest_count,
                       (SELECT MAX(l2.created_at) FROM bs_rnd_log l2 WHERE l2.project_id = p.id) AS last_log_at
                  FROM bs_project p
                 WHERE ' . $where . '
                 ORDER BY ' . $order . '
                 LIMIT ? OFFSET ?';

        $st = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $st->bindValue($i + 1, $v);
        }
        $st->bindValue(count($params) + 1, $size, PDO::PARAM_INT);
        $st->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $st->execute();

        return [
            'rows'  => $st->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'pages' => $pages,
        ];
    }

    /** 상태별 건수. 보드 탭에 쓴다. 가시성은 목록과 같게 걸린다. */
    public function statusCounts(array $f): array
    {
        $f2 = $f;
        unset($f2['status']);                 // 상태 탭은 자기 자신으로 거르지 않는다
        [$where, $params] = $this->buildWhere($f2);

        $st = $this->pdo->prepare(
            'SELECT p.status, COUNT(*) AS cnt FROM bs_project p WHERE ' . $where . ' GROUP BY p.status'
        );
        $st->execute($params);

        $out = ['' => 0];
        foreach (array_keys(BS_RND_STATUS) as $k) {
            $out[$k] = 0;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['status']] = (int)$r['cnt'];
            $out['']                  += (int)$r['cnt'];
        }
        return $out;
    }

    /** 보드 머리의 숫자 (명세서 §8.1). 가시성 안에서만 센다. */
    public function stats(): array
    {
        [$vis, $visParams] = bs_rnd_visible_sql('p');
        $base = 'FROM bs_project p WHERE p.deleted_at IS NULL AND ' . $vis;

        $running = (int)$this->scalar(
            "SELECT COUNT(*) $base AND p.status IN ('approved','running')", $visParams
        );
        $doneQ = (int)$this->scalar(
            "SELECT COUNT(*) $base AND p.status = 'done'
               AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)", $visParams
        );
        $outputs = (int)$this->scalar(
            "SELECT COUNT(*) FROM bs_rnd_output o
              WHERE o.project_id IN (SELECT p.id $base)", $visParams
        );

        return [
            'running_count' => $running,
            'done_quarter'  => $doneQ,
            'output_total'  => $outputs,
        ];
    }

    // =================================================================
    // 변경
    // =================================================================

    /**
     * 발의 (명세서 §8.2).
     *
     * 발의자는 **부른 사람 자신**이다. 남의 이름으로 발의할 수 없다.
     * 상태는 draft 또는 proposed 로만 들어온다 — approved 로 바로 만들 수 없다.
     */
    public function propose(array $data, array $actor): int
    {
        $this->validate($data, true);

        $status = ($data['status'] ?? 'proposed') === 'draft' ? 'draft' : 'proposed';
        $year   = (int)date('Y', strtotime((string)($data['dev_start'] ?? date('Y-m-d'))) ?: time());

        for ($try = 0; $try < 5; $try++) {
            try {
                $st = $this->pdo->prepare(
                    "INSERT INTO bs_project
                        (code, project_type, visibility, name, summary, notes,
                         rnd_category, dev_start, dev_end, status,
                         owner_id, owner_name, proposer_id, proposer_name,
                         load_cap, recruiting)
                     VALUES (?, 'rnd', ?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?)"
                );
                $st->execute([
                    $this->nextCode($year),
                    $data['visibility'] ?? 'private',
                    $data['name'],
                    $this->nn($data['summary'] ?? null),
                    $this->nn($data['notes'] ?? null),
                    $this->nn($data['rnd_category'] ?? null),
                    $this->nn($data['dev_start'] ?? null),
                    $this->nn($data['dev_end'] ?? null),
                    $status,
                    // owner 는 발의자와 같다. 나중에 lead 가 바뀌면 그때 옮긴다.
                    $actor['id'], $actor['name'],
                    $actor['id'], $actor['name'],
                    $this->nn($data['load_cap'] ?? null),
                    !empty($data['recruiting']) ? 1 : 0,
                ]);
                $newId = (int)$this->pdo->lastInsertId();
                // 분야 태그는 역량 반영의 계열 근거다 (명세서 4.7).
                $this->saveDomains($newId, (array)($data['domain_ids'] ?? []));
                return $newId;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // 채번이 겹쳤다. 다시 뽑는다.
            }
        }
        throw new RuntimeException('과제 코드를 채번하지 못했습니다. 다시 시도해 주세요.');
    }

    /**
     * 수정. 넘어온 키만 바꾼다.
     *
     * status 는 여기서 바꾸지 않는다 — approve()/reject() 가 전용 경로다.
     * 그 둘을 거치지 않고 approved 로 올릴 수 있으면 승인 통제(§9.1)가 뚫린다.
     */
    public function update(int $id, array $data): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('과제를 찾을 수 없습니다.');
        }
        if (!bs_rnd_can_edit($cur)) {
            throw new DomainException('이 과제를 수정할 권한이 없습니다.');
        }
        $this->validate($data, false);

        $map = [
            'name'         => 'name',
            'summary'      => 'summary',
            'notes'        => 'notes',
            'rnd_category' => 'rnd_category',
            'visibility'   => 'visibility',
            'dev_start'    => 'dev_start',
            'dev_end'      => 'dev_end',
            'load_cap'     => 'load_cap',
            'recruiting'   => 'recruiting',
        ];
        $sets = [];
        $p    = [];
        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = "`$col` = ?";
            $p[]    = $col === 'recruiting'
                ? (!empty($data[$key]) ? 1 : 0)
                : $this->nn($data[$key]);
        }
        if (!$sets) {
            // 분야 태그만 바꾸는 경우가 있다. 그때는 본문 UPDATE 가 없다.
            if (array_key_exists('domain_ids', $data)) {
                $this->saveDomains($id, (array)$data['domain_ids']);
                return;
            }
            throw new InvalidArgumentException('바꿀 내용이 없습니다.');
        }

        $p[] = $id;
        $this->pdo->prepare(
            'UPDATE bs_project SET ' . implode(', ', $sets)
            . " WHERE id = ? AND project_type = 'rnd' AND deleted_at IS NULL"
        )->execute($p);

        if (array_key_exists('domain_ids', $data)) {
            $this->saveDomains($id, (array)$data['domain_ids']);
        }
    }

    /**
     * 승인 (명세서 §8.2 · §9.1).
     *
     * **approved_at 이 차야 점유를 적재할 수 있다.** 이 메서드 말고 다른
     * 경로로 approved 가 되면 안 된다. 자동 승인을 만들지 마라.
     *
     * 점유(bs_workload) 적재는 **여기서 하지 않는다.** 과제 승인은
     * "이 과제를 해도 좋다" 이고, 점유는 사람이 합류해야 생긴다(P9-3).
     */
    public function approve(int $id, array $actor): void
    {
        $cur = $this->requireForDecision($id);

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 발의자의 상한도 **여기서** 본다                               │
        // │                                                              │
        // │ 승인되면 발의자가 lead 로 앉고 그 자리에서 점유가 올라간다.   │
        // │ 이 검사가 없으면 과제를 여러 개 발의해 승인받는 것만으로      │
        // │ rnd_total_cap 과 rnd_concurrent_max 를 통째로 우회할 수 있다  │
        // │ — approve_member 만 막아 둔 것이 의미가 없어진다.             │
        // │                                                              │
        // │ 관리자라도 넘겨 줄 수 없다. 예외를 한 번 열면 그 길로만 다닌다│
        // └──────────────────────────────────────────────────────────────┘
        $svc        = new RndLoadService($this->pdo);
        $proposerId = $this->memberIdOf((string)$cur['proposer_id']);
        $leadRatio  = $cur['load_cap'] !== null ? (float)$cur['load_cap'] : 0.100;

        if ($proposerId !== null) {
            $check = $svc->checkCap($proposerId, $id, $leadRatio);
            if (!$check['ok']) {
                throw new RndCapExceededException($check, (string)($cur['proposer_name'] ?? ''));
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE bs_project
                    SET status = 'approved', approved_by = ?, approved_by_name = ?,
                        approved_at = NOW()
                  WHERE id = ? AND project_type = 'rnd' AND status = 'proposed'"
            )->execute([$actor['id'] ?? null, $actor['name'] ?? null, $id]);

            // 발의자를 주도자(lead)로 앉힌다.
            //
            // **과제가 승인될 때** 만든다. 발의 시점에 만들면, 승인되지 않은
            // 과제에 '승인된 참여자' 가 생겨 P9-4 의 적재가 §9.1 을 우회할
            // 길이 열린다. 주도자가 없으면 합류 신청을 승인할 사람도 없다.
            $mid = $proposerId;
            if ($mid !== null) {
                $this->pdo->prepare(
                    "INSERT INTO bs_rnd_member
                        (project_id, member_id, role, load_ratio, status,
                         join_reason, approved_by, approved_by_name, approved_at, joined_at)
                     VALUES (?,?, 'lead', ?, 'approved', '발의자', ?,?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        role = 'lead', status = 'approved',
                        approved_at = COALESCE(approved_at, NOW()),
                        joined_at   = COALESCE(joined_at, NOW())"
                )->execute([
                    $id, $mid, $leadRatio,
                    $actor['id'] ?? null, $actor['name'] ?? null,
                ]);
            }

            // 주도자의 점유도 이 자리에서 올린다. 과제가 지금 막 승인됐으므로
            // §9.1 의 조건이 채워졌다.
            $svc->syncWorkload($id);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 반려.
     *
     * 사유를 받는다. 반려만 하고 이유를 안 남기면 발의자가 무엇을 고쳐야
     * 하는지 모른다. 상태는 draft 로 되돌려 **고쳐서 다시 낼 수 있게** 한다.
     */
    public function reject(int $id, string $reason, array $actor): void
    {
        $cur = $this->requireForDecision($id);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('반려 사유를 입력하세요.');
        }

        $note = '[반려 ' . date('Y-m-d H:i') . ' ' . ($actor['name'] ?? '') . "]\n" . $reason;
        $this->pdo->prepare(
            "UPDATE bs_project
                SET status = 'draft',
                    notes = CONCAT_WS('\n\n', notes, ?),
                    approved_by = NULL, approved_by_name = NULL, approved_at = NULL
              WHERE id = ? AND project_type = 'rnd' AND status = 'proposed'"
        )->execute([$note, $id]);
    }

    // =================================================================
    // 분야 태그 (명세서 §8.2 · §4.7)
    // =================================================================

    /**
     * 이 과제가 다루는 분야.
     *
     * 역량 반영(P10-3)에서 **계열의 유일한 근거**다. 태그가 없으면 그 과제는
     * 종료돼도 경험 범위에 반영되지 않는다 — 어느 계열인지 알 수 없으므로.
     */
    public function domains(int $projectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT d.id, d.code, d.name, d.category
               FROM bs_rnd_domain rd
               JOIN bs_domain d ON d.id = rd.domain_id
              WHERE rd.project_id = ?
              ORDER BY d.sort_no, d.id'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 분야 태그를 통째로 갈아 끼운다. 빈 배열이면 전부 지운다. */
    private function saveDomains(int $projectId, array $domainIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $domainIds))));

        $this->pdo->prepare('DELETE FROM bs_rnd_domain WHERE project_id = ?')
            ->execute([$projectId]);

        if (!$ids) {
            return;
        }
        // 없는 분야를 걸러낸다. FK 가 막아 주지만, 한 건 때문에 전체가
        // 실패하는 것보다 조용히 빼고 나머지를 넣는 편이 낫다.
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT id FROM bs_domain WHERE id IN ($ph)");
        $st->execute($ids);
        $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        if (!$valid) {
            return;
        }
        $ins = $this->pdo->prepare(
            'INSERT INTO bs_rnd_domain (project_id, domain_id) VALUES (?,?)'
        );
        foreach ($valid as $d) {
            $ins->execute([$projectId, $d]);
        }
    }

    // =================================================================
    // 참여자 (명세서 §8.3 · §8.4)
    // =================================================================

    /** 참여자 목록. 신청 중인 사람까지 함께 돌려준다 — lead 가 승인해야 하므로. */
    public function members(int $projectId): array
    {
        $this->requireVisible($projectId);

        $st = $this->pdo->prepare(
            'SELECT rm.id, rm.member_id, rm.role, rm.load_ratio, rm.status,
                    rm.join_reason, rm.reject_reason,
                    rm.approved_by, rm.approved_by_name, rm.approved_at,
                    rm.joined_at, rm.left_at, rm.created_at,
                    m.emp_name, m.user_id, m.role_label
               FROM bs_rnd_member rm
               JOIN bs_member m ON m.id = rm.member_id
              WHERE rm.project_id = ?
              ORDER BY FIELD(rm.status, \'requested\',\'approved\',\'left\',\'done\',\'rejected\'),
                       FIELD(rm.role, \'lead\',\'member\'), rm.id'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 합류 신청 (명세서 §8.4).
     *
     * 사유와 신고 점유율을 함께 받는다. 사유 없이 붙으면 lead 가 무엇을 보고
     * 승인할지 알 수 없다.
     *
     * **상한 검증은 여기서 하지 않는다.** 신청은 되고 승인이 막히는 것이
     * 명세서 §8.4 의 설계다 — 신청 단계에서 막으면 "왜 안 되는지" 를 말해 줄
     * 자리가 없다.
     */
    public function requestJoin(int $projectId, array $data, array $actor): int
    {
        $rnd = $this->requireVisible($projectId);

        if (!bs_rnd_join_open($rnd)) {
            throw new DomainException(
                '지금은 합류 신청을 받지 않는 과제입니다. '
                . '공개 범위가 "공개(합류 가능)" 이고 모집 중이며 승인된 과제여야 합니다.'
            );
        }

        $mid = $this->memberIdOf((string)$actor['id']);
        if ($mid === null) {
            throw new DomainException('구성원 명단에 없습니다. 관리자에게 문의하세요.');
        }

        $reason = trim((string)($data['join_reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('합류 사유를 입력하세요.');
        }
        $ratio = $this->ratio($data['load_ratio'] ?? null);
        if ($ratio === null) {
            throw new InvalidArgumentException('신고 점유율을 입력하세요.');
        }

        // 이미 있는 행이면 되살린다. 한 번 나갔다가 다시 들어오는 경우가 있다.
        $cur = $this->memberRowOf($projectId, $mid);
        if ($cur && in_array((string)$cur['status'], ['requested', 'approved'], true)) {
            throw new DomainException('이미 신청했거나 참여 중입니다.');
        }

        if ($cur) {
            $this->pdo->prepare(
                "UPDATE bs_rnd_member
                    SET status = 'requested', role = 'member', load_ratio = ?,
                        join_reason = ?, reject_reason = NULL,
                        approved_by = NULL, approved_by_name = NULL, approved_at = NULL,
                        left_at = NULL
                  WHERE id = ?"
            )->execute([$ratio, $reason, (int)$cur['id']]);
            return (int)$cur['id'];
        }

        $this->pdo->prepare(
            "INSERT INTO bs_rnd_member
                (project_id, member_id, role, load_ratio, status, join_reason)
             VALUES (?,?, 'member', ?, 'requested', ?)"
        )->execute([$projectId, $mid, $ratio, $reason]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 합류 승인. lead 또는 관리자만.
     *
     * **상한을 넘으면 승인하지 않는다** (명세서 §9.2). 신청은 이미 받았고
     * 여기서 막는다 — 신청 단계에서 막으면 왜 안 되는지 말해 줄 자리가 없다.
     * 관리자라도 넘겨 줄 수 없다. 예외를 한 번 열면 그 길로만 다닌다.
     *
     * 승인되면 **그 자리에서 점유를 올린다**(P10-2). 올리는 쪽은
     * RndLoadService 가 과제의 approved_at 을 다시 보고 판단한다 —
     * 과제 승인 없이 참여만 승인되면 §9.1 이 뚫리기 때문이다.
     *
     * @throws RndCapExceededException 상한을 넘을 때. 위반 내역을 들고 있다.
     */
    public function approveMember(int $rowId, array $actor): array
    {
        $row = $this->requireMemberRow($rowId);
        $this->requireTeamAuthority((int)$row['project_id']);

        if ((string)$row['status'] !== 'requested') {
            throw new DomainException('신청 상태인 사람만 승인할 수 있습니다.');
        }

        $svc   = new RndLoadService($this->pdo);
        $check = $svc->checkCap(
            (int)$row['member_id'], (int)$row['project_id'], (float)$row['load_ratio']
        );
        if (!$check['ok']) {
            throw new RndCapExceededException($check, $row['emp_name'] ?? '');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE bs_rnd_member
                    SET status = 'approved', approved_by = ?, approved_by_name = ?,
                        approved_at = NOW(), joined_at = NOW(), reject_reason = NULL
                  WHERE id = ? AND status = 'requested'"
            )->execute([$actor['id'] ?? null, $actor['name'] ?? null, $rowId]);

            // 승인과 적재를 한 트랜잭션에 둔다. 승인만 되고 점유가 안 올라가면
            // 가용도가 조용히 어긋나고, 아무도 그것을 눈치채지 못한다.
            $svc->syncWorkload((int)$row['project_id'], (int)$row['member_id']);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->requireMemberRow($rowId);
    }

    /** 합류 반려. 사유 필수 — 왜 안 되는지 모르면 다시 신청할 수 없다. */
    public function rejectMember(int $rowId, string $reason, array $actor): array
    {
        $row = $this->requireMemberRow($rowId);
        $this->requireTeamAuthority((int)$row['project_id']);

        if ((string)$row['status'] !== 'requested') {
            throw new DomainException('신청 상태인 사람만 반려할 수 있습니다.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('반려 사유를 입력하세요.');
        }

        $this->pdo->prepare(
            "UPDATE bs_rnd_member
                SET status = 'rejected', reject_reason = ?,
                    approved_by = ?, approved_by_name = ?, approved_at = NULL
              WHERE id = ? AND status = 'requested'"
        )->execute([$reason, $actor['id'] ?? null, $actor['name'] ?? null, $rowId]);

        return $this->requireMemberRow($rowId);
    }

    /**
     * 스스로 나가기. **본인만** 한다.
     *
     * lead 는 혼자 나갈 수 없다. 주도자가 사라진 과제가 모집 중인 채로 남으면
     * 신청한 사람을 승인할 사람이 없어진다. 먼저 과제를 종료하거나 중단한다.
     */
    public function leave(int $projectId, array $actor): void
    {
        $this->requireVisible($projectId);

        $mid = $this->memberIdOf((string)$actor['id']);
        $row = $mid === null ? null : $this->memberRowOf($projectId, $mid);
        if (!$row || !in_array((string)$row['status'], ['requested', 'approved'], true)) {
            throw new DomainException('이 과제에 참여하고 있지 않습니다.');
        }
        if ((string)$row['role'] === 'lead') {
            throw new DomainException(
                '주도자는 혼자 나갈 수 없습니다. 과제를 종료하거나 중단해 주세요.'
            );
        }

        $this->pdo->prepare(
            "UPDATE bs_rnd_member SET status = 'left', left_at = NOW() WHERE id = ?"
        )->execute([(int)$row['id']]);

        // 이 사람의 점유를 오늘로 끊는다. **지우지 않는다** — 그때까지
        // 점유한 것은 사실이다. 적재가 없는 지금은 바뀌는 행이 없고,
        // P9-4 에서 적재가 시작되면 이 경로가 그대로 동작한다.
        $this->closeWorkloadFor($projectId, (int)$row['id']);
    }

    // =================================================================
    // 진행 기록 · 산출물 · 관심
    // =================================================================

    /** 진행 기록 목록. 최근 것이 위로. */
    public function logs(int $projectId, int $limit = 100): array
    {
        $this->requireVisible($projectId);

        $st = $this->pdo->prepare(
            'SELECT l.id, l.member_id, l.content, l.finding, l.worked_on, l.created_at,
                    m.emp_name
               FROM bs_rnd_log l
               JOIN bs_member m ON m.id = l.member_id
              WHERE l.project_id = ?
              ORDER BY l.id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 진행 기록 등록. **승인된 참여자만.**
     *
     * content 와 finding 을 나눠 받는다(명세서 §8.3). 과제의 값어치는 대개
     * "무엇을 했다" 가 아니라 "무엇을 알아냈다" 에 쌓이는데, 한 칸에 섞어
     * 받으면 알아낸 것이 작업 일지에 묻힌다.
     */
    public function addLog(int $projectId, array $data, array $actor): int
    {
        $this->requireVisible($projectId);
        $mid = $this->requireActiveMember($projectId, $actor);

        $content = trim((string)($data['content'] ?? ''));
        if ($content === '') {
            throw new InvalidArgumentException('진행 내용을 입력하세요.');
        }
        $workedOn = $this->nn($data['worked_on'] ?? null);
        if ($workedOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$workedOn)) {
            throw new InvalidArgumentException('작업한 날짜 형식이 올바르지 않습니다.');
        }

        $this->pdo->prepare(
            'INSERT INTO bs_rnd_log (project_id, member_id, content, finding, worked_on)
             VALUES (?,?,?,?,?)'
        )->execute([$projectId, $mid, $content, $this->nn($data['finding'] ?? null), $workedOn]);

        return (int)$this->pdo->lastInsertId();
    }

    /** 산출물 목록. */
    public function outputs(int $projectId): array
    {
        $this->requireVisible($projectId);

        $st = $this->pdo->prepare(
            'SELECT id, kind, title, url, file_path, summary,
                    created_by, created_by_name, created_at
               FROM bs_rnd_output
              WHERE project_id = ?
              ORDER BY id DESC'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 산출물 등록. **승인된 참여자만.**
     *
     * 파일 업로드는 아직 붙이지 않았다 — 링크와 설명만 받는다.
     * TODO(P9-5): SourceUploader 를 써서 파일 첨부를 받는다. bs_rnd_output.file_path
     *             칸은 이미 있다. 저장 위치는 bs_project_source 와 같은 BS_UPLOAD_DIR
     *             아래로 하되 과제용 하위 폴더를 따로 둘지 그때 정한다.
     */
    public function addOutput(int $projectId, array $data, array $actor): int
    {
        $this->requireVisible($projectId);
        $this->requireActiveMember($projectId, $actor);

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('산출물 제목을 입력하세요.');
        }
        $kind = (string)($data['kind'] ?? 'doc');
        if (!isset(BS_RND_OUTPUT_KIND[$kind])) {
            throw new InvalidArgumentException('알 수 없는 산출물 갈래입니다.');
        }
        $url = $this->nn($data['url'] ?? null);
        if ($url !== null && !preg_match('#^https?://#i', (string)$url)) {
            throw new InvalidArgumentException('http:// 또는 https:// 로 시작하는 주소만 등록할 수 있습니다.');
        }

        $this->pdo->prepare(
            'INSERT INTO bs_rnd_output
                (project_id, kind, title, url, summary, created_by, created_by_name)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $projectId, $kind, $title, $url,
            $this->nn($data['summary'] ?? null),
            $actor['id'] ?? null, $actor['name'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 관심 표시 토글. 누르면 켜지고 다시 누르면 꺼진다.
     *
     * 합류와 다르다 — **점유를 만들지 않는다.** "이런 거 하면 끼고 싶다" 를
     * 가볍게 남기는 자리다.
     */
    public function toggleInterest(int $projectId, array $actor): bool
    {
        $this->requireVisible($projectId);

        $mid = $this->memberIdOf((string)$actor['id']);
        if ($mid === null) {
            throw new DomainException('구성원 명단에 없습니다.');
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM bs_rnd_interest WHERE project_id = ? AND member_id = ?'
        );
        $st->execute([$projectId, $mid]);

        if ($st->fetchColumn()) {
            $this->pdo->prepare(
                'DELETE FROM bs_rnd_interest WHERE project_id = ? AND member_id = ?'
            )->execute([$projectId, $mid]);
            return false;
        }

        $this->pdo->prepare(
            'INSERT INTO bs_rnd_interest (project_id, member_id) VALUES (?,?)'
        )->execute([$projectId, $mid]);
        return true;
    }

    // =================================================================
    // 종료 (명세서 §8.5)
    // =================================================================

    /**
     * 종료 — **산출물이 1건 이상이어야 한다.**
     *
     * 없으면 RndNoOutputException 을 던진다. 산출물 없이 끝낸 과제를 역량에
     * 반영하면, 발의만 하고 아무것도 남기지 않아도 점수가 오른다. 그런 길을
     * 열어 두면 반드시 그쪽으로 간다(CLAUDE.md 2항).
     */
    public function finish(int $projectId, array $actor): void
    {
        $rnd = $this->requireVisible($projectId);
        $this->requireTeamAuthority($projectId);
        $this->requireLive($rnd);

        $n = (int)$this->scalar('SELECT COUNT(*) FROM bs_rnd_output WHERE project_id = ?', [$projectId]);
        if ($n === 0) {
            throw new RndNoOutputException(
                '산출물이 한 건도 없습니다. 무엇이 남았는지 먼저 등록해 주세요. '
                . '남길 것이 없다면 "중단" 으로 끝냅니다.'
            );
        }

        $this->closeProject($projectId, 'done');
    }

    /**
     * 중단 — 산출물 없이 끝낸다.
     *
     * **역량 지표에 반영하지 않는다**(CLAUDE.md 2항). 그 판정은 P9-4 의
     * 점유·역량 쪽에서 status='dropped' 를 보고 한다.
     */
    public function drop(int $projectId, string $reason, array $actor): void
    {
        $rnd = $this->requireVisible($projectId);
        $this->requireTeamAuthority($projectId);
        $this->requireLive($rnd);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('중단 사유를 입력하세요.');
        }

        $note = '[중단 ' . date('Y-m-d H:i') . ' ' . ($actor['name'] ?? '') . "]\n" . $reason;
        $this->pdo->prepare(
            "UPDATE bs_project SET notes = CONCAT_WS('\n\n', notes, ?)
              WHERE id = ? AND project_type = 'rnd'"
        )->execute([$note, $projectId]);

        $this->closeProject($projectId, 'dropped');
    }

    // =================================================================
    // 안쪽
    // =================================================================

    /**
     * 과제를 닫고 점유의 끝을 오늘로 당긴다.
     *
     * **bs_workload 행을 지우지 않는다**(명세서 §8.5). 끝났다고 지우면
     * "그때 이 사람이 이만큼 점유하고 있었다" 는 사실이 사라져, 지난 기간의
     * 가용도를 다시 계산할 수 없게 된다.
     *
     * 지금은 적재가 없어 아무 행도 바뀌지 않는다. 적재는 P9-4 에서 시작한다.
     */
    private function closeProject(int $projectId, string $status): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE bs_project SET status = ?, recruiting = 0
                  WHERE id = ? AND project_type = 'rnd'"
            )->execute([$status, $projectId]);

            $this->pdo->prepare(
                "UPDATE bs_rnd_member
                    SET status = 'done', left_at = COALESCE(left_at, NOW())
                  WHERE project_id = ? AND status = 'approved'"
            )->execute([$projectId]);

            $this->closeWorkloadFor($projectId, null);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 점유의 end_date 를 오늘로 당긴다. **DELETE 하지 않는다.**
     *
     * $memberRowId 를 주면 그 사람 것만, 안 주면 과제 전체.
     * 적재가 시작되기 전까지는 바뀌는 행이 없다 (P9-4).
     */
    private function closeWorkloadFor(int $projectId, ?int $memberRowId): void
    {
        // ref_id 는 **과제 번호**다 (P10-2).
        //
        // P9-3 에서는 bs_rnd_member.id 를 넣을 셈으로 조인을 걸어 두었는데,
        // 적재 규약이 project_id 로 정해졌다. 그때는 한 줄도 적재되지
        // 않았으므로 옮길 데이터가 없다 — 지금 바로잡는다.
        $sql = "UPDATE bs_workload
                   SET end_date = CURDATE()
                 WHERE ref_type = 'rnd'
                   AND ref_id = ?
                   AND end_date > CURDATE()";
        $p = [$projectId];

        if ($memberRowId !== null) {
            // 참여 행 번호로 왔으므로 구성원으로 바꿔 건다.
            $st = $this->pdo->prepare('SELECT member_id FROM bs_rnd_member WHERE id = ?');
            $st->execute([$memberRowId]);
            $mid = $st->fetchColumn();
            if ($mid === false) {
                return;
            }
            $sql .= ' AND member_id = ?';
            $p[]  = (int)$mid;
        }
        $this->pdo->prepare($sql)->execute($p);
    }

    /** 볼 수 있는 과제인지 확인하고 돌려준다. 못 보면 '없다' 로 끊는다. */
    private function requireVisible(int $projectId): array
    {
        $r = $this->find($projectId);
        if (!$r) {
            throw new DomainException('과제를 찾을 수 없습니다.');
        }
        return $r;
    }

    /** 아직 돌아가는 과제인가. 이미 끝난 것을 또 끝낼 수 없다. */
    private function requireLive(array $rnd): void
    {
        if (!in_array((string)$rnd['status'], ['approved', 'running'], true)) {
            throw new DomainException(
                '진행 중인 과제만 종료할 수 있습니다. 지금 상태: '
                . (BS_RND_STATUS[$rnd['status']] ?? $rnd['status'])
            );
        }
    }

    /** 팀을 다룰 권한 — lead 이거나 관리자. */
    private function requireTeamAuthority(int $projectId): void
    {
        if (bs_is_admin()) {
            return;
        }
        $me  = bs_current_user();
        $mid = $this->memberIdOf((string)($me['id'] ?? ''));
        $row = $mid === null ? null : $this->memberRowOf($projectId, $mid);

        if (!$row || (string)$row['role'] !== 'lead' || (string)$row['status'] !== 'approved') {
            throw new DomainException('과제를 주도하는 사람과 관리자만 할 수 있습니다.');
        }
    }

    /** 승인된 참여자인가. 기록·산출물 등록에 쓴다. */
    private function requireActiveMember(int $projectId, array $actor): int
    {
        $mid = $this->memberIdOf((string)$actor['id']);
        $row = $mid === null ? null : $this->memberRowOf($projectId, $mid);

        if (!$row || (string)$row['status'] !== 'approved') {
            throw new DomainException('승인된 참여자만 등록할 수 있습니다.');
        }
        return $mid;
    }

    /** 참여 행 한 건. 볼 수 있는 과제의 것만. */
    private function requireMemberRow(int $rowId): array
    {
        $st = $this->pdo->prepare(
            'SELECT rm.*, m.emp_name, m.user_id
               FROM bs_rnd_member rm
               JOIN bs_member m ON m.id = rm.member_id
              WHERE rm.id = ?'
        );
        $st->execute([$rowId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('참여 기록을 찾을 수 없습니다.');
        }
        // 과제를 볼 수 없으면 참여 기록도 없는 것으로 다룬다.
        $this->requireVisible((int)$row['project_id']);
        return $row;
    }

    private function memberRowOf(int $projectId, int $memberId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_rnd_member WHERE project_id = ? AND member_id = ?'
        );
        $st->execute([$projectId, $memberId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** 0 < v <= 1 인 점유율. 아니면 null. */
    private function ratio(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float)$v;
        if ($f <= 0 || $f > 1) {
            throw new InvalidArgumentException('점유율은 0 보다 크고 1 이하여야 합니다.');
        }
        return $f;
    }

    /** 승인·반려 공통 전처리. 볼 수 있고, 권한이 있고, proposed 여야 한다. */
    private function requireForDecision(int $id): array
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('과제를 찾을 수 없습니다.');
        }
        if ((string)$cur['status'] !== 'proposed') {
            throw new DomainException('발의된 과제만 승인·반려할 수 있습니다. 지금 상태: '
                . (BS_RND_STATUS[$cur['status']] ?? $cur['status']));
        }
        if (!bs_can(BS_CAP_RND_APPROVE)) {
            throw new DomainException('과제를 승인·반려할 권한이 없습니다.');
        }
        return $cur;
    }

    /** 목록·건수 공통 WHERE. 가시성이 여기서 반드시 붙는다. */
    private function buildWhere(array $f): array
    {
        [$vis, $p] = bs_rnd_visible_sql('p');
        $w = ['p.deleted_at IS NULL', $vis];

        if (!empty($f['category']) && isset(BS_RND_CATEGORY[$f['category']])) {
            $w[] = 'p.rnd_category = ?';
            $p[] = $f['category'];
        }
        if (!empty($f['status'])) {
            $wanted = array_values(array_filter(
                (array)$f['status'],
                static fn($x) => is_string($x) && isset(BS_RND_STATUS[$x])
            ));
            if ($wanted) {
                $w[] = 'p.status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
                foreach ($wanted as $x) { $p[] = $x; }
            }
        }
        if (!empty($f['recruiting'])) {
            $w[] = 'p.recruiting = 1';
        }
        if (!empty($f['keyword'])) {
            $kw  = '%' . $this->escapeLike((string)$f['keyword']) . '%';
            $w[] = "(p.code LIKE ? ESCAPE '!' OR p.name LIKE ? ESCAPE '!'
                     OR p.summary LIKE ? ESCAPE '!')";
            array_push($p, $kw, $kw, $kw);
        }
        if (!empty($f['mine'])) {
            $me  = bs_current_user();
            $mid = $this->memberIdOf((string)($me['id'] ?? ''));
            // 발의했거나, 참여 중이거나.
            $w[] = '(p.proposer_id = ? OR EXISTS (
                        SELECT 1 FROM bs_rnd_member m
                         WHERE m.project_id = p.id AND m.member_id = ?
                           AND m.status IN (\'requested\', \'approved\')))';
            $p[] = (string)($me['id'] ?? '');
            $p[] = $mid ?? 0;
        }

        return [implode(' AND ', $w), $p];
    }

    /** 이메일 → bs_member.id. 없으면 null. */
    private function memberIdOf(string $userId): ?int
    {
        if ($userId === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id FROM bs_member WHERE user_id = ?');
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * 채번 — RND-2026-001.
     *
     * ProjectRepo::nextCode() 와 **접두사만 다르다.** 두 벌로 두는 것이
     * 마음에 걸리지만, 그쪽을 인자화하면 프로젝트 채번의 시그니처가
     * 바뀌어 기존 시험이 함께 흔들린다. 합칠지는 P9 가 끝난 뒤에 본다.
     */
    private function nextCode(int $year): string
    {
        $prefix = sprintf('RND-%04d-', $year);
        $max = (int)$this->scalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)), 0)
               FROM bs_project
              WHERE code LIKE ?",
            [strlen($prefix) + 1, $prefix . '%']
        );
        return $prefix . sprintf('%03d', $max + 1);
    }

    /** 입력 검증. $isNew 면 필수 항목까지 본다. */
    private function validate(array $d, bool $isNew): void
    {
        if ($isNew || array_key_exists('name', $d)) {
            $name = trim((string)($d['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('과제명을 입력하세요.');
            }
            if (mb_strlen($name) > 200) {
                throw new InvalidArgumentException('과제명이 너무 깁니다(200자 이내).');
            }
        }
        if (array_key_exists('rnd_category', $d) && $d['rnd_category'] !== null && $d['rnd_category'] !== '') {
            if (!isset(BS_RND_CATEGORY[$d['rnd_category']])) {
                throw new InvalidArgumentException('알 수 없는 과제 갈래입니다.');
            }
        }
        if (array_key_exists('visibility', $d) && $d['visibility'] !== null && $d['visibility'] !== '') {
            if (!isset(BS_RND_VISIBILITY[$d['visibility']])) {
                throw new InvalidArgumentException('알 수 없는 공개 범위입니다.');
            }
        }
        if (array_key_exists('load_cap', $d) && $d['load_cap'] !== null && $d['load_cap'] !== '') {
            $v = (float)$d['load_cap'];
            if ($v <= 0 || $v > 1) {
                throw new InvalidArgumentException('점유율은 0 보다 크고 1 이하여야 합니다.');
            }
        }
        $from = $d['dev_start'] ?? null;
        $to   = $d['dev_end'] ?? null;
        if ($from && $to && $from > $to) {
            throw new InvalidArgumentException('종료일이 시작일보다 빠릅니다.');
        }
    }

    private function nn(mixed $v): mixed
    {
        if ($v === null) { return null; }
        $v = is_string($v) ? trim($v) : $v;
        return $v === '' ? null : $v;
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
