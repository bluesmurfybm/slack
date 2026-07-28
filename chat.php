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
    $r = slackGet('conversations.history', $tok, ['channel' => $ch, 'limit' => 50]);
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
    echo json_encode(['ok' => true, 'self' => $self, 'names' => $names, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
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
  .conv { flex:1; display:flex; flex-direction:column; min-width:0; background:var(--bg2); }
  .conv-h { padding:10px 16px; background:var(--bg); border-bottom:1px solid var(--line); font-weight:600; font-size:14px; }
  .msgs { flex:1; overflow-y:auto; padding:14px 16px; display:flex; flex-direction:column; gap:8px; }
  .msg { max-width:72%; padding:8px 12px; border-radius:12px; background:var(--bg); border:1px solid var(--line); font-size:13px; line-height:1.5; word-break:break-word; }
  .msg.mine { align-self:flex-end; background:var(--mine); border-color:transparent; }
  .msg .mh { font-size:11px; color:var(--muted); margin-bottom:3px; }
  .msg.mine .mh { text-align:right; }
  .msg a { color:var(--info); }
  .msg .t { color:var(--hint); font-size:10px; margin-left:6px; }
  .msg img.mimg { max-width:220px; max-height:220px; border-radius:8px; margin-top:5px; display:block; border:1px solid var(--line); }
  .empty { margin:auto; color:var(--muted); font-size:13px; }
  /* 입력 */
  .send { display:flex; gap:8px; padding:10px 14px; background:var(--bg); border-top:1px solid var(--line); }
  .send textarea { flex:1; resize:none; height:40px; max-height:120px; border:1px solid var(--line); border-radius:10px; background:var(--bg); color:var(--txt); font:inherit; font-size:13px; padding:9px 12px; }
  .send button { border:none; background:var(--info); color:#fff; border-radius:10px; padding:0 18px; font-size:13px; cursor:pointer; }
  .send button:disabled { opacity:.5; cursor:default; }
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
    <div class="conv-h" id="convh">대화를 선택하세요</div>
    <div class="msgs" id="msgs"><div class="empty">왼쪽에서 대화를 선택하세요.</div></div>
    <div class="send">
      <textarea id="inp" placeholder="메시지 입력 (Enter 전송, Shift+Enter 줄바꿈)" disabled></textarea>
      <button id="sendbtn" disabled>보내기</button>
    </div>
  </div>
</div>
<script>
const $ = id => document.getElementById(id);
let SELF = "", curCh = "", NAMES = {}, pollTimer = null, dmCache = [], botCache = [], botsOpen = false;
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escA(s){ return esc(s).replace(/"/g,"&quot;"); }
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
async function openDM(ch, nm){
  curCh = ch;
  renderDMs();
  $("convh").textContent = nm;
  $("inp").disabled = false; $("sendbtn").disabled = false; $("inp").focus();
  await loadHistory(true);
  startPoll();
}
async function loadHistory(scrollEnd){
  if(!curCh) return;
  try{
    const j = await (await fetch("?dm_history="+encodeURIComponent(curCh),{cache:"no-store"})).json();
    if(!j.ok){ $("msgs").innerHTML = `<div class="err">대화 오류: ${esc(j.error)}${j.error==="missing_scope"?"<br>(im:history 스코프 필요)":""}</div>`; return; }
    SELF = j.self || SELF; NAMES = Object.assign(NAMES, j.names||{});
    const box = $("msgs");
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.innerHTML = (j.rows||[]).map(m=>`
      <div class="msg${m.mine?' mine':''}">
        <div class="mh">${esc(m.name)}<span class="t">${fmtTs(m.ts)}</span></div>
        <div class="mb">${mrkdwn(m.text)}</div>
        ${(m.files||[]).map(f=>f.img&&f.url?`<img class="mimg" src="file.php?u=${encodeURIComponent(f.url)}" alt="${escA(f.name)}" loading="lazy">`:(f.url?`<a href="file.php?u=${encodeURIComponent(f.url)}&dl=1&name=${encodeURIComponent(f.name)}">📎 ${esc(f.name)}</a>`:"")).join("")}
      </div>`).join("") || '<div class="empty">메시지가 없습니다.</div>';
    if(scrollEnd || atBottom) box.scrollTop = box.scrollHeight;
  }catch(e){ /* 폴링 실패 무시 */ }
}
function startPoll(){
  if(pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(()=>{ if(!document.hidden && curCh) loadHistory(false); }, 4000);   // 4초 폴링
}
async function send(){
  const inp = $("inp"), text = inp.value.trim();
  if(!text || !curCh) return;
  $("sendbtn").disabled = true;
  try{
    const j = await (await fetch("?dm_send=1",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({channel:curCh,text})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    inp.value = ""; await loadHistory(true);
  }catch(e){ alert("전송 실패: "+e.message); }
  finally{ $("sendbtn").disabled = false; inp.focus(); }
}
$("sendbtn").addEventListener("click", send);
$("inp").addEventListener("keydown", e=>{ if(e.key==="Enter" && !e.shiftKey){ e.preventDefault(); send(); } });
loadDMs();
setInterval(loadDMs, 30000);   // DM 목록 30초마다 갱신
</script>
</body>
</html>
