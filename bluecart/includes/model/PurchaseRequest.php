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

        $rawStatus = (array)($f['status'] ?? []);
        $status    = array_values(array_filter($rawStatus, fn($s) => isset(BC_STATUS[$s])));
        if ($status) {
            $where[] = 'r.status IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
            array_push($args, ...$status);
        } elseif ($rawStatus) {
            // 넘어온 값이 있는데 하나도 안 남았다 = 어떤 상태와도 맞지 않는다.
            // 빈 배열('조건 없음')과 달리 전체가 아니라 0건이어야 한다.
            $where[] = '1 = 0';
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
        // 조회 조건 중 목록에만 걸리고 집계에는 안 걸리는 것이 있으면, 같은 상자
        // 안에서 어떤 것은 위 숫자를 바꾸고 어떤 것은 안 바꾸게 된다.
        // search() 와 같은 조건을 그대로 태워 둘이 항상 같은 모집단을 본다.
        $kw = trim((string)($f['keyword'] ?? ''));
        if ($kw !== '') {
            $where[] = '(item_name LIKE ? OR note LIKE ? OR requester_name LIKE ? OR req_no LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if (!empty($f['from'])) {
            $where[] = 'requested_at >= ?';
            $args[]  = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'requested_at <= ?';
            $args[]  = $f['to'] . ' 23:59:59';
        }
        // 사람 축(담당)도 모집단이므로 집계에 건다. 상태 축은 집계가 나눠
        // 보여 주는 것이라 걸지 않는다 — 걸면 자기 자신을 지운다.
        if (!empty($f['assignee_id'])) {
            if (!empty($f['include_unassigned'])) {
                $where[] = '(assignee_id = ? OR assignee_id IS NULL)';
            } else {
                $where[] = 'assignee_id = ?';
            }
            $args[] = (string)$f['assignee_id'];
        }
        // '내가 처리할 건' 처럼 사람 축이 단계까지 정하는 경우의 모집단 제한.
        $rawIn = $f['status_in'] ?? null;
        if (is_array($rawIn)) {
            $in = array_values(array_filter($rawIn, fn($s) => isset(BC_STATUS[$s])));
            if ($in) {
                $where[] = 'status IN (' . implode(',', array_fill(0, count($in), '?')) . ')';
                array_push($args, ...$in);
            } elseif ($rawIn) {
                $where[] = '1 = 0';
            }
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

    /**
     * 지난 요청에서 물품 후보를 뽑는다 — 작성 폼의 '필요 물품' 칸에서 쓴다.
     *
     * 같은 물품을 여러 번 요청했으면 **가장 최근 한 건**만 올린다. 화면은 그
     * 건의 갯수·사용처·금액을 그대로 폼에 채우므로, 마지막으로 어떻게 적어
     * 올렸는지가 가장 쓸모 있는 값이다.
     *
     * 철회(CANCELED)한 건은 뺀다 — 없던 일이 된 요청을 다시 권할 이유가 없다.
     * 반려 건은 남긴다. 무엇을 어떻게 올렸었는지가 오히려 참고가 되고, 화면에
     * 처리 단계를 함께 찍어 준다.
     *
     * MySQL 5.7 에서도 돌아야 하므로 윈도우 함수를 쓰지 않는다. 물품명으로
     * 묶어 마지막 id 만 고른 뒤(파생 테이블) 그 행을 다시 읽는다.
     *
     * @param array $f keyword, requester_id(이 사람 것을 위로), limit
     */
    public static function suggestItems(array $f): array
    {
        $where = ["status <> 'CANCELED'"];
        $args  = [];

        $kw = trim((string)($f['keyword'] ?? ''));
        if ($kw !== '') {
            // 물품명만 본다. 비고까지 걸면 이름과 상관없는 후보가 섞여 나오는데,
            // 고른 값이 그대로 칸에 들어가는 자리에서는 그게 더 헷갈린다.
            $where[] = "item_name LIKE ? ESCAPE '!'";
            $args[]  = '%' . self::likeEscape($kw) . '%';
        }

        // 내가 올렸던 물품을 위로 올린다. 한 물품에 여러 건이 묶이므로,
        // 그중 하나라도 내 요청이면 내 것으로 친다.
        $me        = (string)($f['requester_id'] ?? '');
        $mineExpr  = $me !== '' ? 'MAX(CASE WHEN requester_id = ? THEN 1 ELSE 0 END)' : '0';
        $groupArgs = $me !== '' ? [$me] : [];

        $limit    = min(20, max(1, (int)($f['limit'] ?? 8)));
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        return bc_fetch_all(
            "SELECT r.*, c.name AS category_name, c.is_active AS category_active,
                    g.uses, g.mine
               FROM (
                    SELECT MAX(id) AS last_id, COUNT(*) AS uses, $mineExpr AS mine
                      FROM bc_request
                      $whereSql
                     GROUP BY item_name
                     ORDER BY mine DESC, last_id DESC
                     LIMIT $limit
                    ) g
               JOIN bc_request  r ON r.id = g.last_id
               JOIN bc_category c ON c.id = r.category_id
              ORDER BY g.mine DESC, r.id DESC",
            array_merge($groupArgs, $args)
        );
    }

    /** LIKE 특수문자를 글자 그대로 찾게 만든다. 질의의 ESCAPE '!' 와 짝이다. */
    private static function likeEscape(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
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
            // 지워진 번호도 함께 본다 — 마지막 건을 지웠다고 그 번호를 다시
            // 내주면, 이미 메일·슬랙으로 나간 번호가 다른 건을 가리키게 된다.
            $maxSeq = max(
                (int)bc_fetch_value(
                    'SELECT COALESCE(MAX(req_seq), 0) FROM bc_request WHERE req_year = ? FOR UPDATE',
                    [$year], 0
                ),
                (int)bc_fetch_value(
                    'SELECT COALESCE(MAX(req_seq), 0) FROM bc_request_deleted WHERE req_year = ? FOR UPDATE',
                    [$year], 0
                )
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
    // 삭제 (관리자)
    // -----------------------------------------------------------------

    /**
     * 요청을 영구 삭제한다.
     *
     * 철회(cancel)와 쓰임이 다르다. 철회는 "하기로 했다가 그만둔 일"이라 목록에
     * 남아야 하는 기록이고, 삭제는 시험용으로 올렸거나 잘못 올려서 애초에
     * 없었어야 할 건을 치우는 일이다. 그래서 BC_ACTIONS 에 넣지 않았다 —
     * 삭제는 프로세스의 한 단계가 아니고, 전이표에 끼워 넣으면 transition()
     * 이 받아 주게 되어 상태 기계가 흐려진다.
     *
     * 지우면 처리 이력과 첨부도 함께 사라진다(FK 의 ON DELETE CASCADE).
     * 대신 지운 시점의 모습을 bc_request_deleted 에 통째로 남긴다 — 무엇을
     * 누가 언제 지웠는지는 화면에서 지울 수 없어야 한다.
     *
     * @param  string $reason 삭제 사유(선택). 삭제 기록에 함께 남는다.
     * @return array  지워진 요청 행
     */
    public static function delete(int $id, array $user, string $reason = ''): array
    {
        if (!in_array('ADMIN', bc_roles_of($user['id']), true)) {
            throw new DomainException('요청 삭제는 관리자만 할 수 있습니다.');
        }

        $req = self::find($id);
        if (!$req) {
            throw new DomainException('요청을 찾을 수 없습니다.');
        }

        $history = self::history($id);
        $files   = bc_fetch_all(
            'SELECT id, orig_name, stored_path, file_size FROM bc_attachment WHERE request_id = ?',
            [$id]
        );

        bc_transaction(function () use ($id, $req, $history, $files, $user, $reason) {
            bc_query(
                'INSERT INTO bc_request_deleted
                   (request_id, req_year, req_seq, req_no, item_name, status,
                    requester_id, requester_name, snapshot, reason,
                    deleted_by, deleted_by_name)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $id,
                    (int)$req['req_year'], (int)$req['req_seq'], $req['req_no'],
                    $req['item_name'], $req['status'],
                    $req['requester_id'], $req['requester_name'],
                    json_encode(
                        ['request' => $req, 'history' => $history, 'attachments' => $files],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    $user['id'], $user['name'],
                ]
            );

            // 아직 못 보낸 알림은 여기서 끊는다. 재발송 배치(cron/notify_retry.php)는
            // 저장해 둔 본문만 보고 보내므로, 그냥 두면 없는 건의 알림이 뒤늦게 나간다.
            bc_query(
                'UPDATE bc_notify_log
                    SET status = "SKIPPED", error_msg = "요청이 삭제되어 발송을 중단했습니다."
                  WHERE request_id = ? AND status IN ("PENDING", "FAILED")',
                [$id]
            );

            // 처리 이력(bc_request_history)과 첨부 행(bc_attachment)은
            // FK 의 ON DELETE CASCADE 로 함께 지워진다.
            bc_query('DELETE FROM bc_request WHERE id = ?', [$id]);
        });

        // 파일은 커밋이 끝난 뒤에 지운다. 트랜잭션이 되돌아가면 DB 행은
        // 살아나지만 이미 지운 파일은 돌아오지 않기 때문.
        $dirs = [];
        foreach ($files as $f) {
            if (is_file($f['stored_path'])) {
                @unlink($f['stored_path']);
            }
            $dirs[dirname($f['stored_path'])] = true;
        }
        // 요청별 폴더가 빈 채로 남지 않게 한다. 비어 있지 않으면 rmdir 이
        // 실패하고 폴더는 그대로 남는다 — 남의 파일을 건드리지 않는다.
        foreach (array_keys($dirs) as $dir) {
            @rmdir($dir);
        }

        return $req;
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
