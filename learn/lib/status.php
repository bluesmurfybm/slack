<?php
/**
 * 요청상태(승인·환급 워크플로) 판정.
 *
 * 요청상태는 저장하지 않고 타임스탬프에서 파생한다. 승인·청구·환급이 각각 별도 시점을
 * 갖기 때문에 컬럼 하나로 들고 있으면 계속 어긋난다.
 */

const S_REQUESTED       = '수강승인요청';
const S_APPROVED        = '수강승인';
const S_REJECTED        = '수강반려';
const S_CLAIMED         = '수강료청구';
const S_CLAIM_APPROVED  = '청구승인';
const S_CLAIM_REJECTED  = '청구반려';
const S_REFUNDED        = '환급완료';   // 유료·개인계정의 종착점
const S_NO_REFUND       = '환급불필요'; // 회사계정의 종착점
const S_NONE            = '';           // 무료 강의는 요청상태 자체가 없다

const ACT_EDIT          = 'edit';
const ACT_APPROVE       = 'approve';
const ACT_REJECT        = 'reject';
const ACT_CLAIM         = 'claim';
const ACT_CLAIM_APPROVE = 'claim_approve';
const ACT_CLAIM_REJECT  = 'claim_reject';
const ACT_REFUND        = 'refund';
const ACT_ATTACH        = 'attach';
const ACT_REVIEW        = 'review';

// 이수 이후 언제든 손댈 수 있는 상태들 — 이수증과 강의평가가 여기에 걸린다
const SETTLED_STATES = [S_NONE, S_APPROVED, S_CLAIMED, S_CLAIM_APPROVED,
                        S_CLAIM_REJECTED, S_REFUNDED, S_NO_REFUND];

const ALLOWED_STATES = [
    ACT_EDIT          => [S_REQUESTED, S_NONE],
    ACT_APPROVE       => [S_REQUESTED],
    ACT_REJECT        => [S_REQUESTED],
    ACT_CLAIM         => [S_APPROVED, S_CLAIM_REJECTED],
    ACT_CLAIM_APPROVE => [S_CLAIMED],
    ACT_CLAIM_REJECT  => [S_CLAIMED],
    ACT_REFUND        => [S_CLAIM_APPROVED],
    ACT_ATTACH        => SETTLED_STATES,
    ACT_REVIEW        => SETTLED_STATES,
];

// 무료 강의는 승인·청구 절차 자체가 없다
const FLOW_ONLY_ACTIONS = [ACT_APPROVE, ACT_REJECT, ACT_CLAIM,
                           ACT_CLAIM_APPROVE, ACT_CLAIM_REJECT, ACT_REFUND];
// 회사계정은 승인까지만 — 환급 절차가 없다
const REFUND_ONLY_ACTIONS = [ACT_CLAIM, ACT_CLAIM_APPROVE, ACT_CLAIM_REJECT, ACT_REFUND];

function learn_derive_status(array $r) {
    if ((int)$r['is_free'])            return S_NONE;
    if ($r['rejected_at'] !== '')      return S_REJECTED;
    if ($r['account_type'] === ACCOUNT_COMPANY) {
        return $r['approved_at'] !== '' ? S_NO_REFUND : S_REQUESTED;
    }
    if ($r['refunded_at'] !== '')      return S_REFUNDED;
    // 재청구가 반려 기록을 지우므로 두 시각을 비교하지 않는다 — 초 단위 저장이라
    // 같은 초에 청구와 반려가 겹치면 비교가 뒤집힌다. 반려 사유는 이력에 남는다.
    if ($r['claim_rejected_at'] !== '') return S_CLAIM_REJECTED;
    if ($r['claim_approved_at'] !== '') return S_CLAIM_APPROVED;
    if ($r['claimed_at'] !== '')        return S_CLAIMED;
    if ($r['approved_at'] !== '')       return S_APPROVED;
    return S_REQUESTED;
}

/** 전제 조건은 전부 여기서 판정한다 — 라우터는 화면 버튼을 감추는 것으로 대신하지 않는다 */
function learn_ensure_transition(array $r, $action) {
    if ((int)$r['is_free'] && in_array($action, FLOW_ONLY_ACTIONS, true)) {
        throw new LearnError('무료 강의는 승인·청구 절차가 없습니다', 409);
    }
    if ($r['account_type'] === ACCOUNT_COMPANY
            && in_array($action, REFUND_ONLY_ACTIONS, true)) {
        throw new LearnError('회사계정 결제 건은 환급 절차가 없습니다', 409);
    }
    $status = learn_derive_status($r);
    if (!in_array($status, ALLOWED_STATES[$action], true)) {
        throw new LearnError("'" . ($status === '' ? '무료' : $status)
                             . "' 상태에서는 할 수 없는 작업입니다", 409);
    }
}

