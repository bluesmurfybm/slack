<?php
declare(strict_types=1);

final class PurchaseRequest
{
    // -----------------------------------------------------------------
    // 조회
    // -----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return bc_fetch_one(
            'SELECT r.*, c.name AS category_name, c.code AS category_code
               FROM bc_request r
               JOIN bc_category c ON c.id = r.category_id
              WHERE r.id = ?',
            [$id]
        );
    }

    /**
     * 목록 검색.
     *
     * @param array $f year, status[], category_id, keyword, requester_id,
     *                 mine(bool), scope(member|admin), page, size, sort
     */
    public static function search(array $f): array
    {
        $where = [];
        $args  = [];

        $year = (int)($f['year'] ?? 0);
        if ($year > 0) {
            $where[] = 'r.req_year = ?';
            $args[]  = $year;
        }

        $status = array_values(array_filter((array)($f['status'] ?? []), fn($s) => isset(BC_STATUS[$s])));
        if ($status) {
            $where[] = 'r.status IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
            array_push($args, ...$status);
        }

        if (!empty($f['category_id'])) {
            $where[] = 'r.category_id = ?';
            $args[]  = (int)$f['category_id'];
        }

        $kw = trim((string)($f['keyword'] ?? ''));
        if ($kw !== '') {
            $where[] = '(r.item_name LIKE ? OR r.note LIKE ? OR r.requester_name LIKE ? OR r.req_no LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($args, $like, $like, $like, $like);
        }

        if (!empty($f['requester_id'])) {
            $where[] = 'r.requester_id = ?';
            $args[]  = (string)$f['requester_id'];
        }

        // 구매담당자 관점: 내가 맡은 건 + 아직 아무도 안 맡은 건
        if (!empty($f['assignee_id'])) {
            if (!empty($f['include_unassigned'])) {
                $where[] = '(r.assignee_id = ? OR r.assignee_id IS NULL)';
            } else {
                $where[] = 'r.assignee_id = ?';
            }
            $args[] = (string)$f['assignee_id'];
        }

        if (!empty($f['from'])) {
            $where[] = 'r.requested_at >= ?';
            $args[]  = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'r.requested_at <= ?';
            $args[]  = $f['to'] . ' 23:59:59';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // 정렬: 화이트리스트만 허용
        $sortMap = [
            'recent'  => 'r.requested_at DESC, r.id DESC',   // 기본: 최신 요청 순
            'oldest'  => 'r.requested_at ASC, r.id ASC',
            'status'  => "FIELD(r.status,'REQUESTED','APPROVED','PURCHASING','STOCKED','REJECTED','CANCELED'), r.requested_at DESC",
            'item'    => 'r.item_name ASC, r.requested_at DESC',
        ];
        $orderSql = $sortMap[$f['sort'] ?? 'recent'] ?? $sortMap['recent'];

        $page = max(1, (int)($f['page'] ?? 1));
        $size = min(200, max(5, (int)($f['size'] ?? 30)));
        $off  = ($page - 1) * $size;

        $total = (int)bc_fetch_value(
            "SELECT COUNT(*) FROM bc_request r $whereSql", $args, 0
        );

        $rows = bc_fetch_all(
            "SELECT r.*, c.name AS category_name, c.code AS category_code
               FROM bc_request r
               JOIN bc_category c ON c.id = r.category_id
               $whereSql
              ORDER BY $orderSql
              LIMIT $size OFFSET $off",
            $args
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'size' => $size];
    }

    /** 상태별 건수 — 대시보드 집계 영역용 */
    public static function statusCounts(array $f = []): array
    {
        $where = [];
        $args  = [];
        if (!empty($f['year'])) {
            $where[] = 'req_year = ?';
            $args[]  = (int)$f['year'];
        }
        if (!empty($f['category_id'])) {
            $where[] = 'category_id = ?';
            $args[]  = (int)$f['category_id'];
        }
        if (!empty($f['requester_id'])) {
            $where[] = 'requester_id = ?';
            $args[]  = (string)$f['requester_id'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = bc_fetch_all(
            "SELECT status, COUNT(*) AS cnt FROM bc_request $whereSql GROUP BY status", $args
        );

        $out = array_fill_keys(array_keys(BC_STATUS), 0);
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['cnt'];
        }
        $out['TOTAL'] = array_sum(array_intersect_key($out, BC_STATUS));
        return $out;
    }

    /** 요청 가능한 연도 목록 (필터용) */
    public static function years(): array
    {
        $rows = bc_fetch_all('SELECT DISTINCT req_year FROM bc_request ORDER BY req_year DESC');
        $years = array_map('intval', array_column($rows, 'req_year'));
        $now = (int)date('Y');
        if (!in_array($now, $years, true)) {
            array_unshift($years, $now);
        }
        return $years;
    }

    public static function history(int $requestId): array
    {
        return bc_fetch_all(
            'SELECT * FROM bc_request_history WHERE request_id = ? ORDER BY id ASC',
            [$requestId]
        );
    }

    // -----------------------------------------------------------------
    // 생성 / 수정
    // -----------------------------------------------------------------

    /**
     * 신규 구매 요청. 연도별 일련번호를 트랜잭션 안에서 채번한다.
     */
    public static function create(array $data, array $user): int
    {
        $errors = self::validate($data);
        if ($errors) {
            throw new DomainException(implode("\n", $errors));
        }

        return bc_transaction(function () use ($data, $user) {
            $year = (int)date('Y');

            // 동시 요청 시 번호가 겹치지 않도록 같은 해 행을 잠근다.
            $maxSeq = (int)bc_fetch_value(
                'SELECT COALESCE(MAX(req_seq), 0) FROM bc_request WHERE req_year = ? FOR UPDATE',
                [$year], 0
            );
            $seq   = $maxSeq + 1;
            $reqNo = sprintf('%d-%04d', $year, $seq);

            bc_query(
                'INSERT INTO bc_request
                   (req_year, req_seq, req_no, category_id, item_name, quantity, unit,
                    est_amount, ref_url, deliver_to, need_by, note,
                    status, requester_id, requester_name, requested_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, "REQUESTED", ?, ?, NOW())',
                [
                    $year, $seq, $reqNo,
                    (int)$data['category_id'],
                    $data['item_name'],
                    (int)$data['quantity'],
                    $data['unit'] ?: '개',
                    $data['est_amount'] !== null ? (int)$data['est_amount'] : null,
                    bc_safe_url($data['ref_url'] ?? null),
                    $data['deliver_to'] ?: null,
                    $data['need_by'] ?: null,
                    $data['note'] ?: null,
                    $user['id'], $user['name'],
                ]
            );
            $id = (int)bc_db()->lastInsertId();

            self::log($id, 'REQUEST_CREATED', null, 'REQUESTED', $user, null);
            return $id;
        });
    }

    /**
     * 요청자 본인의 수정. 검토 대기 또는 반려 상태에서만 허용.
     */
    public static function updateByOwner(int $id, array $data, array $user): void
    {
        $req = self::find($id);
        if (!$req) {
            throw new DomainException('요청을 찾을 수 없습니다.');
        }
        if ($req['requester_id'] !== $user['id'] && !in_array('ADMIN', bc_roles_of($user['id']), true)) {
            throw new DomainException('본인이 등록한 요청만 수정할 수 있습니다.');
        }
        if (!in_array($req['status'], ['REQUESTED', 'REJECTED'], true)) {
            throw new DomainException('검토 대기 또는 반려 상태에서만 수정할 수 있습니다.');
        }
        // 선택 목록으로 바꾸기 전에 자유 입력으로 저장된 장소는 그대로 두면 통과시킨다.
        // 물품 하나 고치려다 예전 수령 장소 때문에 저장이 막히면 곤란하기 때문.
        $errors = self::validate($data, (string)($req['deliver_to'] ?? ''));
        if ($errors) {
            throw new DomainException(implode("\n", $errors));
        }

        bc_query(
            'UPDATE bc_request SET category_id = ?, item_name = ?, quantity = ?, unit = ?,
                    est_amount = ?, ref_url = ?, deliver_to = ?, need_by = ?, note = ?
              WHERE id = ?',
            [
                (int)$data['category_id'], $data['item_name'], (int)$data['quantity'],
                $data['unit'] ?: '개',
                $data['est_amount'] !== null ? (int)$data['est_amount'] : null,
                bc_safe_url($data['ref_url'] ?? null),
                $data['deliver_to'] ?: null,
                $data['need_by'] ?: null,
                $data['note'] ?: null,
                $id,
            ]
        );
        self::log($id, 'REQUEST_UPDATED', $req['status'], $req['status'], $user, null);
    }

    /**
     * @param string $keepPlace 수정 시 이미 저장돼 있던 수령 장소. 목록 밖 값이어도 허용한다.
     */
    public static function validate(array $d, string $keepPlace = ''): array
    {
        $e = [];
        if (empty($d['category_id']) || !Category::find((int)$d['category_id'])) {
            $e[] = '사용처를 선택하세요.';
        }
        $item = trim((string)($d['item_name'] ?? ''));
        if ($item === '') {
            $e[] = '필요 물품을 입력하세요.';
        } elseif (mb_strlen($item) > 200) {
            $e[] = '필요 물품은 200자 이내로 입력하세요.';
        }
        $qty = (int)($d['quantity'] ?? 0);
        if ($qty < 1 || $qty > 100000) {
            $e[] = '필요 갯수는 1 이상 100,000 이하로 입력하세요.';
        }
        if (!empty($d['need_by']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d['need_by'])) {
            $e[] = '희망 수령일 형식이 올바르지 않습니다.';
        }
        if (!empty($d['ref_url']) && bc_safe_url((string)$d['ref_url']) === null) {
            $e[] = '참고 링크는 http:// 또는 https:// 로 시작해야 합니다.';
        }
        $place = trim((string)($d['deliver_to'] ?? ''));
        if (!bc_is_deliver_place($place) && $place !== $keepPlace) {
            $e[] = '수령 장소는 ' . implode(', ', BC_DELIVER_PLACES) . ' 중에서 고르세요.';
        }
        return $e;
    }

    // -----------------------------------------------------------------
    // 상태 전이
    // -----------------------------------------------------------------

    /**
     * 워크플로 액션 실행. 성공하면 알림까지 발송한다.
     *
     * @param string $action BC_ACTIONS 키
     * @param array  $extra  comment, purchase_note, actual_amount
     */
    public static function transition(int $id, string $action, array $user, array $extra = []): array
    {
        if (!isset(BC_ACTIONS[$action])) {
            throw new DomainException('알 수 없는 처리 동작입니다.');
        }
        $def = BC_ACTIONS[$action];

        $req = self::find($id);
        if (!$req) {
            throw new DomainException('요청을 찾을 수 없습니다.');
        }
        if (!in_array($action, bc_available_actions($req, $user), true)) {
            throw new DomainException('현재 상태에서 처리할 수 없거나 권한이 없습니다.');
        }

        $comment = trim((string)($extra['comment'] ?? ''));
        if ($def['comment'] && $comment === '') {
            throw new DomainException('반려 사유를 입력하세요.');
        }

        if ($action === 'assign' && trim((string)($extra['assignee_id'] ?? '')) === '') {
            throw new DomainException('담당할 구매담당자를 선택하세요.');
        }

        $from = $req['status'];
        $to   = $def['to'] ?? $from;   // assign 처럼 상태를 바꾸지 않는 동작도 있다

        bc_transaction(function () use ($id, $action, $to, $user, $comment, $extra) {
            switch ($action) {
                case 'approve':
                    bc_query(
                        'UPDATE bc_request SET status = ?, reviewer_id = ?, reviewer_name = ?,
                                reviewed_at = NOW(), review_comment = ? WHERE id = ?',
                        [$to, $user['id'], $user['name'], $comment ?: null, $id]
                    );
                    // 승인하면서 구매담당자를 함께 지목할 수 있다(선택).
                    if (!empty($extra['assignee_id'])) {
                        self::setAssignee($id, (string)$extra['assignee_id'], $user);
                    }
                    break;

                case 'assign':
                    self::setAssignee($id, (string)($extra['assignee_id'] ?? ''), $user);
                    break;

                case 'reject':
                    bc_query(
                        'UPDATE bc_request SET status = ?, reviewer_id = ?, reviewer_name = ?,
                                reviewed_at = NOW(), review_comment = ? WHERE id = ?',
                        [$to, $user['id'], $user['name'], $comment, $id]
                    );
                    break;

                case 'start_purchase':
                    bc_query(
                        'UPDATE bc_request SET status = ?, buyer_id = ?, buyer_name = ?,
                                purchasing_at = NOW(), purchase_note = COALESCE(?, purchase_note)
                          WHERE id = ?',
                        [$to, $user['id'], $user['name'], $extra['purchase_note'] ?? null, $id]
                    );
                    break;

                case 'complete':
                    bc_query(
                        'UPDATE bc_request SET status = ?, buyer_id = ?, buyer_name = ?,
                                stocked_at = NOW(),
                                purchasing_at = COALESCE(purchasing_at, NOW()),
                                purchase_note = COALESCE(?, purchase_note),
                                actual_amount = COALESCE(?, actual_amount)
                          WHERE id = ?',
                        [
                            $to, $user['id'], $user['name'],
                            $extra['purchase_note'] ?? null,
                            isset($extra['actual_amount']) && $extra['actual_amount'] !== ''
                                ? (int)$extra['actual_amount'] : null,
                            $id,
                        ]
                    );
                    break;

                case 'resubmit':
                    // 반려 사유는 이력에 남아 있으므로 본문에서는 비운다.
                    bc_query(
                        'UPDATE bc_request SET status = ?, resubmit_count = resubmit_count + 1,
                                reviewer_id = NULL, reviewer_name = NULL, reviewed_at = NULL,
                                review_comment = NULL, requested_at = NOW()
                          WHERE id = ?',
                        [$to, $id]
                    );
                    break;

                case 'cancel':
                    bc_query(
                        'UPDATE bc_request SET status = ?, closed_at = NOW() WHERE id = ?',
                        [$to, $id]
                    );
                    break;
            }
        });

        self::log($id, $def['event'], $from, $to, $user, $comment ?: null);

        $updated = self::find($id);
        Notifier::dispatch($def['event'], $updated, $user, $comment);

        return $updated;
    }

    /**
     * 이 건의 구매담당자를 지정한다.
     * 배정된 구매담당자 중에서만 고를 수 있다. 역할이 없는 사람에게 떠넘기면
     * 그 사람은 화면에서 처리 버튼조차 볼 수 없기 때문.
     */
    public static function setAssignee(int $requestId, string $assigneeId, array $actor): void
    {
        $assigneeId = trim($assigneeId);
        if ($assigneeId === '') {
            // 빈 값이면 담당 해제. 다시 누구나 집어 갈 수 있는 상태가 된다.
            bc_query(
                'UPDATE bc_request SET assignee_id = NULL, assignee_name = NULL,
                        assigned_at = NULL, assigned_by = NULL WHERE id = ?',
                [$requestId]
            );
            return;
        }

        if (!in_array($assigneeId, RoleAssign::idsByType('BUYER'), true)) {
            throw new DomainException('구매담당자로 배정된 사람만 담당으로 지정할 수 있습니다.');
        }

        $member = bc_directory_find($assigneeId);
        bc_query(
            'UPDATE bc_request SET assignee_id = ?, assignee_name = ?,
                    assigned_at = NOW(), assigned_by = ? WHERE id = ?',
            [$assigneeId, $member['name'] ?? $assigneeId, $actor['id'], $requestId]
        );
    }

    public static function log(
        int $requestId, string $event, ?string $from, ?string $to,
        array $actor, ?string $comment
    ): void {
        bc_query(
            'INSERT INTO bc_request_history
               (request_id, event_code, from_status, to_status, actor_id, actor_name, comment)
             VALUES (?,?,?,?,?,?,?)',
            [$requestId, $event, $from, $to, $actor['id'], $actor['name'], $comment]
        );
    }

    // -----------------------------------------------------------------
    // 통계 (관리자 화면)
    // -----------------------------------------------------------------

    public static function statistics(int $year): array
    {
        $byMonth = bc_fetch_all(
            'SELECT MONTH(requested_at) AS m, COUNT(*) AS cnt,
                    SUM(status = "STOCKED") AS done,
                    SUM(COALESCE(actual_amount, 0)) AS amount
               FROM bc_request
              WHERE req_year = ?
              GROUP BY MONTH(requested_at)
              ORDER BY m',
            [$year]
        );

        $byCategory = bc_fetch_all(
            'SELECT c.name, COUNT(*) AS cnt, SUM(COALESCE(r.actual_amount, 0)) AS amount
               FROM bc_request r JOIN bc_category c ON c.id = r.category_id
              WHERE r.req_year = ?
              GROUP BY c.id, c.name
              ORDER BY cnt DESC',
            [$year]
        );

        $byRequester = bc_fetch_all(
            'SELECT requester_name AS name, COUNT(*) AS cnt
               FROM bc_request WHERE req_year = ?
              GROUP BY requester_id, requester_name
              ORDER BY cnt DESC LIMIT 15',
            [$year]
        );

        $topItems = bc_fetch_all(
            'SELECT item_name AS name, COUNT(*) AS cnt, SUM(quantity) AS qty
               FROM bc_request WHERE req_year = ? AND status = "STOCKED"
              GROUP BY item_name
              ORDER BY cnt DESC LIMIT 15',
            [$year]
        );

        $byBuyer = bc_fetch_all(
            'SELECT COALESCE(buyer_name, assignee_name) AS name, COUNT(*) AS cnt,
                    SUM(COALESCE(actual_amount, 0)) AS amount
               FROM bc_request
              WHERE req_year = ? AND status = "STOCKED"
                AND COALESCE(buyer_name, assignee_name) IS NOT NULL
              GROUP BY COALESCE(buyer_name, assignee_name)
              ORDER BY cnt DESC LIMIT 15',
            [$year]
        );

        // 평균 처리 소요(요청 → 구비완료), 시간 단위
        $lead = bc_fetch_one(
            'SELECT
                AVG(TIMESTAMPDIFF(HOUR, requested_at, reviewed_at))  AS review_h,
                AVG(TIMESTAMPDIFF(HOUR, requested_at, stocked_at))   AS total_h,
                COUNT(*) AS n
               FROM bc_request
              WHERE req_year = ? AND status = "STOCKED" AND stocked_at IS NOT NULL',
            [$year]
        );

        return [
            'year'         => $year,
            'status'       => self::statusCounts(['year' => $year]),
            'by_month'     => $byMonth,
            'by_category'  => $byCategory,
            'by_requester' => $byRequester,
            'by_buyer'     => $byBuyer,
            'top_items'    => $topItems,
            'lead_time'    => [
                'review_hours' => $lead && $lead['review_h'] !== null ? round((float)$lead['review_h'], 1) : null,
                'total_hours'  => $lead && $lead['total_h']  !== null ? round((float)$lead['total_h'], 1)  : null,
                'sample'       => (int)($lead['n'] ?? 0),
            ],
        ];
    }
}
