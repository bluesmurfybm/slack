<?php
/**
 * 관리자 명단. 화면에서 바꾸므로 코드가 아니라 learn_admins 가 원본이다.
 * 최고 관리자(OWNER_EMAILS)는 코드에 고정돼 있어 체크를 풀어도 남는다.
 */

function learn_admins_out(array $identity) {
    $admins = learn_admin_emails();
    $owners = array_map('strtolower', OWNER_EMAILS);
    $members = [];
    foreach (learn_members() as $m) {
        $lower = strtolower($m['email']);
        $members[] = $m + ['is_admin' => in_array($lower, $admins, true),
                           'is_owner' => in_array($lower, $owners, true)];
    }
    return ['owners' => OWNER_EMAILS,
            'can_manage' => learn_is_owner($identity['email']),
            'members' => $members];
}

function learn_route_admins(PDO $pdo, array $identity, $method) {
    if ($method === 'GET') {
        learn_require_admin_action($identity);
        jsend(learn_admins_out($identity));
    }
    if ($method !== 'PUT') throw new LearnError('없는 API 입니다', 404);
    if (!learn_is_owner($identity['email'])) {
        throw new LearnError('관리자 명단은 최고 관리자만 바꿀 수 있습니다', 403);
    }

    // 화면의 체크 상태를 그대로 반영한다. 고정 관리자는 빼도 남는다.
    $wanted = [];
    foreach ((array)(body_json()['emails'] ?? []) as $e) {
        $e = strtolower(trim((string)$e));
        if ($e !== '') $wanted[$e] = true;
    }
    $known = [];
    foreach (learn_members() as $m) $known[strtolower($m['email'])] = true;
    $unknown = array_diff(array_keys($wanted), array_keys($known));
    if ($unknown) {
        sort($unknown);
        throw new LearnError('명단에 없는 사람입니다: ' . implode(', ', $unknown), 422);
    }
    foreach (OWNER_EMAILS as $e) $wanted[strtolower($e)] = true;

    $emails = array_keys($wanted);
    $marks  = implode(',', array_fill(0, count($emails), '?'));
    $pdo->prepare("DELETE FROM learn_admins WHERE email NOT IN ($marks)")->execute($emails);
    $ins = $pdo->prepare("INSERT IGNORE INTO learn_admins (email, added_by, created_at)
                          VALUES (?,?,?)");
    $now = now_stamp();
    foreach ($emails as $e) $ins->execute([$e, $identity['email'], $now]);

    // 판정은 캐시된 집합을 보므로 여기서 같이 갈아끼워야 즉시 반영된다
    learn_admin_emails(true);
    jsend(learn_admins_out($identity));
}
