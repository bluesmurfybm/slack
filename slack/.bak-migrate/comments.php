<?php
/**
 * 레코드 댓글 라이브 조회. 로그인 필요.
 *  GET ?request_id=Rec...
 *  - 리스트 백엔드 채널(C083TU7F0BZ)에서 레코드 앵커 메시지를 찾아 스레드 답글(=댓글) 반환
 *  - 매 호출마다 Slack 라이브 조회 → 추가/삭제/수정이 항상 반영됨
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();   // 세션 잠금 즉시 해제(느린 Slack 호출 동안 경합 방지)

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
$tok = current_token();               // 읽기/앵커탐색/작성 모두 로그인 사용자 토큰
$ch  = $cfg['comment_channel'] ?? '';

// ===== 댓글 작성 (POST) — 본인 토큰으로 스레드에 답글 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $rid  = isset($in['request_id']) ? trim($in['request_id']) : '';
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    if ($ch === '' || $rid === '' || $text === '') { echo json_encode(['ok' => false, 'error' => '내용을 입력하세요.']); exit; }

    $utok = current_token();
    if (!$utok) { echo json_encode(['ok' => false, 'error' => '개인 토큰 로그인이 필요합니다.']); exit; }

    try {
        $st = db()->prepare("SELECT created FROM requests WHERE id = ?");
        $st->execute([$rid]);
        $created = (int)$st->fetchColumn();
        $anchor  = $created ? slackFindRecordThread($tok, $ch, $created, $rid) : null;
        if (!$anchor) { echo json_encode(['ok' => false, 'error' => '기존 댓글 스레드가 없어 작성할 수 없습니다.']); exit; }

        $r = slackPost('chat.postMessage', $utok, ['channel' => $ch, 'thread_ts' => $anchor, 'text' => $text]);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$rid = isset($_GET['request_id']) ? trim($_GET['request_id']) : '';
if ($ch === '' || $rid === '') { echo json_encode(['comments' => []]); exit; }

try {
    $pdo = db();
    $st = $pdo->prepare("SELECT created FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $created = (int)$st->fetchColumn();
    if (!$created) { echo json_encode(['comments' => []]); exit; }

    // 1) date_created 주변 시간창에서 레코드 앵커 메시지(ts) 찾기
    $anchor = slackFindRecordThread($tok, $ch, $created, $rid);
    if (!$anchor) { echo json_encode(['comments' => []]); exit; }

    // 2) 스레드 답글 수집 (root/시스템 메시지 제외, 페이지네이션)
    $msgs = []; $uids = []; $cursor = null; $guard = 0;
    do {
        $p = ['channel' => $ch, 'ts' => $anchor, 'limit' => 200];
        if ($cursor) $p['cursor'] = $cursor;
        $r = slackGet('conversations.replies', $tok, $p);
        if (empty($r['ok'])) break;
        foreach ($r['messages'] as $m) {
            if (($m['ts'] ?? '') === $anchor) continue;                       // 스레드 루트
            if (($m['subtype'] ?? '') === 'list_record_comment') continue;    // 시스템(댓글추가 알림)
            if (($m['user'] ?? '') === 'USLACKBOT') continue;
            if (trim($m['text'] ?? '') === '' && empty($m['files'])) continue;   // 텍스트 없어도 첨부 있으면 표시
            $msgs[] = $m;
            if (!empty($m['user'])) $uids[] = $m['user'];
            // 멘션 사용자도 실명 변환 위해 수집
            if (preg_match_all('/<@([UW][A-Z0-9]+)>/', $m['text'], $mm)) $uids = array_merge($uids, $mm[1]);
        }
        $cursor = $r['response_metadata']['next_cursor'] ?? null;
    } while ($cursor && ++$guard < 20);

    $names = slackResolveUsers($tok, $uids);

    // 멘션만 실명으로 변환. 링크/서식(*,_,`,<url|label>)은 클라이언트가 렌더
    $fmt = function ($t) use ($names) {
        return preg_replace_callback('/<@([UW][A-Z0-9]+)>/', function ($m) use ($names) {
            return '@' . (isset($names[$m[1]]) ? $names[$m[1]] : $m[1]);
        }, $t);
    };

    $comments = [];
    foreach ($msgs as $m) {
        $uid = $m['user'] ?? null;
        // 첨부 파일(이미지 등) 추출 — 실제 다운로드는 file.php 프록시가 토큰으로 대신 받음
        $files = [];
        foreach (($m['files'] ?? []) as $f) {
            $mime = $f['mimetype'] ?? '';
            $url  = $f['url_private'] ?? '';
            if ($url === '') continue;
            $files[] = [
                'name'     => $f['name'] ?? 'file',
                'is_image' => strpos($mime, 'image/') === 0,
                'url'      => $url,
                'thumb'    => $f['thumb_360'] ?? ($f['thumb_480'] ?? ($f['thumb_240'] ?? $url)),
            ];
        }
        $comments[] = [
            'author_name' => $uid && isset($names[$uid]) ? $names[$uid] : ($uid ?: 'Slack'),
            'body'        => $fmt($m['text'] ?? ''),
            'created_at'  => date('Y-m-d H:i', (int)floor((float)$m['ts'])),
            'files'       => $files,
        ];
    }
    echo json_encode(['comments' => $comments], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['comments' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
