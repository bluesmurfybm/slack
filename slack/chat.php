<?php
/**
 * Slack DM(1:1) 뷰어 — 테스트 페이지. 폴링 방식(서버가 Slack API 실시간 조회).
 *  토큰: 로그인 세션 토큰(current_token) 그대로 사용.
 *  필요한 User token 스코프: im:read, im:history, chat:write, users:read (신규 DM 시작 시 im:write)
 *
 *  GET  ?dm_list=1              → DM 목록 [{channel,user,name}] + self(내 user id)
 *  GET  ?dm_history=<channel>   → 대화 내역 [{user,name,text,ts,mine}]
 *  POST ?dm_send=1 {channel,text} → 메시지 전송(chat.postMessage)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();

$me  = current_user();
$tok = current_token();   // 기존 로그인 세션 토큰 그대로 사용

/* 토큰의 실제 소유자(self) user id — 내 메시지 정렬용. auth.test 캐시(파일). */
function chat_self($tok) {
    static $id = null;
    if ($id !== null) return $id;
    $r = slackGet('auth.test', $tok, []);
    $id = !empty($r['ok']) ? ($r['user_id'] ?? '') : '';
    return $id;
}

/* 여러 DM의 '마지막 메시지'를 병렬(curl_multi)로 한 번에 조회 → [channel => [ts, text]] */
function dm_latest_multi($tok, $channels) {
    $res = [];
    foreach (array_chunk($channels, 20) as $chunk) {   // 동시연결 20개씩
        $mh = curl_multi_init(); $hs = [];
        foreach ($chunk as $ch) {
            $u = 'https://slack.com/api/conversations.history?' . http_build_query(['channel' => $ch, 'limit' => 1]);
            $c = curl_init($u);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok], CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
            curl_multi_add_handle($mh, $c); $hs[$ch] = $c;
        }
        do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.5); } while ($run > 0 && $st == CURLM_OK);
        foreach ($hs as $ch => $c) {
            $j = json_decode(curl_multi_getcontent($c), true);
            $m = $j['messages'][0] ?? null;
            $res[$ch] = ['ts' => (float)($m['ts'] ?? 0), 'text' => (string)($m['text'] ?? '')];
            curl_multi_remove_handle($mh, $c); curl_close($c);
        }
        curl_multi_close($mh);
    }
    return $res;
}
/* 사용자 정보(이름 + 봇/앱 여부) 병렬 조회 + 캐시 → [uid => [name, bot]] */
function dm_user_info($tok, $uids) {
    $cf = sys_get_temp_dir() . '/slack_userinfo_cache.json';
    $cache = is_file($cf) ? (json_decode(@file_get_contents($cf), true) ?: []) : [];
    $missing = array_values(array_unique(array_filter($uids, fn($u) => $u && !isset($cache[$u]))));
    foreach (array_chunk($missing, 20) as $chunk) {
        $mh = curl_multi_init(); $hs = [];
        foreach ($chunk as $u) {
            $url = 'https://slack.com/api/users.info?' . http_build_query(['user' => $u]);
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok], CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
            curl_multi_add_handle($mh, $c); $hs[$u] = $c;
        }
        do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.5); } while ($run > 0 && $st == CURLM_OK);
        foreach ($hs as $u => $c) {
            $j = json_decode(curl_multi_getcontent($c), true);
            $usr = $j['user'] ?? [];
            if (!empty($j['ok']) && $usr) {
                $p = $usr['profile'] ?? [];
                $cache[$u] = [
                    'name' => $p['real_name'] ?? ($usr['real_name'] ?? ($usr['name'] ?? $u)),
                    'bot'  => (!empty($usr['is_bot']) || !empty($usr['is_app_user']) || ($usr['id'] ?? '') === 'USLACKBOT'),
                ];
            } // 실패분은 캐시하지 않음(다음 기회에 재시도)
            curl_multi_remove_handle($mh, $c); curl_close($c);
        }
        curl_multi_close($mh);
    }
    if ($missing) @file_put_contents($cf, json_encode($cache, JSON_UNESCAPED_UNICODE));
    return $cache;
}
/* 미리보기용 텍스트 정리: 멘션·링크 마크업 제거, 한 줄로 */
function dm_preview($t) {
    $t = preg_replace('/<@(U[A-Z0-9]+)>/', '@…', (string)$t);
    $t = preg_replace('/<(https?:\/\/[^|>]+)\|([^>]+)>/', '$2', $t);
    $t = preg_replace('/<(https?:\/\/[^>]+)>/', '$1', $t);
    $t = preg_replace('/\s+/u', ' ', trim($t));
    return mb_substr($t, 0, 60);
}

