<?php
/** bs_allocation / bs_allocation_item / bs_workload 접근 담당 DAO. 배정안 버전과 점유 기록을 다룬다. */

declare(strict_types=1);

/**
 * 배정안 저장소.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ CLAUDE.md 가 강제하는 것                                          │
 * │   배정안은 항상 "제안" 이다. status 를 confirmed 로 올리는 길은     │
 * │   confirm() 하나뿐이어야 한다. update() 로 status 를 통째로 바꿀   │
 * │   수 있게 만들지 말 것 — 그러면 확정 단계를 건너뛸 수 있다.        │
 * │                                                                  │
 * │   그리고 **확정 전에는 대시보드에 나오지 않는다.** 대시보드가      │
 * │   읽는 경로는 confirmed() 하나뿐이고, 그 안에서 조건을 건다.       │
 * └──────────────────────────────────────────────────────────────────┘
 */
final class AllocationRepo
{
    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 배정안 (bs_allocation)
    // =================================================================

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_allocation WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** 프로젝트의 모든 배정안 버전. 최신 버전이 앞. */
    public function versions(int $projectId): array
    {
        // uk_bs_alloc_ver (project_id, version) 를 탄다.
        $st = $this->pdo->prepare(
            'SELECT a.*,
                    (SELECT COUNT(*) FROM bs_allocation_item i WHERE i.allocation_id = a.id) AS item_count,
                    (SELECT COUNT(*) FROM bs_allocation_item i
                      WHERE i.allocation_id = a.id AND i.is_manual = 1) AS manual_count
               FROM bs_allocation a
              WHERE a.project_id = ?
              ORDER BY a.version DESC'
        );
        $st->execute([$projectId]);
        return array_map([$this, 'present'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** 가장 최근 버전(상태 무관). 다음 version 번호를 정할 때 쓴다. */
    public function latest(int $projectId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_allocation WHERE project_id = ? ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$projectId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 확정된 배정안. **대시보드와 진행상황 화면이 이것만 본다.**
     *
     * 확정 전 배정안을 대시보드에 흘리지 않기 위한 유일한 관문이다.
     * 여기 말고 다른 곳에서 status 를 직접 조건에 넣지 말 것 — 한 군데를
     * 빠뜨리면 초안이 새어 나간다.
     */
    public function confirmed(int $projectId): ?array
    {
        // ix_bs_alloc_status (project_id, status) 를 탄다.
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_allocation
              WHERE project_id = ? AND status = ?
              ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$projectId, 'confirmed']);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->present($r) : null;
    }

    /**
     * 확정본과 그 항목을 한 번에. 대시보드가 쓸 모양.
     * 확정본이 없으면 null 이다 — 빈 배열이 아니다. "아직 확정 안 됨" 과
     * "확정했는데 항목이 없음" 은 다른 일이다.
     */
    public function confirmedWithItems(int $projectId): ?array
    {
        $a = $this->confirmed($projectId);
        if ($a === null) {
            return null;
        }
        return ['allocation' => $a, 'items' => $this->items((int)$a['id'])];
    }

    /**
     * 새 배정안 버전을 만든다(version = 직전 + 1).
     * 항상 status='proposed' 로 시작한다. 이 값을 인자로 받지 않는 게 중요하다.
     */
    public function createVersion(int $projectId, array $params, array $actor): int
    {
        // eval_ver 에 그때 쓴 역량 스냅샷을 반드시 기록한다.
        // 나중에 "왜 이렇게 배정됐나" 를 되짚으려면 이 값이 있어야 한다.
        $evalVer = $params['eval_ver'] ?? null;

        for ($try = 0; $try < 5; $try++) {
            $next = (int)($this->latest($projectId)['version'] ?? 0) + 1;
            try {
                $st = $this->pdo->prepare(
                    'INSERT INTO bs_allocation
                        (project_id, version, status, engine_ver, eval_ver, params_json,
                         created_by, created_by_name)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $st->execute([
                    $projectId, $next, 'proposed',
                    $params['engine_ver'] ?? BS_ENGINE_VER,
                    $evalVer !== null ? (int)$evalVer : null,
                    json_encode($params, JSON_UNESCAPED_UNICODE),
                    $actor['id'] ?? null, $actor['name'] ?? null,
                ]);
                return (int)$this->pdo->lastInsertId();
            } catch (PDOException $e) {
                // 23000 = 무결성 위반. 두 사람이 동시에 산출하면 version 이 겹친다.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('배정안 번호를 매기지 못했습니다. 다시 시도해 주세요.');
    }

    /** 사람이 손댔음을 표시한다. proposed → adjusted */
    public function markAdjusted(int $allocationId, array $actor): void
    {
        // 확정된 것은 되돌리지 않는다. 확정본을 고치려면 새 버전을 낸다.
        $st = $this->pdo->prepare(
            "UPDATE bs_allocation SET status = 'adjusted'
              WHERE id = ? AND status = 'proposed'"
        );
        $st->execute([$allocationId]);
    }

    /**
     * 확정. **status 를 confirmed 로 올리는 유일한 경로.**
     *
     * 한 트랜잭션 안에서:
     *   1. confirmed_by / confirmed_by_name / confirmed_at 채우기
     *   2. 같은 프로젝트의 다른 버전을 archived 로 내리기
     *   3. bs_workload 에 kind='assigned' 점유 기록 만들기
     * 2번이 빠지면 확정본이 둘이 된다.
     *
     * 알림은 여기서 보내지 않는다 — 바깥 사정 때문에 확정이 실패하면 안 된다.
     * 부르는 쪽이 확정 뒤에 Notifier 로 접수한다.
     */
    public function confirm(int $allocationId, array $actor): void
    {
        $a = $this->find($allocationId);
        if (!$a) {
            throw new DomainException('배정안을 찾을 수 없습니다.');
        }
        if ($a['status'] === 'confirmed') {
            throw new DomainException('이미 확정된 배정안입니다.');
        }
        if ($a['status'] === 'archived') {
            throw new DomainException('지난 배정안은 확정할 수 없습니다. 새로 산출하세요.');
        }
        if (!$this->items($allocationId)) {
            throw new DomainException('배정 항목이 없습니다. 먼저 배정안을 산출하세요.');
        }

        // 담당자 없는 태스크가 있으면 확정하지 않는다. 확정본은 "이 일은
        // 아무도 안 맡는다" 를 담을 수 없다 — 대시보드가 그걸 못 그린다.
        $orphan = $this->tasksWithoutOwner($allocationId);
        if ($orphan) {
            throw new DomainException(
                "담당자가 없는 태스크가 있습니다. 먼저 채워 주세요.\n · "
                . implode("\n · ", array_map(
                    static fn($t) => ($t['wbs_no'] ?: $t['id']) . ' ' . $t['title'], $orphan))
            );
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE bs_allocation
                    SET status = 'archived'
                  WHERE project_id = ? AND id <> ? AND status <> 'archived'"
            )->execute([(int)$a['project_id'], $allocationId]);

            $this->pdo->prepare(
                "UPDATE bs_allocation
                    SET status = 'confirmed', confirmed_by = ?, confirmed_by_name = ?,
                        confirmed_at = NOW()
                  WHERE id = ?"
            )->execute([$actor['id'] ?? null, $actor['name'] ?? null, $allocationId]);

            $this->syncWorkloadFrom($allocationId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function archive(int $allocationId): void
    {
        $this->pdo->prepare(
            "UPDATE bs_allocation SET status = 'archived' WHERE id = ? AND status <> 'confirmed'"
        )->execute([$allocationId]);
    }

    /**
     * 담당(owner)이 없는 태스크. 확정 전 점검용.
     *
     * **말단 태스크만 본다.** 배정 엔진이 말단만 배정하기 때문이다(상위
     * 태스크까지 배정하면 공수가 이중으로 잡힌다). 여기서 상위까지 세면
     * "1.2 통합 출석부 화면에 담당자가 없다" 며 확정이 영영 막힌다 —
     * 실제로 그렇게 막혔다. 두 곳의 '배정 대상' 정의가 같아야 한다.
     */
    public function tasksWithoutOwner(int $allocationId): array
    {
        $a = $this->find($allocationId);
        if (!$a) {
            return [];
        }
        $ph = implode(',', array_fill(0, count(BS_TASK_NOT_ASSIGNABLE_STATUS), '?'));
        $st = $this->pdo->prepare(
            "SELECT t.id, t.wbs_no, t.title
               FROM bs_task t
              WHERE t.project_id = ?
                AND t.confirmed = 1
                AND t.status NOT IN ($ph)
                AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id = t.id)
                /* ┌────────────────────────────────────────────────────┐
                   │ 상위가 맡고 있으면 그 하위도 임자가 있는 것이다      │
                   │                                                    │
                   │ 배정 단위를 대분류·중분류로 고르면 항목이 **상위     │
                   │ 노드**를 가리킨다. 그런데 이 질의가 말단만 보던      │
                   │ 탓에, 멀쩡히 묶여 배정된 하위 62건이 임자 없는 것   │
                   │ 처럼 떴다(2026-10-07).                             │
                   │                                                    │
                   │ 깊이는 3 까지라 부모·조부모만 보면 된다.            │
                   │ parent_id 가 NULL 이면 IN 에서 그냥 안 맞는다.      │
                   └────────────────────────────────────────────────────┘ */
                AND NOT EXISTS (
                    SELECT 1 FROM bs_allocation_item i
                     WHERE i.allocation_id = ? AND i.role = 'owner'
                       AND i.task_id IN (
                             t.id,
                             t.parent_id,
                             (SELECT p.parent_id FROM bs_task p WHERE p.id = t.parent_id)))
              ORDER BY t.wbs_no"
        );
        $st->execute(array_merge(
            [(int)$a['project_id']], BS_TASK_NOT_ASSIGNABLE_STATUS, [$allocationId]
        ));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // =================================================================
    // 배정 항목 (bs_allocation_item)
    // =================================================================

    /** 한 배정안의 전체 항목. 태스크·구성원 정보까지 조인해서. */
    public function items(int $allocationId): array
    {
        $st = $this->pdo->prepare(
            'SELECT i.*,
                    t.wbs_no, t.title AS task_title, t.depth, t.seq, t.est_md, t.difficulty,
                    t.plan_start, t.plan_end, t.status AS task_status, t.parent_id,
                    m.emp_name, m.role_label, m.team
               FROM bs_allocation_item i
               JOIN bs_task   t ON t.id = i.task_id
               JOIN bs_member m ON m.id = i.member_id
              WHERE i.allocation_id = ?
              ORDER BY t.wbs_no, FIELD(i.role, "owner", "support", "reviewer"), m.emp_name'
        );
        $st->execute([$allocationId]);
        return array_map([$this, 'presentItem'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findItem(int $itemId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT i.*, t.wbs_no, t.title AS task_title, t.est_md, t.project_id,
                    m.emp_name
               FROM bs_allocation_item i
               JOIN bs_task   t ON t.id = i.task_id
               JOIN bs_member m ON m.id = i.member_id
              WHERE i.id = ?'
        );
        $st->execute([$itemId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->presentItem($r) : null;
    }

    /**
     * 엔진이 산출한 항목을 통째로 넣는다.
     * reason_json 에 근거(evidence)를 반드시 담는다 — CLAUDE.md 의 역추적 요건.
     */
    public function saveItems(int $allocationId, array $items): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM bs_allocation_item WHERE allocation_id = ?')
                      ->execute([$allocationId]);

            $st = $this->pdo->prepare(
                'INSERT INTO bs_allocation_item
                    (allocation_id, task_id, member_id, role, alloc_ratio, fit_score,
                     reason_json, is_manual, manual_note)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            foreach ($items as $it) {
                if (empty($it['reason_json'])) {
                    // 근거 없는 배정은 넣지 않는다. "왜 이 사람인가" 에
                    // 답할 수 없는 항목이 생기면 화면이 거짓말을 하게 된다.
                    throw new DomainException(
                        '근거(reason_json) 없는 배정 항목은 저장할 수 없습니다: task#'
                        . ($it['task_id'] ?? '?'));
                }
                try {
                    $st->execute([
                        $allocationId,
                        (int)$it['task_id'], (int)$it['member_id'],
                        $it['role'] ?? 'owner',
                        (float)($it['alloc_ratio'] ?? 1.0),
                        isset($it['fit_score']) ? (float)$it['fit_score'] : null,
                        is_string($it['reason_json'])
                            ? $it['reason_json']
                            : json_encode($it['reason_json'], JSON_UNESCAPED_UNICODE),
                        !empty($it['is_manual']) ? 1 : 0,
                        $it['manual_note'] ?? null,
                    ]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        // uk_bs_allocitem_one 위반 = 엔진이 같은 조합을 두 번 냈다.
                        // 조용히 넘기면 공수가 이중 계산된다. 드러낸다.
                        throw new DomainException(
                            '같은 태스크에 같은 사람이 같은 역할로 두 번 들어갔습니다: task#'
                            . $it['task_id'] . ' member#' . $it['member_id']);
                    }
                    throw $e;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 담당자 수동 조정.
     * is_manual=1 과 manual_note 를 반드시 함께 남긴다 — 나중에 왜 바꿨는지 알아야 한다.
     */
    public function updateItem(int $itemId, array $data, array $actor): void
    {
        $cur = $this->findItem($itemId);
        if (!$cur) {
            throw new DomainException('배정 항목을 찾을 수 없습니다.');
        }
        $alloc = $this->find((int)$cur['allocation_id']);
        if (($alloc['status'] ?? '') === 'confirmed') {
            throw new DomainException(
                '확정된 배정안은 고칠 수 없습니다. 새 버전을 산출해 조정하세요.');
        }
        if (($alloc['status'] ?? '') === 'archived') {
            throw new DomainException('지난 배정안은 고칠 수 없습니다.');
        }

        $set = ['is_manual = 1'];
        $par = [];

        if (array_key_exists('member_id', $data)) {
            $set[] = 'member_id = ?';
            $par[] = (int)$data['member_id'];
        }
        if (array_key_exists('role', $data)) {
            if (!isset(BS_ALLOC_ROLE[$data['role']])) {
                throw new InvalidArgumentException('알 수 없는 역할입니다: ' . $data['role']);
            }
            $set[] = 'role = ?';
            $par[] = $data['role'];
        }
        if (array_key_exists('alloc_ratio', $data)) {
            $r = (float)$data['alloc_ratio'];
            if ($r <= 0 || $r > 1) {
                throw new InvalidArgumentException('배정 비율은 0 초과 1 이하여야 합니다.');
            }
            $set[] = 'alloc_ratio = ?';
            $par[] = $r;
        }
        // 사람이 바꿨으면 엔진 점수는 더 이상 그 사람 것이 아니다. 지운다.
        // 남겨 두면 화면이 "적합도 82" 라고 말하는데 그건 다른 사람 점수다.
        if (array_key_exists('member_id', $data)
            && (int)$data['member_id'] !== (int)$cur['member_id']) {
            $set[] = 'fit_score = NULL';
        }

        $note = trim((string)($data['manual_note'] ?? ''));
        $set[] = 'manual_note = ?';
        $par[] = $note !== '' ? mb_substr($note, 0, 300) : null;

        $par[] = $itemId;
        try {
            $this->pdo->prepare(
                'UPDATE bs_allocation_item SET ' . implode(', ', $set) . ' WHERE id = ?'
            )->execute($par);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException(
                    '그 사람은 이미 같은 태스크에 같은 역할로 들어가 있습니다.');
            }
            throw $e;
        }

        $this->markAdjusted((int)$cur['allocation_id'], $actor);
    }

    /** 배정 항목 추가(수동). 화면에서 지원 인원을 붙일 때. */
    public function addItem(int $allocationId, array $data, array $actor): int
    {
        $alloc = $this->find($allocationId);
        if (!$alloc) {
            throw new DomainException('배정안을 찾을 수 없습니다.');
        }
        if ($alloc['status'] === 'confirmed' || $alloc['status'] === 'archived') {
            throw new DomainException('확정됐거나 지난 배정안은 고칠 수 없습니다.');
        }

        $role = $data['role'] ?? 'support';
        if (!isset(BS_ALLOC_ROLE[$role])) {
            throw new InvalidArgumentException('알 수 없는 역할입니다: ' . $role);
        }
        $note = trim((string)($data['manual_note'] ?? ''));

        try {
            $st = $this->pdo->prepare(
                'INSERT INTO bs_allocation_item
                    (allocation_id, task_id, member_id, role, alloc_ratio,
                     fit_score, reason_json, is_manual, manual_note)
                 VALUES (?,?,?,?,?, NULL, ?, 1, ?)'
            );
            $st->execute([
                $allocationId, (int)$data['task_id'], (int)$data['member_id'], $role,
                (float)($data['alloc_ratio'] ?? 1.0),
                json_encode([
                    'lines'  => ['사람이 직접 추가한 항목입니다.'
                        . ($note !== '' ? ' 사유: ' . $note : '')],
                    'manual' => true,
                ], JSON_UNESCAPED_UNICODE),
                $note !== '' ? mb_substr($note, 0, 300) : null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException(
                    '그 사람은 이미 같은 태스크에 같은 역할로 들어가 있습니다.');
            }
            throw $e;
        }

        $this->markAdjusted($allocationId, $actor);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteItem(int $itemId): void
    {
        $cur = $this->findItem($itemId);
        if (!$cur) {
            throw new DomainException('배정 항목을 찾을 수 없습니다.');
        }
        $alloc = $this->find((int)$cur['allocation_id']);
        if (($alloc['status'] ?? '') === 'confirmed') {
            throw new DomainException('확정된 배정안은 고칠 수 없습니다.');
        }
        $this->pdo->prepare('DELETE FROM bs_allocation_item WHERE id = ?')->execute([$itemId]);
    }

    /** 한 사람이 이 배정안에서 맡은 몫의 합. 과배정 확인용. */
    public function totalRatioOf(int $allocationId, int $memberId): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(alloc_ratio), 0) FROM bs_allocation_item
              WHERE allocation_id = ? AND member_id = ?'
        );
        $st->execute([$allocationId, $memberId]);
        return (float)$st->fetchColumn();
    }

    /**
     * 사람별 배정 공수 합계(M/D). 화면의 부하 막대가 쓴다.
     *
     * 말단 태스크의 est_md 만 센다. 상위 태스크의 est_md 는 하위 합계와
     * 겹치므로 같이 더하면 이중 계산된다(TaskRepo::toTree 의 롤업 규칙과 같다).
     *
     * @return array<int, array{md:float,items:int,owner:int}>
     */
    public function loadByMember(int $allocationId): array
    {
        // ┌──────────────────────────────────────────────────────────────┐
        // │ 묶어 배정한 줄의 공수는 하위를 더해야 한다                     │
        // │                                                              │
        // │ 전에는 "하위가 있으면 0" 이었다. 상위는 하위의 묶음이라 자기   │
        // │ 공수를 안 받기 때문이고, 말단만 배정하던 시절에는 맞았다.      │
        // │                                                              │
        // │ 배정 단위를 대분류로 고르면 항목이 상위를 가리키므로, 그 규칙  │
        // │ 그대로면 **27건을 맡고도 공수 0 으로 잡힌다**(2026-10-07).     │
        // │                                                              │
        // │ 그래서 말단이면 자기 값, 아니면 **확정된 말단 하위의 합**을    │
        // │ 쓴다. 깊이가 3 까지라 자식·손자만 보면 된다.                  │
        // └──────────────────────────────────────────────────────────────┘
        $st = $this->pdo->prepare(
            'SELECT i.member_id,
                    SUM(COALESCE(
                      CASE WHEN c.n = 0 THEN t.est_md
                           ELSE (SELECT SUM(l.est_md) FROM bs_task l
                                  WHERE l.confirmed = 1
                                    AND NOT EXISTS (SELECT 1 FROM bs_task g WHERE g.parent_id = l.id)
                                    AND (l.parent_id = t.id
                                         OR l.parent_id IN (SELECT m.id FROM bs_task m
                                                             WHERE m.parent_id = t.id)))
                      END, 0) * i.alloc_ratio) AS md,
                    COUNT(*) AS items,
                    SUM(i.role = "owner") AS owner_n
               FROM bs_allocation_item i
               JOIN bs_task t ON t.id = i.task_id
               JOIN (SELECT p.id, (SELECT COUNT(*) FROM bs_task c2 WHERE c2.parent_id = p.id) AS n
                       FROM bs_task p) c ON c.id = t.id
              WHERE i.allocation_id = ?
              GROUP BY i.member_id'
        );
        $st->execute([$allocationId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['member_id']] = [
                'md'    => round((float)$r['md'], 2),
                'items' => (int)$r['items'],
                'owner' => (int)$r['owner_n'],
            ];
        }
        return $out;
    }

    // =================================================================
    // 점유 기록 (bs_workload)
    // =================================================================

    /**
     * 기간이 겹치는 점유 기록. 가용도 계산의 입력.
     *
     * @param string $from 'YYYY-MM-DD'
     * @param string $to   'YYYY-MM-DD'
     */
    public function workloadOf(int $memberId, string $from, string $to): array
    {
        $m = $this->workloadOfMany([$memberId], $from, $to);
        return $m[$memberId] ?? [];
    }

    /** 여러 사람 것을 한 번에. 후보 리스트가 N+1 을 내지 않도록. */
    public function workloadOfMany(array $memberIds, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // ix_bs_workload_period (member_id, start_date, end_date)
        $st = $this->pdo->prepare(
            "SELECT * FROM bs_workload
              WHERE member_id IN ($ph) AND start_date <= ? AND end_date >= ?
              ORDER BY member_id, start_date"
        );
        $st->execute(array_merge($ids, [$to, $from]));

        $out = array_fill_keys($ids, []);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['member_id']][] = $r;
        }
        return $out;
    }

    /**
     * 점유 기록을 넣는다.
     *
     * `kind='manual'`(사람이 직접 등록)은 **사유와 작성자를 반드시 받는다.**
     * 이 한 줄이 그 사람의 가용도를 그대로 깎고, 그러면 후보 목록에서
     * 사실상 사라진다. 나중에 "왜 이렇지" 를 물었을 때 답이 있어야 한다.
     */
    public function addWorkload(int $memberId, array $data): int
    {
        $kind = $data['kind'] ?? 'manual';
        if (!in_array($kind, ['assigned', 'inferred', 'manual'], true)) {
            throw new InvalidArgumentException('알 수 없는 점유 종류입니다: ' . $kind);
        }

        $ratio = (float)($data['load_ratio'] ?? 1.0);
        if ($ratio <= 0 || $ratio > 1) {
            throw new InvalidArgumentException('점유율은 0 초과 1 이하여야 합니다.');
        }
        $from = $this->wlDate($data['start_date'] ?? null, '시작일');
        $to   = $this->wlDate($data['end_date'] ?? null, '종료일');
        if ($from > $to) {
            throw new InvalidArgumentException('시작일이 종료일보다 늦습니다: ' . $from . ' ~ ' . $to);
        }

        $note   = $this->wlTrim($data['note'] ?? null, 500);
        $label  = $this->wlTrim($data['label'] ?? null, 200);
        $source = isset($data['source']) && $data['source'] !== '' ? (string)$data['source'] : null;

        if ($kind === 'manual') {
            if ($label === null) {
                throw new InvalidArgumentException('무슨 업무인지 적어 주세요.');
            }
            if ($note === null) {
                throw new InvalidArgumentException(
                    '사유를 적어 주세요. 이 값이 가용도를 깎기 때문에 근거가 남아야 합니다.');
            }
            if ($source !== null && !isset(BS_WORKLOAD_SOURCE[$source])) {
                throw new InvalidArgumentException('알 수 없는 출처입니다: ' . $source);
            }
            if (empty($data['created_by'])) {
                throw new InvalidArgumentException('작성자 정보가 없습니다.');
            }
        }

        $st = $this->pdo->prepare(
            'INSERT INTO bs_workload
                (member_id, kind, ref_type, ref_id, source, source_url, label,
                 start_date, end_date, load_ratio, confidence, note,
                 created_by, created_by_name)
             VALUES (?,?,?,?,?,?,?, ?,?,?,?,?, ?,?)'
        );
        $st->execute([
            $memberId, $kind,
            $data['ref_type'] ?? null,
            isset($data['ref_id']) ? (int)$data['ref_id'] : null,
            $source,
            $this->wlUrl($data['source_url'] ?? null),
            $label,
            $from, $to, $ratio,
            (float)($data['confidence'] ?? 1.0),
            $note,
            $data['created_by'] ?? null,
            $data['created_by_name'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findWorkload(int $workloadId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT w.*, m.emp_name, m.user_id
               FROM bs_workload w JOIN bs_member m ON m.id = w.member_id
              WHERE w.id = ?'
        );
        $st->execute([$workloadId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->presentWorkload($r) : null;
    }

    /**
     * 사람이 직접 등록한 점유만. 기간을 주면 겹치는 것만.
     *
     * 배정이 만든 것(assigned)과 수집기 추정(inferred)은 빼고 본다 —
     * 화면에서 고칠 수 있는 것은 직접 등록한 것뿐이다.
     */
    public function manualWorkloadOf(int $memberId, ?string $from = null, ?string $to = null): array
    {
        // ix_bs_workload_manual (kind, member_id, start_date)
        $sql = "SELECT w.*, m.emp_name, m.user_id
                  FROM bs_workload w JOIN bs_member m ON m.id = w.member_id
                 WHERE w.kind = 'manual' AND w.member_id = ?";
        $par = [$memberId];
        if ($from !== null && $to !== null) {
            $sql .= ' AND w.start_date <= ? AND w.end_date >= ?';
            $par[] = $to;
            $par[] = $from;
        }
        $sql .= ' ORDER BY w.start_date, w.id';

        $st = $this->pdo->prepare($sql);
        $st->execute($par);
        return array_map([$this, 'presentWorkload'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 직접 등록한 점유를 고친다.
     *
     * **배정이 만든 기록(assigned)은 못 고친다.** 그것은 배정안에서 나온
     * 파생 데이터라, 여기서 고쳐도 다음 확정 때 도로 덮인다.
     */
    /**
     * @param array $actor 고친 사람. **등록한 사람은 건드리지 않는다** —
     *                     둘은 다른 정보이고, 둘 다 있어야 "이 숫자가 어떻게
     *                     지금 모습이 됐나" 에 답할 수 있다.
     */
    public function updateWorkload(int $workloadId, array $data, array $actor = []): void
    {
        $cur = $this->findWorkload($workloadId);
        if (!$cur) {
            throw new DomainException('점유 기록을 찾을 수 없습니다.');
        }
        if ($cur['kind'] !== 'manual') {
            throw new DomainException(
                '직접 등록한 점유만 고칠 수 있습니다. 배정에서 나온 기록은 '
                . '배정안을 고쳐야 바뀝니다.');
        }

        // 고친 뒤의 모습으로 검증한다. 넣을 때 요구한 것을 고칠 때 풀면
        // 사유 없는 기록이 우회로로 생긴다.
        $after = $data + $cur;
        if ($this->wlTrim($after['label'] ?? null, 200) === null) {
            throw new InvalidArgumentException('무슨 업무인지 적어 주세요.');
        }
        if ($this->wlTrim($after['note'] ?? null, 500) === null) {
            throw new InvalidArgumentException('사유를 비울 수 없습니다.');
        }

        $set = [];
        $par = [];
        foreach (['label', 'note', 'source', 'source_url', 'start_date', 'end_date',
                  'load_ratio'] as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $set[] = '`' . $k . '` = ?';
            $par[] = $this->wlValue($k, $data[$k]);
        }
        if (!$set) {
            return;
        }

        // 고친 사람을 함께 적는다. 가용도를 깎는 값이라 등록만큼이나
        // 고친 것도 근거가 남아야 한다.
        if (($actor['id'] ?? '') !== '') {
            $set[] = '`updated_by` = ?';
            $par[] = (string)$actor['id'];
            $set[] = '`updated_by_name` = ?';
            $par[] = (string)($actor['name'] ?? '');
        }

        $f = array_key_exists('start_date', $data)
            ? $this->wlDate($data['start_date'], '시작일') : $cur['start_date'];
        $t = array_key_exists('end_date', $data)
            ? $this->wlDate($data['end_date'], '종료일') : $cur['end_date'];
        if ($f > $t) {
            throw new InvalidArgumentException('시작일이 종료일보다 늦습니다: ' . $f . ' ~ ' . $t);
        }

        $par[] = $workloadId;
        $this->pdo->prepare('UPDATE bs_workload SET ' . implode(', ', $set) . ' WHERE id = ?')
                  ->execute($par);
    }

    /** 직접 등록한 점유만 지울 수 있다. 배정이 만든 것은 배정안이 관리한다. */
    public function deleteWorkload(int $workloadId): void
    {
        $cur = $this->findWorkload($workloadId);
        if (!$cur) {
            throw new DomainException('점유 기록을 찾을 수 없습니다.');
        }
        if ($cur['kind'] !== 'manual') {
            throw new DomainException(
                '직접 등록한 점유만 지울 수 있습니다. 배정에서 나온 기록은 '
                . '배정안에서 빼야 사라집니다.');
        }
        $this->pdo->prepare('DELETE FROM bs_workload WHERE id = ?')->execute([$workloadId]);
    }

    private function wlValue(string $k, mixed $v): mixed
    {
        return match ($k) {
            'start_date' => $this->wlDate($v, '시작일'),
            'end_date'   => $this->wlDate($v, '종료일'),
            'load_ratio' => $this->wlRatio($v),
            'source'     => $this->wlSource($v),
            'source_url' => $this->wlUrl($v),
            'note'       => $this->wlTrim($v, 500),
            default      => $this->wlTrim($v, 200),
        };
    }

    private function presentWorkload(array $r): array
    {
        $src = $r['source'] ?? null;
        return [
            'id'          => (int)$r['id'],
            'member_id'   => (int)$r['member_id'],
            'emp_name'    => $r['emp_name'] ?? null,
            'user_id'     => $r['user_id'] ?? null,
            'kind'        => $r['kind'],
            'label'       => $r['label'],
            'note'        => $r['note'] ?? null,
            'source'      => $src,
            'source_label' => ($src !== null && isset(BS_WORKLOAD_SOURCE[$src]))
                              ? BS_WORKLOAD_SOURCE[$src] : null,
            'source_url'  => $r['source_url'] ?? null,
            'start_date'  => $r['start_date'],
            'end_date'    => $r['end_date'],
            'load_ratio'  => (float)$r['load_ratio'],
            'load_pct'    => (int)round((float)$r['load_ratio'] * 100),
            'created_by'  => $r['created_by'] ?? null,
            'created_by_name' => $r['created_by_name'] ?? null,
            // 등록한 사람과 고친 사람은 **다른 정보**다. 둘 다 있어야
            // 이 숫자가 어떻게 지금 모습이 됐는지 알 수 있다.
            'updated_by'      => $r['updated_by'] ?? null,
            'updated_by_name' => $r['updated_by_name'] ?? null,
            'created_at'  => $r['created_at'],
            'updated_at'  => $r['updated_at'] ?? null,
        ];
    }

    private function wlRatio(mixed $v): float
    {
        $r = (float)$v;
        if ($r <= 0 || $r > 1) {
            throw new InvalidArgumentException('점유율은 0 초과 1 이하여야 합니다.');
        }
        return $r;
    }

    private function wlSource(mixed $v): ?string
    {
        $v = ($v !== null && $v !== '') ? (string)$v : null;
        if ($v !== null && !isset(BS_WORKLOAD_SOURCE[$v])) {
            throw new InvalidArgumentException('알 수 없는 출처입니다: ' . $v);
        }
        return $v;
    }

    private function wlDate(mixed $v, string $field): string
    {
        $v = trim((string)($v ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new InvalidArgumentException('날짜 형식이 올바르지 않습니다(' . $field . '): ' . $v);
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) {
            throw new InvalidArgumentException('없는 날짜입니다(' . $field . '): ' . $v);
        }
        return $v;
    }

    private function wlTrim(mixed $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** 근거 링크. http/https 만 받는다 — javascript: 를 그대로 걸면 안 된다. */
    private function wlUrl(mixed $v): ?string
    {
        $v = $this->wlTrim($v, 500);
        if ($v === null) {
            return null;
        }
        if (!preg_match('#^https?://#i', $v)) {
            throw new InvalidArgumentException('근거 링크는 http:// 또는 https:// 로 시작해야 합니다.');
        }
        return $v;
    }

    /**
     * 확정된 배정안으로부터 kind='assigned' 점유 기록을 다시 만든다.
     *
     * 이 프로젝트가 만든 assigned 기록만 지우고 새로 넣는다.
     * inferred/manual 은 건드리지 않는다 — 다른 데서 온 값이다.
     *
     * 기간이 없는 태스크는 프로젝트 기간으로 대신한다. 기간을 모르면
     * 언제 점유하는지 알 수 없어 가용도 계산에서 빠져 버린다.
     */
    public function syncWorkloadFrom(int $allocationId): void
    {
        $a = $this->find($allocationId);
        if (!$a) {
            throw new DomainException('배정안을 찾을 수 없습니다.');
        }
        $projectId = (int)$a['project_id'];

        $p = $this->pdo->prepare(
            'SELECT dev_start, dev_end, test_start, test_end, deploy_date
               FROM bs_project WHERE id = ?'
        );
        $p->execute([$projectId]);
        $pr = $p->fetch(PDO::FETCH_ASSOC) ?: [];
        $pFrom = $pr['dev_start'] ?: ($pr['test_start'] ?: null);
        $pTo   = $pr['deploy_date'] ?: ($pr['test_end'] ?: ($pr['dev_end'] ?: null));

        // 이 프로젝트의 태스크에서 나온 assigned 기록만 지운다.
        $this->pdo->prepare(
            "DELETE w FROM bs_workload w
               JOIN bs_allocation_item i ON i.id = w.ref_id
               JOIN bs_task t            ON t.id = i.task_id
              WHERE w.kind = 'assigned' AND w.ref_type = 'allocation_item'
                AND t.project_id = ?"
        )->execute([$projectId]);

        $ins = $this->pdo->prepare(
            "INSERT INTO bs_workload
                (member_id, kind, ref_type, ref_id, label, start_date, end_date,
                 load_ratio, confidence)
             VALUES (?, 'assigned', 'allocation_item', ?, ?, ?, ?, ?, 1.000)"
        );

        foreach ($this->items($allocationId) as $it) {
            $from = $it['plan_start'] ?: $pFrom;
            $to   = $it['plan_end']   ?: $pTo;
            if ($from === null || $to === null || $from > $to) {
                // 기간을 짐작할 수 없으면 점유로 세지 않는다. 틀린 기간을
                // 넣느니 빼는 편이 낫다 — 가용도가 조용히 어긋난다.
                continue;
            }
            $ins->execute([
                (int)$it['member_id'], (int)$it['id'],
                ($it['wbs_no'] ? $it['wbs_no'] . ' ' : '') . $it['task_title'],
                $from, $to, (float)$it['alloc_ratio'],
            ]);
        }
    }

    // =================================================================
    // 안쪽
    // =================================================================

    private function present(array $r): array
    {
        $r['id']           = (int)$r['id'];
        $r['project_id']   = (int)$r['project_id'];
        $r['version']      = (int)$r['version'];
        $r['status_label'] = BS_ALLOC_STATUS[$r['status']] ?? $r['status'];
        $r['eval_ver']     = $r['eval_ver'] !== null ? (int)$r['eval_ver'] : null;
        $r['params']       = $r['params_json'] ? json_decode($r['params_json'], true) : null;
        if (isset($r['item_count']))   { $r['item_count']   = (int)$r['item_count']; }
        if (isset($r['manual_count'])) { $r['manual_count'] = (int)$r['manual_count']; }
        unset($r['params_json']);
        return $r;
    }

    private function presentItem(array $r): array
    {
        $r['id']          = (int)$r['id'];
        $r['task_id']     = (int)$r['task_id'];
        $r['member_id']   = (int)$r['member_id'];
        $r['alloc_ratio'] = (float)$r['alloc_ratio'];
        $r['fit_score']   = $r['fit_score'] !== null ? (float)$r['fit_score'] : null;
        $r['is_manual']   = (int)$r['is_manual'] === 1;
        $r['role_name']   = BS_ALLOC_ROLE[$r['role']] ?? $r['role'];
        $r['est_md']      = isset($r['est_md']) && $r['est_md'] !== null ? (float)$r['est_md'] : null;
        $r['difficulty']  = isset($r['difficulty']) && $r['difficulty'] !== null
                            ? (int)$r['difficulty'] : null;
        $r['reason']      = $r['reason_json'] ? json_decode($r['reason_json'], true) : null;
        unset($r['reason_json']);
        return $r;
    }
}
