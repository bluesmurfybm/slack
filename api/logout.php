<?php
require_once __DIR__ . '/../auth.php';

$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => '/']);
}
if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
clear_sso_cookie();

// book처럼 다른 오리진의 "로그아웃" 링크는 fetch가 아니라 그냥 <a href>로 여기 들어온다(GET).
// 그런 경우는 JSON을 뿌리지 말고 포털 로그인 화면으로 보내준다. 포털 자체 JS는 POST로 호출한다.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
