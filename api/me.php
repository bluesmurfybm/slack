<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

$u = require_portal_login();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'name'        => $u['name'],
        'email'       => $u['email'],
        'has_token'   => !empty($u['slack_token_enc']),
        'needs_setup' => needs_setup($u),
        'color'       => user_color($u),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $vals   = [];

    if (!empty($body['name']))  { $fields[] = 'name=?';  $vals[] = trim($body['name']); }
    if (!empty($body['email'])) { $fields[] = 'email=?'; $vals[] = trim($body['email']); }
    if (!empty($body['password'])) {
        $fields[] = 'pw_hash=?';
        $vals[]   = password_hash($body['password'], PASSWORD_DEFAULT);
    }
    if (isset($body['slack_token']) && trim((string)$body['slack_token']) !== '') {
        $fields[] = 'slack_token_enc=?';
        $vals[]   = enc_token(trim($body['slack_token']));
    }
    if (!empty($body['color'])) {
        $color = trim((string)$body['color']);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            http_response_code(400);
            echo json_encode(['error' => '색상 형식이 올바르지 않습니다 (#rrggbb)'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $fields[] = 'color=?';
        $vals[]   = $color;
    }

    if ($fields) {
        $fields[] = 'updated_at=NOW()';
        $vals[]   = $u['id'];
        try {
            portal_db()->prepare("UPDATE portal_users SET " . implode(', ', $fields) . " WHERE id = ?")
                       ->execute($vals);
        } catch (PDOException $e) {
            http_response_code(409);
            echo json_encode(['error' => '이미 사용 중인 이메일입니다'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = portal_db()->prepare("SELECT * FROM portal_users WHERE id = ?");
        $stmt->execute([$u['id']]);
        $u = $stmt->fetch();
    }

    echo json_encode([
        'name'        => $u['name'],
        'email'       => $u['email'],
        'has_token'   => !empty($u['slack_token_enc']),
        'needs_setup' => needs_setup($u),
        'color'       => user_color($u),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
