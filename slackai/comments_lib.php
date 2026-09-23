<?php
/**
 * 레코드 댓글 스레드 조회 (comments.php 웹 API 와 tools/comments_cli.php 워커 CLI 가 공유).
 *  Slack 호출만 하고 출력/인증은 하지 않는다.
 */
require_once __DIR__ . '/slack_lib.php';

/** 레코드의 생성시각/댓글채널 조회 */
function rec_channel($pdo, $boards, $rid) {
    $st = $pdo->prepare("SELECT created, list_id FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $row = $st->fetch();
    $created = (int)($row['created'] ?? 0);
    $list    = $row['list_id'] ?? '';
    $ch      = $boards[$list]['comment_channel'] ?? '';
    return [$created, $ch];
}

/**
 * 레코드 스레드의 답글(=댓글) 원본 메시지 배열 + 등장 사용자 ID 목록.
 * @return array ['anchor'=>?string, 'messages'=>[...], 'uids'=>[...]]
 */
function slack_thread_messages($tok, $ch, $created, $rid, $maxPages = 20) {
    $anchor = ($created && $ch !== '') ? slackFindRecordThread($tok, $ch, $created, $rid) : null;
    if (!$anchor) return ['anchor' => null, 'messages' => [], 'uids' => []];
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
            if (preg_match_all('/<@([UW][A-Z0-9]+)>/', $m['text'] ?? '', $mm)) $uids = array_merge($uids, $mm[1]);
            foreach (($m['reactions'] ?? []) as $rc) $uids = array_merge($uids, $rc['users'] ?? []);
        }
        $cursor = $r['response_metadata']['next_cursor'] ?? null;
    } while ($cursor && ++$guard < $maxPages);
    return ['anchor' => $anchor, 'messages' => $msgs, 'uids' => $uids];
}

/**
 * 스레드를 "이름: 내용" 평문으로 (LLM 프롬프트용). 멘션은 이름으로 치환, 파일은 [파일: 이름].
 * @return array ['anchor'=>?string, 'count'=>int, 'text'=>string, 'lines'=>[{ts,author,text}]]
 */
function slack_thread_plain($tok, $ch, $created, $rid, $maxMessages = 60) {
    $th = slack_thread_messages($tok, $ch, $created, $rid);
    if (!$th['messages']) return ['anchor' => $th['anchor'], 'count' => 0, 'text' => '', 'lines' => []];
    $names = slackResolveUsers($tok, $th['uids']);
    $lines = []; $out = [];
    foreach (array_slice($th['messages'], 0, $maxMessages) as $m) {
        $uid  = $m['user'] ?? null;
        $who  = $uid && isset($names[$uid]) ? $names[$uid] : ($uid ?: 'Slack');
        $text = (string)($m['text'] ?? '');
        $text = preg_replace_callback('/<@([UW][A-Z0-9]+)>/', fn($mm) => '@' . ($names[$mm[1]] ?? $mm[1]), $text);
        $text = preg_replace('/<(https?:\/\/[^|>]+)\|([^>]+)>/', '$2 ($1)', $text);
        $text = preg_replace('/<(https?:\/\/[^>]+)>/', '$1', $text);
        foreach (($m['files'] ?? []) as $f) $text .= ' [파일: ' . ($f['name'] ?? 'file') . ']';
        $when = date('Y-m-d H:i', (int)floor((float)($m['ts'] ?? 0)));
        $lines[] = ['ts' => $m['ts'] ?? '', 'author' => $who, 'when' => $when, 'text' => trim($text)];
        $out[]   = "[$when] $who: " . trim($text);
    }
    return ['anchor' => $th['anchor'], 'count' => count($th['messages']), 'text' => implode("\n", $out), 'lines' => $lines];
}
