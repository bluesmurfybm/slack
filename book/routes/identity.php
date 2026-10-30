<?php

function book_route_identity(array $ctx, array $req): array {
    $identity = $ctx['identity'];
    $email = $identity['email'] ?? null;

    if (($req['seg'][0] ?? '') === 'whoami') {
        return book_json([
            'email' => $email,
            'name' => $identity['name'] ?? null,
            'color' => $identity['color'] ?? null,
            'is_admin' => book_is_admin($ctx['config'], $email),
            'portal_url' => $ctx['config']['portal_url'],
        ]);
    }

    return book_json(book_members($ctx['pdo']));
}

/** 구성원 명단. 포털 계정이 곧 명단이다 — 신청자 뱃지 색도 여기서 온다 */
function book_members(PDO $pdo): array {
    $rows = [];
    foreach ($pdo->query("SELECT name, email, color FROM portal_users ORDER BY id") as $r) {
        $rows[] = ['name' => (string)$r['name'], 'email' => (string)$r['email'], 'color' => (string)($r['color'] ?? '')];
    }
    return $rows;
}