/* ---------- DM 목록 ---------- */
if (isset($_GET['dm_list'])) {
    header('Content-Type: application/json; charset=utf-8');
    $out = []; $cursor = null; $guard = 0; $userIds = [];
    do {
        $p = ['types' => 'im', 'limit' => 200, 'exclude_archived' => true];
        if ($cursor) $p['cursor'] = $cursor;
        $r = slackGet('conversations.list', $tok, $p);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'fail']); exit; }
        foreach (($r['channels'] ?? []) as $c) {
            if (empty($c['user']) || !empty($c['is_user_deleted'])) continue;
            $out[] = ['channel' => $c['id'], 'user' => $c['user']];
            $userIds[] = $c['user'];
        }
        $cursor = $r['response_metadata']['next_cursor'] ?? null;
    } while ($cursor && ++$guard < 10);
    // 이름 + 봇/앱 여부 → 사람 DM / 앱·봇 DM 분리
    $info = dm_user_info($tok, $userIds);
    $people = []; $bots = [];
    foreach ($out as $o) {
        $o['name'] = $info[$o['user']]['name'] ?? $o['user'];
        $o['bot']  = !empty($info[$o['user']]['bot']);
        if ($o['bot']) $bots[] = $o; else $people[] = $o;
    }
    // 최근순 정렬은 '사람 DM'만 마지막 메시지 조회(호출 최소화 → 정렬 안정). 봇은 이름순.
    $plast = dm_latest_multi($tok, array_column($people, 'channel'));
    foreach ($people as &$o) {
        $l = $plast[$o['channel']] ?? ['ts' => 0, 'text' => ''];
        $o['ts'] = $l['ts']; $o['preview'] = dm_preview($l['text']);
    }
    unset($o);
    usort($people, function ($a, $b) {
        if (($a['ts'] > 0) !== ($b['ts'] > 0)) return $a['ts'] > 0 ? -1 : 1;   // 대화 있는 것 먼저
        if ($a['ts'] != $b['ts']) return $b['ts'] <=> $a['ts'];                 // 최신순
        return strcmp($a['name'], $b['name']);
    });
    foreach ($bots as &$o) { $o['ts'] = 0; $o['preview'] = ''; }
    unset($o);
    usort($bots, fn($a, $b) => strcmp($a['name'], $b['name']));
    echo json_encode(['ok' => true, 'self' => chat_self($tok), 'rows' => $people, 'bots' => $bots], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 대화 내역 ---------- */
if (isset($_GET['dm_history'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ch = trim((string)$_GET['dm_history']);
    if ($ch === '') { echo json_encode(['ok' => false, 'error' => 'channel 필요']); exit; }
    $params = ['channel' => $ch, 'limit' => 50];
    if (!empty($_GET['cursor']))              $params['cursor']    = (string)$_GET['cursor'];   // (미사용 예비) 커서 페이지
    if (isset($_GET['latest']) && $_GET['latest'] !== '') $params['latest'] = (string)$_GET['latest'];   // 이 시각보다 과거 → 위로 스크롤/날짜이동
    if (isset($_GET['oldest']) && $_GET['oldest'] !== '') $params['oldest'] = (string)$_GET['oldest'];   // 이 시각보다 미래 → 아래로 스크롤/날짜창
    if (isset($_GET['inclusive'])) $params['inclusive'] = $_GET['inclusive'] ? 'true' : 'false';         // 경계 ts 포함 여부
    $r = slackGet('conversations.history', $tok, $params);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'fail']); exit; }
    $self = chat_self($tok);
    $msgs = array_reverse($r['messages'] ?? []);          // 최신→과거 응답 → 과거→최신 정렬
    $uids = [];
    foreach ($msgs as $m) if (!empty($m['user'])) $uids[] = $m['user'];
    // 멘션(<@U…>)에 쓰인 사용자도 이름 해석
    foreach ($msgs as $m) if (preg_match_all('/<@(U[A-Z0-9]+)>/', $m['text'] ?? '', $mm)) foreach ($mm[1] as $u) $uids[] = $u;
    $names = slackResolveUsers($tok, $uids);
    $rows = [];
    foreach ($msgs as $m) {
        if (($m['type'] ?? '') !== 'message' || isset($m['subtype'])) {
            // 일반 텍스트 메시지만(입장/파일 등 subtype 은 단순 표시)
        }
        $uid = $m['user'] ?? '';
        $rows[] = [
            'user' => $uid,
            'name' => $names[$uid] ?? ($uid ?: '시스템'),
            'text' => (string)($m['text'] ?? ''),
            'ts'   => $m['ts'] ?? '',
            'mine' => ($uid !== '' && $uid === $self),
            'files'=> array_map(fn($f) => ['name' => $f['name'] ?? '', 'url' => $f['url_private'] ?? '', 'img' => (strpos($f['mimetype'] ?? '', 'image/') === 0)], $m['files'] ?? []),
        ];
    }
    echo json_encode(['ok' => true, 'self' => $self, 'names' => $names, 'rows' => $rows,
        'next_cursor' => ($r['response_metadata']['next_cursor'] ?? ''), 'has_more' => !empty($r['has_more'])], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 메시지 전송 ---------- */
if (isset($_GET['dm_send']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ch = trim((string)($in['channel'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    if ($ch === '' || $text === '') { echo json_encode(['ok' => false, 'error' => 'channel/text 필요']); exit; }
    $r = slackPost('chat.postMessage', $tok, ['channel' => $ch, 'text' => $text]);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'fail']); exit; }
    echo json_encode(['ok' => true, 'ts' => $r['ts'] ?? ''], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 내 메시지 수정: chat.update (본인 메시지만 가능) */
if (isset($_GET['dm_edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ch = trim((string)($in['channel'] ?? ''));
    $ts = trim((string)($in['ts'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    if ($ch === '' || $ts === '' || $text === '') { echo json_encode(['ok' => false, 'error' => 'channel/ts/text 필요']); exit; }
    $r = slackPost('chat.update', $tok, ['channel' => $ch, 'ts' => $ts, 'text' => $text]);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'fail']); exit; }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 내 메시지 삭제: chat.delete (본인 메시지만 가능) */
if (isset($_GET['dm_delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ch = trim((string)($in['channel'] ?? ''));
    $ts = trim((string)($in['ts'] ?? ''));
    if ($ch === '' || $ts === '') { echo json_encode(['ok' => false, 'error' => 'channel/ts 필요']); exit; }
    $r = slackPost('chat.delete', $tok, ['channel' => $ch, 'ts' => $ts]);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'fail']); exit; }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 파일 첨부 전송: files.getUploadURLExternal → 업로드 URL 에 바이트 POST → files.completeUploadExternal (files:write 스코프 필요) */
if (isset($_GET['dm_upload']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $ch      = trim((string)($_POST['channel'] ?? ''));
    $comment = trim((string)($_POST['text'] ?? ''));
    if ($ch === '' || empty($_FILES['file'])) { echo json_encode(['ok' => false, 'error' => 'channel/file 필요']); exit; }
    $up = slackUploadFile($tok, $ch, $_FILES['file'], $comment);
    echo json_encode($up, JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>💬 Slack DM 뷰어 (테스트)</title>
<style>
  :root { --bg:#fff; --bg2:#f6f7f8; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --hint:#8b949e; --info:#0c447c; --info-bg:#e6f1fb; --mine:#d7ecff; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1a1d21; --bg2:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --hint:#6b7177; --info:#85b7eb; --info-bg:#0c2740; --mine:#123a5e; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg2); color:var(--txt); margin:0; height:100vh; display:flex; flex-direction:column; }
  header { display:flex; align-items:center; gap:10px; padding:10px 16px; background:var(--bg); border-bottom:1px solid var(--line); }
  header h1 { font-size:16px; margin:0; font-weight:600; }
  header .who { font-size:12px; color:var(--muted); margin-left:auto; }
  header a { font-size:13px; color:var(--info); text-decoration:none; }
  .main { flex:1; display:flex; min-height:0; }
  /* DM 목록 */
  .list { width:240px; flex:none; border-right:1px solid var(--line); background:var(--bg); overflow-y:auto; }
  .dm { display:flex; align-items:center; gap:9px; padding:10px 14px; cursor:pointer; border-bottom:1px solid var(--line); }
  .dm:hover { background:var(--bg2); }
  .dm.on { background:var(--info-bg); }
  .dm .av { width:30px; height:30px; border-radius:50%; background:var(--info-bg); color:var(--info); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; flex:none; }
  .dm .dmc { min-width:0; flex:1; }
  .dm .dmtop { display:flex; align-items:baseline; gap:6px; }
  .dm .nm { font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; }
  .dm .dmt { font-size:10px; color:var(--hint); flex:none; }
  .dm .dmp { font-size:11px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px; }
  .dm-sec { display:flex; align-items:center; gap:6px; padding:8px 14px; font-size:11px; font-weight:700; color:var(--muted);
            background:var(--bg2); border-top:1px solid var(--line); border-bottom:1px solid var(--line); cursor:pointer; user-select:none; }
  .dm-sec .dm-secn { background:var(--line); color:var(--muted); border-radius:8px; padding:0 6px; font-size:10px; }
  .dm-sec .dm-caret { margin-left:auto; }
  /* 대화 */
  .conv { flex:1; display:flex; flex-direction:column; min-width:0; background:var(--bg2); position:relative; }
  .conv-h { padding:10px 16px; background:var(--bg); border-bottom:1px solid var(--line); font-weight:600; font-size:14px; display:flex; align-items:center; gap:10px; }
  .conv-h #convname { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:none; max-width:40%; }
  .conv-h .cnote { flex:1; font-weight:400; font-size:11px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .conv-h .datepick { margin-left:auto; font:inherit; font-size:12px; font-weight:400; height:30px; padding:0 8px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); cursor:pointer; }
  .daysep { align-self:stretch; display:flex; align-items:center; margin:8px 0; }
  .daysep::before, .daysep::after { content:""; flex:1; height:1px; background:var(--line); }
  .daysep span { padding:2px 12px; margin:0 8px; background:var(--bg); border:1px solid var(--line); border-radius:12px; color:var(--muted); font-size:11px; font-weight:600; white-space:nowrap; }
  .tolatest { position:absolute; right:20px; bottom:74px; z-index:5; height:32px; padding:0 14px; border:none; border-radius:16px; background:var(--info); color:#fff; font-size:12px; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.18); }
  .msgs { flex:1; overflow-y:auto; padding:14px 16px; display:flex; flex-direction:column; gap:8px; }
  .msg { position:relative; max-width:72%; padding:8px 12px; border-radius:12px; background:var(--bg); border:1px solid var(--line); font-size:13px; line-height:1.5; word-break:break-word; }
  .msg.mine { align-self:flex-end; background:var(--mine); border-color:transparent; }
  /* 내 메시지 수정/삭제 툴바(호버) */
  .msg-tools { position:absolute; top:-12px; right:8px; display:none; gap:2px; background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:2px; box-shadow:0 1px 5px rgba(0,0,0,.14); }
  .msg.mine:hover .msg-tools { display:flex; }
  .msg-tools button { border:none; background:none; color:var(--muted); cursor:pointer; padding:3px; border-radius:6px; display:flex; align-items:center; }
  .msg-tools button:hover { background:var(--bg2); color:var(--info); }
  .msg-tools .del:hover { color:#d93025; }
  /* 인라인 편집 */
  .msg-ed { max-width:72%; align-self:flex-end; }
  .msg-ed .me-inp { width:100%; min-width:240px; min-height:64px; border:1px solid var(--info); border-radius:10px; background:var(--bg); color:var(--txt); font:inherit; font-size:13px; padding:7px 10px; resize:vertical; }
  .msg-ed .me-btns { display:flex; gap:6px; justify-content:flex-end; margin-top:6px; }
  .msg-ed .me-btns button { border:none; border-radius:8px; padding:5px 14px; font-size:12px; cursor:pointer; }
  .msg-ed .me-save { background:var(--info); color:#fff; }
  .msg-ed .me-cancel { background:var(--bg2); color:var(--muted); border:1px solid var(--line); }
  .msg .mh { font-size:11px; color:var(--muted); margin-bottom:3px; }
  .msg.mine .mh { text-align:right; }
  .msg a { color:var(--info); }
  .msg .t { color:var(--hint); font-size:10px; margin-left:6px; }
  .msg img.mimg { max-width:220px; max-height:220px; border-radius:8px; margin-top:5px; display:block; border:1px solid var(--line); }
  .empty { margin:auto; color:var(--muted); font-size:13px; }
  /* 입력 */
  .send { display:flex; gap:8px; padding:10px 14px; background:var(--bg); border-top:1px solid var(--line); }
  .send textarea { flex:1; resize:none; height:40px; max-height:120px; border:1px solid var(--line); border-radius:10px; background:var(--bg); color:var(--txt); font:inherit; font-size:13px; padding:9px 12px; }
  .send button#sendbtn { border:none; background:var(--info); color:#fff; border-radius:10px; padding:0 18px; font-size:13px; cursor:pointer; }
  .send button:disabled { opacity:.5; cursor:default; }
  .send .att-btn { flex:none; border:1px solid var(--line); background:var(--bg); color:var(--muted); border-radius:10px; padding:0 12px; font-size:16px; cursor:pointer; }
  /* 첨부 대기(전송 전) 미리보기 */
  .pend { display:flex; flex-wrap:wrap; gap:10px; padding:8px 14px; background:var(--bg); border-top:1px solid var(--line); }
  .pend .pf { position:relative; display:flex; align-items:center; gap:6px; border:1px solid var(--line); border-radius:8px; padding:5px 9px; background:var(--bg2); max-width:190px; }
  .pend .pf img { width:40px; height:40px; object-fit:cover; border-radius:6px; display:block; }
  .pend .pf .pf-ic { font-size:18px; }
  .pend .pf .pf-nm { font-size:12px; color:var(--txt); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .pend .pf .pf-x { position:absolute; top:-7px; right:-7px; width:19px; height:19px; border:none; border-radius:50%; background:#d93025; color:#fff; font-size:11px; line-height:1; cursor:pointer; padding:0; }
  .conv.drag-over::after { content:"파일을 놓아 첨부"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                           background:rgba(12,68,124,.12); border:2px dashed var(--info); color:var(--info); font-size:14px; font-weight:600; z-index:20; pointer-events:none; }
  .err { color:#d93025; font-size:12px; padding:8px 16px; }
</style>
</head>
<body>
<header>
  <h1>💬 DM 뷰어 <span style="font-size:11px;color:var(--muted);font-weight:400">테스트</span></h1>
  <span class="who"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님</span>
  <a href="lists.php">← 목록</a>
</header>
<div class="main">
  <div class="list" id="dmlist"><div class="empty" style="padding:20px">불러오는 중…</div></div>
  <div class="conv">
    <div class="conv-h" id="convh">
      <span id="convname">대화를 선택하세요</span>
      <span id="convnote" class="cnote"></span>
      <input type="date" id="datePick" class="datepick" title="날짜로 이동" style="display:none">
    </div>
    <div class="msgs" id="msgs"><div class="empty">왼쪽에서 대화를 선택하세요.</div></div>
    <button class="tolatest" id="tolatest" style="display:none">최신 메시지로 ↓</button>
    <div id="pend" class="pend" style="display:none"></div>
    <div class="send">
      <button type="button" id="attbtn" class="att-btn" title="파일 첨부" disabled>📎</button>
      <input type="file" id="fileinp" multiple style="display:none">
      <textarea id="inp" placeholder="메시지 입력 (Enter 전송, Shift+Enter 줄바꿈 · 파일은 📎 또는 Ctrl+V/드래그)" disabled></textarea>
      <button id="sendbtn" disabled>보내기</button>
    </div>
  </div>
</div>
<script>
const $ = id => document.getElementById(id);
let SELF = "", curCh = "", NAMES = {}, pollTimer = null, dmCache = [], botCache = [], botsOpen = false;
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escA(s){ return esc(s).replace(/"/g,"&quot;"); }
let editingTs = "";   // 현재 인라인 수정 중인 메시지 ts
const ICON = {   // 이모지 대신 SVG (currentColor 상속)
  edit:  '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>',
  trash: '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>',
};
function initial(n){ return (n||"?").trim().slice(0,1) || "?"; }
function fmtTs(ts){ const d=new Date(parseFloat(ts)*1000); const p=n=>n<10?"0"+n:n; return p(d.getHours())+":"+p(d.getMinutes()); }
function relTime(ts){                                    // 상대 시간(목록용)
  const s=Math.floor(Date.now()/1000 - parseFloat(ts));
  if(s<60) return "방금"; if(s<3600) return Math.floor(s/60)+"분";
  if(s<86400) return Math.floor(s/3600)+"시간"; if(s<172800) return "어제";
  if(s<604800) return Math.floor(s/86400)+"일";
  const d=new Date(parseFloat(ts)*1000), p=n=>n<10?"0"+n:n; return (d.getMonth()+1)+"."+p(d.getDate());
}
/* Slack 텍스트 → HTML: 모든 링크를 esc 전에 토큰화(이중처리 방지) */
function mrkdwn(t){
  t=(t||"");
  const S="\u0001";
  const L=[]; const push=(u,l)=>{ L.push([u,l]); return S+"L"+(L.length-1)+S; };
  t=t.replace(/<(https?:\/\/[^|>]+)\|([^>]+)>/g,(m,u,l)=>push(u,l));
  t=t.replace(/<(https?:\/\/[^>]+)>/g,(m,u)=>push(u,u));
  t=t.replace(/https?:\/\/[^\s<>]+/g,(u)=>push(u,u));
  t=t.replace(/<@(U[A-Z0-9]+)>/g,(m,u)=>S+"M"+u+S);
  t=esc(t).replace(/&amp;(amp|lt|gt|quot|#39);/g,"&$1;");
  t=t.replace(new RegExp(S+"L(\\d+)"+S,"g"),(m,i)=>{ const a=L[+i]; return '<a href="'+escA(a[0])+'" target="_blank" rel="noopener">'+esc(a[1])+'</a>'; });
  t=t.replace(new RegExp(S+"M(U[A-Z0-9]+)"+S,"g"),(m,u)=>"@"+esc(NAMES[u]||u));
  return t.replace(/\n/g,"<br>");
}
async function loadDMs(){
  try{
    const j = await (await fetch("?dm_list=1",{cache:"no-store"})).json();
    if(!j.ok){ $("dmlist").innerHTML = `<div class="err">DM 목록 오류: ${esc(j.error)}${j.error==="missing_scope"?"<br>(im:read 스코프 필요)":""}</div>`; return; }
    SELF = j.self || ""; dmCache = j.rows || []; botCache = j.bots || [];
    renderDMs();
  }catch(e){ $("dmlist").innerHTML = '<div class="err">DM 목록을 불러오지 못했습니다</div>'; }
}
function dmRow(d){
  return `<div class="dm${d.channel===curCh?' on':''}" data-ch="${escA(d.channel)}" data-nm="${escA(d.name)}">
      <div class="av">${esc(initial(d.name))}</div>
      <div class="dmc"><div class="dmtop"><span class="nm">${esc(d.name)}</span><span class="dmt">${d.ts?relTime(d.ts):""}</span></div>
        ${d.preview?`<div class="dmp">${esc(d.preview)}</div>`:""}</div></div>`;
}
function renderDMs(){
  const box = $("dmlist");
  if(!dmCache.length && !botCache.length){ box.innerHTML = '<div class="empty" style="padding:20px">DM이 없습니다.</div>'; return; }
  let html = dmCache.length ? dmCache.map(dmRow).join("") : '<div class="empty" style="padding:14px;font-size:12px">대화가 없습니다.</div>';
  if(botCache.length){
    html += `<div class="dm-sec" id="botsec">앱 · 봇 <span class="dm-secn">${botCache.length}</span><span class="dm-caret">${botsOpen?"▾":"▸"}</span></div>`;
    if(botsOpen) html += botCache.map(dmRow).join("");
  }
  box.innerHTML = html;
  box.querySelectorAll(".dm").forEach(el=>el.addEventListener("click",()=>openDM(el.dataset.ch, el.dataset.nm)));
  const sec = $("botsec"); if(sec) sec.addEventListener("click",()=>{ botsOpen=!botsOpen; renderDMs(); });
}
let curMsgs=[], seenTs=new Set(),
    hasMoreOlder=false, loadingOlder=false,   // 위(과거) 방향
    atLive=true, hasNewerGap=false, loadingNewer=false;   // 아래(미래) 방향 / 현재 최신에 붙어있는지
function topTs(){ return curMsgs.length ? curMsgs[0].ts : ""; }
function botTs(){ return curMsgs.length ? curMsgs[curMsgs.length-1].ts : ""; }
function todayStr(){ const d=new Date(),p=n=>n<10?"0"+n:n; return d.getFullYear()+"-"+p(d.getMonth()+1)+"-"+p(d.getDate()); }
function dayKey(ts){ const d=new Date(parseFloat(ts)*1000); return d.getFullYear()+"-"+(d.getMonth()+1)+"-"+d.getDate(); }
function dayLabel(ts){
  const d=new Date(parseFloat(ts)*1000), now=new Date(), y=new Date(now); y.setDate(y.getDate()-1);
  const k=dayKey(ts);
  if(k===dayKey(now.getTime()/1000)) return "오늘";
  if(k===dayKey(y.getTime()/1000))   return "어제";
  const w=["일","월","화","수","목","금","토"][d.getDay()];
  return d.getFullYear()+"년 "+(d.getMonth()+1)+"월 "+d.getDate()+"일 ("+w+")";
}
function mergeNames(j){ SELF=j.self||SELF; NAMES=Object.assign(NAMES,j.names||{}); }
/* dm_history 공통 fetch: {latest,oldest,inclusive} 시간창 */
async function fetchHist(ch, opt){
  const q=new URLSearchParams({dm_history:ch});
  if(opt.latest!=null) q.set("latest", String(opt.latest));
  if(opt.oldest!=null) q.set("oldest", String(opt.oldest));
  if(opt.inclusive!=null) q.set("inclusive", opt.inclusive?"1":"0");
  try{ return await (await fetch("?"+q.toString(),{cache:"no-store"})).json(); }catch(e){ return null; }
}
function msgHtml(m){
  if(m.ts===editingTs){   // 인라인 수정 모드
    return `<div class="msg mine msg-ed" data-ts="${escA(m.ts)}">
      <textarea class="me-inp">${esc(m.text)}</textarea>
      <div class="me-btns"><button type="button" class="me-cancel" data-ts="${escA(m.ts)}">취소</button><button type="button" class="me-save" data-ts="${escA(m.ts)}">저장</button></div>
    </div>`;
  }
  return `<div class="msg${m.mine?' mine':''}" data-ts="${escA(m.ts)}">
    ${m.mine?`<div class="msg-tools"><button type="button" class="msg-edit" data-ts="${escA(m.ts)}" title="수정">${ICON.edit}</button><button type="button" class="msg-del del" data-ts="${escA(m.ts)}" title="삭제">${ICON.trash}</button></div>`:""}
    <div class="mh">${esc(m.name)}<span class="t">${fmtTs(m.ts)}</span></div>
    <div class="mb">${mrkdwn(m.text)}</div>
    ${(m.files||[]).map(f=>f.img&&f.url?`<img class="mimg" src="file.php?u=${encodeURIComponent(f.url)}" alt="${escA(f.name)}" loading="lazy">`:(f.url?`<a href="file.php?u=${encodeURIComponent(f.url)}&dl=1&name=${encodeURIComponent(f.name)}">📎 ${esc(f.name)}</a>`:"")).join("")}
  </div>`;
}
function renderMsgs(){
  const box=$("msgs");
  if(!curMsgs.length){ box.innerHTML='<div class="empty">메시지가 없습니다.</div>'; return; }
  let html="", lastDay="";
  for(const m of curMsgs){
    const k=dayKey(m.ts);
    if(k!==lastDay){ html+=`<div class="daysep"><span>${esc(dayLabel(m.ts))}</span></div>`; lastDay=k; }   // 날짜 구분선
    html+=msgHtml(m);
  }
  box.innerHTML=html;
}
function updateNewerBtn(){ $("tolatest").style.display = atLive ? "none" : ""; }
async function openDM(ch, nm){
  curCh = ch; renderDMs();
  $("convname").textContent = nm; $("convnote").textContent="";
  const dp=$("datePick"); dp.style.display=""; dp.max=todayStr(); dp.value="";
  $("inp").disabled = false; $("sendbtn").disabled = false; $("attbtn").disabled = false; $("inp").focus();
  pendFiles=[]; renderPend();   // 첨부 대기 초기화
  editingTs="";
  curMsgs=[]; seenTs=new Set(); hasMoreOlder=false; atLive=true; hasNewerGap=false;   // 채널 전환 초기화
  $("msgs").innerHTML = '<div class="empty">불러오는 중…</div>';
  await loadLatest(true, true);
  updateNewerBtn(); startPoll();
}
/* 최신 창 로드(초기/폴링): 새 메시지만 병합(append) — 기존 로드분 유지 */
async function loadLatest(scrollEnd, isInit){
  if(!curCh) return;
  const ch=curCh;
  const j = await fetchHist(ch, {});   // 커서 없음 = 최신 50
  if(ch!==curCh) return;
  if(!j || !j.ok){ if(!curMsgs.length) $("msgs").innerHTML = `<div class="err">대화 오류: ${esc(j&&j.error)}${(j&&j.error==="missing_scope")?"<br>(im:history 스코프 필요)":""}</div>`; return; }
  mergeNames(j);
  const box=$("msgs");
  const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
  let added=false;
  (j.rows||[]).forEach(m=>{ if(!seenTs.has(m.ts)){ seenTs.add(m.ts); curMsgs.push(m); added=true; } });
  if(isInit){ hasMoreOlder=!!j.has_more; atLive=true; }
  if(added || scrollEnd){ curMsgs.sort((a,b)=>parseFloat(a.ts)-parseFloat(b.ts)); renderMsgs(); }
  if(scrollEnd || atBottom) box.scrollTop = box.scrollHeight;
}
/* 위로 스크롤 → 과거(topTs 이전) 이어 불러오기(prepend, 스크롤 위치 보존) */
async function loadOlder(){
  if(!curCh || loadingOlder || !hasMoreOlder || !curMsgs.length) return;
  loadingOlder=true;
  const ch=curCh, box=$("msgs"), prevH=box.scrollHeight, prevTop=box.scrollTop;
  try{
    const j = await fetchHist(ch, {latest:topTs(), inclusive:false});
    if(ch!==curCh || !j || !j.ok) return;
    mergeNames(j);
    const older=[];
    (j.rows||[]).forEach(m=>{ if(!seenTs.has(m.ts)){ seenTs.add(m.ts); older.push(m); } });
    hasMoreOlder=!!j.has_more;
    if(older.length){
      curMsgs = older.concat(curMsgs);
      curMsgs.sort((a,b)=>parseFloat(a.ts)-parseFloat(b.ts));
      renderMsgs();
      box.scrollTop = box.scrollHeight - prevH + prevTop;   // 스크롤 위치 유지
    }
  }catch(e){}
  finally{ loadingOlder=false; }
}
/* 아래로 스크롤 → 미래(botTs 이후) 이어 불러오기 (날짜 이동 후 현재로 복귀) */
async function loadNewer(){
  if(!curCh || loadingNewer || atLive || !curMsgs.length) return;
  loadingNewer=true;
  const ch=curCh, box=$("msgs");
  const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
  try{
    const j = await fetchHist(ch, {oldest:botTs(), inclusive:false});
    if(ch!==curCh || !j || !j.ok) return;
    mergeNames(j);
    if(j.has_more){   // 사이에 50건 초과 남음 → 연속 로드 불가(Slack API는 미래 페이징 미지원). 버튼으로 최신 이동.
      hasNewerGap=true; updateNewerBtn(); return;
    }
    const add=[];
    (j.rows||[]).forEach(m=>{ if(!seenTs.has(m.ts)){ seenTs.add(m.ts); add.push(m); } });
    if(add.length){
      curMsgs = curMsgs.concat(add);
      curMsgs.sort((a,b)=>parseFloat(a.ts)-parseFloat(b.ts));
      renderMsgs();
    }
    atLive=true; hasNewerGap=false; updateNewerBtn(); startPoll();   // 최신에 도달 → 폴링 재개
    if(atBottom) box.scrollTop = box.scrollHeight;
  }catch(e){}
  finally{ loadingNewer=false; }
}
/* 날짜 선택 → 그 날짜 창으로 이동 */
async function jumpToDate(dateStr){
  if(!curCh || !dateStr) return;
  const start=Math.floor(new Date(dateStr+"T00:00:00").getTime()/1000), end=start+86400;
  stopPoll();
  const ch=curCh;
  curMsgs=[]; seenTs=new Set(); hasMoreOlder=false; atLive=false; hasNewerGap=false;
  $("convnote").textContent=""; $("msgs").innerHTML='<div class="empty">불러오는 중…</div>';
  let j = await fetchHist(ch, {oldest:start, latest:end, inclusive:true});
  if(ch!==curCh) return;
  if(!j || !j.ok){ $("msgs").innerHTML='<div class="err">해당 날짜 대화를 불러오지 못했습니다.</div>'; return; }
  mergeNames(j);
  let rows=j.rows||[];
  if(!rows.length){   // 그 날짜에 메시지 없음 → 그 이전 대화 표시
    const j2=await fetchHist(ch, {latest:end, inclusive:true});
    if(ch!==curCh) return;
    if(j2 && j2.ok){ mergeNames(j2); rows=j2.rows||[]; j=j2; $("convnote").textContent="※ 그 날짜엔 메시지가 없어 이전 대화를 표시합니다"; }
  }
  rows.forEach(m=>{ if(!seenTs.has(m.ts)){ seenTs.add(m.ts); curMsgs.push(m); } });
  curMsgs.sort((a,b)=>parseFloat(a.ts)-parseFloat(b.ts));
  hasMoreOlder=!!j.has_more;
  atLive = end >= (Date.now()/1000);   // 오늘로 이동한 경우엔 사실상 라이브
  renderMsgs();
  $("msgs").scrollTop = 0;   // 그 날짜 시작이 보이도록 상단
  updateNewerBtn();
  if(atLive) startPoll();
}
/* 최신으로 복귀 */
async function jumpToLatest(){
  stopPoll();
  curMsgs=[]; seenTs=new Set(); hasMoreOlder=false; atLive=true; hasNewerGap=false;
  $("convnote").textContent=""; $("msgs").innerHTML='<div class="empty">불러오는 중…</div>';
  await loadLatest(true, true);
  updateNewerBtn(); startPoll();
}
function startPoll(){ stopPoll(); pollTimer=setInterval(()=>{ if(!document.hidden && curCh && atLive) loadLatest(false,false); }, 4000); }
function stopPoll(){ if(pollTimer){ clearInterval(pollTimer); pollTimer=null; } }
$("msgs").addEventListener("scroll", ()=>{
  const box=$("msgs");
  if(box.scrollTop < 40) loadOlder();                                              // 맨 위 근처 → 과거
  if(!atLive && box.scrollHeight - box.scrollTop - box.clientHeight < 40) loadNewer();   // 맨 아래 근처 → 미래
});
$("datePick").addEventListener("change", ()=>{ const v=$("datePick").value; if(v) jumpToDate(v); });
$("tolatest").addEventListener("click", jumpToLatest);
/* 내 메시지 수정/삭제 (이벤트 위임) */
$("msgs").addEventListener("click", e=>{
  const ed=e.target.closest(".msg-edit");   if(ed){ editMsg(ed.dataset.ts); return; }
  const dl=e.target.closest(".msg-del");     if(dl){ deleteMsg(dl.dataset.ts); return; }
  const sv=e.target.closest(".me-save");     if(sv){ saveEdit(sv.dataset.ts); return; }
  const cc=e.target.closest(".me-cancel");   if(cc){ editingTs=""; renderMsgs(); startPoll(); return; }
});
function editMsg(ts){
  editingTs=ts; stopPoll(); renderMsgs();   // 수정 중엔 폴링 중단(재렌더로 편집창 안 지워지게)
  const ta=$("msgs").querySelector('.msg-ed[data-ts="'+ts+'"] .me-inp');
  if(ta){ ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); }
}
async function saveEdit(ts){
  const ta=$("msgs").querySelector('.msg-ed[data-ts="'+ts+'"] .me-inp'); if(!ta) return;
  const text=ta.value.trim(); if(!text){ alert("내용을 입력하세요."); return; }
  const ch=curCh;
  try{
    const j=await (await fetch("?dm_edit=1",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({channel:ch,ts,text})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    const m=curMsgs.find(x=>x.ts===ts); if(m) m.text=text;
    editingTs=""; renderMsgs(); startPoll();
  }catch(e){ alert("수정 실패: "+e.message); }
}
async function deleteMsg(ts){
  if(!confirm("이 메시지를 삭제할까요?")) return;
  const ch=curCh;
  try{
    const j=await (await fetch("?dm_delete=1",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({channel:ch,ts})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    const i=curMsgs.findIndex(x=>x.ts===ts); if(i>=0){ curMsgs.splice(i,1); seenTs.delete(ts); }
    if(editingTs===ts) editingTs="";
    renderMsgs();
  }catch(e){ alert("삭제 실패: "+e.message); }
}
/* ===== 첨부 파일 (📎 버튼 / Ctrl+V 붙여넣기 / 드래그드롭) ===== */
let pendFiles=[];
function addFiles(list){
  for(let f of list){
    if(!f) continue;
    if(!f.name){ const ext=((f.type||"").split("/")[1]||"bin"); f=new File([f], "pasted-"+Date.now()+"."+ext, {type:f.type}); }   // 붙여넣기 이미지 이름 보정
    pendFiles.push(f);
  }
  renderPend();
}
function renderPend(){
  const box=$("pend");
  if(!pendFiles.length){ box.style.display="none"; box.innerHTML=""; return; }
  box.style.display="";
  box.innerHTML = pendFiles.map((f,i)=>{
    const isImg=(f.type||"").indexOf("image/")===0;
    const inner = isImg ? `<img src="${URL.createObjectURL(f)}" alt="">`
                        : `<span class="pf-ic">📎</span><span class="pf-nm">${esc(f.name)}</span>`;
    return `<div class="pf">${inner}<button type="button" class="pf-x" data-i="${i}" title="제거">✕</button></div>`;
  }).join("");
  box.querySelectorAll(".pf-x").forEach(b=>b.addEventListener("click",()=>{ pendFiles.splice(+b.dataset.i,1); renderPend(); }));
}
async function send(){
  const inp = $("inp"), text = inp.value.trim();
  if((!text && !pendFiles.length) || !curCh) return;
  $("sendbtn").disabled = true; $("attbtn").disabled = true;
  try{
    if(pendFiles.length){
      for(let i=0;i<pendFiles.length;i++){
        const fd=new FormData();
        fd.append("channel", curCh);
        if(i===0 && text) fd.append("text", text);   // 첫 파일에 메시지(코멘트) 첨부
        fd.append("file", pendFiles[i]);
        const j=await (await fetch("?dm_upload=1",{method:"POST",body:fd})).json();
        if(!j.ok) throw new Error(j.error==="missing_scope" ? "권한 부족(files:write 스코프 필요)" : (j.error||"업로드 실패"));
      }
      pendFiles=[]; renderPend();
    } else {
      const j = await (await fetch("?dm_send=1",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({channel:curCh,text})})).json();
      if(!j.ok) throw new Error(j.error||"실패");
    }
    inp.value = "";
    if(atLive) await loadLatest(true,false); else await jumpToLatest();   // 과거 보던 중 전송 → 최신으로
  }catch(e){ alert("전송 실패: "+e.message); }
  finally{ $("sendbtn").disabled = false; $("attbtn").disabled = !curCh; inp.focus(); }
}
$("sendbtn").addEventListener("click", send);
$("inp").addEventListener("keydown", e=>{ if(e.key==="Enter" && !e.shiftKey){ e.preventDefault(); send(); } });
$("attbtn").addEventListener("click", ()=>$("fileinp").click());
$("fileinp").addEventListener("change", ()=>{ if($("fileinp").files.length){ addFiles([...$("fileinp").files]); $("fileinp").value=""; } });
$("inp").addEventListener("paste", e=>{                       // Ctrl+V: 클립보드 파일/이미지 첨부
  if(!curCh) return;
  const items=(e.clipboardData||{}).items||[], files=[];
  for(const it of items){ if(it.kind==="file"){ const f=it.getAsFile(); if(f) files.push(f); } }
  if(files.length){ e.preventDefault(); addFiles(files); }    // 파일이 있으면 텍스트 붙여넣기는 막고 첨부
});
(function(){                                                  // 대화창에 드래그드롭
  const conv=document.querySelector(".conv");
  conv.addEventListener("dragover", e=>{ if(curCh){ e.preventDefault(); conv.classList.add("drag-over"); } });
  conv.addEventListener("dragleave", e=>{ if(e.target===conv) conv.classList.remove("drag-over"); });
  conv.addEventListener("drop", e=>{
    conv.classList.remove("drag-over");
    if(!curCh) return;
    const fs=[...((e.dataTransfer&&e.dataTransfer.files)||[])];
    if(fs.length){ e.preventDefault(); addFiles(fs); }
  });
})();
loadDMs();
setInterval(loadDMs, 30000);   // DM 목록 30초마다 갱신
</script>
</body>
</html>
