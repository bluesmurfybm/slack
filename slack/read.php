<?php
/**
 * 사용자별 읽음/안읽음 처리. 로그인 필요.
 *  POST { request_id, read: 1|0 }
 *   - read=1: 읽음 (reads 행 추가)
 *   - read=0: 안읽음 (reads 행 삭제)
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

// 선택 일괄 처리: ids 배열을 읽음/안읽음으로
if (isset($in['ids']) && is_array($in['ids'])) {
    $read = isset($in['read']) ? (int)$in['read'] : 1;
    $ids  = array_values(array_filter(array_map('strval', $in['ids']), function ($v) { return $v !== ''; }));
    if (!$ids) { echo json_encode(['ok' => true, 'count' => 0]); exit; }
    try {
        $pdo = db();
        if ($read) {
            $uname = $user['name'] ?? null;
            $rows = []; $params = [];
            foreach ($ids as $id) { $rows[] = '(?,?,?,?)'; array_push($params, $user['id'], $uname, $id, date('Y-m-d H:i:s')); }
            $sql = "INSERT INTO user_reads (user_id, user_name, request_id, read_at) VALUES " . implode(',', $rows)
                 . " ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), read_at = VALUES(read_at)";
            $pdo->prepare($sql)->execute($params);
        } else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM user_reads WHERE user_id = ? AND request_id IN ($ph)";
            $pdo->prepare($sql)->execute(array_merge([$user['id']], $ids));
        }
        echo json_encode(['ok' => true, 'count' => count($ids), 'read' => $read ? 1 : 0], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$rid  = isset($in['request_id']) ? trim($in['request_id']) : '';
$read = isset($in['read']) ? (int)$in['read'] : 1;
if ($rid === '') {
    echo json_encode(['ok' => false, 'error' => 'request_id 필요']);
    exit;
}

try {
    $pdo = db();
    if ($read) {
        $st = $pdo->prepare("INSERT INTO user_reads (user_id, user_name, request_id, read_at)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), read_at = VALUES(read_at)");
        $st->execute([$user['id'], $user['name'] ?? null, $rid, date('Y-m-d H:i:s')]);
    } else {
        $st = $pdo->prepare("DELETE FROM user_reads WHERE user_id = ? AND request_id = ?");
        $st->execute([$user['id'], $rid]);
    }
    echo json_encode(['ok' => true, 'request_id' => $rid, 'read' => $read ? 1 : 0], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
