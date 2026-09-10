<?php
/**
 * 환급 정책 판정 — 부분환급(건당 상한), 연간 한도, 연간 건수, 청구 기한.
 *
 * 기본값은 네 기능 모두 꺼짐이고, 꺼져 있으면 검사 자체를 하지 않는다.
 * 정책은 설정이 아니라 데이터다 — config.php 가 아니라 learn_policy 테이블에서만 읽는다.
 */

const POLICY_INT_COLS = ['id',
    'partial_enabled', 'partial_cap',
    'annual_amount_enabled', 'annual_amount_limit',
    'annual_count_enabled', 'annual_count_limit',
    'claim_deadline_enabled', 'claim_deadline_days'];

function learn_policy(PDO $pdo) {
    static $policy = null;
    if ($policy !== null) return $policy;

    $st = $pdo->prepare("SELECT * FROM learn_policy WHERE id=?");
    $st->execute([POLICY_ID]);
    $row = $st->fetch();
    if (!$row) {
        // learn_seed 가 만들어 두지만, 행이 지워진 DB 에서도 판정은 돌아야 한다
        $pdo->prepare("INSERT INTO learn_policy (id, updated_at) VALUES (?,?)")
            ->execute([POLICY_ID, now_stamp()]);
        $st->execute([POLICY_ID]);
        $row = $st->fetch();
    }
    foreach (POLICY_INT_COLS as $c) $row[$c] = (int)$row[$c];
    $policy = $row;
    return $policy;
}

/** 정책을 저장한 뒤 같은 요청 안에서 다시 읽어야 할 때 */
function learn_policy_reload(PDO $pdo) {
    $st = $pdo->prepare("SELECT * FROM learn_policy WHERE id=?");
    $st->execute([POLICY_ID]);
    $row = $st->fetch();
    foreach (POLICY_INT_COLS as $c) $row[$c] = (int)$row[$c];
    return $row;
}

/** 신청 건에 박아 둘 건당 상한. 부분환급이 꺼져 있으면 0(상한 없음). */
function learn_cap_for_new_request(array $policy) {
    return $policy['partial_enabled'] ? $policy['partial_cap'] : 0;
}

function learn_refundable(array $r) {
    return !(int)$r['is_free'] && $r['account_type'] !== ACCOUNT_COMPANY;
}

function learn_compute_refund(array $r) {
    // 상한은 신청 시점 스냅샷을 쓴다 — 관리자가 나중에 상한을 바꿔도 이미 신청한 건이
    // 뒤에서 움직이면 안 된다.
    if (!learn_refundable($r)) return 0;
    $cap = (int)($r['refund_cap_at_request'] ?? 0);
    return $cap ? min((int)$r['price'], $cap) : (int)$r['price'];
}

function learn_expected_refund(array $r) {
    return (int)$r['refund_amount'] ?: learn_compute_refund($r);
}

/** 반려된 건과 무료 건은 세지 않는다. 회사계정은 환급 자체가 없어 한도와 무관하다. */
function learn_year_requests(PDO $pdo, $email, $year) {
    $st = $pdo->prepare("SELECT * FROM learn_requests
                         WHERE applicant_email=? AND is_free=0 AND account_type<>?
                           AND rejected_at='' AND created_at LIKE ?");
    $st->execute([$email, ACCOUNT_COMPANY, $year . '%']);
    return $st->fetchAll();
}

function learn_ensure_within_limits(PDO $pdo, array $policy, $email, array $incoming,
                                    $exclude_id = null) {
    if (!learn_refundable($incoming)) return;
    if (!$policy['annual_count_enabled'] && !$policy['annual_amount_enabled']) return;

    $mine = [];
    foreach (learn_year_requests($pdo, $email, date('Y')) as $r) {
        if ($exclude_id !== null && (int)$r['id'] === (int)$exclude_id) continue;
        $mine[] = $r;
    }

    if ($policy['annual_count_enabled'] && $policy['annual_count_limit']
            && count($mine) + 1 > $policy['annual_count_limit']) {
        throw new LearnError(
            "연간 신청 건수 한도({$policy['annual_count_limit']}건)를 넘습니다. "
            . '올해 ' . count($mine) . '건을 신청했습니다', 409);
    }

    if ($policy['annual_amount_enabled'] && $policy['annual_amount_limit']) {
        $spent = 0;
        foreach ($mine as $r) $spent += learn_expected_refund($r);
        $want = learn_expected_refund($incoming);
        if ($spent + $want > $policy['annual_amount_limit']) {
            $left = max(0, $policy['annual_amount_limit'] - $spent);
            throw new LearnError(
                '연간 환급 한도(' . number_format($policy['annual_amount_limit'])
                . '원)를 넘습니다. 남은 한도는 ' . number_format($left) . '원입니다', 409);
        }
    }
}

function learn_ensure_claim_deadline(array $policy, array $r) {
    if (!$policy['claim_deadline_enabled'] || !$policy['claim_deadline_days']) return;
    if ($r['end_date'] === '') return;
    $deadline = date('Y-m-d',
        strtotime($r['end_date'] . " +{$policy['claim_deadline_days']} days"));
    if (date('Y-m-d') > $deadline) {
        throw new LearnError(
            "청구 기한이 지났습니다. 강의 종료일로부터 {$policy['claim_deadline_days']}일"
            . "({$deadline})까지 청구할 수 있습니다", 409);
    }
}
