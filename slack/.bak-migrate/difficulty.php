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
session_release();   // 세션 잠금 즉시 해제
$cfg = require __DIR__ . '/config.php';
$listUrl = $cfg['list_url'] ?? '';   // Slack 리스트 permalink (링크 복사용)

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
  .names { flex:none; width:140px; font-size:11px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
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
  /* 담당자 자동 배정 패널 */
  .assignpanel { background:var(--bg); border:1px solid var(--line); border-radius:12px; padding:12px 14px; margin-bottom:14px; }
  .ap-head { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .ap-head b { font-size:14px; }
  #ap-info { font-size:12px; color:var(--muted); margin-right:auto; }
  .ap-head button { height:30px; }
  .ap-head .primary { background:var(--info); color:#fff; border-color:var(--info); }
  #ap-load { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
  .ap-user { font-size:12px; color:var(--muted); background:var(--bg2); border:1px solid var(--line); border-radius:8px; padding:4px 9px; }
  .ap-user b { color:var(--txt); }
  .ap-user.free { border-color:#bfe3c6; }
  .ap-add { color:#1b5e20; font-weight:700; }
  .ap-rtitle { font-size:12px; color:var(--muted); margin:12px 0 6px; }
  .ap-list { max-height:340px; overflow-y:auto; border:1px solid var(--line); border-radius:8px; }
  .ap-row { display:flex; align-items:center; gap:10px; padding:6px 10px; border-bottom:1px solid var(--line); }
  .ap-row:last-child { border-bottom:0; }
  .ap-t { flex:1; min-width:0; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ap-sel { height:28px; padding:0 8px; }
  .ap-copy { height:28px; padding:0 10px; font-size:12px; white-space:nowrap; color:#4a154b; border-color:#d9c3da; }
  .ap-copy:hover { background:#f3e9f3; }
  .asg-chip { font-size:11px; padding:2px 9px; border-radius:8px; white-space:nowrap; background:#e6f1fb; color:#0c447c; }
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

  <div class="assignpanel">
    <div class="ap-head">
      <b>🎯 미지정 담당자 자동 배정</b>
      <span id="ap-info"></span>
      <button id="ap-run" type="button">자동 배정 미리보기</button>
      <button id="ap-save" class="primary" type="button" style="display:none">배정 저장</button>
      <button id="ap-clear" type="button">배정 초기화</button>
    </div>
    <div id="ap-load"></div>
    <div id="ap-result"></div>
  </div>

  <div class="summary" id="summary"></div>
  <div id="board">불러오는 중…</div>
  <div id="updated"></div>
</div>

<script>
/* ===================== 난이도 규칙 (여기만 고치면 튜닝됨) =====================
 * 947건 AI 채점 라벨에서 "키워드별 평균 별점"을 뽑아 가중치로 역산한 데이터 기반 규칙.
 * 기본 3점(보통)에서 시작해 제목+본문 키워드 가중치를 합산(각 키워드 1회) 후 반올림·1~5 클램프.
 * pts = (해당 키워드 포함 요청들의 평균별점 - 3) 근사값. easy 작업일수록 큰 음수.
 * 새 요청은 이 규칙으로 자동 채점되고, AI 채점값(ai_stars)이 있으면 그게 우선 적용됨.
 */
const RULES = [
  // ★5 급 핵심 고난도 (평균 3.8~4.1)
  { pts:+1.5, label:"핵심 고난도",   words:["무한로딩","무한 로딩","정합성","마이그레이션"] },
  // ★4 급: 복구·보안·DB오류·이관 (평균 3.5~4.0)
  { pts:+1.0, label:"복구/보안/DB",  words:["복구","취약점","xss","asm","데이터베이스 쓰기","데이터베이스 읽기","db 쓰기","db 읽기","이관"] },
  { pts:+0.7, label:"연동/배치/개발", words:["배치","크론","스케줄러","세션","동기화","신규 개발","챗봇","장바구니","합반","인코딩","변환","장애","뷰 생성","view 생성","커스터마이징","코어","대규모"] },
  // 버그·오류류 (평균 3.3~3.7)
  { pts:+0.4, label:"버그/오류",     words:["실패","오류","에러","버그","안됨","안 됨","불가","증상","현상","깨짐","누락","멈춤","튕김","api","성적부","플레이어"] },
  // 약한 상향 (평균 3.0~3.3)
  { pts:+0.2, label:"조사/검토",     words:["export","엑스포트","유사도","개선","조사","검토","집계","통계","권한","진도","재생","sso","500","504"] },
  { pts:+0.1, label:"경미",          words:["로그","연동","데이터","모듈","가능여부","가능 여부"] },
  // 단순 운영성 (평균 2.3~2.7) → 하향
  { pts:-0.35, label:"단순 수정",    words:["수정","추가"] },
  { pts:-1.0, label:"양식/문구/추출", words:["문구","팝업","추출","수료증","uuid","양식 추가","이수증","언어팩","일괄 변경","일괄변경","메뉴명"] },
  // 매우 단순 (평균 1.0~1.9)
  { pts:-1.6, label:"단순 운영작업",  words:["학사연동 지원","학사연동지원","삭제 요청","삭제요청","로고","배너","이미지 변경","파비콘","사이트명","전화번호","다운로드 링크"] },
  { pts:-2.5, label:"단순 생성/치환", words:["링크 생성","링크생성","링크 변경","링크변경","문구 수정","문구수정","매뉴얼"] },
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
const ASSIGNEES = ["김아랑","박화랑","안정민","유병문","유승인","이준영","이한재"];
let LOCAL = {};        // request_id -> 배정 담당자(로컬)
let PREVIEW = null;    // 자동 배정 미리보기 결과 [{id, assignee}]
const LIST_URL = <?= json_encode($listUrl) ?>;   // Slack 리스트 링크(복사용)
/* 배정 제외 규칙: 담당자 → 이 요청자(req)의 문의에는 배정 금지 */
const ASSIGN_EXCLUDE = { "김아랑": ["차윤미"] };
function canAssign(user, r){
  const reqs = ASSIGN_EXCLUDE[user];
  return !(reqs && reqs.includes((r.req||"").trim()));
}

function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escAttr(s){ return esc(s).replace(/"/g,"&quot;"); }
function unslack(s){ return s.replace(/&amp;(amp|lt|gt|quot|#39|#x27);/g,"&$1;"); }  // Slack 인코딩 이중 이스케이프 복원
function pad2(n){ return n<10?"0"+n:""+n; }
function fmtDate(ts){ if(!ts) return ""; const d=new Date(ts*1000); return d.getFullYear()+"."+pad2(d.getMonth()+1)+"."+pad2(d.getDate()); }
function stars(n){ return "★★★★★".slice(0,n)+"☆☆☆☆☆".slice(0,5-n); }

/* 핵심: 한 요청의 난이도 점수/별점/근거 계산 */
function difficultyOf(r){
  const text = ((r.title||"")+" "+(r.body||"")).toLowerCase();
  let sum = 0;               // 기본 3점(보통) 대비 가감치 합
  const signals = [];
  RULES.forEach(rule=>{
    rule.words.forEach(w=>{
      if(text.includes(w.toLowerCase())){ sum += rule.pts; signals.push({w, pts:rule.pts}); }
    });
  });
  // 미세 보정: 시스템개발팀(개발성) 소폭 상향, 본문 길이(맥락 복잡도)
  if(r.team==="시스템개발"){ sum+=0.3; signals.push({w:"담당팀:시스템개발", pts:0.3}); }
  const blen = (r.body||"").length;
  if(blen>=1200){ sum+=0.5; signals.push({w:"본문 매우 김", pts:0.5}); }
  else if(blen>=600){ sum+=0.25; signals.push({w:"본문 김", pts:0.25}); }

  // 상·하한 클램프(장문/키워드 과다로 인한 과대·과소 채점 방지)
  if(sum>1.4) sum=1.4; else if(sum<-2.2) sum=-2.2;

  // 기본 3점 + 가감치 → 반올림 → 1~5 클램프
  let lvl = Math.round(3 + sum);
  if(lvl>5) lvl=5; else if(lvl<1) lvl=1;
  const score = Math.round((3+sum)*10)/10;   // 표시용(소수1자리)
  return { score, lvl, signals };
}

function matchBase(r){
  return (!filter || (r.title+r.body+r.req+r.asg).toLowerCase().includes(filter)) &&
         (!fteam.size || fteam.has(r.team)) &&
         (!fstat.size || fstat.has(r.status));
}

/* 표시 난이도: AI 채점값 우선, 없으면 규칙기반 */
function lvlOf(r){ return r.ai_stars ? r.ai_stars : r._diff.lvl; }
const AITAG = '<span style="font-size:9px;background:var(--info-bg);color:var(--info);border-radius:4px;padding:0 4px;margin-left:3px;vertical-align:middle">AI</span>';

function rowHtml(r){
  const d=r._diff, lv=lvlOf(r), ai=!!r.ai_stars, open=openId===r.id;
  let html = `
    <div class="row" data-id="${esc(r.id)}">
      <div class="rstars" title="${ai?('AI 채점 · 신뢰도 '+esc(r.ai_conf||'')):(d.score+'점(규칙)')}">${stars(lv)}${ai?AITAG:''}</div>
      <div class="names">${esc(r.req||'—')}${r.asg && r.asg!=='—' ? `, ${esc(r.asg)}` : ''}</div>
      <div class="title">${esc(r.title)}</div>
      ${LOCAL[r.id]?`<span class="asg-chip">담당 ${esc(LOCAL[r.id])}</span>`:''}
      ${r.team?`<span class="st" style="background:${teamColor(r.team).bg};color:${teamColor(r.team).fg}">${esc(r.team)}</span>`:''}
      ${r.status?`<span class="st" style="background:${pal(r.status).bg};color:${pal(r.status).fg}">${esc(r.status)}</span>`:''}
      <div class="date">${fmtDate(r.created)}</div>
    </div>`;
  if(!open) return html;
  const why = ai
    ? `<b>🤖 AI 난이도 ${stars(lv)} (${LEVELS[lv].name}) · 신뢰도 ${esc(r.ai_conf||'-')}</b><br><span class="sig">${esc(r.ai_reason||'')}</span>`
    : `<b>난이도 ${stars(d.lvl)} (${LEVELS[d.lvl].name}) · 규칙 ${d.score}점</b><br>${
        d.signals.length ? d.signals.map(s=>`<span class="sig">${esc(s.w)} ${s.pts>0?'+':''}${s.pts}</span>`).join("")
                         : '<span class="sig">특이 키워드 없음</span>'}`;
  return html + `
    <div class="detail">
      <div class="why">${why}</div>
      <div class="body-card">${unslack(esc(r.body||'(본문 없음)'))}</div>
      <div class="links">
        ${r.momo?`<a href="${esc(r.momo)}" target="_blank" rel="noopener">모모 이슈</a>`:''}
        ${r.lms?`<a href="${esc(r.lms)}" target="_blank" rel="noopener">LMS 링크</a>`:''}
      </div>
    </div>`;
}

function render(){
  const items = DATA.filter(matchBase).filter(r=>!fLevel || lvlOf(r)===fLevel);
  document.getElementById("count").textContent = items.length+"건";

  // 요약 카드 (현재 필터 기준 분포, AI 우선 별점)
  const dist = {1:0,2:0,3:0,4:0,5:0};
  DATA.filter(matchBase).forEach(r=>dist[lvlOf(r)]++);
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
    const g = items.filter(r=>lvlOf(r)===lv).sort((a,b)=> (b.created-a.created));
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

/* ===================== 담당자 자동 배정 ===================== */
async function loadAssignments(){
  try{ LOCAL = (await (await fetch("assign.php",{cache:"no-store"})).json()).assignments || {}; }
  catch(e){ LOCAL = {}; }
}
/* 미지정 대상: Slack 담당자 없음 + 진행상태 '등록' + 로컬 배정도 아직 없음 */
function unassignedTargets(){
  return DATA.filter(r => (!r.asg || r.asg==='—') && r.status==='등록' && !LOCAL[r.id]);
}
/* 담당자별 현재 부하 = 진행중(status='진행중') 항목들의 난이도 합 (Slack asg + 로컬 배정 모두 고려)
   단, 등록(created)된 지 2개월 이상 지난 진행중 항목은 오래 멈춘 것으로 보고 부하에서 제외 */
function computeLoads(){
  const load={}, cnt={};
  ASSIGNEES.forEach(u=>{ load[u]=0; cnt[u]=0; });
  const cut = new Date(); cut.setMonth(cut.getMonth()-2);
  const cutoff = cut.getTime()/1000;   // 2개월 이전 unixtime
  DATA.forEach(r=>{
    const who = LOCAL[r.id] || (r.asg && r.asg!=='—' ? r.asg : null);
    if(who && load.hasOwnProperty(who) && r.status==='진행중' && r.created >= cutoff){
      load[who]+=lvlOf(r); cnt[who]++;
    }
  });
  return {load,cnt};
}
/* 그리디: 어려운 항목부터, 매번 부하 최소(=진행중 없는 사람 우선) 담당자에게 배정하며 균등 분배 */
function autoAssign(){
  const {load,cnt}=computeLoads();
  const added={}; ASSIGNEES.forEach(u=>added[u]=0);
  const work={}; ASSIGNEES.forEach(u=>work[u]=load[u]);
  const targets = unassignedTargets().slice().sort((a,b)=> (lvlOf(b)-lvlOf(a)) || (b.created-a.created));
  const result=[];
  targets.forEach(r=>{
    const cands = ASSIGNEES.filter(u=>canAssign(u, r));   // 제외 규칙 적용
    const pool = cands.length ? cands : ASSIGNEES;         // 모두 제외되면 전체로 폴백
    const best = pool.slice().sort((x,y)=>
      (work[x]-work[y]) || (cnt[x]-cnt[y]) || (added[x]-added[y]) || x.localeCompare(y,'ko'))[0];
    work[best]+=lvlOf(r); added[best]++;
    result.push({id:r.id, assignee:best});
  });
  return result;
}
function renderAssignPanel(){
  const {load,cnt}=computeLoads();
  const targets = unassignedTargets();
  document.getElementById("ap-info").textContent =
    `미지정 ${targets.length}건 · 배정됨 ${Object.keys(LOCAL).length}건`;
  document.getElementById("ap-load").innerHTML = ASSIGNEES.map(u=>{
    const add = PREVIEW ? PREVIEW.filter(x=>x.assignee===u).length : 0;
    return `<span class="ap-user${cnt[u]===0?' free':''}"><b>${esc(u)}</b> · 진행중 ${cnt[u]}건(난이도 ${load[u]})${add?` <span class="ap-add">+${add}</span>`:''}</span>`;
  }).join("");
  const box=document.getElementById("ap-result");
  if(!PREVIEW){ box.innerHTML=""; return; }
  if(!PREVIEW.length){ box.innerHTML='<div class="ap-rtitle">배정할 미지정 항목이 없습니다.</div>'; return; }
  box.innerHTML = `<div class="ap-rtitle">배정 미리보기 (${PREVIEW.length}건) — 저장 전 담당자 수정 가능</div>
    <div class="ap-list">` + PREVIEW.map(p=>{
      const r=DATA.find(x=>x.id===p.id); if(!r) return "";
      return `<div class="ap-row">
        <span class="rstars">${stars(lvlOf(r))}</span>
        <span class="ap-t" title="${escAttr(r.title)}">${esc(r.title)}</span>
        <select class="ap-sel" data-id="${esc(p.id)}">${ASSIGNEES.filter(u=>canAssign(u,r)).map(u=>`<option ${u===p.assignee?'selected':''}>${esc(u)}</option>`).join("")}</select>
        ${LIST_URL?`<button class="ap-copy" type="button" data-id="${esc(p.id)}" title="Slack 링크 복사">🔗 링크</button>`:''}
      </div>`;
    }).join("") + `</div>`;
  box.querySelectorAll(".ap-sel").forEach(s=>s.addEventListener("change",()=>{
    const p=PREVIEW.find(x=>x.id===s.dataset.id); if(p){ p.assignee=s.value; renderAssignPanel(); }
  }));
  box.querySelectorAll(".ap-copy").forEach(b=>b.addEventListener("click", async ()=>{
    const url = LIST_URL + "?record_id=" + b.dataset.id;
    try{ await navigator.clipboard.writeText(url); }
    catch(_){ const ta=document.createElement("textarea"); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove(); }
    const old=b.textContent; b.textContent="복사됨!"; setTimeout(()=>{ b.textContent=old; }, 1000);
  }));
}
document.getElementById("ap-run").addEventListener("click",()=>{
  PREVIEW = autoAssign();
  document.getElementById("ap-save").style.display = PREVIEW.length ? "" : "none";
  renderAssignPanel();
});
document.getElementById("ap-save").addEventListener("click", async ()=>{
  if(!PREVIEW || !PREVIEW.length) return;
  const btn=document.getElementById("ap-save"); btn.disabled=true;
  try{
    const j = await (await fetch("assign.php",{method:"POST",headers:{"Content-Type":"application/json"},
      body:JSON.stringify({action:"save", items:PREVIEW.map(p=>({request_id:p.id, assignee:p.assignee}))})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    await loadAssignments(); PREVIEW=null;
    document.getElementById("ap-save").style.display="none";
    renderAssignPanel(); render();
    alert("배정을 저장했습니다.");
  }catch(e){ alert("저장 실패: "+e.message); }
  finally{ btn.disabled=false; }
});
document.getElementById("ap-clear").addEventListener("click", async ()=>{
  if(!confirm("저장된 로컬 배정을 모두 초기화할까요?")) return;
  try{
    const j = await (await fetch("assign.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"clear_all"})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    await loadAssignments(); PREVIEW=null;
    document.getElementById("ap-save").style.display="none";
    renderAssignPanel(); render();
  }catch(e){ alert("초기화 실패: "+e.message); }
});

async function load(){
  try{
    const res = await fetch("data.php",{cache:"no-store"});
    const json = await res.json();
    if(json.error){ document.getElementById("board").innerHTML='<div class="err">에러: '+esc(json.error)+'</div>'; return; }
    DATA = json.rows||[];
    DATA.forEach(r=>r._diff = difficultyOf(r));   // 난이도 1회 계산(데이터 갱신 시 재계산)
    await loadAssignments();                       // 로컬 배정 로드
    buildAllMS(); renderAssignPanel(); render();
    document.getElementById("updated").textContent = "마지막 갱신: "+new Date().toLocaleTimeString("ko-KR");
  }catch(e){ document.getElementById("board").innerHTML='<div class="err">불러오기 실패</div>'; }
}

load();
</script>
</body>
</html>
