<?php
/**
 * Slack ID 기반 로그인 세션 헬퍼.
 *  - 인증(authentication)이 아닌 식별(identification): ID만 알면 접속 가능.
 *    사내 내부 도구 전제. 외부 노출 시 비밀번호 추가 권장.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user() {
    return isset($_SESSION['slack_user']) ? $_SESSION['slack_user'] : null;
}

function require_login() {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

/** 로그인한 사용자의 개인 Slack 토큰 (댓글 작성 등 본인 명의 write-back 용) */
function current_token() {
    $u = current_user();
    return $u && isset($u['token']) ? $u['token'] : null;
}

/**
 * 개인 Slack User Token(xoxp-) 으로 로그인.
 *  - auth.test 로 토큰 검증 + 본인 user_id 확보
 *  - 실명은 본인 토큰 users.info 로 보강
 *  - 토큰은 세션에만 저장 → 댓글을 본인 명의로 작성 가능
 * @return array ['ok'=>bool, 'error'=>?string, 'user'=>?array]
 */
function login_with_token($token) {
    $token = trim($token);
    if ($token === '') {
        return ['ok' => false, 'error' => 'Slack 토큰을 입력하세요.'];
    }
    if (strpos($token, 'xoxp-') !== 0) {
        return ['ok' => false, 'error' => '개인 User Token(xoxp-...) 형식이 아닙니다.'];
    }

    require_once __DIR__ . '/slack_lib.php';

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

    $_SESSION['slack_user'] = ['id' => $uid, 'name' => $name, 'token' => $token, 'icon' => $icon];
    return ['ok' => true, 'user' => $_SESSION['slack_user']];
}

function logout() {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
