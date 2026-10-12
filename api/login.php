<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/push.php';    // push_can_receive()
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
    'slack_email' => $row['slack_email'] ?? null,
    'needs_setup' => needs_setup($row),
    'color'       => user_color($row),
    // index.php·api/me.php 와 같은 모양이어야 한다. 화면은 로그인 응답을 그대로 current 로 쓰므로
    // 여기서 빠지면 로그인 직후(홈 화면 앱은 세션이 따로라 늘 이 경로) 마이페이지 알림 칸이 숨는다.
    'push_ok'     => push_can_receive($row['email']),
], JSON_UNESCAPED_UNICODE);
