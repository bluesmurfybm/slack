<?php
/**
 * 로컬 담당자 배정 API (Slack 미연동, 사이트 자체 저장). 로그인 필요.
 *  GET                                   → { ok, assignments: { request_id: assignee } }
 *  POST {action:'save', items:[{request_id, assignee}]}  → 일괄 upsert (assignee '' 이면 삭제)
 *  POST {action:'clear_all'}             → 전체 배정 삭제
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
$me = current_user();
session_release();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $map = [];
        foreach ($pdo->query("SELECT request_id, assignee FROM local_assignments") as $r) {
            $map[$r['request_id']] = $r['assignee'];
        }
        echo json_encode(['ok' => true, 'assignments' => (object)$map], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    if ($action === 'save') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $up = $pdo->prepare("INSERT INTO local_assignments (request_id, assignee, assigned_at, assigned_by)
                             VALUES (?, ?, NOW(), ?)
                             ON DUPLICATE KEY UPDATE assignee=VALUES(assignee), assigned_at=NOW(), assigned_by=VALUES(assigned_by)");
        $del = $pdo->prepare("DELETE FROM local_assignments WHERE request_id=?");
        $by  = $me['name'] ?? null;
        $n = 0;
        $pdo->beginTransaction();
        foreach ($items as $it) {
            $rid = trim((string)($it['request_id'] ?? ''));
            $asg = trim((string)($it['assignee'] ?? ''));
            if ($rid === '') continue;
            if ($asg === '') { $del->execute([$rid]); }
            else { $up->execute([$rid, $asg, $by]); }
            $n++;
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'saved' => $n]);

    } elseif ($action === 'clear_all') {
        $pdo->exec("DELETE FROM local_assignments");
        echo json_encode(['ok' => true]);

    } else {
        throw new Exception('알 수 없는 action');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
