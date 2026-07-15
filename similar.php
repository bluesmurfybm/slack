<?php
/**
 * 유사/중복 요청 찾기 UI. 로그인 필요.
 *  - 기존 항목 검색·선택하거나 텍스트를 붙여넣어 유사(중복 의심) 항목을 보여줌 (보관 포함).
 *  - 유사도 계산은 similar_api.php (IDF 가중).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
$me = current_user();
session_release();
$listUrl = (require __DIR__ . '/config.php')['list_url'] ?? '';
// 피커용 경량 목록 (id/제목/보드/보관)
$items = db()->query("SELECT id, title, board, archived FROM requests ORDER BY created DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($items as &$it) { $it['archived'] = (int)$it['archived']; } unset($it);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>유사 요청 찾기</title>
<style>
  :root { --bg:#fff; --bg2:#f6f7f8; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --hint:#8b949e; --info:#0c447c; --info-bg:#e6f1fb; }
  @media (prefers-color-scheme: dark){ :root{ --bg:#1a1d21; --bg2:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --hint:#6b7177; --info:#85b7eb; --info-bg:#0c2740; } }
  *{box-sizing:border-box;} body{font-family:-apple-system,"Malgun Gothic",sans-serif;background:var(--bg2);color:var(--txt);margin:0;padding:24px;}
  .wrap{max-width:1000px;margin:0 auto;}
  .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;}
  .head h1{font-size:18px;margin:0;} .tag{font-size:11px;background:#fff0e0;color:#a85b00;border-radius:6px;padding:2px 8px;}
  a.back{font-size:13px;color:var(--info);text-decoration:none;}
  input,button,select,textarea{font-family:inherit;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--txt);font-size:13px;}
  input,button,select{height:34px;padding:0 10px;}
  /* 모든 버튼: 아이콘/텍스트 수직·수평 가운데 정렬 */
  button{cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}
  button[hidden]{display:none !important;}
  button.primary{background:var(--info);color:#fff;border-color:var(--info);}
  .panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:14px;}
  .row1{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;}
  .row1 label{font-size:12px;color:var(--muted);}
  #pick{position:relative;flex:1;min-width:220px;}
  #pickInput{width:100%;}
  #pickMenu{position:absolute;z-index:30;top:36px;left:0;right:0;max-height:300px;overflow-y:auto;background:var(--bg);border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 22px rgba(0,0,0,.18);padding:4px;}
  #pickMenu[hidden]{display:none;} .pk{padding:6px 9px;border-radius:6px;font-size:13px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pk:hover{background:var(--info-bg);}
  textarea{width:100%;min-height:70px;padding:8px 10px;line-height:1.5;}
  .self{font-size:13px;color:var(--muted);margin-bottom:10px;padding:8px 12px;background:var(--bg2);border:1px solid var(--line);border-radius:8px;}
  .self b{color:var(--txt);}
  .res{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--bg);}
  .r{display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid var(--line);cursor:pointer;}
  .rwrap:last-child .r{border-bottom:0;} .r:hover{background:var(--bg2);}
  .rcaret{flex:none;color:var(--hint);font-size:12px;transition:transform .15s;}
  .rwrap.open .rcaret{transform:rotate(180deg);}
  .rwrap.open .r{background:var(--bg2);}
  .rdetail{display:none;container-type:inline-size;} .rwrap.open .rdetail{display:block;}
  /* ===== lists.php 상세/댓글 UI 이식 ===== */
  .detail{padding:16px 20px 20px;border-bottom:1px solid var(--line);background:var(--bg2);}
  .rwrap:last-child .detail{border-bottom:0;}
  .detail.with-cmts{display:flex;gap:20px;align-items:flex-start;}
  .detail-main{flex:1;min-width:0;}
  .detail-cmts{flex:none;width:380px;max-width:90%;min-width:280px;display:flex;flex-direction:column;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:12px 14px;}
  @container (max-width:900px){ .detail.with-cmts{flex-direction:column;} .detail-cmts{width:100%;max-width:100%;} }
  .meta{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;}
  .mi{display:flex;align-items:center;gap:6px;font-size:12px;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:4px 10px;}
  .ml{color:var(--hint);} .mv{color:var(--txt);font-weight:500;}
  .st{font-size:11px;padding:2px 9px;border-radius:8px;white-space:nowrap;}
  .body-card{font-size:13px;line-height:1.75;white-space:normal;word-break:break-word;color:var(--txt);background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:14px 16px;}
  .body-card a,.cmt-b a{color:#1a73e8;font-weight:600;text-decoration:underline;text-underline-offset:2px;}
  .body-card a:hover,.cmt-b a:hover{color:#0b57d0;}
  .body-card code,.cmt-b code{background:var(--bg2);border:1px solid var(--line);border-radius:4px;padding:0 4px;font-family:Consolas,monospace;font-size:12px;}
  .body-card pre,.cmt-b pre{background:var(--bg2);border:1px solid var(--line);border-radius:6px;padding:8px 10px;margin:6px 0;overflow:auto;font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;}
  .body-card blockquote,.cmt-b blockquote{margin:4px 0;padding:1px 0 1px 12px;border-left:4px solid var(--hint);color:var(--txt);}
  .dlinks{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center;}
  .dlinks a,.dlinks button{font-size:12px;text-decoration:none;color:var(--info);background:var(--bg);border:1px solid var(--line);padding:7px 11px;border-radius:8px;}
  .dlinks .slack-link{color:#fff;background:#4a154b;border-color:#4a154b;}
  .cmts-title{font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
  .cmts-n{background:var(--info-bg);color:var(--info);border-radius:9px;padding:0 7px;font-size:11px;}
  .cmts{display:flex;flex-direction:column;overflow-y:auto;max-height:60vh;padding-right:4px;}
  .cmt{display:flex;gap:8px;padding:6px 0;border-bottom:1px solid var(--line);}
  .cmt:last-child{border-bottom:0;}
  .cmt-av{flex:none;width:20px;height:20px;margin-top:1px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;}
  .cmt-bub{flex:1;min-width:0;}
  .cmt-h b{font-weight:600;font-size:12px;} .cmt-t{color:var(--hint);margin-left:6px;font-size:10px;}
  .cmt-b{font-size:12.5px;line-height:1.5;white-space:normal;word-break:break-word;color:var(--txt);margin-top:1px;}
  .cmt-files{display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;}
  .cmt-img{max-width:180px;max-height:180px;border-radius:6px;border:1px solid var(--line);display:block;object-fit:cover;background:var(--bg2);cursor:zoom-in;}
  .cmt-filedl{font-size:12px;color:var(--info);text-decoration:none;border:1px solid var(--line);border-radius:6px;padding:3px 8px;}
  .cmt-loading,.cmt-empty{font-size:12px;color:var(--hint);padding:8px 0;}
  .sc{flex:none;width:52px;font-weight:700;font-size:13px;color:var(--info);text-align:right;}
  .rmain{flex:1;min-width:0;} .rt{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .rsnip{font-size:12px;color:var(--hint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
  .chip{font-size:10px;padding:1px 7px;border-radius:8px;white-space:nowrap;}
  .c-blue{background:#e6f1fb;color:#0c447c;} .c-yoz{background:#e0f2f1;color:#00695c;} .c-arch{background:#e5e7eb;color:#4b5563;}
  .mention{background:var(--info-bg);color:var(--info);border-radius:4px;padding:0 3px;font-weight:600;}
  .cemoji{width:16px;height:16px;vertical-align:-3px;}
  .rmeta{flex:none;font-size:11px;color:var(--muted);white-space:nowrap;} .rlink{flex:none;font-size:11px;color:#4a154b;text-decoration:none;border:1px solid var(--line);border-radius:6px;padding:3px 8px;}
  .empty{padding:24px;text-align:center;color:var(--muted);}
</style>
</head>
<body>
<div class="wrap">
  <div class="head"><h1>🔍 유사 요청 찾기</h1><a class="back" href="lists.php">← 목록으로</a></div>

  <div class="panel">
    <div class="row1">
      <div id="pick"><input id="pickInput" type="text" placeholder="기존 항목 제목 검색…(선택 시 그 항목과 유사한 것 표시)"><div id="pickMenu" hidden></div></div>
      <label>임계값 <select id="min"><option>0.1</option><option selected>0.15</option><option>0.2</option><option>0.25</option><option>0.3</option></select></label>
    </div>
    <textarea id="q" placeholder="또는 내용을 붙여넣고 [유사 찾기] — 새 요청이 기존/보관 이력과 겹치는지 확인"></textarea>
    <div style="margin-top:8px;text-align:right;"><button class="primary" id="run">유사 찾기</button></div>
  </div>

  <div id="self"></div>
  <div id="out" class="res"><div class="empty">항목을 선택하거나 내용을 입력하세요.</div></div>
  <div id="cnt" style="font-size:12px;color:var(--hint);margin-top:8px;"></div>
</div>

<script>
const ITEMS = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
const LIST_URL = <?= json_encode($listUrl) ?>;
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escA(s){ return esc(s).replace(/"/g,"&quot;"); }
function boardChip(b,arch){ const c=b==='와이오즈'?'c-yoz':'c-blue', l=b==='와이오즈'?'와이오즈':'유비온'; return `<span class="chip ${c}">${l}</span>`+(arch?`<span class="chip c-arch">보관</span>`:''); }

/* ===== lists.php 렌더 헬퍼 이식 (본문 서식 + 댓글 버블) ===== */
const escAttr = escA;
function unslack(s){ return s.replace(/&amp;(amp|lt|gt|quot|#39|#x27);/g,"&$1;"); }
const EMOJI = {smile:"😄",smiley:"😃",grinning:"😀",grin:"😁",joy:"😂",rofl:"🤣",sweat_smile:"😅",laughing:"😆",wink:"😉",blush:"😊",slightly_smiling_face:"🙂",yum:"😋",sunglasses:"😎",heart_eyes:"😍",thinking_face:"🤔",hugging_face:"🤗",neutral_face:"😐",smirk:"😏",unamused:"😒",roll_eyes:"🙄",sweat:"😓",pensive:"😔",confused:"😕",worried:"😟",disappointed:"😞",tired_face:"😫",weary:"😩",cry:"😢",sob:"😭",angry:"😠",rage:"😡",scream:"😱",flushed:"😳",open_mouth:"😮",sleeping:"😴",zzz:"💤","+1":"👍",thumbsup:"👍","-1":"👎",thumbsdown:"👎",ok_hand:"👌",punch:"👊",fist:"✊",v:"✌️",wave:"👋",raised_hands:"🙌",pray:"🙏",clap:"👏",muscle:"💪",point_up:"☝️",point_down:"👇",point_left:"👈",point_right:"👉",bow:"🙇",see_no_evil:"🙈",heart:"❤️",broken_heart:"💔",blue_heart:"💙",fire:"🔥",star:"⭐",sparkles:"✨",zap:"⚡",boom:"💥",tada:"🎉","100":"💯",white_check_mark:"✅",heavy_check_mark:"✔️",x:"❌",o:"⭕",warning:"⚠️",exclamation:"❗",question:"❓",bulb:"💡",rocket:"🚀",eyes:"👀",ok:"🆗","new":"🆕",hourglass:"⏳",alarm_clock:"⏰",calendar:"📅",memo:"📝",pencil2:"✏️",pushpin:"📌",paperclip:"📎",link:"🔗",mag:"🔍",lock:"🔒",key:"🔑",bell:"🔔",email:"✉️",computer:"💻",hammer:"🔨",wrench:"🔧",gear:"⚙️",package:"📦",chart_with_upwards_trend:"📈",bar_chart:"📊",clipboard:"📋",coffee:"☕",check:"✔️",robot_face:"🤖",speech_balloon:"💬"};
const MENTION_NAMES = {};
/* 커스텀 이모지 맵 (lists.php 와 동일 소스) — 페이지 로드 시 미리 받아둠 */
let _EM = null;
(async()=>{ try{ _EM = await (await fetch("emoji_meta.php")).json(); }catch(e){ _EM = {custom:{}}; } })();
/* 미해석 멘션(@…)을 user_info.php 로 lazy 해석 — lists.php 와 동일 */
async function resolveMentions(scope){
  const els=[...(scope||document).querySelectorAll('.mention[data-unres]')];
  if(!els.length) return;
  const ids=[...new Set(els.map(e=>e.dataset.uid))];
  try{
    const j = await (await fetch("user_info.php?ids="+ids.join(","))).json();
    Object.assign(MENTION_NAMES, j.users||{});
    els.forEach(e=>{ const n=MENTION_NAMES[e.dataset.uid]; if(n){ e.textContent="@"+n; e.removeAttribute("data-unres"); } });
  }catch(e){ /* 다음 렌더에서 재시도 */ }
}
function mrkdwn(t){
  if(!t) return '';
  var ph=[]; var stash=function(h){ ph.push(h); return ''+(ph.length-1)+''; };
  t = t.replace(/```([\s\S]*?)```/g,function(m,c){ return stash('<pre>'+unslack(esc(c.replace(/^\n|\n$/g,'')))+'</pre>'); });
  t = t.replace(/`([^`\n]+)`/g,function(m,c){ return stash('<code>'+unslack(esc(c))+'</code>'); });
  t = t.replace(/<((?:https?:\/\/|tel:|mailto:)[^|>]+)\|([^>]+)>/g,function(m,u,l){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(l))+'</a>'); });
  t = t.replace(/<((?:https?:\/\/|tel:|mailto:)[^>]+)>/g,function(m,u){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u.replace(/^(?:tel|mailto):/,'')))+'</a>'); });
  t = t.replace(/https?:\/\/[^\s<>]+/g,function(u){ var tail=''; var mt=u.match(/[*_~`)\]}.,;:!?]+$/); if(mt){ tail=mt[0]; u=u.slice(0,-tail.length); } return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u))+'</a>')+tail; });
  // @멘션: <@UID> → 파란 멘션 배지. 이름 미해석분은 표시 후 lazy 해석 (lists.php 와 동일)
  t = t.replace(/<@([UW][A-Z0-9]+)>/g,function(m,id){
    var nm = MENTION_NAMES[id];
    return stash('<span class="mention" data-uid="'+escAttr(id)+'"'+(nm?'':' data-unres="1"')+'>@'+esc(nm||'…')+'</span>');
  });
  t = unslack(esc(t));
  // Slack 처럼 "단어 경계" 에서만 서식 적용 (식별자 중간 _ / * 보호)
  t = t.replace(/(?<![\w가-힣*])\*(?!\s)([^*\n]+?)\*(?!\w)/g,'<b>$1</b>');
  t = t.replace(/(?<![\w가-힣_])_(?!\s)([^_\n]+?)_(?!\w)/g,'<i>$1</i>');
  t = t.replace(/(?<![\w가-힣~])~(?!\s)([^~\n]+?)~(?!\w)/g,'<s>$1</s>');
  t = t.replace(/:skin-tone-[2-6]:/g,'');
  // 표준 맵 → 커스텀 이미지(emoji_meta) → 미해석은 원문 유지 (lists.php 와 동일)
  t = t.replace(/:([a-z0-9_+가-힣-]+):/g,function(m,n){
    if(EMOJI[n]) return EMOJI[n];
    var u=(_EM && _EM.custom || {})[n];
    return u ? '<img class="cemoji" src="'+escAttr(u)+'" alt=":'+escAttr(n)+':">' : m;
  });
  t = quoteBlocks(t);
  t = t.replace(/\n/g,'<br>');
  t = t.replace(/(\d+)/g,function(m,i){ return ph[+i]; });
  return t;
}
function quoteBlocks(txt){
  var lines=txt.split('\n'), out=[], buf=[];
  function flush(){ if(buf.length){ out.push('<blockquote>'+buf.join('<br>')+'</blockquote>'); buf=[]; } }
  for(var k=0;k<lines.length;k++){
    var mq=lines[k].match(/^\s*&gt;\s?(.*)$/);
    if(mq){ buf.push(mq[1]); } else { flush(); out.push(lines[k]); }
  }
  flush();
  return out.join('\n');
}
function cmtInitial(n){ return (n||"?").trim().slice(0,1) || "?"; }
function authorColor(name){ let h=0; const s=(name||"?"); for(let i=0;i<s.length;i++) h=(h*31+s.charCodeAt(i))>>>0; const hue=h%360; return { bg:`hsl(${hue} 68% 92%)`, fg:`hsl(${hue} 45% 35%)` }; }
function metaItem(label,val){ return `<div class="mi"><span class="ml">${esc(label)}</span><span class="mv">${esc(val)}</span></div>`; }
function p2(n){ return n<10?"0"+n:""+n; }
function fmtCreated(ts){ if(!ts) return '—'; const d=new Date(ts*1000); return d.getFullYear()+"-"+p2(d.getMonth()+1)+"-"+p2(d.getDate()); }
function cmtHtml(list){
  if(!list||!list.length) return '<div class="cmt-empty">아직 댓글이 없습니다.</div>';
  return list.map(c=>{
    const col=authorColor(c.author_name);
    return `
    <div class="cmt">
      <div class="cmt-av" style="background:${col.bg};color:${col.fg}">${esc(cmtInitial(c.author_name))}</div>
      <div class="cmt-bub">
        <div class="cmt-h"><b style="color:${col.fg}">${esc(c.author_name)}</b><span class="cmt-t">${esc(c.created_at)}</span></div>
        ${c.body?`<div class="cmt-b">${mrkdwn(c.body)}</div>`:''}
        ${(c.files&&c.files.length)?`<div class="cmt-files">${c.files.map(f=>f.is_image
          ? `<a href="file.php?u=${encodeURIComponent(f.url)}" target="_blank" rel="noopener"><img class="cmt-img" src="file.php?u=${encodeURIComponent(f.thumb||f.url)}" alt="${escAttr(f.name)}" title="${escAttr(f.name)}" loading="lazy"></a>`
          : `<a class="cmt-filedl" href="file.php?u=${encodeURIComponent(f.url)}&dl=1&name=${encodeURIComponent(f.name)}" rel="noopener">📎 ${esc(f.name)}</a>`
        ).join("")}</div>`:''}
      </div>
    </div>`;
  }).join("");
}

/* ---- 기존 항목 검색 피커 ---- */
const pickInput=document.getElementById("pickInput"), pickMenu=document.getElementById("pickMenu");
pickInput.addEventListener("input",()=>{
  const q=pickInput.value.trim().toLowerCase();
  if(!q){ pickMenu.hidden=true; return; }
  const m=ITEMS.filter(it=>(it.title||"").toLowerCase().includes(q)).slice(0,20);
  pickMenu.innerHTML = m.length ? m.map(it=>`<div class="pk" data-id="${escA(it.id)}">${it.archived?'🗄️ ':''}${esc(it.title)}</div>`).join("") : '<div class="pk" style="color:var(--hint)">결과 없음</div>';
  pickMenu.hidden=false;
  pickMenu.querySelectorAll(".pk[data-id]").forEach(el=>el.addEventListener("click",()=>{ pickInput.value=""; pickMenu.hidden=true; findById(el.dataset.id); }));
});
document.addEventListener("click",e=>{ if(!document.getElementById("pick").contains(e.target)) pickMenu.hidden=true; });

document.getElementById("run").addEventListener("click",()=>{
  const q=document.getElementById("q").value.trim();
  if(!q){ alert("내용을 입력하거나 위에서 항목을 선택하세요."); return; }
  findByText(q);
});

async function findById(id){ document.getElementById("q").value=""; await run("id="+encodeURIComponent(id)); }

// lists.php 상세의 "🔍 유사 이력" 버튼으로 ?id= 로 열리면 자동 조회
(function(){ const p=new URLSearchParams(location.search).get("id"); if(p) findById(p); })();
async function findByText(q){ await run("q="+encodeURIComponent(q)); }
async function run(param){
  const min=document.getElementById("min").value;
  document.getElementById("out").innerHTML='<div class="empty">찾는 중…</div>';
  document.getElementById("self").innerHTML=""; document.getElementById("cnt").textContent="";
  try{
    const j=await (await fetch("similar_api.php?"+param+"&min="+min+"&limit=50",{cache:"no-store"})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    if(j.self) document.getElementById("self").innerHTML=`<div class="self">기준: ${boardChip(j.self.board,j.self.archived)} <b>${esc(j.self.title)}</b></div>`;
    render(j.results||[]);
  }catch(e){ document.getElementById("out").innerHTML='<div class="empty">오류: '+esc(e.message)+'</div>'; }
}
let RESULTS={};
function render(list){
  RESULTS={}; list.forEach(r=>RESULTS[r.id]=r);
  document.getElementById("cnt").textContent = "유사 후보 "+list.length+"건 (임계값 이상) · 행 클릭 시 상세·댓글";
  const out=document.getElementById("out");
  if(!list.length){ out.innerHTML='<div class="empty">유사한 항목이 없습니다. 임계값을 낮춰보세요.</div>'; return; }
  out.innerHTML=list.map(r=>`
    <div class="rwrap">
      <div class="r" data-id="${escA(r.id)}">
        <div class="sc">${Math.round(r.score*100)}%</div>
        <div class="rmain">
          <div class="rt">${boardChip(r.board,r.archived)} ${esc(r.title)}</div>
          <div class="rsnip">${esc(r.snip||'')}</div>
        </div>
        <div class="rmeta">${esc(r.req||'—')}${r.asg&&r.asg!=='—'?' → '+esc(r.asg):''}<br>${esc(r.status||'')}</div>
        ${LIST_URL?`<a class="rlink" href="${escA(LIST_URL)}?record_id=${escA(r.id)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">🔗 열기</a>`:''}
        <div class="rcaret">▾</div>
      </div>
      <div class="rdetail" data-detail="${escA(r.id)}"></div>
    </div>`).join("");
  out.querySelectorAll(".r[data-id]").forEach(row=>row.addEventListener("click",()=>toggleDetail(row.dataset.id)));
}
async function toggleDetail(id){
  const d=document.querySelector('.rdetail[data-detail="'+id+'"]'); if(!d) return;
  const wrap=d.parentElement;
  if(wrap.classList.contains("open")){ wrap.classList.remove("open"); d.innerHTML=""; return; }
  wrap.classList.add("open");
  const r=RESULTS[id]||{};
  d.innerHTML=`
    <div class="detail with-cmts">
      <div class="detail-main">
        <div class="meta">
          ${metaItem('진행상태', r.status||'—')}
          ${metaItem('요청자', r.req||'—')}
          ${metaItem('담당자', (r.asg && r.asg!=='—')?r.asg:'미지정')}
          ${metaItem('요청일', fmtCreated(r.created))}
          ${r.archived?`<div class="mi"><span class="mv">🗄️ 보관</span></div>`:''}
        </div>
        <div class="body-card">${mrkdwn(r.body||r.snip||'(내용 없음)')}</div>
        <div class="dlinks">
          ${LIST_URL?`<button type="button" class="slack-link copyLink" data-url="${escA(LIST_URL)}?record_id=${escA(id)}">링크 복사</button>`:''}
        </div>
      </div>
      <div class="detail-cmts">
        <div class="cmts-title">💬 댓글</div>
        <div class="cmts" id="scmts-${escA(id)}"><div class="cmt-loading">댓글 불러오는 중…</div></div>
      </div>
    </div>`;
  // 링크 복사 (lists.php 와 동일 동작)
  d.querySelectorAll(".copyLink").forEach(el=>el.addEventListener("click", async e=>{
    e.stopPropagation();
    const url = el.dataset.url, old = el.textContent;
    try{ await navigator.clipboard.writeText(url); }
    catch(_){ const ta=document.createElement("textarea"); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove(); }
    el.textContent = "복사됨!";
    setTimeout(()=>{ el.textContent = old; }, 1200);
  }));
  resolveMentions(d);                                        // 본문 멘션 이름 해석
  try{
    const j=await (await fetch("comments.php?request_id="+encodeURIComponent(id),{cache:"no-store"})).json();
    const cs=j.comments||[];
    Object.assign(MENTION_NAMES, j.users||{});               // 댓글 멘션 이름 맵 병합
    const box=document.getElementById("scmts-"+id);
    if(box){ box.innerHTML=cmtHtml(cs); box.scrollTop=box.scrollHeight; resolveMentions(box); }
    const tt=d.querySelector(".cmts-title");
    if(tt) tt.innerHTML='💬 댓글'+(cs.length?` <span class="cmts-n">${cs.length}</span>`:'');
  }catch(e){ const box=document.getElementById("scmts-"+id); if(box) box.innerHTML='<div class="cmt-empty">댓글 로드 실패</div>'; }
}
</script>
</body>
</html>
