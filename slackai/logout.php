<?php
require_once __DIR__ . '/auth.php';
// 슬랙/포털이 세션을 공유하므로 여기서 로그아웃하면 포털에서도 로그아웃된다.
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => '/']);
}
if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
clear_sso_cookie();
header('Location: ../index.php');
exit;
