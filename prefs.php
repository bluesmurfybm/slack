<?php
/**
 * 사용자별 설정(key-value) 저장/조회. 로그인 필요. (브라우저 무관 = DB 저장)
 *   GET  ?key=filter_presets            → { ok, value }  (value = 저장된 JSON, 없으면 null)
 *   POST { key, value }                 → 저장(upsert). value 는 임의 JSON.
 * 허용 키만 저장(오용 방지).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();   // 세션 잠금 즉시 해제

header('Content-Type: application/json; charset=utf-8');

$ALLOWED = ['filter_presets', 'filters', 'view'];   // 저장 허용 키
$user = current_user();

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $key = isset($_GET['key']) ? trim($_GET['key']) : '';
        if (!in_array($key, $ALLOWED, true)) { echo json_encode(['ok' => false, 'error' => 'bad key']); exit; }
        $st = $pdo->prepare("SELECT pref_value FROM user_prefs WHERE user_id = ? AND pref_key = ?");
        $st->execute([$user['id'], $key]);
        $raw = $st->fetchColumn();
        $val = ($raw === false || $raw === null) ? null : json_decode($raw, true);
        echo json_encode(['ok' => true, 'value' => $val], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) { echo json_encode(['ok' => false, 'error' => 'bad body']); exit; }
        $key = isset($in['key']) ? trim($in['key']) : '';
        if (!in_array($key, $ALLOWED, true)) { echo json_encode(['ok' => false, 'error' => 'bad key']); exit; }
        // value 는 임의 JSON → 문자열로 직렬화해 저장
        $value = array_key_exists('value', $in) ? json_encode($in['value'], JSON_UNESCAPED_UNICODE) : null;
        $st = $pdo->prepare("INSERT INTO user_prefs (user_id, pref_key, pref_value, updated_at)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = VALUES(updated_at)");
        $st->execute([$user['id'], $key, $value, date('Y-m-d H:i:s')]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
