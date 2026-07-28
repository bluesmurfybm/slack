<?php
/**
 * slack 모듈 인증.
 *  - 더 이상 자체 로그인이 없다. blue-iWorks 포털 로그인을 그대로 쓰고(같은 호스트,
 *    같은 PHP 세션을 공유), 포털 프로필에 저장된 슬랙 토큰을 자동으로 불러와
 *    세션에 캐시한다. 사용자가 슬랙 토큰을 직접 입력하는 화면은 없어졌다.
 */

require_once __DIR__ . '/../auth.php';   // 포털: current_portal_user(), dec_token(), 세션 시작
require_once __DIR__ . '/slack_lib.php';

function current_user() {
    return isset($_SESSION['slack_cache']) ? $_SESSION['slack_cache'] : null;
}

/** 세션 잠금 즉시 해제 (읽기 전용 엔드포인트용) — 포털과 세션을 공유하므로 그대로 위임 */
function session_release() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

/** 로그인한 사용자의 개인 Slack 토큰 (댓글 작성 등 본인 명의 write-back 용) */
function current_token() {
    $u = current_user();
    return $u && isset($u['token']) ? $u['token'] : null;
}

/**
 * 포털 로그인 + 슬랙 토큰 보유를 강제한다.
 *  - 포털 로그인이 없으면 포털 로그인 화면으로.
 *  - 포털 로그인은 있지만 슬랙 토큰을 아직 등록 안 했으면 포털 프로필 화면으로(알림과 함께).
 *  - 둘 다 있으면 토큰을 검증해 슬랙 신원을 세션에 캐시하고 반환한다.
 *
 * slack/ 바로 아래 페이지는 신경 안 써도 됨(기본 '../index.php'로 충분).
 * slack/xxx/ 하위 폴더 페이지는 require_login() 호출 전에 $__bwBase = '../'; 를 정해줘야
 * "../index.php"가 slack/index.php(존재 안 함)로 잘못 풀리지 않는다.
 */
function require_login() {
    $base = $GLOBALS['__bwBase'] ?? '';
    $pu = current_portal_user();
    if (!$pu) {
        header('Location: ' . $base . '../index.php');
        exit;
    }
    if (empty($pu['slack_token_enc'])) {
        header('Location: ' . $base . '../index.php?need_token=1');
        exit;
    }

    $cached = current_user();
    if ($cached && ($cached['portal_email'] ?? null) === $pu['email']) {
        return $cached;
    }

    $token = dec_token($pu['slack_token_enc']);
    $r = bootstrap_slack_identity($token, $pu['email']);
    if (!$r['ok']) {
        header('Location: ' . $base . '../index.php?need_token=1&invalid=1');
        exit;
    }
    return $r['user'];
}

/**
 * 슬랙 토큰 검증(auth.test) + 실명 보강(users.info) 후 세션에 캐시.
 *  - portal_email 을 같이 저장해 계정 전환 시 재검증하도록 함.
 * @return array ['ok'=>bool, 'error'=>?string, 'user'=>?array]
 */
function bootstrap_slack_identity($token, $portalEmail) {
    $token = trim((string)$token);
    if ($token === '' || strpos($token, 'xoxp-') !== 0) {
        return ['ok' => false, 'error' => '유효하지 않은 슬랙 토큰 형식입니다.'];
    }

    $test = slackGet('auth.test', $token, []);
    if (empty($test['ok'])) {
        return ['ok' => false, 'error' => '유효하지 않은 토큰입니다 (' . ($test['error'] ?? 'unknown') . ')'];
    }
    $uid    = $test['user_id'] ?? '';
    $handle = $test['user'] ?? $uid;

    $name = $handle; $icon = null;
    $info = slackGet('users.info', $token, ['user' => $uid]);
    if (!empty($info['ok'])) {
        $p = $info['user']['profile'] ?? [];
        $name = $p['real_name'] ?? ($info['user']['real_name'] ?? $handle);
        $icon = $p['image_72'] ?? ($p['image_48'] ?? null);
    }

    $_SESSION['slack_cache'] = [
        'id' => $uid, 'name' => $name, 'token' => $token, 'icon' => $icon,
        'portal_email' => $portalEmail,
    ];
    return ['ok' => true, 'user' => $_SESSION['slack_cache']];
}
