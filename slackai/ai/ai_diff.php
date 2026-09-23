<?php
/**
 * 실행 결과 전체 diff (text/plain).  GET ai/ai_diff.php?execution_id=N[&download=1]
 *  패널은 ai_state.php 가 20KB 로 잘라 보낸 diff 를 먼저 보여주고, 잘린 경우 이 주소로 전체를 연다.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';
require_login();
session_release();

$eid = (int)($_GET['execution_id'] ?? 0);
if ($eid <= 0) ai_fail('execution_required', 'execution_id 가 필요합니다.', 400);

$st = db()->prepare("SELECT id, request_id, plan_id, status, diff, files_changed, lines_added, lines_deleted, finished_at
                     FROM ai_executions WHERE id = ?");
$st->execute([$eid]);
$e = $st->fetch();
if (!$e) ai_fail('execution_not_found', '실행 기록이 없습니다: #' . $eid, 404);

$name = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$e['request_id']) . '-exec' . $e['id'] . '.diff';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
if ($e['diff'] === null || $e['diff'] === '') {
    echo "# execution #{$e['id']} ({$e['request_id']}, plan #{$e['plan_id']}, status {$e['status']}) — diff 없음\n";
} else {
    echo $e['diff'];
}
