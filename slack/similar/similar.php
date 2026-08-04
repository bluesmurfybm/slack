<?php
/**
 * 유사/중복 요청 찾기 UI. 로그인 필요.
 *  - 기존 항목 검색·선택하거나 텍스트를 붙여넣어 유사(중복 의심) 항목을 보여줌 (보관 포함).
 *  - 유사도 계산은 similar_api.php (IDF 가중).
 */
$__bwBase = '../';   // slack/ 하위 폴더 페이지 — require_login()/header.php 리다이렉트 경로 계산용
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_login();
$me = current_user();
session_release();
$listUrl = (require __DIR__ . '/../../config.php')['list_url'] ?? '';
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
<link rel="icon" href="../../styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/header.css">
<link rel="stylesheet" href="../styles/common.css">
<link rel="stylesheet" href="../styles/similar.css">
</head>
<body>
<?php include __DIR__ . '/../header.php'; ?>
<div class="wrap">
  <div class="head"><h1>🔍 유사 요청 찾기</h1><a class="back" href="../lists.php">← 목록으로</a></div>

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
function mrkdwn(t){
  if(!t) return '';
  var ph=[]; var SENT=String.fromCharCode(1); var stash=function(h){ ph.push(h); return SENT+(ph.length-1)+SENT; };   // placeholder 를 sentinel 로 감싸 본문 숫자와 구분
  t = t.replace(/```([\s\S]*?)```/g,function(m,c){ return stash('<pre>'+unslack(esc(c.replace(/^\n|\n$/g,'')))+'</pre>'); });
  t = t.replace(/`([^`\n]+)`/g,function(m,c){ return stash('<code>'+unslack(esc(c))+'</code>'); });
  t = t.replace(/<(https?:\/\/[^|>]+)\|([^>]+)>/g,function(m,u,l){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(l))+'</a>'); });
  t = t.replace(/<(https?:\/\/[^>]+)>/g,function(m,u){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u))+'</a>'); });
  t = t.replace(/https?:\/\/[^\s<>]+/g,function(u){ var tail=''; var mt=u.match(/[*_~`)\]}.,;:!?]+$/); if(mt){ tail=mt[0]; u=u.slice(0,-tail.length); } return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u))+'</a>')+tail; });
  t = unslack(esc(t));
  // 단어 경계에서만 서식 적용 — 식별자 중간의 _ / * (예: es_course_plan)가 서식으로 먹히지 않게
  t = t.replace(/(?<![\w가-힣*])\*(?!\s)([^*\n]+?)\*(?!\w)/g,'<b>$1</b>');
  t = t.replace(/(?<![\w가-힣_])_(?!\s)([^_\n]+?)_(?!\w)/g,'<i>$1</i>');
  t = t.replace(/(?<![\w가-힣~])~(?!\s)([^~\n]+?)~(?!\w)/g,'<s>$1</s>');
  t = t.replace(/:skin-tone-[2-6]:/g,'');
  t = t.replace(/:([a-z0-9_+-]+):/g,function(m,n){ return EMOJI[n]||m; });
  t = quoteBlocks(t);
  t = t.replace(/\n/g,'<br>');
  t = t.replace(new RegExp(String.fromCharCode(1)+'(\\d+)'+String.fromCharCode(1),'g'),function(m,i){ return ph[+i]; });
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
/* ===== 첨부파일 렌더 + 라이트박스(모달 슬라이드) — lists.php 이식 ===== */
const _pf = u => "../file.php?u="+encodeURIComponent(u);   // similar/ 하위 폴더 → ../file.php
function fmtSize(n){ if(!n) return ""; if(n<1024) return n+"B"; if(n<1048576) return Math.round(n/1024)+"KB"; return (n/1048576).toFixed(1)+"MB"; }
function fileExt(n){ const m=(n||"").match(/\.([a-z0-9]+)$/i); return m?m[1].toUpperCase():"FILE"; }
function docType(name){ const e=((name||"").split(".").pop()||"").toLowerCase(); if(e==="pdf") return "pdf"; if(e==="xlsx"||e==="xls"||e==="csv") return "sheet"; return ""; }
function isVideo(f){ const e=((f.name||"").split(".").pop()||"").toLowerCase(); return (f.mime||"").indexOf("video/")===0 || ["mp4","mov","webm","m4v","ogv","avi","mkv"].includes(e); }
function fileHtml(f, imgCls){
  const dl = _pf(f.download||f.url)+"&dl=1&name="+encodeURIComponent(f.name);
  if(isVideo(f)){
    const vsrc = _pf(f.mp4 || f.download || f.url);
    const poster = f.thumb_video ? _pf(f.thumb_video) : (f.is_image && f.thumb ? _pf(f.thumb) : "");
    return `<div class="media-video lb" data-type="video" data-src="${escAttr(vsrc)}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" title="${escAttr(f.name)}">`
      + (poster ? `<img src="${poster}" alt="${escAttr(f.name)}" loading="lazy">` : `<div class="media-noposter">🎬</div>`)
      + `<span class="media-play">▶</span></div>`;
  }
  if(f.is_image && f.thumb){
    return `<img class="${imgCls} lb" src="${_pf(f.thumb)}" data-full="${escAttr(_pf(f.url))}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" alt="${escAttr(f.name)}" loading="lazy" title="${escAttr(f.name)}">`;
  }
  const dt = docType(f.name);
  if(dt){
    const src=escAttr(_pf(f.url)), badge=esc(fileExt(f.name));
    if(f.thumb_pdf){
      return `<div class="media-doc lb" data-type="${dt}" data-src="${src}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" title="${escAttr(f.name)}">`
        + `<img src="${_pf(f.thumb_pdf)}" alt="${escAttr(f.name)}" loading="lazy">`
        + `<span class="media-doc-badge">${badge}</span></div>`;
    }
    return `<div class="media-doc media-doc--noimg lb" data-type="${dt}" data-src="${src}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" title="${escAttr(f.name)}">`
      + `<span class="media-doc-ic">${dt==="sheet"?"📊":"📄"}</span>`
      + `<span class="media-doc-nm">${esc(f.name)}</span><span class="media-doc-badge">${badge}</span></div>`;
  }
  if(f.thumb_pdf){
    return `<a class="media-doc" href="${_pf(f.url)}" target="_blank" rel="noopener" title="${escAttr(f.name)}">`
      + `<img src="${_pf(f.thumb_pdf)}" alt="${escAttr(f.name)}" loading="lazy">`
      + `<span class="media-doc-badge">${esc(fileExt(f.name))}</span></a>`;
  }
  return `<a class="att-file" href="${dl}" rel="noopener">📎 <span>${esc(f.name)}</span>${f.size?`<span class="sz">${fmtSize(f.size)}</span>`:""}</a>`;
}
function attHtml(list){
  if(!list || !list.length) return "";
  const items = list.map(f=>fileHtml(f, "att-img")).join("");
  return `<div class="atts"><div class="atts-title">📎 첨부파일 ${list.length}</div><div class="atts-list">${items}</div></div>`;
}
/* 이미지/미디어 클릭 → 라이트박스 열기 (같은 묶음 내 항목들로 슬라이드) */
function bindLightbox(scope){
  scope.querySelectorAll(".lb").forEach(el=>{
    el.addEventListener("click", e=>{
      e.stopPropagation();
      const group = el.closest(".atts-list, .cmt-files") || scope;
      const nodes = [...group.querySelectorAll(".lb")];
      const imgs = nodes.map(x=>({ type:x.dataset.type||"image", full:x.dataset.full, src:x.dataset.src,
                                   name:x.dataset.name||"", dl:x.dataset.dl||x.dataset.full }));
      lbOpen(imgs, Math.max(0, nodes.indexOf(el)));
    });
  });
}
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
        ${(c.files&&c.files.length)?`<div class="cmt-files">${c.files.map(f=>fileHtml(f,"cmt-img")).join("")}</div>`:''}
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
  pickMenu.innerHTML = m.length ? m.map(it=>`<div class="pk" data-id="${escA(it.id)}">${it.archived?'🗄 ':''}${esc(it.title)}</div>`).join("") : '<div class="pk" style="color:var(--hint)">결과 없음</div>';
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
          ${r.archived?`<div class="mi"><span class="mv">🗄 보관</span></div>`:''}
        </div>
        <div class="body-card">${mrkdwn(r.body||r.snip||'(내용 없음)')}</div>
        ${attHtml(r.attachments)}
        <div class="dlinks">
          ${LIST_URL?`<button type="button" class="slack-link copyLink" data-url="${escA(LIST_URL)}?record_id=${escA(id)}">🔗 링크 복사</button>`:''}
        </div>
      </div>
      <div class="detail-cmts">
        <div class="cmts-title">💬 댓글</div>
        <div class="cmts" id="scmts-${escA(id)}"><div class="cmt-loading">댓글 불러오는 중…</div></div>
      </div>
    </div>`;
  bindLightbox(d);   // 본문 첨부 → 모달
  d.querySelectorAll(".copyLink").forEach(el=>{
    el.addEventListener("click", async e=>{
      e.stopPropagation();
      const url=el.dataset.url, old=el.textContent;
      try{ await navigator.clipboard.writeText(url); }
      catch(_){ const ta=document.createElement("textarea"); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove(); }
      el.textContent="복사됨!"; setTimeout(()=>{ el.textContent=old; }, 1200);
    });
  });
  try{
    const j=await (await fetch("../comments.php?request_id="+encodeURIComponent(id),{cache:"no-store"})).json();
    const cs=j.comments||[];
    const box=document.getElementById("scmts-"+id);
    if(box){ box.innerHTML=cmtHtml(cs); box.scrollTop=box.scrollHeight; bindLightbox(box); }   // 댓글 첨부 → 모달
    const tt=d.querySelector(".cmts-title");
    if(tt) tt.innerHTML='💬 댓글'+(cs.length?` <span class="cmts-n">${cs.length}</span>`:'');
  }catch(e){ const box=document.getElementById("scmts-"+id); if(box) box.innerHTML='<div class="cmt-empty">댓글 로드 실패</div>'; }
}

/* ===== 이미지 라이트박스(모달 슬라이드) — lists.php 이식 ===== */
document.body.insertAdjacentHTML("beforeend", `
  <div id="lightbox" role="dialog" aria-modal="true">
    <button id="lb-close" title="닫기 (Esc)">✕</button>
    <button class="lb-btn" id="lb-prev" title="이전 (←)"><svg viewBox="0 0 24 24" width="38" height="38" fill="currentColor" aria-hidden="true"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></button>
    <button class="lb-btn" id="lb-next" title="다음 (→)"><svg viewBox="0 0 24 24" width="38" height="38" fill="currentColor" aria-hidden="true"><path d="M10 6 8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></button>
    <div class="lb-stage">
      <div id="lb-loading"><span class="lb-spin"></span>불러오는 중…</div>
      <img id="lb-img" src="" alt="">
      <video id="lb-video" controls playsinline preload="metadata" style="display:none"></video>
      <iframe id="lb-frame" title="문서 미리보기" style="display:none"></iframe>
      <div id="lb-sheet" style="display:none"></div>
      <div class="lb-bar"><span id="lb-count"></span><span id="lb-name"></span>
        <span class="lb-zoom">
          <button id="lb-zout" type="button" title="축소 (−)">−</button>
          <span id="lb-zval">100%</span>
          <button id="lb-zin" type="button" title="확대 (+)">＋</button>
          <button id="lb-zreset" type="button" title="원본 크기 (0)">1:1</button>
        </span>
        <a id="lb-dl" href="#" title="원본 다운로드">⬇️ 다운로드</a></div>
    </div>
  </div>`);
let lbImgs=[], lbIdx=0;
const _lb=()=>document.getElementById("lightbox");
function lbOpen(imgs, idx){ lbImgs=imgs||[]; lbIdx=idx||0; if(!lbImgs.length) return; lbRender(); _lb().classList.add("open"); }
function lbRender(){
  const c=lbImgs[lbIdx]; if(!c) return;
  const img=document.getElementById("lb-img"), vid=document.getElementById("lb-video");
  const frm=document.getElementById("lb-frame"), sht=document.getElementById("lb-sheet");
  const t=c.type||"image";
  const isVid=t==="video", isPdf=t==="pdf", isSheet=t==="sheet", isImg=!isVid&&!isPdf&&!isSheet;
  img.style.display=isImg?"":"none";
  vid.style.display=isVid?"":"none";
  frm.style.display=isPdf?"":"none";
  sht.style.display=isSheet?"":"none";
  const zoom=document.querySelector("#lightbox .lb-zoom"); if(zoom) zoom.style.display=isImg?"":"none";
  if(!isVid){ vid.pause?.(); vid.removeAttribute("src"); vid.load?.(); }
  if(!isPdf) frm.removeAttribute("src");
  if(!isSheet) sht.innerHTML="";
  const loading=document.getElementById("lb-loading");
  loading.style.display="none"; img.classList.remove("lb-dim");
  if(isVid){ vid.src=c.src||""; vid.play?.().catch(()=>{}); }
  else if(isPdf){ frm.src=c.src||""; }
  else if(isSheet){ sht.innerHTML='<div class="lb-sheet-load">불러오는 중…</div>'; renderSheet(sht, c.src, c.name); }
  else {
    if(img.getAttribute("src") !== c.full){
      img.classList.add("lb-dim");
      loading.style.display="flex";
      img.onload = img.onerror = () => { img.classList.remove("lb-dim"); loading.style.display="none"; };
      img.src=c.full;
    }
    img.alt=c.name||"";
    [lbIdx+1, lbIdx-1].forEach(i=>{
      const n=lbImgs[(i+lbImgs.length)%lbImgs.length];
      if(n && (!n.type || n.type==="image") && n.full){ const p=new Image(); p.src=n.full; }
    });
  }
  document.getElementById("lb-count").textContent = lbImgs.length>1 ? ((lbIdx+1)+" / "+lbImgs.length) : "";
  document.getElementById("lb-name").textContent = c.name||"";
  document.getElementById("lb-dl").href = c.dl||c.full||c.src;
  const multi=lbImgs.length>1;
  document.getElementById("lb-prev").style.display = multi?"":"none";
  document.getElementById("lb-next").style.display = multi?"":"none";
  if(isImg) lbZoomReset();
}
/* ---- 엑셀(SheetJS) 로컬 번들 지연 로드 + 표 렌더 ---- */
let _xlsxP=null;
function loadXLSX(){
  if(window.XLSX) return Promise.resolve(window.XLSX);
  if(_xlsxP) return _xlsxP;
  _xlsxP=new Promise((res,rej)=>{
    const s=document.createElement("script");
    s.src="../vendor/xlsx.full.min.js";
    s.onload=()=>res(window.XLSX); s.onerror=()=>rej(new Error("xlsx load fail"));
    document.head.appendChild(s);
  });
  return _xlsxP;
}
async function renderSheet(box, url, name){
  try{
    const XLSX=await loadXLSX();
    const buf=await (await fetch(url,{cache:"force-cache"})).arrayBuffer();
    const wb=XLSX.read(buf,{type:"array"});
    const names=wb.SheetNames; let cur=0;
    const draw=()=>{
      const html=XLSX.utils.sheet_to_html(wb.Sheets[names[cur]], {editable:false, header:"", footer:""});
      const tabs = names.length>1
        ? `<div class="lb-sheet-tabs">${names.map((n,i)=>`<button class="${i===cur?'on':''}" data-i="${i}">${esc(n)}</button>`).join("")}</div>` : "";
      box.innerHTML = tabs + `<div class="lb-sheet-body">${html}</div>`;
      box.querySelectorAll(".lb-sheet-tabs button").forEach(b=>b.addEventListener("click",()=>{ cur=+b.dataset.i; draw(); }));
    };
    draw();
  }catch(e){
    box.innerHTML='<div class="lb-sheet-load">엑셀을 표시할 수 없습니다. 아래 다운로드를 이용하세요.</div>';
  }
}
function lbNav(d){ if(lbImgs.length<2) return; lbIdx=(lbIdx+d+lbImgs.length)%lbImgs.length; lbRender(); }
function lbClose(){ const v=document.getElementById("lb-video"); if(v){ v.pause?.(); v.removeAttribute("src"); v.load?.(); }
  const f=document.getElementById("lb-frame"); if(f) f.removeAttribute("src");
  const s=document.getElementById("lb-sheet"); if(s) s.innerHTML="";
  _lb().classList.remove("open"); }
document.getElementById("lb-prev").addEventListener("click", e=>{ e.stopPropagation(); lbNav(-1); });
document.getElementById("lb-next").addEventListener("click", e=>{ e.stopPropagation(); lbNav(1); });
document.getElementById("lb-close").addEventListener("click", e=>{ e.stopPropagation(); lbClose(); });
document.getElementById("lb-dl").addEventListener("click", e=>e.stopPropagation());
document.getElementById("lb-img").addEventListener("click", e=>e.stopPropagation());
_lb().addEventListener("click", e=>{ if(e.target.id==="lightbox") lbClose(); });
/* ---- 확대/축소 + 패닝 ---- */
let lbScale=1, lbTx=0, lbTy=0, lbDrag=null;
const LB_MIN=1, LB_MAX=8;
function lbImg(){ return document.getElementById("lb-img"); }
function lbApply(){
  const img=lbImg();
  img.style.transform = `translate(${lbTx}px, ${lbTy}px) scale(${lbScale})`;
  img.classList.toggle("zoomed", lbScale>1);
  const zv=document.getElementById("lb-zval"); if(zv) zv.textContent = Math.round(lbScale*100)+"%";
}
function lbZoomReset(){ lbScale=1; lbTx=0; lbTy=0; lbApply(); }
function lbZoomAt(newScale, cx, cy){
  newScale = Math.min(LB_MAX, Math.max(LB_MIN, newScale));
  const rect=lbImg().getBoundingClientRect();
  const layoutCX=rect.left+rect.width/2-lbTx, layoutCY=rect.top+rect.height/2-lbTy;
  const relX=cx-layoutCX, relY=cy-layoutCY, k=newScale/lbScale;
  lbTx = relX - (relX - lbTx)*k;
  lbTy = relY - (relY - lbTy)*k;
  lbScale = newScale;
  if(lbScale<=LB_MIN){ lbTx=0; lbTy=0; }
  lbApply();
}
function lbZoomStep(factor){
  const rect=lbImg().getBoundingClientRect();
  lbZoomAt(lbScale*factor, rect.left+rect.width/2, rect.top+rect.height/2);
}
lbImg().addEventListener("wheel", e=>{
  e.preventDefault(); e.stopPropagation();
  lbZoomAt(lbScale*(e.deltaY<0 ? 1.15 : 1/1.15), e.clientX, e.clientY);
}, {passive:false});
lbImg().addEventListener("dblclick", e=>{
  e.preventDefault(); e.stopPropagation();
  if(lbScale>1) lbZoomReset(); else lbZoomAt(2, e.clientX, e.clientY);
});
lbImg().addEventListener("mousedown", e=>{
  if(lbScale<=1) return;
  e.preventDefault();
  lbDrag={x:e.clientX, y:e.clientY, tx:lbTx, ty:lbTy};
  lbImg().classList.add("dragging");
});
window.addEventListener("mousemove", e=>{
  if(!lbDrag) return;
  lbTx=lbDrag.tx+(e.clientX-lbDrag.x); lbTy=lbDrag.ty+(e.clientY-lbDrag.y); lbApply();
});
window.addEventListener("mouseup", ()=>{ if(lbDrag){ lbDrag=null; lbImg().classList.remove("dragging"); } });
document.getElementById("lb-zin").addEventListener("click", e=>{ e.stopPropagation(); lbZoomStep(1.25); });
document.getElementById("lb-zout").addEventListener("click", e=>{ e.stopPropagation(); lbZoomStep(1/1.25); });
document.getElementById("lb-zreset").addEventListener("click", e=>{ e.stopPropagation(); lbZoomReset(); });
document.addEventListener("keydown", e=>{
  if(!_lb().classList.contains("open")) return;
  if(e.key==="Escape") lbClose();
  else if(e.key==="ArrowLeft") lbNav(-1);
  else if(e.key==="ArrowRight") lbNav(1);
  else if(e.key==="+"||e.key==="=") { e.preventDefault(); lbZoomStep(1.25); }
  else if(e.key==="-"||e.key==="_") { e.preventDefault(); lbZoomStep(1/1.25); }
  else if(e.key==="0") lbZoomReset();
});
</script>
</body>
</html>
