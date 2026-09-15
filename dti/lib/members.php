<?php
/**
 * 구성원 명단. 포털 계정이 곧 명단이다 — 파이썬은 core/config.py 에 하드코딩했지만
 * 그건 magazine 이 포털 MySQL 을 보지 못해서였다. 입·퇴사 때 고칠 곳이 한 군데로 준다.
 */

function dti_members_all(PDO $pdo, array $config): array {
    $out = [];
    foreach ($pdo->query("SELECT name, email FROM portal_users ORDER BY id")->fetchAll() as $row) {
        $out[] = [
            'name' => $row['name'],
            'email' => $row['email'],
            'teams' => dti_teams_of($config, $row['email']),
        ];
    }
    return $out;
}

function dti_members_name_of(array $members, string $email): string {
    foreach ($members as $member) {
        if (strcasecmp($member['email'], $email) === 0) return $member['name'];
    }
    return '';
}

function dti_members_has(array $members, string $email): bool {
    foreach ($members as $member) {
        if (strcasecmp($member['email'], $email) === 0) return true;
    }
    return false;
}