/* ---------- 신청 행 읽기 ---------- */

function learn_fetch_request(PDO $pdo, $rid) {
    $st = $pdo->prepare("SELECT * FROM learn_requests WHERE id=?");
    $st->execute([$rid]);
    $row = $st->fetch();
    if (!$row) throw new LearnError('없는 신청입니다', 404);
    return $row;
}

function learn_is_applicant(array $r, array $identity) {
    return $r['applicant_email'] !== ''
        && strcasecmp($r['applicant_email'], $identity['email']) === 0;
}

function learn_require_applicant(array $r, array $identity, $what) {
    if (!learn_is_applicant($r, $identity)) {
        throw new LearnError("본인만 {$what} 수 있습니다", 403);
    }
}

function learn_require_applicant_or_admin(array $r, array $identity, $what) {
    if (!learn_is_applicant($r, $identity) && !learn_is_admin($identity['email'])) {
        throw new LearnError("본인이나 관리자만 {$what} 수 있습니다", 403);
    }
}

/* ---------- 이력 ---------- */

function learn_record(PDO $pdo, $rid, $status, array $identity, $memo = '') {
    $pdo->prepare("INSERT INTO learn_histories
                   (request_id, status, memo, actor, actor_email, created_at)
                   VALUES (?,?,?,?,?,?)")
        ->execute([$rid, $status, $memo, $identity['name'] ?? '',
                   $identity['email'] ?? '', now_stamp()]);
}

function learn_history(PDO $pdo, $rid) {
    $st = $pdo->prepare("SELECT * FROM learn_histories WHERE request_id=? ORDER BY id");
    $st->execute([$rid]);
    $out = [];
    foreach ($st->fetchAll() as $h) {
        $h['id']         = (int)$h['id'];
        $h['request_id'] = (int)$h['request_id'];
        $out[] = $h;
    }
    return $out;
}

/* ---------- 응답 ---------- */

// PDO 는 숫자 컬럼도 문자열로 준다. 화면은 is_free/archived 를 그대로 참·거짓으로 쓰는데
// JS 에서 "0" 은 참이라 캐스팅을 빠뜨리면 무료 건과 보관 건이 통째로 뒤집힌다.
const REQUEST_INT_COLS = ['id', 'duration_min', 'is_free', 'price',
                          'refund_cap_at_request', 'refund_amount', 'active', 'archived'];

function learn_request_out(array $r, array $cert_names = []) {
    foreach (REQUEST_INT_COLS as $c) $r[$c] = (int)$r[$c];
    $r['rating']    = $r['rating']    === null ? null : (float)$r['rating'];
    $r['recommend'] = $r['recommend'] === null ? null : (float)$r['recommend'];

    $r['status'] = learn_derive_status($r);
    // 환급 예정액은 화면 여러 곳에서 쓰는데, 규칙(신청 시점 상한 스냅샷)을 클라이언트에
    // 베끼면 정책이 바뀔 때 두 곳이 갈라진다 — 서버가 계산해 실어 보낸다.
    $r['expected_refund'] = learn_expected_refund($r);
    $r['cert_count'] = count($cert_names);
    $r['cert_names'] = array_values($cert_names);
    return $r;
}

/** 목록에서 건마다 조회하면 N+1 이 된다 — 한 번에 읽어 request_id 로 묶는다 */
function learn_cert_names_map(PDO $pdo, array $rids = null) {
    if ($rids !== null && !$rids) return [];
    $sql = "SELECT request_id, name FROM learn_certs";
    if ($rids !== null) {
        $sql .= ' WHERE request_id IN (' . implode(',', array_map('intval', $rids)) . ')';
    }
    $map = [];
    foreach ($pdo->query($sql . ' ORDER BY id')->fetchAll() as $c) {
        $map[(int)$c['request_id']][] = $c['name'];
    }
    return $map;
}

function learn_cert_count(PDO $pdo, $rid) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM learn_certs WHERE request_id=?");
    $st->execute([$rid]);
    return (int)$st->fetchColumn();
}
