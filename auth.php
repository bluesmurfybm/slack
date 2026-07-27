<?php
/**
 * 포털(blue-iwork) 세션/인증 헬퍼.
 *  - slack 모듈도 PHP 세션을 쓰므로 쿠키명/저장경로를 분리해 서로 겹치지 않게 함.
 */

require_once __DIR__ . '/db.php';

// 로그인 유지(자동로그인): 로그아웃 전까지 30일 슬라이딩 만료 — 방문할 때마다 갱신.
$SESS_LIFETIME = 60 * 60 * 24 * 30;

if (session_status() === PHP_SESSION_NONE) {
    $sessDir = __DIR__ . '/.sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0777, true);
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
    }
    ini_set('session.gc_maxlifetime', $SESS_LIFETIME);
    session_name('BLUEIWORK_SESSID');
    session_set_cookie_params(['lifetime' => $SESS_LIFETIME, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    // 방문 시마다 쿠키 만료 갱신 → "자동 로그인" 체크한 세션만 로그아웃 전까지 유지.
    // 체크 안 했으면(remember=false) 세션쿠키(브라우저 종료 시 만료)로 그대로 둔다.
    if (isset($_COOKIE[session_name()]) && !empty($_SESSION['portal_uid']) && !empty($_SESSION['remember'])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $SESS_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * 로그인 직후 호출: "자동 로그인" 체크 여부에 따라 세션 쿠키 수명을 정한다.
 *  - true  → 30일 슬라이딩 유지(로그아웃 전까지)
 *  - false → 세션 쿠키(브라우저 닫으면 로그아웃)
 */
function set_session_persistence($remember) {
    global $SESS_LIFETIME;
    $_SESSION['remember'] = (bool)$remember;
    setcookie(session_name(), session_id(), [
        'expires'  => $remember ? time() + $SESS_LIFETIME : 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_portal_user() {
    $uid = $_SESSION['portal_uid'] ?? null;
    if (!$uid) return null;
    $stmt = portal_db()->prepare("SELECT * FROM portal_users WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if ($row) refresh_sso_cookie($row['email'], $row['name'], user_color($row));
    return $row ?: null;
}

/** 저장된 고유색이 없으면(신규 계정 등) 이름 해시로 색을 만들어 폴백 */
function user_color($u) {
    if (!empty($u['color'])) return $u['color'];
    $h = 0;
    foreach (preg_split('//u', $u['name'] ?? '?', -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $h = ($h * 31 + mb_ord($ch)) % 360;
    }
    return "hsl($h,58%,52%)";
}

/**
 * 아직 초기 비번(blue$123) 그대로면 true — 대시보드/book/slack 등 어디로도 못 가게 정보수정
 * 페이지에 강제로 묶어둬야 함. 슬랙 토큰 미등록은 별도 조건(need_token) — book은 토큰 없이도
 * 써야 하므로 여기 포함하지 않는다.
 */
function needs_setup($u) {
    return password_verify('blue$123', $u['pw_hash']);
}

/** book 등 다른 언어/프로세스 앱이 검증할 수 있는 공유 시크릿 (git 제외, 최초 실행 시 1회 생성) */
function sso_secret() {
    static $secret = null;
    if ($secret !== null) return $secret;
    $path = __DIR__ . '/sso_secret.key';
    if (!is_file($path)) {
        file_put_contents($path, bin2hex(random_bytes(32)));
    }
    $secret = trim((string)file_get_contents($path));
    return $secret;
}

/** base64url — setcookie()가 값을 urlencode 하므로 +,/,= 가 없는 안전한 문자만 쓴다 */
function b64url_encode($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * book처럼 별도 프로세스/언어로 떠 있는 모듈이 "로그인 사용자 = 이 이메일" 을 신뢰할 수 있도록
 * HMAC 서명 쿠키를 심는다. 같은 호스트(포트만 다름)면 브라우저가 자동으로 같이 보내준다.
 */
function issue_sso_cookie($email, $name, $color = '') {
    global $SESS_LIFETIME;
    // 서명 내부 만료는 항상 넉넉하게(상한선일 뿐) — 실제 유지 여부는 쿠키 자체의 수명이 결정한다.
    $exp     = time() + $SESS_LIFETIME;
    $inner   = $email . "\t" . $name . "\t" . $color . "\t" . $exp;
    $payload = b64url_encode($inner);
    $sig     = hash_hmac('sha256', $payload, sso_secret());
    $cookieExp = !empty($_SESSION['remember']) ? $exp : 0;   // 자동 로그인 아니면 세션쿠키
    setcookie('blueiwork_id', $payload . '.' . $sig, [
        'expires'  => $cookieExp,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_sso_cookie() {
    setcookie('blueiwork_id', '', ['expires' => time() - 3600, 'path' => '/']);
}

/** current_portal_user() 호출 때마다 SSO 쿠키도 같이 슬라이딩 갱신 */
function refresh_sso_cookie($email, $name, $color = '') {
    if (isset($_COOKIE['blueiwork_id'])) {
        issue_sso_cookie($email, $name, $color);
    }
}

/** 로그인 안 돼 있으면 401 JSON 응답 후 종료, 돼 있으면 사용자 행 반환 */
function require_portal_login() {
    $u = current_portal_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}

/** 슬랙 토큰 암호화 (AES-256-GCM). 원문은 DB/응답 어디에도 남기지 않음. */
function enc_token($plain) {
    if ($plain === null || $plain === '') return null;
    $cfg = require __DIR__ . '/config.php';
    $key = base64_decode($cfg['key']);
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function dec_token($enc) {
    if (!$enc) return '';
    $cfg = require __DIR__ . '/config.php';
    $key = base64_decode($cfg['key']);
    $raw = base64_decode($enc);
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}
