<?php
/**
 * 사용자별 고정/고정해제. 로그인 필요.
 *  POST { request_id, pin: 1|0 }
 *   - pin=1: 고정 (user_pins 행 추가) → 목록 최상단
 *   - pin=0: 고정 해제 (user_pins 행 삭제)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$user = current_user();
$in   = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$rid = isset($in['request_id']) ? trim($in['request_id']) : '';
$pin = isset($in['pin']) ? (int)$in['pin'] : 1;
if ($rid === '') {
    echo json_encode(['ok' => false, 'error' => 'request_id 필요']);
    exit;
}

try {
    $pdo = db();
    if ($pin) {
        $st = $pdo->prepare("INSERT INTO user_pins (user_id, request_id, pinned_at)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE pinned_at = VALUES(pinned_at)");
        $st->execute([$user['id'], $rid, date('Y-m-d H:i:s')]);
    } else {
        $st = $pdo->prepare("DELETE FROM user_pins WHERE user_id = ? AND request_id = ?");
        $st->execute([$user['id'], $rid]);
    }
    echo json_encode(['ok' => true, 'request_id' => $rid, 'pin' => $pin ? 1 : 0], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
