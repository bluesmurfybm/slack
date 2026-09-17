<?php
/** 신청 CRUD + 상태 전이. 전제 조건 판정은 전부 lib/status.php 가 한다. */

function learn_route_requests(PDO $pdo, array $identity, array $seg, $method) {
    $rid = isset($seg[1]) ? (int)$seg[1] : 0;

    if (!$rid) {
        if ($method === 'GET')  learn_requests_list($pdo, $identity);
        if ($method === 'POST') learn_requests_create($pdo, $identity);
        throw new LearnError('없는 API 입니다', 404);
    }

    $tail = $seg[2] ?? '';

    if ($tail === 'certs')  learn_route_certs($pdo, $identity, $rid, $seg, $method);
    if ($tail === 'review') learn_route_review($pdo, $identity, $rid, $method);

    if ($tail === '') {
        if ($method === 'GET')    learn_requests_detail($pdo, $rid);
        if ($method === 'PUT')    learn_requests_update($pdo, $identity, $rid);
        if ($method === 'DELETE') learn_requests_delete($pdo, $identity, $rid);
        throw new LearnError('없는 API 입니다', 404);
    }

    if ($method !== 'POST') throw new LearnError('없는 API 입니다', 404);
    learn_requests_action($pdo, $identity, $rid, $tail);
}

/* ---------- 조회 ---------- */

/** 한 건을 응답 모양으로. 이수증 이름과 카탈로그 등급을 함께 붙인다. */
function learn_one_out(PDO $pdo, array $req) {
    $rid = (int)$req['id'];
    return learn_request_out($req, learn_cert_names_map($pdo, [$rid])[$rid] ?? [],
        learn_catalog_grades($pdo)[(int)$req['catalog_id']] ?? []);
}

function learn_requests_list(PDO $pdo, array $identity) {
    $sql = "SELECT * FROM learn_requests";
    // 숨김·보관은 관리자 화면에만 있어야 한다. 목록에서 빼는 판정은 서버가 한다.
    if (!learn_is_admin($identity['email'])) $sql .= " WHERE active=1 AND archived=0";
    $rows   = $pdo->query($sql . " ORDER BY id DESC")->fetchAll();
    $names  = learn_cert_names_map($pdo);
    $grades = learn_catalog_grades($pdo);

    $out = [];
    foreach ($rows as $r) {
        $out[] = learn_request_out($r, $names[(int)$r['id']] ?? [],
            $grades[(int)$r['catalog_id']] ?? []);
    }
    jsend($out);
}

function learn_requests_detail(PDO $pdo, $rid) {
    $req = learn_fetch_request($pdo, $rid);
    $out = learn_one_out($pdo, $req);
    $out['certs']   = learn_cert_listing($pdo, $rid);
    $out['history'] = learn_history($pdo, $rid);
    jsend($out);
}

/* ---------- 등록·수정·삭제 ---------- */

/** 화면이 보낸 값을 검사해 DB 컬럼 배열로. $partial 이면 온 키만 담는다. */
function learn_read_request_body(array $body, $partial) {
    $has = fn($k) => array_key_exists($k, $body) && $body[$k] !== null;
    $v   = [];

    if (!$partial || $has('site')) {
        $v['site'] = want_str($body, 'site', '교육 플랫폼', true);
    }
    if (!$partial || $has('title')) {
        $v['title'] = want_str($body, 'title', '강의명', true);
    }
    foreach (['category_large', 'category_medium'] as $k) {
        if (!$partial || $has($k)) $v[$k] = want_str($body, $k, '분류');
    }
    if (!$partial || $has('level')) {
        $v['level'] = want_one_of($body['level'] ?? '', LEVELS, '학습수준', true);
    }
    if (!$partial || $has('url')) {
        $v['url'] = want_url($body['url'] ?? '', '수강주소');
    }
    if (!$partial || $has('account_type')) {
        $v['account_type'] = want_one_of($body['account_type'] ?? ACCOUNT_PERSONAL,
                                         ACCOUNT_TYPES, '계정 구분');
    }
    if (!$partial || $has('duration_min')) {
        $v['duration_min'] = want_int($body, 'duration_min', '강의 시간');
    }
    if (!$partial || $has('is_free')) {
        $v['is_free'] = to_flag($body['is_free'] ?? false);
    }
    if (!$partial || $has('price')) {
        $v['price'] = want_int($body, 'price', '수강료');
    }
    foreach (['start_date' => '시작일', 'end_date' => '종료일'] as $k => $label) {
        if (!$partial || $has($k)) $v[$k] = want_date($body[$k] ?? '', $label);
    }
    return $v;
}

