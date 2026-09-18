<?php
/**
 * 로컬 개발용 라우터. PHP 내장 서버에서만 씁니다.
 *
 *   php -S 127.0.0.1:8080 -t . dev/router.php
 *
 * iworks 세션이 없는 로컬에서 로그인 상태를 흉내 냅니다.
 * dev/login.php 에서 사용자를 고르면 세션에 넣어 줍니다.
 *
 * ┌──────────────────────────────────────────────────────────────┐
 * │ 이 파일은 절대 운영 서버에 올리지 마세요.                      │
 * │ 비밀번호 없이 아무 계정으로나 로그인시키는 파일입니다.          │
 * │ 내장 서버가 아니면 아래에서 스스로 실행을 거부합니다.           │
 * └──────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);

// 내장 서버(cli-server)가 아니면 동작하지 않는다. Apache/Nginx 로 열려도 무해.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('dev/router.php 는 PHP 내장 서버에서만 동작합니다.');
}

// 외부에서 접근 못 하게 루프백만 허용
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('로컬에서만 접근할 수 있습니다.');
}

// 포털 안에 들어와 있으면 포털 루트를 문서 루트로 삼아야 한다.
// ../styles/topbar.css, ../api/logout.php 같은 상대경로가 실제로 열려야 하고,
// 세션도 포털 core/auth.php 가 열어야 로그인이 이어진다.
$module = dirname(__DIR__);                 // <...>/bluecart
$portal = dirname($module);                 // <...>/  (포털 루트 후보)
$inPortal = is_file($portal . '/core/auth.php')
         && is_file($portal . '/core/worksystems.php')
         && is_file($portal . '/config.php');

if ($inPortal) {
    // 포털이 세션 이름과 저장 경로를 정한 뒤 세션을 연다. 우리가 먼저 열면 안 된다.
    require_once $portal . '/core/auth.php';
} else {
    session_start();
}

// 실제로 뜬 주소를 프로그램에 알려 준다. 포트가 8080 이 아닐 수 있다.
// 루프백만 허용하는 위쪽 검사를 통과한 뒤라 HTTP_HOST 를 믿어도 된다.
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
if (preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(:\d{1,5})?$/', $host)) {
    define('BC_BASE_URL', 'http://' . $host);
}

// 포털 모드에서는 포털 루트가 문서 루트, 모듈은 /bluecart/ 아래에 있다.
$root = $inPortal ? $portal : $module;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// ---------------------------------------------------------------------
// 개발용 로그인 / 로그아웃
// ---------------------------------------------------------------------
if ($path === '/dev/logout') {
    session_destroy();
    header('Location: /dev/login.php');
    exit;
}

if ($path === '/dev/login.php') {
    if ($inPortal) {
        // 포털이 있으면 포털 로그인 화면을 쓴다. 가짜 로그인을 섞으면
        // 실제 세션 구조와 다른 상태로 시험하게 된다.
        header('Location: /index.php?need_login=bluecart');
        exit;
    }
    require $root . '/dev/login.php';
    return true;
}

// 아직 로그인하지 않았으면 개발용 로그인 화면으로 보낸다.
if (!$inPortal && empty($_SESSION['ss_mb_id'])) {
    // 정적 파일은 그대로 내보낸다 (로그인 화면 스타일)
    $file = $root . $path;
    if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
    header('Location: /dev/login.php');
    exit;
}

// ---------------------------------------------------------------------
// 실제 프로그램으로 넘김
// ---------------------------------------------------------------------

// 내장 서버는 .htaccess 를 읽지 않으므로 여기서 직접 막는다.
// 운영(Apache/Nginx)과 같은 동작을 로컬에서도 보게 하려는 것.
$modulePrefix = $inPortal ? '/' . basename($module) : '';
foreach (['/config/', '/includes/', '/cron/', '/sql/', '/tests/'] as $blocked) {
    if (str_starts_with($path, $modulePrefix . $blocked) || str_starts_with($path, $blocked)) {
        http_response_code(404);
        echo 'not found';
        return true;
    }
}

// /dev/ 아래에서 열어 주는 것은 위에서 처리한 login.php 와 logout 뿐이다.
// 여기까지 왔다는 건 seed_dev.sql 같은 다른 파일을 요청했다는 뜻.
if (str_starts_with($path, $modulePrefix . '/dev/') || str_starts_with($path, '/dev/')) {
    http_response_code(404);
    echo 'not found';
    return true;
}

$file = $root . ($path === '/' ? '/index.php' : $path);
// 디렉터리를 가리키면 index.php 를 찾는다 (예: /bluecart/)
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    $file = rtrim($file, '/') . '/index.php';
}

// 경로 조작 방지
$real = realpath($file);
if ($real === false || !str_starts_with($real, realpath($root) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    echo 'not found';
    return true;
}

if (is_file($real) && str_ends_with($real, '.php')) {
    chdir(dirname($real));
    require $real;
    return true;
}

if (is_file($real)) {
    return false;   // 내장 서버가 정적 파일을 직접 처리
}

http_response_code(404);
echo 'not found';
return true;
