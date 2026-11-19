<?php
/**
 * 서버 일괄 배포 허용.
 *
 * blue-app 의 deploy-svn-projects 스킬은 여러 대학 운영 서버에 한꺼번에 소스를 올린다.
 * 늘 열려 있으면 위험하므로 두 번 막는다.
 *   1) 포털 관리자가 '배포 가능 사용자' 로 넣은 사람만
 *   2) 그 사람이 직접 '허용' 을 켜 둔 동안에만 — 켠 뒤 DEPLOY_ALLOW_HOURS 시간이 지나면 저절로 꺼진다
 *
 * 스킬은 실행 직전에 api/deploy.php 를 읽어 allowed 가 true 일 때만 진행한다.
 */
require_once __DIR__ . '/board.php';      // BoardError, board_name_of()

/** 허용을 켠 뒤 저절로 꺼지기까지의 시간 */
const DEPLOY_ALLOW_HOURS = 2;

function deploy_now()
{
    return date('Y-m-d H:i:s');
}

function deploy_row($email)
{
    $st = portal_db()->prepare("SELECT * FROM portal_deploy_user WHERE email = ?");
    $st->execute([strtolower(trim((string)$email))]);
    $row = $st->fetch();
    return $row ?: null;
}

function deploy_is_member($email)
{
    return $email && deploy_row($email) !== null;
}

/** 지금 허용 중인가 — 켜져 있고 아직 기한이 남았을 때만 */
function deploy_row_allowed($row)
{
    return $row && (int)$row['enabled'] === 1
        && !empty($row['enabled_until']) && $row['enabled_until'] > deploy_now();
}

/** 한 사람의 상태. 스킬과 화면이 같은 꼴을 받는다. */
function deploy_state($email)
{
    $row     = deploy_row($email);
    $allowed = deploy_row_allowed($row);
    return [
        'member'  => $row !== null,
        'allowed' => $allowed,
        'until'   => $allowed ? $row['enabled_until'] : null,
        'hours'   => DEPLOY_ALLOW_HOURS,
        'now'     => deploy_now(),
    ];
}

function deploy_set_enabled($email, $on)
{
    $email = strtolower(trim((string)$email));
    if (!deploy_is_member($email)) {
        throw new BoardError('일괄 배포 가능 사용자가 아닙니다. 포털 관리자에게 요청하세요', 403);
    }
    $until = $on ? date('Y-m-d H:i:s', time() + DEPLOY_ALLOW_HOURS * 3600) : null;
    portal_db()->prepare("UPDATE portal_deploy_user SET enabled = ?, enabled_until = ?, updated_at = ? WHERE email = ?")
               ->execute([$on ? 1 : 0, $until, deploy_now(), $email]);
}

/** 배포 가능 사용자 명단 + 이름 + 지금 허용 중인지. 포털 관리의 탭에서 쓴다. */
function deploy_user_list()
{
    $rows = portal_db()->query(
        "SELECT d.email, d.enabled, d.enabled_until, d.added_by, d.created_at, u.name
           FROM portal_deploy_user d
           LEFT JOIN portal_users u ON u.email = d.email
          ORDER BY d.id"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $allowed = deploy_row_allowed($r);
        $out[] = [
            'email'   => $r['email'],
            'name'    => $r['name'] ?: '(포털 계정 없음)',
            'allowed' => $allowed,
            'until'   => $allowed ? $r['enabled_until'] : null,
        ];
    }
    return $out;
}

function deploy_add_user($email, $by)
{
    $email = strtolower(trim((string)$email));
    if ($email === '') {
        throw new BoardError('이메일을 입력하세요', 422);
    }
    if (board_name_of($email) === '') {
        throw new BoardError('포털 계정에 없는 이메일입니다', 422);
    }
    if (deploy_is_member($email)) return;
    // 넣기만 해서는 허용되지 않는다. 본인이 직접 켜야 한다.
    portal_db()->prepare("INSERT INTO portal_deploy_user (email, added_by, created_at, enabled) VALUES (?, ?, ?, 0)")
               ->execute([$email, $by, deploy_now()]);
}

function deploy_remove_user($email)
{
    portal_db()->prepare("DELETE FROM portal_deploy_user WHERE email = ?")
               ->execute([strtolower(trim((string)$email))]);
}
