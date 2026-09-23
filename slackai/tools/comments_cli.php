<?php
/**
 * 레코드 댓글 스레드를 평문/JSON 으로 (워커 전용 CLI).
 *  php slackai/tools/comments_cli.php --id Rec… [--max 60]      (SLACK_TOKEN 또는 SLACK_BOT_TOKEN — channels:history 필요)
 *  → stdout JSON {ok, request_id, anchor, count, text, lines:[{ts,author,when,text}]}
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../comments_lib.php';

$o   = getopt('', ['id:', 'max:']);
$rid = trim((string)($o['id'] ?? ''));
$max = max(1, (int)($o['max'] ?? 60));
$tok = getenv('SLACK_TOKEN') ?: (getenv('SLACK_BOT_TOKEN') ?: '');
if ($rid === '') { echo json_encode(['ok' => false, 'error' => 'id 필요']), "\n"; exit(1); }
if ($tok === '') { echo json_encode(['ok' => false, 'error' => 'no_token']), "\n"; exit(1); }

try {
    $boards = require __DIR__ . '/../boards.php';
    [$created, $ch] = rec_channel(db(), $boards, $rid);
    if ($ch === '' || $created === 0) { echo json_encode(['ok' => true, 'request_id' => $rid, 'anchor' => null, 'count' => 0, 'text' => '', 'lines' => []]), "\n"; exit; }
    $th = slack_thread_plain($tok, $ch, $created, $rid, $max);
    echo json_encode(['ok' => true, 'request_id' => $rid] + $th, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
