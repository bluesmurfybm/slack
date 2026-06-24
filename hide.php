<?php
/**
 * 사용자별 숨김/숨김해제. 로그인 필요.
 *  POST { request_id, hide: 1|0 }
 *   - hide=1: 숨김 (user_hides 행 추가) → 기본 목록에서 제외
 *   - hide=0: 숨김 해제 (user_hides 행 삭제)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();   // 세션 잠금 즉시 해제(동시요청 경합 방지)

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$user = current_user();
$in   = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$rid  = isset($in['request_id']) ? trim($in['request_id']) : '';
$hide = isset($in['hide']) ? (int)$in['hide'] : 1;
if ($rid === '') {
    echo json_encode(['ok' => false, 'error' => 'request_id 필요']);
    exit;
}

try {
    $pdo = db();
    if ($hide) {
        $st = $pdo->prepare("INSERT INTO user_hides (user_id, request_id, hidden_at)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE hidden_at = VALUES(hidden_at)");
        $st->execute([$user['id'], $rid, date('Y-m-d H:i:s')]);
    } else {
        $st = $pdo->prepare("DELETE FROM user_hides WHERE user_id = ? AND request_id = ?");
        $st->execute([$user['id'], $rid]);
    }
    echo json_encode(['ok' => true, 'request_id' => $rid, 'hide' => $hide ? 1 : 0], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
