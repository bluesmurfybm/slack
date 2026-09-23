<?php
/**
 * 레코드 댓글 스레드에 글 남기기 (워커 전용 CLI, ai_settings.post_to_slack=1 일 때만 워커가 호출).
 *  php slackai/tools/post_cli.php --id Rec… --text-file <파일>      (SLACK_TOKEN 또는 SLACK_BOT_TOKEN — chat:write 필요)
 *  → stdout JSON {ok, ts}
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../comments_lib.php';

$o    = getopt('', ['id:', 'text-file:']);
$rid  = trim((string)($o['id'] ?? ''));
$file = (string)($o['text-file'] ?? '');
$tok  = getenv('SLACK_TOKEN') ?: (getenv('SLACK_BOT_TOKEN') ?: '');
if ($rid === '' || $file === '' || !is_file($file)) { echo json_encode(['ok' => false, 'error' => 'id/text-file 필요']), "\n"; exit(1); }
if ($tok === '') { echo json_encode(['ok' => false, 'error' => 'no_token']), "\n"; exit(1); }
$text = trim((string)file_get_contents($file));
if ($text === '') { echo json_encode(['ok' => false, 'error' => 'empty_text']), "\n"; exit(1); }

try {
    $boards = require __DIR__ . '/../boards.php';
    [$created, $ch] = rec_channel(db(), $boards, $rid);
    if ($ch === '' || $created === 0) { echo json_encode(['ok' => false, 'error' => 'no_channel']), "\n"; exit(1); }
    $anchor = slackFindRecordThread($tok, $ch, $created, $rid);
    if (!$anchor) { echo json_encode(['ok' => false, 'error' => 'no_thread']), "\n"; exit(1); }
    $r = slackPost('chat.postMessage', $tok, ['channel' => $ch, 'thread_ts' => $anchor, 'text' => $text]);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]), "\n"; exit(1); }
    echo json_encode(['ok' => true, 'ts' => $r['ts'] ?? null]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
