<?php
require_once __DIR__ . '/auth.php';
require_login();
$me = current_user();
session_release();   // 세션 잠금 즉시 해제(서브 AJAX 동시요청 경합 방지)
$cfg = require __DIR__ . '/config.php';
$listUrl = $cfg['list_url'] ?? '';

// 캐시 방지: 코드 수정(색상 등)이 새로고침 시 바로 반영되도록
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// 동기화는 화면 JS가 fetch('sync.php')로 백그라운드 호출 → 콘솔창 안 뜸
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>유지보수 요청</title>
<style>
  :root {
    --bg:#fff; --bg2:#f6f7f8; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --hint:#8b949e; --info:#0c447c; --info-bg:#e6f1fb;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1a1d21; --bg2:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --hint:#6b7177; --info:#85b7eb; --info-bg:#0c2740; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg2); color:var(--txt); margin:0; padding:24px; }
  .wrap { width:100%; max-width:1600px; margin:0 auto; }   /* 창 크기에 맞춰 가변(최대 1600px) */
  .head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px; }
  .head h1 { font-size:18px; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; }
  .filters { display:flex; gap:8px; align-items:center; }   /* 제목 옆 오른쪽 정렬(부모 space-between) */
  /* 다중선택 필터 드롭다운 */
  .ms { position:relative; display:inline-block; }
  .ms-btn { height:32px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-size:13px; cursor:pointer; white-space:nowrap; }
  .ms-btn.active { border-color:var(--info); color:var(--info); background:var(--info-bg); }
  .ms-n { display:inline-block; min-width:16px; text-align:center; background:var(--info); color:#fff; border-radius:8px; font-size:11px; padding:0 5px; margin-left:2px; }
  .ms-ar { color:var(--muted); font-size:10px; }
  .ms-menu { position:absolute; z-index:50; top:36px; right:0; left:auto; min-width:170px; max-height:320px; overflow-y:auto;
             background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:6px; box-shadow:0 6px 22px rgba(0,0,0,.18); }
  .ms-menu[hidden] { display:none; }
  .ms-item { display:flex; align-items:center; gap:8px; padding:5px 7px; border-radius:6px; font-size:13px; cursor:pointer; white-space:nowrap; }
  .ms-item:hover { background:var(--bg2); }
  .ms-item input { width:15px; height:15px; margin:0; cursor:pointer; }
  .ms-empty { font-size:12px; color:var(--muted); padding:6px 7px; }
  .toolbar { display:flex; gap:8px; align-items:center; justify-content:flex-end; margin-bottom:14px; flex-wrap:wrap; }
  .badge { font-size:12px; color:var(--info); background:var(--info-bg); padding:2px 10px; border-radius:8px; }
  .who { font-size:12px; color:var(--muted); }
  input,button,select,textarea { font-family:inherit; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-size:13px; }
  input,button,select { height:32px; padding:0 10px; }
  button { cursor:pointer; }
  button.primary { background:var(--info); color:#fff; border-color:var(--info); }
  .listhead { display:flex; align-items:center; gap:10px; padding:9px 16px; border:1px solid var(--line); border-bottom:0; border-radius:12px 12px 0 0; background:var(--bg2); font-size:12px; color:var(--muted); }
  #selInfo { user-select:none; }
  .listbox { border:1px solid var(--line); border-radius:0 0 12px 12px; overflow:hidden; background:var(--bg); }
  .cols { display:flex; gap:16px; align-items:flex-start; }
  .col { flex:1; min-width:0; }
  .row { display:flex; align-items:center; gap:12px; padding:11px 16px; cursor:pointer; border-bottom:1px solid var(--line); }
  .row:hover { background:var(--bg2); }
  /* 커스텀 체크박스 (둥근 초록 체크) */
  .chk { flex:none; appearance:none; -webkit-appearance:none; width:18px; height:18px; margin:0; padding:0;
         border:1.5px solid var(--line); border-radius:5px; background:var(--bg); cursor:pointer; position:relative; transition:all .12s; }
  .chk:hover { border-color:#16a34a; }
  .chk:checked { background:#16a34a; border-color:#16a34a; }
  .chk:checked::after { content:""; position:absolute; left:5px; top:2px; width:4px; height:8px;
         border:solid #fff; border-width:0 2px 2px 0; transform:rotate(45deg); }
  /* 고정(핀) */
  .pinBtn { flex:none; cursor:pointer; font-size:14px; line-height:1; opacity:.22; filter:grayscale(1); user-select:none; }
  .pinBtn:hover { opacity:.7; }
  .pinBtn.on { opacity:1; filter:none; }
  .row.pinned, .row.pinned.unread { box-shadow: inset 3px 0 0 #f5a623; }   /* 좌측 금색 띠로 고정 표시(안읽음보다 우선) */
  /* 안읽음: 제목 굵게 / 읽음: 보통+흐리게 */
  .row.unread .title { font-weight:700; }
  .row.unread .names { font-weight:700; color:var(--txt); }
  .row.unread { box-shadow: inset 3px 0 0 #50b86fc4; }   /* 안읽음: 좌측 파란 띠로 구분 */
  .row.read .title { font-weight:400; color:var(--muted); }
  .row.read { background:var(--bg2); }
  /* 숨김(숨김 보기 모드에서만 노출) */
  .row.hid { opacity:.5; }
  .row.hid .title { text-decoration:line-through; }
  .hideBtn { flex:none; cursor:pointer; font-size:13px; line-height:1; opacity:.3; user-select:none; }
  .hideBtn:hover { opacity:.8; }
  .names { flex:none; width:96px; font-size:11px; font-weight:500; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .title { flex:1; min-width:0; font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .snipline { display:flex; align-items:center; gap:6px; min-width:0; margin-top:2px; }
  .snip { flex:1; min-width:0; font-size:12px; color:var(--hint); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .st { font-size:11px; padding:2px 9px; border-radius:8px; white-space:nowrap; }
  .lock { font-size:10px; color:var(--muted); }
  .cmtcnt { flex:none; font-size:11px; color:var(--muted); white-space:nowrap; }
  .date { font-size:12px; color:var(--hint); flex:none; white-space:nowrap; }
  .detail { padding:16px 20px 20px; border-bottom:1px solid var(--line); background:var(--bg2); }
  .detail.with-cmts { display:flex; gap:20px; align-items:flex-start; }
  .detail-main { flex:1; min-width:0; }
  .detail-cmts { flex:none; width:380px; max-width:90%; min-width:280px; display:flex; flex-direction:column;
                 resize:horizontal; overflow:auto;
                 background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:12px 14px; }
  @media (max-width:760px){ .detail.with-cmts{ flex-direction:column; } .detail-cmts{ width:100%; max-width:100%; } }
  .cmtToggle.on { background:var(--info-bg); color:var(--info); border-color:var(--info); }
  /* 메타 칩 */
  .meta { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
  .mi { display:flex; align-items:center; gap:6px; font-size:12px; background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:4px 10px; }
  .ml { color:var(--hint); }
  .mv { color:var(--txt); font-weight:500; }
  .mi select { height:24px; padding:0 22px 0 6px; font-size:12px; border-radius:6px; }
  .mi input[type=date] { height:24px; padding:0 6px; font-size:12px; border-radius:6px; }
  /* 본문 카드 */
  .body-card { font-size:13px; line-height:1.75; white-space:normal; word-break:break-word; color:var(--txt);
               background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:14px 16px; }
  /* 서식 렌더 공통 */
  .body-card a, .cmt-b a { color:var(--info); }
  .body-card code, .cmt-b code { background:var(--bg2); border:1px solid var(--line); border-radius:4px; padding:0 4px; font-family:Consolas,monospace; font-size:12px; }
  .body-card pre, .cmt-b pre { background:var(--bg2); border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin:6px 0; overflow:auto; font-family:Consolas,monospace; font-size:12px; white-space:pre-wrap; }
  .links { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; align-items:center; }
  .links a { font-size:12px; text-decoration:none; color:var(--info); border:1px solid var(--line); padding:5px 11px; border-radius:8px; }
  .links .slack-link { font-size:12px; color:#fff; background:#4a154b; border:1px solid #4a154b; padding:5px 11px; border-radius:8px; height:auto; }
  #updated { font-size:12px; color:var(--hint); margin:10px 2px 0; }
  .err { color:#e24b4a; padding:16px; }
  /* 댓글 패널 (우측, 고정 높이 + 스크롤) */
  .cmts-title { font-size:12px; font-weight:600; color:var(--muted); margin-bottom:8px; display:flex; align-items:center; gap:6px; flex:none; }
  .cmts-n { background:var(--info-bg); color:var(--info); border-radius:9px; padding:0 7px; font-size:11px; }
  .cmts { display:flex; flex-direction:column; overflow-y:auto; resize:vertical; height:360px; max-height:85vh; min-height:80px; padding-right:4px; }
  /* 각 댓글 = 컴팩트 한 줄형 */
  .cmt { display:flex; gap:8px; padding:6px 0; border-bottom:1px solid var(--line); }
  .cmt:last-child { border-bottom:0; }
  .cmt-av { flex:none; width:20px; height:20px; margin-top:1px; border-radius:50%;
            display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; }
  .cmt-bub { flex:1; min-width:0; }
  .cmt-h b { font-weight:600; font-size:12px; }
  .cmt-t { color:var(--hint); margin-left:6px; font-size:10px; }
  .cmt-b { font-size:12.5px; line-height:1.5; white-space:normal; word-break:break-word; color:var(--txt); margin-top:1px; }
  .cmt-loading,.cmt-empty { font-size:12px; color:var(--hint); padding:8px 0; }
  .cmt-form { flex:none; display:flex; flex-direction:column; gap:5px; margin-top:10px; padding-top:10px; border-top:1px solid var(--line); }
  .cmt-tb { display:flex; gap:3px; }
  .cmt-tb .tb { width:26px; height:24px; padding:0; font-size:11px; color:var(--muted); display:flex; align-items:center; justify-content:center; }
  .cmt-tb .tb:hover { border-color:var(--info); color:var(--info); }
  /* 리치 에디터: 입력하면서 서식이 바로 보이는 단일 입력창 */
  .cmt-input { width:100%; min-height:54px; max-height:220px; overflow-y:auto; line-height:1.5; padding:6px 9px;
               border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-family:inherit; font-size:12.5px;
               text-align:left; word-break:break-word; }
  .cmt-input:focus { outline:none; border-color:var(--info); }
  .cmt-input:empty:before { content:attr(data-ph); color:var(--hint); pointer-events:none; }
  .cmt-input code { background:var(--bg2); border:1px solid var(--line); border-radius:4px; padding:0 4px; font-family:Consolas,monospace; font-size:12px; }
  .cmt-input a { color:var(--info); }
  .cmt-actions { display:flex; justify-content:flex-end; }
  .cmt-send { height:30px; font-size:12px; background:var(--info); color:#fff; border-color:var(--info); }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>📥 유지보수 요청 <span class="badge" id="count"></span></h1>
    <div class="filters">
      <div class="ms" id="msPriority"></div>
      <div class="ms" id="msTeam"></div>
      <div class="ms" id="msStatus"></div>
      <div class="ms" id="msAsg"></div>
      <button id="reset" type="button">필터 초기화</button>
    </div>
  </div>
  <div class="toolbar">
    <span class="who"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님</span>
    <input id="search" type="text" placeholder="검색…" style="width:120px;">
    <button id="readSel" type="button">선택 읽음</button>
    <button id="unreadSel" type="button">선택 안읽음</button>
    <button id="toggleUn" type="button">미지정 숨기기</button>
    <button id="toggleHidden" type="button">숨김 보기</button>
    <a href="difficulty.php"><button type="button">⭐ 난이도 분석</button></a>
    <button id="sync">동기화</button>
    <a href="logout.php"><button type="button">로그아웃</button></a>
  </div>
  <div class="cols">
    <div class="col" id="unpanel">
      <div class="listhead"><b>미지정 담당자</b> <span id="uncount"></span></div>
      <div id="unlist" class="listbox"></div>
    </div>
    <div class="col">
      <div class="listhead">
        <input type="checkbox" id="selAll" class="chk">
        <span id="selInfo">전체 선택</span>
      </div>
      <div id="list" class="listbox">불러오는 중…</div>
    </div>
  </div>
  <div id="updated"></div>
</div>

<script>
const LIST_URL = <?= json_encode($listUrl) ?>;
const PALETTE = {
  "등록":            {bg:"#eef0f2", fg:"#555c66"},
  "공수산정요청":    {bg:"#faeeda", fg:"#633806"},
  "진행중":          {bg:"#9b7d55", fg:"#ffefdb"},
  "확인요청(검토완료)":     {bg:"#e7d2bb", fg:"#683d00"},
  "확인요청(개발서버반영)": {bg:"#e0f2f1", fg:"#00695c"},
  "확인요청(운영서버반영)": {bg:"#e0f2f1", fg:"#00695c"},
  "운영배포요청":    {bg:"#fff0e0", fg:"#a85b00"},
  "완료":            {bg:"#e4f3e7", fg:"#1b5e20"},
  "보류":            {bg:"#f1efe8", fg:"#6b6b6b"},
  "처리불가":        {bg:"#f3e6e6", fg:"#9b2c2c"},
  "기타":            {bg:"#f1efe8", fg:"#444441"}
};
const PRIORITY = {
  "긴급": {bg:"#fbe4e4", fg:"#b91c1c"},
  "일반": {bg:"#e6f1fb", fg:"#0c447c"}
};
function priColor(s){ return PRIORITY[s] || PRIORITY["일반"]; }
const TEAM = {
  "블루소프트": {bg:"#e6f1fb", fg:"#0c447c"},
  "와이오즈":   {bg:"#e0f2f1", fg:"#00695c"},
  "시스템개발": {bg:"#eeedfe", fg:"#3c3489"},
  "달빛소프트": {bg:"#ede7f6", fg:"#5e35b1"},
  "프레임":     {bg:"#fff0e0", fg:"#a85b00"},
  "미지정":     {bg:"#eceff1", fg:"#455a64"}
};
function teamColor(s){ return TEAM[s] || {bg:"#eceff1", fg:"#455a64"}; }
let DATA = [], openId = null, filter = "";
let fpri = new Set(), fteam = new Set(), fstat = new Set(), fasg = new Set();   // 헤더 다중선택 필터(Set)
let selected = new Set();                // 체크박스 선택 항목
let splitOn = true;                       // 미지정 리스트 2분할 표시 여부(기본 표시)
let cmtCache = {};                        // 레코드별 댓글 캐시(렌더 깜빡임 방지)
let showCmts = true;                      // 상세에서 댓글 패널(우측) 표시 여부(기본 표시)
let showHidden = false;                   // 숨김 항목 표시 여부(기본: 숨김)

function pal(s){ return PALETTE[s] || PALETTE["기타"]; }
function pad2(n){ return n<10 ? "0"+n : ""+n; }
function fmtDate(ts){   // yyyy.mm.dd
  if(!ts) return "";
  const d=new Date(ts*1000);
  return d.getFullYear()+"."+pad2(d.getMonth()+1)+"."+pad2(d.getDate());
}
function fmtDT(ts){     // yyyy.mm.dd hh:mm
  if(!ts) return "—";
  const d=new Date(ts*1000);
  return d.getFullYear()+"."+pad2(d.getMonth()+1)+"."+pad2(d.getDate())+" "+pad2(d.getHours())+":"+pad2(d.getMinutes());
}
function fmtYmd(s){ return (s||"").replace(/-/g,"."); }   // "2026-06-17" -> "2026.06.17"
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escAttr(s){ return esc(s).replace(/"/g,"&quot;"); }
/* Slack 은 텍스트의 & < > 를 &amp; &lt; &gt; 로 인코딩해 보냄. esc() 후 이중 이스케이프된 걸 1단계 복원 */
function unslack(s){ return s.replace(/&amp;(amp|lt|gt|quot|#39|#x27);/g,"&$1;"); }
/* 자주 쓰는 Slack 이모지 :shortcode: → 유니코드 */
const EMOJI = {
  smile:"😄",smiley:"😃",grinning:"😀",grin:"😁",joy:"😂",rofl:"🤣",sweat_smile:"😅",laughing:"😆",satisfied:"😆",
  wink:"😉",blush:"😊",slightly_smiling_face:"🙂",upside_down_face:"🙃",relaxed:"☺️",yum:"😋",sunglasses:"😎",
  heart_eyes:"😍",kissing_heart:"😘",thinking_face:"🤔",hugging_face:"🤗",neutral_face:"😐",expressionless:"😑",
  no_mouth:"😶",smirk:"😏",unamused:"😒",roll_eyes:"🙄",grimacing:"😬",sweat:"😓",pensive:"😔",confused:"😕",
  worried:"😟",confounded:"😖",persevere:"😣",disappointed:"😞",tired_face:"😫",weary:"😩",cry:"😢",sob:"😭",
  angry:"😠",rage:"😡",sleepy:"😪",mask:"😷",dizzy_face:"😵",astonished:"😲",scream:"😱",flushed:"😳",
  fearful:"😨",cold_sweat:"😰",open_mouth:"😮",hushed:"😯",sleeping:"😴",zzz:"💤",exploding_head:"🤯",
  star_struck:"🤩",partying_face:"🥳",pleading_face:"🥺",smiling_face_with_tear:"🥲",
  "+1":"👍",thumbsup:"👍","-1":"👎",thumbsdown:"👎",ok_hand:"👌",punch:"👊",fist:"✊",fist_raised:"✊",
  v:"✌️",wave:"👋",raised_hands:"🙌",pray:"🙏",clap:"👏",muscle:"💪",raised_hand:"✋",
  point_up:"☝️",point_down:"👇",point_left:"👈",point_right:"👉",ok_woman:"🙆",bow:"🙇",see_no_evil:"🙈",
  heart:"❤️",broken_heart:"💔",two_hearts:"💕",sparkling_heart:"💖",blue_heart:"💙",green_heart:"💚",
  yellow_heart:"💛",purple_heart:"💜",heavy_heart_exclamation:"❣️",
  fire:"🔥",star:"⭐",star2:"🌟",sparkles:"✨",zap:"⚡",boom:"💥",collision:"💥",tada:"🎉",confetti_ball:"🎊",
  gift:"🎁",balloon:"🎈","100":"💯",
  white_check_mark:"✅",heavy_check_mark:"✔️",ballot_box_with_check:"☑️",x:"❌",o:"⭕",heavy_multiplication_x:"✖️",
  warning:"⚠️",exclamation:"❗",heavy_exclamation_mark:"❗",question:"❓",grey_question:"❔",bangbang:"‼️",
  bulb:"💡",rocket:"🚀",eyes:"👀",ok:"🆗","new":"🆕",
  hourglass:"⏳",hourglass_flowing_sand:"⏳",alarm_clock:"⏰",clock:"🕐",calendar:"📅",date:"📆",
  memo:"📝",pencil:"✏️",pencil2:"✏️",pushpin:"📌",round_pushpin:"📍",paperclip:"📎",link:"🔗",
  mag:"🔍",lock:"🔒",unlock:"🔓",key:"🔑",bell:"🔔",email:"✉️",envelope:"✉️",phone:"📞",telephone:"☎️",
  computer:"💻",desktop_computer:"🖥️",hammer:"🔨",wrench:"🔧",gear:"⚙️",package:"📦",
  chart_with_upwards_trend:"📈",chart_with_downwards_trend:"📉",bar_chart:"📊",clipboard:"📋",
  coffee:"☕",beer:"🍺",beers:"🍻",cake:"🍰",pizza:"🍕",rice:"🍚",
  sob_:"😭",sweat_drops:"💦",dash:"💨",poop:"💩",hankey:"💩",check:"✔️",
  smiling_imp:"😈",ghost:"👻",skull:"💀",alien:"👽",robot_face:"🤖",
  raising_hand:"🙋",man_bowing:"🙇‍♂️",woman_bowing:"🙇‍♀️",speech_balloon:"💬",thought_balloon:"💭"
};
/* Slack mrkdwn → HTML (굵게/기울임/취소선/코드/코드블록/링크/줄바꿈) */
function mrkdwn(t){
  if(!t) return '';
  var ph=[]; var stash=function(h){ ph.push(h); return ''+(ph.length-1)+''; };
  t = t.replace(/```([\s\S]*?)```/g,function(m,c){ return stash('<pre>'+unslack(esc(c.replace(/^\n|\n$/g,'')))+'</pre>'); });
  t = t.replace(/`([^`\n]+)`/g,function(m,c){ return stash('<code>'+unslack(esc(c))+'</code>'); });
  t = t.replace(/<(https?:\/\/[^|>]+)\|([^>]+)>/g,function(m,u,l){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(l))+'</a>'); });
  t = t.replace(/<(https?:\/\/[^>]+)>/g,function(m,u){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u))+'</a>'); });
  t = unslack(esc(t));
  t = t.replace(/\*(?!\s)([^*\n]+?)\*/g,'<b>$1</b>');
  t = t.replace(/_(?!\s)([^_\n]+?)_/g,'<i>$1</i>');
  t = t.replace(/~(?!\s)([^~\n]+?)~/g,'<s>$1</s>');
  t = t.replace(/:skin-tone-[2-6]:/g,'');
  t = t.replace(/:([a-z0-9_+-]+):/g,function(m,n){ return EMOJI[n]||m; });
  t = t.replace(/\n/g,'<br>');
  t = t.replace(/(\d+)/g,function(m,i){ return ph[+i]; });
  return t;
}
/* 텍스트영역 선택영역을 pre/post 로 감싸기 */
/* 리치 에디터(contenteditable) HTML → Slack mrkdwn 텍스트로 변환 (전송용) */
function htmlToMrkdwn(root){
  function walk(node){
    let out="";
    node.childNodes.forEach(n=>{
      if(n.nodeType===3){ out += n.nodeValue; return; }      // 텍스트
      if(n.nodeType!==1) return;
      const tag=n.tagName.toLowerCase(), inner=walk(n);
      if(tag==="b"||tag==="strong")           out += "*"+inner+"*";
      else if(tag==="i"||tag==="em")          out += "_"+inner+"_";
      else if(tag==="s"||tag==="strike"||tag==="del") out += "~"+inner+"~";
      else if(tag==="code")                   out += "`"+inner+"`";
      else if(tag==="a"){ const h=n.getAttribute("href")||""; out += h ? (h===inner ? "<"+h+">" : "<"+h+"|"+inner+">") : inner; }
      else if(tag==="br")                     out += "\n";
      else if(tag==="div"||tag==="p")         out += (out && !out.endsWith("\n") ? "\n" : "") + inner;
      else if(tag==="span"){                  // CSS 서식 폴백
        const fw=n.style.fontWeight, fs=n.style.fontStyle, td=(n.style.textDecoration||n.style.textDecorationLine||"");
        let s=inner;
        if(fw==="bold"||parseInt(fw,10)>=600) s="*"+s+"*";
        if(fs==="italic") s="_"+s+"_";
        if(/line-through/.test(td)) s="~"+s+"~";
        out += s;
      }
      else out += inner;
    });
    return out;
  }
  return walk(root);
}
function snip(b){ return (b||"").replace(/\s+/g," ").trim().slice(0,90); }

function matchBase(r, withStatus){   // 공통 필터 (검색/우선순위/담당팀 + 선택적으로 진행상태). Set 이 비면 미적용, 있으면 포함(OR)
  return (!filter || (r.title + r.body + r.req + r.asg).toLowerCase().includes(filter)) &&
         (!fpri.size  || fpri.has(r.priority)) &&
         (!fteam.size || fteam.has(r.team)) &&
         (withStatus === false || !fstat.size || fstat.has(r.status));
}
function visible(r){ return showHidden || !r.is_hidden; }   // 숨김 항목은 '숨김 보기'에서만
function filteredItems(){   return DATA.filter(r => visible(r) && matchBase(r, true) && (!fasg.size || fasg.has(r.asg))); }
// 미지정 패널: 담당자·진행상태 필터는 적용하지 않음(항상 미지정 + 상태 '등록'만)
function unassignedItems(){ return DATA.filter(r => visible(r) && matchBase(r, false) && (!r.asg || r.asg === '—') && r.status === '등록'); }

function rowHtml(r){
  const p = pal(r.status), open = openId === r.id, unread = !r.is_read;
  const row = `
    <div class="row${unread?' unread':' read'}${r.is_pinned?' pinned':''}${r.is_hidden?' hid':''}" data-id="${esc(r.id)}" ${open?'style="background:var(--bg2)"':''}>
      <input type="checkbox" class="chk" data-id="${esc(r.id)}" ${selected.has(r.id)?'checked':''}>
      <div class="pinBtn doPin${r.is_pinned?' on':''}" data-id="${esc(r.id)}" data-pin="${r.is_pinned?0:1}" title="${r.is_pinned?'고정 해제':'상단 고정'}">📌</div>
      <div class="names">${esc(r.req||'—')}${r.asg && r.asg!=='—' ? `, ${esc(r.asg)}` : ''}</div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px">
          ${r.priority?`<span class="st" style="flex:none;background:${priColor(r.priority).bg};color:${priColor(r.priority).fg}">${esc(r.priority)}</span>`:''}
          <span class="title">${esc(r.title)}</span>
          ${r.status?`<span class="st" style="background:${p.bg};color:${p.fg}">${esc(r.status)}</span>`:''}
          ${r.team?`<span class="st" style="background:${teamColor(r.team).bg};color:${teamColor(r.team).fg}">${esc(r.team)}</span>`:''}
          ${r.locked?'<span class="lock">✎수정됨</span>':''}
        </div>
        <div class="snipline">
          ${r.cmt_count>0?`<span class="cmtcnt">💬 +${r.cmt_count}</span>`:''}
          <div class="snip">${esc(snip(r.body))}</div>
        </div>
      </div>
      <div class="date">${fmtDate(r.created)}</div>
    </div>`;
  if(!open) return row;
  return row + `
    <div class="detail${showCmts?' with-cmts':''}">
      <div class="detail-main">
        <div class="meta">
          <div class="mi"><span class="ml">진행상태</span><select class="edit-status" data-id="${esc(r.id)}">${statusEditOptions(r.status)}</select></div>
          <div class="mi"><span class="ml">담당자</span><select class="edit-asg" data-id="${esc(r.id)}">${asgEditOptions(r.asg_id)}</select></div>
          ${metaItem('요청일', r.date?fmtYmd(r.date):fmtDate(r.created))}
          <div class="mi"><span class="ml">예상완료일</span><input type="date" class="edit-eta" data-id="${esc(r.id)}" value="${esc(r.eta||'')}"></div>
          ${r.done?metaItem('완료일', fmtYmd(r.done)):''}
          ${metaItem('갱신', fmtDT(r.updated))}
          ${r.edited_by?metaItem('최종수정', r.edited_by):''}
        </div>
        <div class="body-card">${mrkdwn(r.body)}</div>
        <div class="links">
          ${LIST_URL?`<button type="button" class="slack-link copyLink" data-url="${esc(LIST_URL)}?record_id=${esc(r.id)}">링크 복사</button>`:''}
          ${r.momo?`<a href="${esc(r.momo)}" target="_blank" rel="noopener">모모 이슈</a>`:''}
          ${r.lms?`<a href="${esc(r.lms)}" target="_blank" rel="noopener">LMS 링크</a>`:''}
          <button type="button" class="rdBtn" data-id="${esc(r.id)}" data-read="${r.is_read?0:1}">${r.is_read?'안읽음으로':'읽음으로'}</button>
          <button type="button" class="doHide" data-id="${esc(r.id)}" data-hide="${r.is_hidden?0:1}">${r.is_hidden?'👁 숨김 해제':'🙈 숨기기'}</button>
          <button type="button" class="cmtToggle ${showCmts?'on':''}">💬 댓글${r.cmt_count>0?` ${r.cmt_count}`:''}${showCmts?' 닫기':''}</button>
        </div>
      </div>
      ${showCmts?`
      <div class="detail-cmts">
        <div class="cmts-title">💬 댓글${r.cmt_count>0?` <span class="cmts-n">${r.cmt_count}</span>`:''}</div>
        <div class="cmts" id="cmts-${esc(r.id)}">${cmtCache[r.id]!==undefined ? cmtHtml(cmtCache[r.id]) : '<div class="cmt-loading">댓글 불러오는 중…</div>'}</div>
        <div class="cmt-form">
          <div class="cmt-tb">
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="b" title="굵게"><b>B</b></button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="i" title="기울임"><i>I</i></button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="s" title="취소선"><s>S</s></button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="code" title="코드">&lt;/&gt;</button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="link" title="링크">🔗</button>
          </div>
          <div class="cmt-input" id="cin-${esc(r.id)}" contenteditable="true" data-ph="댓글 입력… (Ctrl+Enter 전송)"></div>
          <div class="cmt-actions"><button type="button" class="cmt-send" data-id="${esc(r.id)}">작성</button></div>
        </div>
      </div>`:''}
    </div>`;
}

function metaItem(label, val){
  return `<div class="mi"><span class="ml">${esc(label)}</span><span class="mv">${esc(val)}</span></div>`;
}
function statusEditOptions(cur){
  return Object.keys(PALETTE).filter(k=>k!=="기타")
    .map(s=>`<option${s===cur?' selected':''}>${esc(s)}</option>`).join("");
}
function asgUsers(){   // 데이터에서 distinct 담당자 {id:name}
  const m={};
  DATA.forEach(r=>{ if(r.asg_id && r.asg && r.asg!=="—") m[r.asg_id]=r.asg; });
  return Object.entries(m).sort((a,b)=>String(a[1]).localeCompare(String(b[1])));
}
function asgEditOptions(curId){
  let h=`<option value=""${!curId?' selected':''}>미지정</option>`;
  let found=false;
  h+=asgUsers().map(([id,name])=>{ if(id===curId)found=true; return `<option value="${esc(id)}"${id===curId?' selected':''}>${esc(name)}</option>`; }).join("");
  return h;
}
async function updateField(id, field, value){
  try{
    const j = await (await fetch("update.php", {
      method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({request_id:id, field, value})
    })).json();
    if(!j.ok) throw new Error(j.error || "실패");
    const r = DATA.find(x=>x.id===id);
    if(r){
      if(field==="status"){ r.status=j.value; r.status_id=j.status_id; }
      else if(field==="eta"){ r.eta=j.eta||null; }
      else { r.asg_id=j.asg_id||null; r.asg=j.asg; }
    }
    render();
  }catch(err){ alert("수정 실패: " + err.message); }
}
function cmtInitial(n){ return (n||"?").trim().slice(0,1) || "?"; }
function authorColor(name){            // 작성자 이름 → 고유 색상(HSL 해시)
  let h=0; const s=(name||"?");
  for(let i=0;i<s.length;i++) h=(h*31 + s.charCodeAt(i))>>>0;
  const hue=h%360;
  return { bg:`hsl(${hue} 68% 92%)`, fg:`hsl(${hue} 45% 35%)` };
}
function cmtHtml(list){
  if(!list || !list.length) return '<div class="cmt-empty">아직 댓글이 없습니다.</div>';
  return list.map(c=>{
    const col = authorColor(c.author_name);
    return `
    <div class="cmt">
      <div class="cmt-av" style="background:${col.bg};color:${col.fg}">${esc(cmtInitial(c.author_name))}</div>
      <div class="cmt-bub">
        <div class="cmt-h"><b style="color:${col.fg}">${esc(c.author_name)}</b><span class="cmt-t">${esc(c.created_at)}</span></div>
        <div class="cmt-b">${mrkdwn(c.body)}</div>
      </div>
    </div>`;
  }).join("");
}
async function loadComments(id){
  try{
    const j = await (await fetch("comments.php?request_id="+encodeURIComponent(id), {cache:"no-store"})).json();
    cmtCache[id] = j.comments || [];
  }catch(e){ cmtCache[id] = []; }
  const box = document.getElementById("cmts-"+id);
  if(box) box.innerHTML = cmtHtml(cmtCache[id]);
}
async function postComment(id){
  const ed = document.getElementById("cin-"+id);
  const text = ed ? htmlToMrkdwn(ed).trim() : "";
  if(!text) return;
  const btn = document.querySelector('.cmt-send[data-id="'+id+'"]');
  if(btn){ btn.disabled = true; btn.textContent = "작성 중…"; }
  try{
    const j = await (await fetch("comments.php", {
      method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({request_id:id, text})
    })).json();
    if(!j.ok) throw new Error(j.error || "실패");
    if(ed) ed.innerHTML = "";
    await loadComments(id);   // 작성 후 최신 댓글 다시 로드
  }catch(err){ alert("댓글 작성 실패: " + err.message); }
  finally{ if(btn){ btn.disabled = false; btn.textContent = "작성"; } }
}

function bindRows(box){
  box.querySelectorAll(".row").forEach(el=>{
    el.addEventListener("click",()=>{
      const id = el.dataset.id;
      const opening = (openId !== id);
      openId = opening ? id : null;
      if(opening){
        showCmts = true;                  // 새 항목 열면 댓글 패널을 기본으로 표시
        const r = DATA.find(x=>x.id===id);
        if(r && !r.is_read) markRead(id, 1, false);
      }
      render();
      if(opening && showCmts) loadComments(id);   // 열 때 최신 댓글 자동 로드
    });
  });
  box.querySelectorAll(".cmtToggle").forEach(el=>{
    el.addEventListener("click", e=>{
      e.stopPropagation();
      showCmts = !showCmts;
      render();
      if(showCmts && openId) loadComments(openId);   // 열 때 최신 댓글 로드
    });
  });
  box.querySelectorAll(".rdBtn").forEach(el=>{
    el.addEventListener("click",(e)=>{ e.stopPropagation(); markRead(el.dataset.id, +el.dataset.read, true); });
  });
  box.querySelectorAll(".doPin").forEach(el=>{
    el.addEventListener("click",(e)=>{ e.stopPropagation(); markPin(el.dataset.id, +el.dataset.pin); });
  });
  box.querySelectorAll(".doHide").forEach(el=>{
    el.addEventListener("click",(e)=>{ e.stopPropagation(); markHide(el.dataset.id, +el.dataset.hide); });
  });
  box.querySelectorAll(".copyLink").forEach(el=>{
    el.addEventListener("click", async e=>{
      e.stopPropagation();
      const url = el.dataset.url, old = el.textContent;
      try{ await navigator.clipboard.writeText(url); }
      catch(_){ const ta=document.createElement("textarea"); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove(); }
      el.textContent = "복사됨!";
      setTimeout(()=>{ el.textContent = old; }, 1200);
    });
  });
  box.querySelectorAll(".chk").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{
      if(e.target.checked) selected.add(e.target.dataset.id);
      else selected.delete(e.target.dataset.id);
    });
  });
  box.querySelectorAll(".edit-status").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{ e.stopPropagation(); updateField(el.dataset.id,"status",e.target.value); });
  });
  box.querySelectorAll(".edit-asg").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{ e.stopPropagation(); updateField(el.dataset.id,"asg",e.target.value); });
  });
  box.querySelectorAll(".edit-eta").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{ e.stopPropagation(); updateField(el.dataset.id,"eta",e.target.value); });
  });
  box.querySelectorAll(".cmt-input").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("keydown", e=>{   // Ctrl/Cmd+Enter 전송
      if(e.key==="Enter" && (e.ctrlKey||e.metaKey)){ e.preventDefault(); postComment(el.id.replace("cin-","")); }
    });
  });
  box.querySelectorAll(".tb").forEach(el=>{
    el.addEventListener("mousedown", e=>e.preventDefault());   // 클릭해도 에디터 선택 유지
    el.addEventListener("click", e=>{
      e.stopPropagation();
      const ed=document.getElementById("cin-"+el.dataset.id), a=el.dataset.act;
      if(!ed) return; ed.focus();
      try{ document.execCommand('styleWithCSS', false, null); }catch(_){}
      if(a==="b") document.execCommand('bold');
      else if(a==="i") document.execCommand('italic');
      else if(a==="s") document.execCommand('strikeThrough');
      else if(a==="code"){ const t=(window.getSelection().toString()||'코드'); document.execCommand('insertHTML', false, '<code>'+t.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))+'</code>'); }
      else if(a==="link"){ const u=prompt("링크 URL:"); if(u) document.execCommand('createLink', false, u); }
    });
  });
  box.querySelectorAll(".cmt-send").forEach(el=>{
    el.addEventListener("click", e=>{ e.stopPropagation(); postComment(el.dataset.id); });
  });
}

function paint(box, items){
  if(items.length === 0){ box.innerHTML = '<div class="err">표시할 항목이 없습니다.</div>'; return; }
  box.innerHTML = items.map(rowHtml).join("");
  bindRows(box);
}

/* ---------- 댓글 영역 크기 조절(드래그) + 크기 기억 ---------- */
const CMT_W_KEY="slackapi_cmt_w", CMT_H_KEY="slackapi_cmt_h";
function applyCmtSize(){
  const panel=document.querySelector(".detail-cmts");
  if(!panel) return;                       // 댓글 패널이 열려있을 때만
  const list=panel.querySelector(".cmts");
  const w=localStorage.getItem(CMT_W_KEY); if(w) panel.style.width=w+"px";
  const h=localStorage.getItem(CMT_H_KEY); if(list&&h) list.style.height=h+"px";
  // 드래그로 크기 바꾸면(마우스 놓을 때) 기록
  panel.addEventListener("mouseup",()=>localStorage.setItem(CMT_W_KEY, Math.round(panel.getBoundingClientRect().width)));
  if(list) list.addEventListener("mouseup",()=>localStorage.setItem(CMT_H_KEY, Math.round(list.getBoundingClientRect().height)));
}

function render(){
  const items = filteredItems();
  document.getElementById("count").textContent = items.length + "건";
  const hc = DATA.filter(r=>r.is_hidden).length;   // 숨김 개수 → 토글 버튼에 표시
  document.getElementById("toggleHidden").textContent = (showHidden?"숨김 가리기":"숨김 보기") + (hc?` (${hc})`:"");
  document.getElementById("selAll").checked = items.length>0 && items.every(r=>selected.has(r.id));
  document.getElementById("selInfo").textContent = selected.size>0 ? `${selected.size}개 선택됨` : "전체 선택";
  paint(document.getElementById("list"), items);

  if(splitOn){                                  // 미지정 패널(좌측)
    const un = unassignedItems();
    document.getElementById("uncount").textContent = un.length + "건";
    paint(document.getElementById("unlist"), un);
  }
  applyCmtSize();   // 댓글 영역 사용자 지정 크기 복원
}

/* 선택 항목 일괄 읽음/안읽음 */
async function bulkRead(read){
  const ids = [...selected];
  if(!ids.length){ alert("선택된 항목이 없습니다."); return; }
  try{
    const j = await (await fetch("read.php", {
      method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({ids, read})
    })).json();
    if(!j.ok) throw new Error(j.error || "실패");
    ids.forEach(id=>{ const r=DATA.find(x=>x.id===id); if(r) r.is_read = read?1:0; });
    selected.clear();
    render();
  }catch(err){ alert("처리 실패: " + err.message); }
}

/* 읽음/안읽음 처리: 로컬 즉시 반영(재정렬 안 함) + 서버 저장 */
function markRead(id, read, doRender){
  const r = DATA.find(x=>x.id===id);
  if(r) r.is_read = read ? 1 : 0;
  fetch("read.php", {
    method:"POST", headers:{"Content-Type":"application/json"},
    body: JSON.stringify({request_id:id, read})
  }).catch(()=>{});
  if(doRender) render();
}

/* 고정 우선 정렬: 고정 → 안읽음 → 최신순 (data.php ORDER BY 와 동일) */
function sortData(){
  DATA.sort((a,b)=> (b.is_pinned-a.is_pinned) || (a.is_read-b.is_read) || (b.created-a.created));
}
/* 고정/해제: 로컬 즉시 반영 + 재정렬(상단 이동) + 서버 저장 */
function markPin(id, pin){
  const r = DATA.find(x=>x.id===id);
  if(r) r.is_pinned = pin ? 1 : 0;
  fetch("pin.php", {
    method:"POST", headers:{"Content-Type":"application/json"},
    body: JSON.stringify({request_id:id, pin})
  }).catch(()=>{});
  sortData();
  render();
}
/* 숨김/해제: 로컬 즉시 반영 + 서버 저장 */
function markHide(id, hide){
  const r = DATA.find(x=>x.id===id);
  if(r) r.is_hidden = hide ? 1 : 0;
  fetch("hide.php", {
    method:"POST", headers:{"Content-Type":"application/json"},
    body: JSON.stringify({request_id:id, hide})
  }).catch(()=>{});
  if(hide && !showHidden) openId = null;   // 숨기면 목록에서 사라지므로 상세 닫기
  render();
}

/* ---------- 헤더 다중선택 필터 드롭다운 ---------- */
const MS = [
  { id:'msPriority', label:'우선순위', set:()=>fpri,  values:()=>Object.keys(PRIORITY) },
  { id:'msTeam',     label:'담당팀',   set:()=>fteam, values:()=>Object.keys(TEAM) },
  { id:'msStatus',   label:'진행상태', set:()=>fstat, values:()=>Object.keys(PALETTE).filter(k=>k!=="기타") },
  { id:'msAsg',      label:'담당자',   set:()=>fasg,  values:()=>[...new Set(DATA.map(r=>r.asg).filter(v=>v && v!=="—"))].sort() },
];
function closeAllMS(){ document.querySelectorAll(".ms-menu").forEach(m=>m.hidden=true); }
function msBtnInner(cfg){ const n=cfg.set().size; return `${esc(cfg.label)}${n?` <span class="ms-n">${n}</span>`:''} <span class="ms-ar">▾</span>`; }
function updateMSBtn(cfg){
  const btn=document.querySelector("#"+cfg.id+" .ms-btn"); if(!btn) return;
  btn.innerHTML=msBtnInner(cfg); btn.classList.toggle("active", cfg.set().size>0);
}
function buildMS(cfg){
  const cont=document.getElementById(cfg.id); if(!cont) return;
  const set=cfg.set(), vals=cfg.values();
  cont.innerHTML =
    `<button type="button" class="ms-btn${set.size?' active':''}">${msBtnInner(cfg)}</button>`+
    `<div class="ms-menu" hidden>`+
      (vals.length ? vals.map(v=>`<label class="ms-item"><input type="checkbox" value="${escAttr(v)}" ${set.has(v)?'checked':''}><span>${esc(v)}</span></label>`).join("")
                   : '<div class="ms-empty">항목 없음</div>')+
    `</div>`;
  const btn=cont.querySelector(".ms-btn"), menu=cont.querySelector(".ms-menu");
  btn.addEventListener("click", e=>{ e.stopPropagation(); const willOpen=menu.hidden; closeAllMS(); menu.hidden=!willOpen; });
  menu.addEventListener("click", e=>e.stopPropagation());
  menu.querySelectorAll("input").forEach(inp=>inp.addEventListener("change", ()=>{
    if(inp.checked) cfg.set().add(inp.value); else cfg.set().delete(inp.value);
    updateMSBtn(cfg); saveFilters(); openId=null; render();
  }));
}
function buildAllMS(){ MS.forEach(buildMS); }
document.addEventListener("click", closeAllMS);   // 바깥 클릭 시 메뉴 닫기

/* ---------- 필터 상태 저장/복원 (새로고침에도 유지) ---------- */
const FILTER_KEY = "slackapi_filters";
function saveFilters(){
  localStorage.setItem(FILTER_KEY, JSON.stringify({
    fpri:[...fpri], fteam:[...fteam], fstat:[...fstat], fasg:[...fasg],
    search: document.getElementById("search").value
  }));
}
function restoreFilters(){
  let s = {};
  try { s = JSON.parse(localStorage.getItem(FILTER_KEY) || "{}"); } catch(e){}
  const toSet = v => new Set(Array.isArray(v) ? v : (v ? [v] : []));   // 구버전(문자열) 호환
  fpri = toSet(s.fpri); fteam = toSet(s.fteam); fstat = toSet(s.fstat); fasg = toSet(s.fasg);
  const sv = s.search || "";
  document.getElementById("search").value = sv;
  filter = sv.toLowerCase().trim();
}

/* ---------- 뷰 토글(미지정 분할/숨김 보기) 저장·복원 ---------- */
const VIEW_KEY = "slackapi_view";
function saveView(){ localStorage.setItem(VIEW_KEY, JSON.stringify({ splitOn, showHidden })); }
function restoreView(){
  let v = {};
  try { v = JSON.parse(localStorage.getItem(VIEW_KEY) || "{}"); } catch(e){}
  if(typeof v.splitOn === "boolean")    splitOn = v.splitOn;
  if(typeof v.showHidden === "boolean") showHidden = v.showHidden;
  // 버튼/패널 UI 에 반영 (toggleHidden 텍스트+개수는 render() 에서 갱신)
  document.getElementById("unpanel").style.display = splitOn ? "" : "none";
  document.getElementById("toggleUn").textContent  = splitOn ? "미지정 숨기기" : "미지정 리스트 표시";
  document.getElementById("toggleHidden").classList.toggle("primary", showHidden);
}

/* ---------- 데이터 로드 / 동기화 ---------- */
async function load(){
  try {
    const res = await fetch("data.php", { cache:"no-store" });
    const json = await res.json();
    if(json.error){
      document.getElementById("list").innerHTML = '<div class="err">에러: ' + esc(json.error) + '</div>';
      return;
    }
    DATA = json.rows || [];
    sortData();     // 고정 우선 정렬(서버 정렬과 동일하게 보정)
    buildAllMS();   // 담당자 옵션 등 갱신(데이터 기반), 선택값 유지
    render();
    document.getElementById("updated").textContent = "마지막 갱신: " + new Date().toLocaleTimeString("ko-KR");
  } catch(e){
    document.getElementById("list").innerHTML = '<div class="err">불러오기 실패</div>';
  }
}

document.getElementById("sync").addEventListener("click", async ()=>{
  const btn = document.getElementById("sync"); btn.disabled = true; btn.textContent = "동기화 중…";
  try{
    const res = await fetch("sync.php", { cache:"no-store" });
    const j = await res.json();
    if(!j.ok) throw new Error(j.error || "동기화 실패");
    await load();
    if(j.skipped === 'locked'){
      alert("다른 동기화가 진행 중입니다. 잠시 후 자동 반영됩니다.");
    } else {
      alert(`동기화 완료 (${j.mode==='full'?'전체':'증분'})\n`
        + `스캔 ${j.scanned} · 변경 ${j.changed}건 (신규 ${j.inserted} · 갱신 ${j.updated} · 보존 ${j.skipped})`);
    }
  }catch(err){ alert("동기화 실패: " + err.message); }
  finally{ btn.disabled = false; btn.textContent = "동기화"; }
});

document.getElementById("search").addEventListener("input",e=>{ filter=e.target.value.toLowerCase().trim(); saveFilters(); openId=null; render(); });
document.getElementById("reset").addEventListener("click",()=>{
  fpri = new Set(); fteam = new Set(); fstat = new Set(); fasg = new Set(); filter = "";
  document.getElementById("search").value = "";
  saveFilters();        // 비운 상태 저장
  buildAllMS();         // 버튼 카운트/체크 초기화
  openId = null; render();
});
document.getElementById("readSel").addEventListener("click",()=>bulkRead(1));
document.getElementById("unreadSel").addEventListener("click",()=>bulkRead(0));
document.getElementById("toggleUn").addEventListener("click",()=>{
  splitOn = !splitOn;
  document.getElementById("unpanel").style.display = splitOn ? "" : "none";
  document.getElementById("toggleUn").textContent = splitOn ? "미지정 숨기기" : "미지정 리스트 표시";
  saveView();
  render();
});
document.getElementById("toggleHidden").addEventListener("click",()=>{
  showHidden = !showHidden;
  document.getElementById("toggleHidden").classList.toggle("primary", showHidden);
  saveView();
  openId = null; render();
});
document.getElementById("selAll").addEventListener("change", e=>{
  if(e.target.checked) filteredItems().forEach(r=>selected.add(r.id));  // 보이는 항목 전체 선택
  else selected.clear();
  render();
});

/* ---------- 백그라운드 동기화 감지 → 화면 리로드 없이 목록만 자동 갱신 ---------- */
let lastChanged = null;
async function pollStatus(){
  try{
    const s = await (await fetch("status.php", { cache:"no-store" })).json();
    if(lastChanged === null){ lastChanged = s.changed_at; return; }   // 최초 호출은 기준값만
    if(s.changed_at > lastChanged){                                   // 변경된 동기화 완료 감지
      if(openId){ return; }                                           // 항목을 펼쳐 보는 중엔 보류(재정렬로 포커스 사라짐 방지)
      lastChanged = s.changed_at;
      await load();                                                   // DOM만 조용히 갱신(페이지 리로드 X)
    }
  }catch(e){ /* 무시하고 다음 폴링 */ }
}
setInterval(pollStatus, 5000);   // 5초마다 가볍게 상태 확인(DB 1행 조회)

/* ---------- 백그라운드 동기화 (JS fetch → 콘솔창 없음) ---------- */
async function bgSync(){
  try{ await fetch("sync.php", { cache:"no-store" }); }catch(e){}
  // 결과 반영은 pollStatus 가 changed_at 변화를 감지해 자동 처리
}
setInterval(bgSync, 60000);   // 60초마다 백그라운드 동기화

restoreFilters();  // 새로고침 전 필터 복원 (localStorage)
restoreView();     // 미지정 분할/숨김 보기 토글 상태 복원
buildAllMS();      // 헤더 다중선택 필터 생성 — 복원된 선택값 반영
load();
pollStatus();          // 기준값 즉시 설정
bgSync().then(load);   // 진입 즉시 동기화 → 완료되면 화면 1회 갱신(최신 반영)
</script>
</body>
</html>
