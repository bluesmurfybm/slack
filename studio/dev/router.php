<?php
/** 로컬 개발용 라우터. PHP 내장 서버 전용 — .htaccess 가 막는 경로를 여기서 대신 막는다. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 이 파일은 운영 서버에 올려도 스스로 실행을 거부합니다.             │
   │ 내장 서버(cli-server) + 루프백 접속에서만 동작합니다.              │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('dev/router.php 는 PHP 내장 서버에서만 동작합니다.');
}
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('로컬에서만 접근할 수 있습니다.');
}

$MODULE = dirname(__DIR__);      // <포털>/assign
$PORTAL = dirname($MODULE);      // <포털>

if (!is_file($PORTAL . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("포털 config.php 가 없습니다.\n먼저 실행하세요:  php assign/dev/setup_local.php\n");
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// ---------------------------------------------------------------------
// .htaccess 대신 막기
//
// 내장 서버는 .htaccess 를 읽지 않는다. 운영(Apache)과 같은 결과를 로컬에서도
// 보려면 여기서 직접 404 를 내야 한다. 목록은 assign/.htaccess 와 같아야 한다.
// 한쪽만 고치면 "로컬에선 막히는데 운영에선 뚫리는" 상태가 된다.
// ---------------------------------------------------------------------
$blocked = ['inc', 'sql', 'docs', 'collector', 'var', 'dev'];
foreach ($blocked as $dir) {
    if (preg_match('#^/studio/' . $dir . '(/|$)#', $path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'not found';
        return true;
    }
}
// 포털 쪽 비공개 경로도 같이 막는다(.sessions, sso_secret.key, config.php).
if (preg_match('#^/(\.sessions|config\.php|sso_secret\.key|\.git)(/|$)#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    return true;
}

// ---------------------------------------------------------------------
// 실제 파일로 넘김
// ---------------------------------------------------------------------
$file = $PORTAL . ($path === '/' ? '/index.php' : $path);

// 디렉터리면 index.php (예: /assign/ → /assign/index.php)
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    $file = rtrim($file, '/') . '/index.php';
}

// 경로 조작 방지
$real = realpath($file);
if ($real === false || !str_starts_with($real, realpath($PORTAL) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    return true;
}

if (is_file($real) && str_ends_with($real, '.php')) {
    // 모듈이 __DIR__ 기준 상대경로를 쓰므로 작업 디렉터리를 맞춰 준다.
    chdir(dirname($real));
    require $real;
    return true;
}

if (is_file($real)) {
    return false;   // 정적 파일은 내장 서버가 직접 내보낸다
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'not found';
return true;
