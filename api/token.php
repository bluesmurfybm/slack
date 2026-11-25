<?php
/**
 * 내 개인 API 토큰.
 *
 *   GET                       {ok, has, token, created_at, rotated_at, last_used_at, last_used_ip}
 *   POST {"action":"ensure"}  {ok, token} 내 토큰
 *   POST {"action":"rotate"}  {ok, token} 새 값으로 바꾼다. 이전 값은 곧바로 무효
 *   DELETE                    {ok} 폐기
 */
require_once __DIR__ . '/../core/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// 토큰이 새도 그 토큰으로 새 토큰을 받거나 바꿀 수 없어야 한다
$u = require_session_login();
$pdo = portal_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true] + portal_api_token_get($pdo, $u['id']), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    portal_api_token_delete($pdo, $u['id']);
    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = match ($body['action'] ?? '') {
        'ensure' => portal_api_token_ensure($pdo, $u['id']),
        'rotate' => portal_api_token_rotate($pdo, $u['id']),
        default => null,
    };
    if ($token === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '알 수 없는 action'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'token' => $token]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method not allowed']);
