<?php
/**
 * 레코드 댓글 라이브 조회/작성. 로그인 필요.
 *  - 보드별 백엔드 채널(boards.php)에서 레코드 앵커 스레드의 답글(=댓글) 반환.
 *  GET ?request_id=Rec...    POST {request_id, text}
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_once __DIR__ . '/comments_lib.php';   // rec_channel(), slack_thread_messages() — 워커 CLI 와 공유
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');

$boards = require __DIR__ . '/boards.php';
$tok    = current_token();

// ===== 작성 (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $rid  = isset($in['request_id']) ? trim($in['request_id']) : '';
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    $action = isset($in['action']) ? (string)$in['action'] : '';

    // 내 댓글 수정/삭제 (chat.update / chat.delete — 본인 메시지만 가능)
    if ($action === 'edit' || $action === 'delete') {
        $ts = isset($in['ts']) ? trim((string)$in['ts']) : '';
        if ($rid === '' || $ts === '' || !$tok) { echo json_encode(['ok' => false, 'error' => '잘못된 요청']); exit; }
        if ($action === 'edit' && $text === '') { echo json_encode(['ok' => false, 'error' => '내용을 입력하세요.']); exit; }
        try {
            [, $ch] = rec_channel(db(), $boards, $rid);
            if ($ch === '') { echo json_encode(['ok' => false, 'error' => '댓글 채널이 없습니다.']); exit; }
            if ($action === 'edit') {
                $r = slackPost('chat.update', $tok, ['channel' => $ch, 'ts' => $ts, 'text' => $text]);
            } else {
                $r = slackPost('chat.delete', $tok, ['channel' => $ch, 'ts' => $ts]);
            }
            if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        exit;
    }

    $hasFile = !empty($_FILES['file']) && empty($_FILES['file']['error']);
    if ($rid === '' || !$tok || ($text === '' && !$hasFile)) { echo json_encode(['ok' => false, 'error' => '내용을 입력하세요.']); exit; }
    try {
        [$created, $ch] = rec_channel(db(), $boards, $rid);
        if ($ch === '') { echo json_encode(['ok' => false, 'error' => '이 리스트는 댓글 채널이 설정되지 않았습니다.']); exit; }
        $anchor = $created ? slackFindRecordThread($tok, $ch, $created, $rid) : null;
        if (!$anchor) { echo json_encode(['ok' => false, 'error' => '기존 댓글 스레드가 없어 작성할 수 없습니다.']); exit; }
        if ($hasFile) {   // 스레드에 파일 첨부(+텍스트는 코멘트로)
            $up = slackUploadFile($tok, $ch, $_FILES['file'], $text, $anchor);
            if (empty($up['ok'])) { echo json_encode(['ok' => false, 'error' => $up['error'] ?? '업로드 실패']); exit; }
        } else {
            $r = slackPost('chat.postMessage', $tok, ['channel' => $ch, 'thread_ts' => $anchor, 'text' => $text]);
            if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
        }
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

    $th = slack_thread_messages($tok, $ch, $created, $rid);
    if (!$th['anchor']) { echo json_encode(['comments' => []]); exit; }
    $msgs = $th['messages']; $uids = $th['uids'];

    $names = slackResolveUsers($tok, $uids);
    // 멘션(<@U...>)은 치환하지 않고 원형 유지 → 프론트가 파란 멘션 + 프로필 팝업으로 렌더.
    // 이름은 응답의 users 맵으로 함께 전달.
    $fmt = function ($t) { return $t; };
    $comments = [];
    $meId = current_user()['id'] ?? '';
    foreach ($msgs as $m) {
        $uid = $m['user'] ?? null;
        // 이모지 반응: 이름/개수/내가 눌렀는지/누른 사람들(툴팁)
        $reactions = [];
        foreach (($m['reactions'] ?? []) as $rc) {
            $who = [];
            foreach (($rc['users'] ?? []) as $ru) $who[] = $names[$ru] ?? $ru;
            $reactions[] = ['name' => $rc['name'] ?? '', 'count' => (int)($rc['count'] ?? 0),
                            'me' => $meId !== '' && in_array($meId, $rc['users'] ?? [], true),
                            'who' => $who];
        }
        $files = [];
        foreach (($m['files'] ?? []) as $f) {
            $url = $f['url_private'] ?? ''; if ($url === '') continue;
            $files[] = ['name' => $f['name'] ?? 'file', 'is_image' => strpos($f['mimetype'] ?? '', 'image/') === 0,
                        'url' => $url, 'thumb' => $f['thumb_360'] ?? ($f['thumb_480'] ?? ($f['thumb_240'] ?? $url)),
                        'thumb_pdf' => $f['thumb_pdf'] ?? '', 'thumb_video' => $f['thumb_video'] ?? '', 'mp4' => $f['mp4'] ?? ''];
        }
        $comments[] = ['author_name' => $uid && isset($names[$uid]) ? $names[$uid] : ($uid ?: 'Slack'),
                       'body' => $fmt($m['text'] ?? ''), 'created_at' => date('Y-m-d H:i', (int)floor((float)$m['ts'])),
                       'ts' => $m['ts'] ?? '', 'reactions' => $reactions,
                       'mine' => ($uid !== null && $meId !== '' && $uid === $meId),   // 본인 댓글 여부(수정/삭제 노출용)
                       'files' => $files];
    }
    echo json_encode(['comments' => $comments, 'users' => $names], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['comments' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