function learn_ensure_site(PDO $pdo, $name) {
    $st = $pdo->prepare("SELECT active FROM learn_sites WHERE name=?");
    $st->execute([$name]);
    $row = $st->fetch();
    if (!$row || !(int)$row['active']) {
        throw new LearnError('선택할 수 없는 교육 플랫폼입니다', 422);
    }
}

function learn_requests_create(PDO $pdo, array $identity) {
    $body = body_json();
    $v = learn_read_request_body($body, false);
    learn_ensure_site($pdo, $v['site']);
    if ($v['is_free']) $v['price'] = 0;

    $policy = learn_policy($pdo);
    $stamp  = now_stamp();
    $email  = $identity['email'];

    // 추천·필수 강의에서 온 신청이면 그 카탈로그를 따른다. 강의 정보는 클라이언트가 보낸
    // 값이 아니라 카탈로그 쪽을 쓴다 — 화면에서 잠가 두어도 요청은 고쳐 보낼 수 있다.
    $catalog = learn_catalog_for_request($pdo, $identity, (int)($body['catalog_id'] ?? 0));
    if ($catalog) {
        $v = array_merge($v, learn_catalog_fields($catalog));
        $v['catalog_id'] = $catalog['id'];
    }

    // 신청자는 클라이언트가 보낸 값을 쓰지 않는다 — 세션의 신원으로 강제한다
    $v['applicant_email']       = $email;
    $v['applicant']             = learn_email_to_name($email) ?: ($identity['name'] ?? '');
    $v['refund_cap_at_request'] = learn_cap_for_new_request($policy);
    $v['progress']              = PROGRESSES[0];
    $v['progress_at']           = $stamp;
    $v['created_by']            = $email;
    $v['created_at']            = $stamp;

    // 필수 강의는 회사가 시킨 것이라 개인 연간 한도를 소모시키지 않는다.
    // 추천 강의와 직접 신청은 평소대로 검사한다.
    if (!$catalog || $catalog['grade'] !== GRADE_REQUIRED) {
        // 한도를 넘으면 행을 만들지 않는다 — 검사가 INSERT 보다 먼저다
        learn_ensure_within_limits($pdo, $policy, $email,
            $v + ['refund_amount' => 0, 'rejected_at' => '']);
    }

    // 필수 강의는 승인 절차를 두지 않는다 — 회사가 이미 들으라고 지정한 건이다.
    // 무료 건은 요청상태 자체가 없어 도장을 찍어도 의미가 없으므로 건너뛴다.
    $auto = $catalog && $catalog['grade'] === GRADE_REQUIRED && !$v['is_free'];
    if ($auto) $v['approved_at'] = $stamp;

    $cols = array_keys($v);
    $pdo->prepare('INSERT INTO learn_requests (`' . implode('`,`', $cols) . '`) VALUES ('
                  . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($v));
    $rid = (int)$pdo->lastInsertId();

    $req = learn_fetch_request($pdo, $rid);
    if ($auto) {
        learn_record($pdo, $rid, S_REQUESTED, $identity, '필수 강의 신청');
        learn_record($pdo, $rid, S_APPROVED, $identity, '필수 강의라 승인 없이 수강 시작');
    } else {
        learn_record($pdo, $rid, learn_derive_status($req), $identity);
    }
    jsend(learn_one_out($pdo, $req), 201);
}

