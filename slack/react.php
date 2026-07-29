<?php
/**
 * 댓글 이모지 반응 추가/제거 (Slack reactions.add / reactions.remove). 로그인 필요.
 *  POST { request_id, ts, name, on:true|false }
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }
$boards = require __DIR__ . '/boards.php';
$tok = current_token();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$rid  = trim((string)($in['request_id'] ?? ''));
$ts   = trim((string)($in['ts'] ?? ''));
$name = trim((string)($in['name'] ?? ''));
$on   = !empty($in['on']);
// 이모지 이름: 한글 등 유니코드 커스텀 이름 허용 (예: 확인_중, 넵_blue, 감사합니다)
if ($rid === '' || $ts === '' || $name === '' || !preg_match('/^[\p{L}\p{N}_+\-]+$/u', $name)) {
    echo json_encode(['ok' => false, 'error' => '잘못된 요청']); exit;
}

try {
    $st = db()->prepare("SELECT list_id FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $ch = $boards[$st->fetchColumn() ?: '']['comment_channel'] ?? '';
    if ($ch === '') { echo json_encode(['ok' => false, 'error' => '댓글 채널 없음']); exit; }
    $r = slackPost($on ? 'reactions.add' : 'reactions.remove', $tok,
                   ['channel' => $ch, 'timestamp' => $ts, 'name' => $name]);
    // 이미 누름/이미 없음은 성공으로 간주 (동기화 지연으로 흔함)
    if (empty($r['ok']) && !in_array($r['error'] ?? '', ['already_reacted', 'no_reaction'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit;
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
