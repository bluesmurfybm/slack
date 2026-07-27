<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$email    = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => '이메일과 비밀번호를 입력하세요'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = portal_db()->prepare("SELECT * FROM portal_users WHERE LOWER(email) = ?");
$stmt->execute([mb_strtolower($email)]);
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['pw_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => '이메일 또는 비밀번호가 올바르지 않습니다'], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['portal_uid'] = $row['id'];
set_session_persistence(!empty($body['remember']));   // "자동 로그인" 체크 여부
issue_sso_cookie($row['email'], $row['name'], user_color($row));
echo json_encode([
    'name'        => $row['name'],
    'email'       => $row['email'],
    'has_token'   => !empty($row['slack_token_enc']),
    'needs_setup' => needs_setup($row),
    'color'       => user_color($row),
], JSON_UNESCAPED_UNICODE);