/** 카탈로그에서 온 신청인지 확인한다. 노출이 끝났거나 내 대상이 아니면 받지 않는다. */
function learn_catalog_for_request(PDO $pdo, array $identity, $cid) {
    if (!$cid) return null;
    $c = learn_catalog_out(learn_fetch_catalog($pdo, $cid));
    if (!learn_is_open($c, date('Y-m-d'))) {
        throw new LearnError('지금은 신청할 수 없는 강의입니다', 409);
    }
    if (!learn_is_target($c, $identity['email'])) {
        throw new LearnError('이 강의의 대상이 아닙니다', 403);
    }
    // 같은 강의를 두 번 신청하면 이수 현황이 중복으로 잡힌다
    $st = $pdo->prepare("SELECT COUNT(*) FROM learn_requests
                         WHERE catalog_id=? AND applicant_email=? AND rejected_at=''");
    $st->execute([$cid, $identity['email']]);
    if ((int)$st->fetchColumn()) throw new LearnError('이미 신청한 강의입니다', 409);
    return $c;
}

/** 강의 정보는 카탈로그가 원본이다 */
function learn_catalog_fields(array $c) {
    return [
        'site' => $c['site'], 'category_large' => $c['category_large'],
        'category_medium' => $c['category_medium'], 'level' => $c['level'],
        'title' => $c['title'], 'url' => $c['url'],
        'duration_min' => $c['duration_min'], 'is_free' => $c['is_free'],
        'price' => $c['is_free'] ? 0 : $c['price'],
    ];
}

function learn_requests_update(PDO $pdo, array $identity, $rid) {
    $req = learn_fetch_request($pdo, $rid);
    learn_require_applicant($req, $identity, '수정할');
    learn_ensure_transition($req, ACT_EDIT);

    $patch = learn_read_request_body(body_json(), true);
    if (isset($patch['site'])) learn_ensure_site($pdo, $patch['site']);

    $after = array_merge($req, $patch);
    if ((int)$after['is_free']) $patch['price'] = $after['price'] = 0;

    learn_ensure_within_limits($pdo, learn_policy($pdo), $identity['email'], $after, $rid);

    learn_update_request($pdo, $rid, $patch);
    jsend(learn_one_out($pdo, learn_fetch_request($pdo, $rid)));
}

function learn_requests_delete(PDO $pdo, array $identity, $rid) {
    $req = learn_fetch_request($pdo, $rid);
    // 관리자는 상태를 가리지 않고 지운다 — 잘못 올라온 건을 치우려면 필요하다.
    // 본인은 아직 승인 전(또는 무료)일 때만.
    if (!learn_is_admin($identity['email'])) {
        learn_require_applicant($req, $identity, '삭제할');
        learn_ensure_transition($req, ACT_EDIT);
    }

    $st = $pdo->prepare("SELECT path FROM learn_certs WHERE request_id=?");
    $st->execute([$rid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $stored) learn_remove_upload($stored);

    $pdo->prepare("DELETE FROM learn_certs WHERE request_id=?")->execute([$rid]);
    $pdo->prepare("DELETE FROM learn_histories WHERE request_id=?")->execute([$rid]);
    $pdo->prepare("DELETE FROM learn_requests WHERE id=?")->execute([$rid]);
    jsend(['ok' => true]);
}

function learn_update_request(PDO $pdo, $rid, array $fields) {
    if (!$fields) return;
    $set = [];
    foreach (array_keys($fields) as $c) $set[] = "`$c`=?";
    $pdo->prepare("UPDATE learn_requests SET " . implode(',', $set) . " WHERE id=?")
        ->execute(array_merge(array_values($fields), [$rid]));
}

/* ---------- 상태 전이 ---------- */

function learn_reason_from_body() {
    $reason = trim((string)(body_json()['reason'] ?? ''));
    if ($reason === '') throw new LearnError('사유를 입력해 주세요', 422);
    return $reason;
}

function learn_requests_action(PDO $pdo, array $identity, $rid, $action) {
    $req    = learn_fetch_request($pdo, $rid);
    $stamp  = now_stamp();
    $fields = [];

    switch ($action) {
        case 'progress':
            learn_require_applicant($req, $identity, '진행상태를 바꿀');
            $progress = want_one_of(body_json()['progress'] ?? '', PROGRESSES, '진행상태');
            $fields = ['progress' => $progress, 'progress_at' => $stamp];
            learn_record($pdo, $rid, "진행상태 {$progress}", $identity);
            break;

        case 'approve':
            learn_require_admin_action($identity);
            learn_ensure_transition($req, ACT_APPROVE);
            $fields = ['approved_at' => $stamp];
            learn_record($pdo, $rid,
                learn_derive_status(array_merge($req, $fields)), $identity);
            break;

        case 'reject':
            learn_require_admin_action($identity);
            learn_ensure_transition($req, ACT_REJECT);
            $reason = learn_reason_from_body();
            $fields = ['rejected_at' => $stamp, 'reject_reason' => $reason];
            learn_record($pdo, $rid, S_REJECTED, $identity, $reason);
            break;

        case 'claim':
            learn_require_applicant($req, $identity, '청구할');
            learn_ensure_transition($req, ACT_CLAIM);
            if (!learn_cert_count($pdo, $rid)) {
                throw new LearnError('이수증을 먼저 등록해 주세요', 409);
            }
            learn_ensure_claim_deadline(learn_policy($pdo), $req);
            $fields = [
                'claimed_at' => $stamp,
                // 재청구는 지난 반려를 덮는다. 사유는 이력에 남으므로 지워도 잃는 게 없다.
                'claim_rejected_at' => '', 'claim_reject_reason' => '',
                // 같은 사실을 두 번 입력시키지 않는다 — 청구했으면 수강은 끝난 것이다
                'progress' => '완료', 'progress_at' => $stamp,
            ];
            learn_record($pdo, $rid, S_CLAIMED, $identity);
            break;

        case 'claim-approve':
            learn_require_admin_action($identity);
            learn_ensure_transition($req, ACT_CLAIM_APPROVE);
            $amount = learn_compute_refund($req);
            $fields = ['claim_approved_at' => $stamp, 'refund_amount' => $amount];
            $memo   = '환급액 ' . number_format($amount) . '원';
            if ($amount < (int)$req['price']) {
                $memo .= ' (수강료 ' . number_format((int)$req['price']) . '원, 건당 상한 '
                       . number_format((int)$req['refund_cap_at_request']) . '원)';
            }
            learn_record($pdo, $rid, S_CLAIM_APPROVED, $identity, $memo);
            break;

        case 'claim-reject':
            learn_require_admin_action($identity);
            learn_ensure_transition($req, ACT_CLAIM_REJECT);
            $reason = learn_reason_from_body();
            $fields = ['claim_rejected_at' => $stamp, 'claim_reject_reason' => $reason];
            learn_record($pdo, $rid, S_CLAIM_REJECTED, $identity, $reason);
            break;

        case 'refund':
            learn_require_admin_action($identity);
            learn_ensure_transition($req, ACT_REFUND);
            $fields = ['refunded_at' => $stamp];
            learn_record($pdo, $rid, S_REFUNDED, $identity);
            break;

        // 상태를 가리지 않고 보관한다 — 목록에서 내리는 것일 뿐 되돌릴 수 있다
        case 'archive':
        case 'unarchive':
            learn_require_admin_action($identity);
            $fields = ['archived' => $action === 'archive' ? 1 : 0];
            break;

        default:
            throw new LearnError('없는 API 입니다', 404);
    }

    learn_update_request($pdo, $rid, $fields);
    jsend(learn_one_out($pdo, learn_fetch_request($pdo, $rid)));
}
