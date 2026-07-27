<?php
/**
 * 레코드 댓글 라이브 조회/작성. 로그인 필요.
 *  - 보드별 백엔드 채널(boards.php)에서 레코드 앵커 스레드의 답글(=댓글) 반환.
 *  GET ?request_id=Rec...    POST {request_id, text}
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');

$boards = require __DIR__ . '/boards.php';
$tok    = current_token();

/** 레코드의 리스트/생성시각/댓글채널 조회 */
function rec_channel($pdo, $boards, $rid) {
    $st = $pdo->prepare("SELECT created, list_id FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $row = $st->fetch();
    $created = (int)($row['created'] ?? 0);
    $list    = $row['list_id'] ?? '';
    $ch      = $boards[$list]['comment_channel'] ?? '';
    return [$created, $ch];
}

// ===== 작성 (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $rid  = isset($in['request_id']) ? trim($in['request_id']) : '';
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    if ($rid === '' || $text === '' || !$tok) { echo json_encode(['ok' => false, 'error' => '내용을 입력하세요.']); exit; }
    try {
        [$created, $ch] = rec_channel(db(), $boards, $rid);
        if ($ch === '') { echo json_encode(['ok' => false, 'error' => '이 리스트는 댓글 채널이 설정되지 않았습니다.']); exit; }
        $anchor = $created ? slackFindRecordThread($tok, $ch, $created, $rid) : null;
        if (!$anchor) { echo json_encode(['ok' => false, 'error' => '기존 댓글 스레드가 없어 작성할 수 없습니다.']); exit; }
        $r = slackPost('chat.postMessage', $tok, ['channel' => $ch, 'thread_ts' => $anchor, 'text' => $text]);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
    exit;
}

// ===== 조회 (GET) =====
$rid = isset($_GET['request_id']) ? trim($_GET['request_id']) : '';
if ($rid === '') { echo json_encode(['comments' => []]); exit; }

try {
    [$created, $ch] = rec_channel(db(), $boards, $rid);
    if ($ch === '' || $created === 0 || !$tok) { echo json_encode(['comments' => []]); exit; }

    $anchor = slackFindRecordThread($tok, $ch, $created, $rid);
    if (!$anchor) { echo json_encode(['comments' => []]); exit; }

    $msgs = []; $uids = []; $cursor = null; $guard = 0;
    do {
        $p = ['channel' => $ch, 'ts' => $anchor, 'limit' => 200];
        if ($cursor) $p['cursor'] = $cursor;
        $r = slackGet('conversations.replies', $tok, $p);
        if (empty($r['ok'])) break;
        foreach ($r['messages'] as $m) {
            if (($m['ts'] ?? '') === $anchor) continue;
            if (($m['subtype'] ?? '') === 'list_record_comment') continue;
            if (($m['user'] ?? '') === 'USLACKBOT') continue;
            if (trim($m['text'] ?? '') === '' && empty($m['files'])) continue;
            $msgs[] = $m;
            if (!empty($m['user'])) $uids[] = $m['user'];
            if (preg_match_all('/<@([UW][A-Z0-9]+)>/', $m['text'], $mm)) $uids = array_merge($uids, $mm[1]);
        }
        $cursor = $r['response_metadata']['next_cursor'] ?? null;
    } while ($cursor && ++$guard < 20);

    $names = slackResolveUsers($tok, $uids);
    $fmt = function ($t) use ($names) {
        return preg_replace_callback('/<@([UW][A-Z0-9]+)>/', function ($m) use ($names) {
            return '@' . (isset($names[$m[1]]) ? $names[$m[1]] : $m[1]);
        }, $t);
    };
    $comments = [];
    foreach ($msgs as $m) {
        $uid = $m['user'] ?? null;
        $files = [];
        foreach (($m['files'] ?? []) as $f) {
            $url = $f['url_private'] ?? ''; if ($url === '') continue;
            $files[] = ['name' => $f['name'] ?? 'file', 'is_image' => strpos($f['mimetype'] ?? '', 'image/') === 0,
                        'url' => $url, 'thumb' => $f['thumb_360'] ?? ($f['thumb_480'] ?? ($f['thumb_240'] ?? $url)),
                        'thumb_pdf' => $f['thumb_pdf'] ?? '', 'thumb_video' => $f['thumb_video'] ?? '', 'mp4' => $f['mp4'] ?? ''];
        }
        $comments[] = ['author_name' => $uid && isset($names[$uid]) ? $names[$uid] : ($uid ?: 'Slack'),
                       'body' => $fmt($m['text'] ?? ''), 'created_at' => date('Y-m-d H:i', (int)floor((float)$m['ts'])),
                       'files' => $files];
    }
    echo json_encode(['comments' => $comments], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['comments' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
