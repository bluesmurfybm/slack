<?php
/**
 * 유지보수 난이도 분석 (규칙기반).
 *  - data.php 의 동일 데이터를 받아 제목/본문 키워드 + 담당팀 + 본문길이로 1~5 별점 산출
 *  - 별점은 클라이언트에서 실시간 계산 (DB/스키마 변경 없음). 동기화되면 자동 재계산.
 *  - 읽기 전용 분석 보드 (수정/댓글 없음). lists.php 에서 "난이도 분석" 버튼으로 진입.
 */
require_once __DIR__ . '/auth.php';
require_login();
$me = current_user();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>유지보수 난이도 분석</title>
<style>
  :root { --bg:#fff; --bg2:#f6f7f8; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --hint:#8b949e; --info:#0c447c; --info-bg:#e6f1fb; --star:#f5a623; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1a1d21; --bg2:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --hint:#6b7177; --info:#85b7eb; --info-bg:#0c2740; --star:#f5b942; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg2); color:var(--txt); margin:0; padding:24px; }
  .wrap { width:100%; max-width:1500px; margin:0 auto; }
  .head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px; }
  .head h1 { font-size:18px; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; }
  .badge { font-size:12px; color:var(--info); background:var(--info-bg); padding:2px 10px; border-radius:8px; }
  .toolbar { display:flex; gap:8px; align-items:center; justify-content:flex-end; margin-bottom:14px; flex-wrap:wrap; }
  .who { font-size:12px; color:var(--muted); margin-right:auto; }
  input,button,select { font-family:inherit; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-size:13px; height:32px; padding:0 10px; }
  button { cursor:pointer; }
  a.btn { text-decoration:none; }
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
  /* 난이도 요약 막대 */
  .summary { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
  .scard { flex:1; min-width:120px; background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:10px 12px; cursor:pointer; }
  .scard.active { border-color:var(--info); box-shadow:0 0 0 1px var(--info) inset; }
  .scard .lv { font-size:13px; color:var(--star); letter-spacing:1px; }
  .scard .lb { font-size:11px; color:var(--muted); margin-top:2px; }
  .scard .ct { font-size:20px; font-weight:700; margin-top:4px; }
  /* 그룹 */
  .group { margin-bottom:18px; }
  .ghead { display:flex; align-items:center; gap:10px; padding:8px 4px; font-size:14px; font-weight:600; }
  .ghead .stars { color:var(--star); letter-spacing:2px; font-size:16px; }
  .ghead .gname { color:var(--txt); }
  .ghead .gcnt { font-size:12px; color:var(--muted); font-weight:400; }
  .listbox { border:1px solid var(--line); border-radius:12px; overflow:hidden; background:var(--bg); }
  .row { display:flex; align-items:center; gap:12px; padding:11px 16px; cursor:pointer; border-bottom:1px solid var(--line); }
  .row:last-child { border-bottom:0; }
  .row:hover { background:var(--bg2); }
  .rstars { flex:none; width:84px; color:var(--star); letter-spacing:1px; font-size:13px; white-space:nowrap; }
  .names { flex:none; width:90px; font-size:11px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .title { flex:1; min-width:0; font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .st { font-size:11px; padding:2px 9px; border-radius:8px; white-space:nowrap; }
  .date { font-size:12px; color:var(--hint); flex:none; white-space:nowrap; }
  .detail { padding:14px 18px 18px; border-bottom:1px solid var(--line); background:var(--bg2); }
  .why { font-size:12px; color:var(--muted); background:var(--bg); border:1px dashed var(--line); border-radius:8px; padding:8px 11px; margin-bottom:12px; }
  .why b { color:var(--txt); }
  .why .sig { display:inline-block; background:var(--info-bg); color:var(--info); border-radius:6px; padding:1px 7px; margin:2px 3px 0 0; font-size:11px; }
  .body-card { font-size:13px; line-height:1.7; white-space:pre-wrap; word-break:break-word; color:var(--txt); background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:12px 14px; }
  .links { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
  .links a { font-size:12px; text-decoration:none; color:var(--info); border:1px solid var(--line); padding:5px 11px; border-radius:8px; }
  #updated { font-size:12px; color:var(--hint); margin:10px 2px 0; }
  .err { color:#e24b4a; padding:16px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>⭐ 유지보수 난이도 분석 <span class="badge" id="count"></span></h1>
    <div style="display:flex;gap:8px;align-items:center">
      <div class="ms" id="msTeam"></div>
      <div class="ms" id="msStatus"></div>
      <input id="search" type="text" placeholder="검색…" style="width:140px;">
    </div>
  </div>
  <div class="toolbar">
    <span class="who"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님 · 별 많을수록 어려움(★5 기준)</span>
    <button id="reset" type="button">필터 초기화</button>
    <a class="btn" href="lists.php"><button type="button">← 요청 목록</button></a>
  </div>

  <div class="summary" id="summary"></div>
  <div id="board">불러오는 중…</div>
  <div id="updated"></div>
</div>

<script>
/* ===================== 난이도 규칙 (여기만 고치면 튜닝됨) =====================
 * 제목+본문에서 키워드를 찾아 점수를 합산하고, 담당팀/본문길이로 보정 후 1~5 별점으로 매핑.
 * pts 가 클수록 어려운 작업. easy 는 단순 운영성 작업이라 음수.
 */
const RULES = [
  { pts:+3, label:"고난도(연동/개발)", words:["학사연동","연동","마이그레이션","이관","정합성","무한로딩","코어","커스터마이징","커스텀","대규모"] },
  { pts:+2, label:"버그/복구/개선",    words:["복구","개발","개선","오류","에러","버그","불가","실패","안됨","안 됨","증상","현상","export","엑스포트","익스포트","변환","성적부","카테고리","동기화","검토","장애","깨짐","누락"] },
  { pts:+1, label:"조사/규칙확인",      words:["규칙","권한","설정","표기","표시","뷰테이블","로그","가능여부","추출","모듈","데이터","집계","통계"] },
  { pts:-2, label:"단순 운영작업",      words:["링크 생성","링크생성","로고","문구","텍스트","이미지","폐강","변경 요청","변경요청","삭제 요청","삭제요청","추출요청"] },
];
const LEVELS = {
  5:{name:"매우 어려움", desc:"연동·개발·정합성 복구"},
  4:{name:"어려움",      desc:"버그 수정·데이터 복구·기능 개선"},
  3:{name:"보통",        desc:"규칙 확인·표시 오류"},
  2:{name:"쉬움",        desc:"조회·추출·단순 처리"},
  1:{name:"매우 쉬움",   desc:"문구·로고·링크 생성"},
};

/* 색상 (lists.php 와 동일 팔레트) */
const PALETTE = {
  "등록":{bg:"#eef0f2",fg:"#555c66"},"공수산정요청":{bg:"#faeeda",fg:"#633806"},"진행중":{bg:"#9b7d55",fg:"#ffefdb"},
  "확인요청(검토완료)":{bg:"#e0f2f1",fg:"#00695c"},"확인요청(개발서버반영)":{bg:"#e0f2f1",fg:"#00695c"},"확인요청(운영서버반영)":{bg:"#e0f2f1",fg:"#00695c"},
  "운영배포요청":{bg:"#fff0e0",fg:"#a85b00"},"완료":{bg:"#e4f3e7",fg:"#1b5e20"},"보류":{bg:"#f1efe8",fg:"#6b6b6b"},"처리불가":{bg:"#f3e6e6",fg:"#9b2c2c"},"기타":{bg:"#f1efe8",fg:"#444441"}
};
const TEAM = {"블루소프트":{bg:"#e6f1fb",fg:"#0c447c"},"와이오즈":{bg:"#e0f2f1",fg:"#00695c"},"시스템개발":{bg:"#eeedfe",fg:"#3c3489"},"달빛소프트":{bg:"#ede7f6",fg:"#5e35b1"},"프레임":{bg:"#fff0e0",fg:"#a85b00"},"미지정":{bg:"#eceff1",fg:"#455a64"}};
function pal(s){ return PALETTE[s]||PALETTE["기타"]; }
function teamColor(s){ return TEAM[s]||{bg:"#eceff1",fg:"#455a64"}; }

let DATA=[], filter="", fteam=new Set(), fstat=new Set(), openId=null, fLevel=0;

function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escAttr(s){ return esc(s).replace(/"/g,"&quot;"); }
function unslack(s){ return s.replace(/&amp;(amp|lt|gt|quot|#39|#x27);/g,"&$1;"); }  // Slack 인코딩 이중 이스케이프 복원
function pad2(n){ return n<10?"0"+n:""+n; }
function fmtDate(ts){ if(!ts) return ""; const d=new Date(ts*1000); return d.getFullYear()+"."+pad2(d.getMonth()+1)+"."+pad2(d.getDate()); }
function stars(n){ return "★★★★★".slice(0,n)+"☆☆☆☆☆".slice(0,5-n); }

/* 핵심: 한 요청의 난이도 점수/별점/근거 계산 */
function difficultyOf(r){
  const text = ((r.title||"")+" "+(r.body||""));
  let score = 0;
  const signals = [];
  RULES.forEach(rule=>{
    rule.words.forEach(w=>{
      if(text.includes(w)){ score += rule.pts; signals.push({w, pts:rule.pts}); }
    });
  });
  // 보정: 시스템개발팀(개발성) +1, 본문 길이(맥락 복잡도)
  if(r.team==="시스템개발"){ score+=1; signals.push({w:"담당팀:시스템개발", pts:1}); }
  const blen = (r.body||"").length;
  if(blen>=800){ score+=2; signals.push({w:"본문 매우 김", pts:2}); }
  else if(blen>=400){ score+=1; signals.push({w:"본문 김", pts:1}); }

  // 점수 → 별점 매핑
  let lvl;
  if(score<=0) lvl=1; else if(score<=2) lvl=2; else if(score<=4) lvl=3; else if(score<=6) lvl=4; else lvl=5;
  return { score, lvl, signals };
}

function matchBase(r){
  return (!filter || (r.title+r.body+r.req+r.asg).toLowerCase().includes(filter)) &&
         (!fteam.size || fteam.has(r.team)) &&
         (!fstat.size || fstat.has(r.status));
}

function rowHtml(r){
  const d=r._diff, open=openId===r.id;
  let html = `
    <div class="row" data-id="${esc(r.id)}">
      <div class="rstars" title="${d.score}점">${stars(d.lvl)}</div>
      <div class="names">${esc(r.req||'—')}</div>
      <div class="title">${esc(r.title)}</div>
      ${r.team?`<span class="st" style="background:${teamColor(r.team).bg};color:${teamColor(r.team).fg}">${esc(r.team)}</span>`:''}
      ${r.status?`<span class="st" style="background:${pal(r.status).bg};color:${pal(r.status).fg}">${esc(r.status)}</span>`:''}
      <div class="date">${fmtDate(r.created)}</div>
    </div>`;
  if(!open) return html;
  const sigHtml = d.signals.length
    ? d.signals.map(s=>`<span class="sig">${esc(s.w)} ${s.pts>0?'+':''}${s.pts}</span>`).join("")
    : '<span class="sig">특이 키워드 없음</span>';
  return html + `
    <div class="detail">
      <div class="why"><b>난이도 ${stars(d.lvl)} (${LEVELS[d.lvl].name}) · 합계 ${d.score}점</b><br>${sigHtml}</div>
      <div class="body-card">${unslack(esc(r.body||'(본문 없음)'))}</div>
      <div class="links">
        ${r.momo?`<a href="${esc(r.momo)}" target="_blank" rel="noopener">모모 이슈</a>`:''}
        ${r.lms?`<a href="${esc(r.lms)}" target="_blank" rel="noopener">LMS 링크</a>`:''}
      </div>
    </div>`;
}

function render(){
  const items = DATA.filter(matchBase).filter(r=>!fLevel || r._diff.lvl===fLevel);
  document.getElementById("count").textContent = items.length+"건";

  // 요약 카드 (필터 무시한 전체 분포는 별도로? → 현재 필터 기준 분포)
  const dist = {1:0,2:0,3:0,4:0,5:0};
  DATA.filter(matchBase).forEach(r=>dist[r._diff.lvl]++);
  document.getElementById("summary").innerHTML = [5,4,3,2,1].map(lv=>`
    <div class="scard ${fLevel===lv?'active':''}" data-lv="${lv}">
      <div class="lv">${stars(lv)}</div>
      <div class="lb">${LEVELS[lv].name}</div>
      <div class="ct">${dist[lv]}</div>
    </div>`).join("");

  // 어려운순 그룹
  const board = document.getElementById("board");
  if(!items.length){ board.innerHTML='<div class="err">표시할 항목이 없습니다.</div>'; bindCards(); return; }
  let html="";
  for(let lv=5; lv>=1; lv--){
    const g = items.filter(r=>r._diff.lvl===lv).sort((a,b)=> (b._diff.score-a._diff.score) || (b.created-a.created));
    if(!g.length) continue;
    html += `<div class="group">
      <div class="ghead"><span class="stars">${stars(lv)}</span><span class="gname">${LEVELS[lv].name}</span><span class="gcnt">${g.length}건 · ${LEVELS[lv].desc}</span></div>
      <div class="listbox">${g.map(rowHtml).join("")}</div>
    </div>`;
  }
  board.innerHTML = html;
  bindRows(); bindCards();
}

function bindRows(){
  document.querySelectorAll(".row").forEach(el=>{
    el.addEventListener("click",()=>{ const id=el.dataset.id; openId = (openId===id)?null:id; render(); });
  });
}
function bindCards(){
  document.querySelectorAll(".scard").forEach(el=>{
    el.addEventListener("click",()=>{ const lv=+el.dataset.lv; fLevel = (fLevel===lv)?0:lv; render(); });
  });
}

/* ---------- 헤더 다중선택 필터 드롭다운 ---------- */
const MS = [
  { id:'msTeam',   label:'담당팀',   set:()=>fteam, values:()=>[...new Set(DATA.map(r=>r.team).filter(Boolean))].sort() },
  { id:'msStatus', label:'진행상태', set:()=>fstat, values:()=>[...new Set(DATA.map(r=>r.status).filter(Boolean))].sort() },
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
    updateMSBtn(cfg); openId=null; render();
  }));
}
function buildAllMS(){ MS.forEach(buildMS); }
document.addEventListener("click", closeAllMS);   // 바깥 클릭 시 메뉴 닫기

document.getElementById("search").addEventListener("input",e=>{ filter=e.target.value.toLowerCase().trim(); openId=null; render(); });
document.getElementById("reset").addEventListener("click",()=>{
  filter=""; fteam=new Set(); fstat=new Set(); fLevel=0; document.getElementById("search").value="";
  buildAllMS(); openId=null; render();
});

async function load(){
  try{
    const res = await fetch("data.php",{cache:"no-store"});
    const json = await res.json();
    if(json.error){ document.getElementById("board").innerHTML='<div class="err">에러: '+esc(json.error)+'</div>'; return; }
    DATA = json.rows||[];
    DATA.forEach(r=>r._diff = difficultyOf(r));   // 난이도 1회 계산(데이터 갱신 시 재계산)
    buildAllMS(); render();
    document.getElementById("updated").textContent = "마지막 갱신: "+new Date().toLocaleTimeString("ko-KR");
  }catch(e){ document.getElementById("board").innerHTML='<div class="err">불러오기 실패</div>'; }
}
load();
</script>
</body>
</html>
