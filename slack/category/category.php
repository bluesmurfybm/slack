<?php
/**
 * 요청 카테고리 빈도 차트. 로그인 필요. category_api.php 집계 사용.
 */
$__bwBase = '../';   // slack/ 하위 폴더 페이지 — require_login()/header.php 리다이렉트 경로 계산용
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_login();
session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>요청 카테고리 분석</title>
<link rel="icon" href="../../styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/header.css">
<link rel="stylesheet" href="../styles/common.css">
<link rel="stylesheet" href="../styles/category.css">
</head>
<body>
<?php include __DIR__ . '/../header.php'; ?>
<div class="wrap">
  <div class="head">
    <h1>📊 요청 카테고리 분석</h1>
    <a class="back" href="../lists.php">← 목록으로</a>
  </div>
  <div class="panel">
    <div class="head" style="margin-bottom:14px;">
      <div class="seg" id="scope">
        <button data-s="all" class="on">전체</button>
        <button data-s="active">활성만</button>
        <button data-s="archived">보관만</button>
      </div>
      <div class="sub" id="sub" style="margin:0;"></div>
    </div>
    <div class="head" style="margin-bottom:14px;gap:6px;">
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <span class="sub" style="margin:0;">기간</span>
        <input type="date" id="from" class="bar-btn"> <span class="sub" style="margin:0;">~</span>
        <input type="date" id="to" class="bar-btn">
        <button class="bar-btn" id="apply">적용</button>
        <button class="bar-btn" id="clear">전체기간</button>
      </div>
      <div class="seg" id="quick">
        <button data-d="7">최근7일</button>
        <button data-d="30">최근30일</button>
        <button data-d="90">최근90일</button>
        <button data-d="365">최근1년</button>
      </div>
    </div>
    <div id="chart"><div class="empty">불러오는 중…</div></div>
  </div>
  <div class="sub" style="margin-top:10px;">막대를 클릭하면 해당 카테고리 안의 <b>세부 분류</b>가 펼쳐집니다. 분류는 제목+본문 키워드 규칙(첫 매칭 우선).</div>
</div>

<script>
const COLORS = ["#4a90d9","#50a14f","#e0983a","#c678dd","#e06c75","#56b6c2","#d19a66","#98c379","#61afef","#be5046","#9aa0a6"];
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
let curScope="all";
const $=id=>document.getElementById(id);

document.querySelectorAll("#scope button").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll("#scope button").forEach(x=>x.classList.remove("on"));
  b.classList.add("on"); curScope=b.dataset.s; load();
}));

function fmtDate(d){ return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0"); }
document.querySelectorAll("#quick button").forEach(b=>b.addEventListener("click",()=>{
  const days=+b.dataset.d, now=new Date(), start=new Date(); start.setDate(now.getDate()-days+1);
  $("from").value=fmtDate(start); $("to").value=fmtDate(now);
  document.querySelectorAll("#quick button").forEach(x=>x.classList.remove("on")); b.classList.add("on");
  load();
}));
$("apply").addEventListener("click",()=>{ document.querySelectorAll("#quick button").forEach(x=>x.classList.remove("on")); load(); });
$("clear").addEventListener("click",()=>{ $("from").value=""; $("to").value=""; document.querySelectorAll("#quick button").forEach(x=>x.classList.remove("on")); load(); });

async function load(){
  $("chart").innerHTML='<div class="empty">불러오는 중…</div>';
  $("sub").textContent="";
  const qs=new URLSearchParams({scope:curScope});
  if($("from").value) qs.set("from",$("from").value);
  if($("to").value)   qs.set("to",$("to").value);
  try{
    const j=await (await fetch("category_api.php?"+qs.toString(),{cache:"no-store"})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    render(j);
  }catch(e){ document.getElementById("chart").innerHTML='<div class="empty">오류: '+esc(e.message)+'</div>'; }
}

function subChart(c,col){
  const subs=c.subs||[]; if(!subs.length) return '<div class="dh">세부 분류 없음</div>';
  const smax=Math.max(1,...subs.map(s=>s.count));
  return `<div class="dh">▸ ${esc(c.label)} 세부 분류 (${c.count.toLocaleString()}건 기준)</div>`+
    subs.map(s=>{
      const w=(s.count/smax*100).toFixed(1);
      const samp=(s.samples||[]).slice(0,2).map(esc).join("  ·  ");
      return `<div class="subrow">
          <div class="slab" title="${esc(s.label)}">${esc(s.label)}</div>
          <div class="strack"><div class="sfill" style="width:0%;background:${col}" data-w="${w}"></div></div>
          <div class="sval"><b>${s.count.toLocaleString()}</b> · ${s.pct}%</div>
        </div>${samp?`<div class="samp">· ${samp}</div>`:''}`;
    }).join("");
}
function render(j){
  const cats=j.categories, max=Math.max(1,...cats.map(c=>c.count));
  const period=(j.from||j.to)?` · 기간 ${j.from||'처음'}~${j.to||'현재'}`:" · 전체기간";
  $("sub").textContent = "총 "+j.total.toLocaleString()+"건 · "+cats.length+"개 카테고리"+period;
  const chart=$("chart");
  if(!j.total){ chart.innerHTML='<div class="empty">해당 기간에 요청이 없습니다.</div>'; return; }
  chart.innerHTML = cats.map((c,i)=>{
    const w=(c.count/max*100).toFixed(1), col=COLORS[i%COLORS.length];
    return `<div class="rowc" data-i="${i}">
        <div class="lab" title="${esc(c.label)}">${esc(c.label)}</div>
        <div class="track"><div class="fill" style="width:0%;background:${col}" data-w="${w}"></div></div>
        <div class="val"><b>${c.count.toLocaleString()}</b> · ${c.pct}%</div>
      </div>
      <div class="drill" hidden data-d="${i}">${subChart(c,col)}</div>`;
  }).join("");
  requestAnimationFrame(()=>chart.querySelectorAll(".fill").forEach(f=>f.style.width=f.dataset.w+"%"));
  chart.querySelectorAll(".rowc").forEach(r=>r.addEventListener("click",()=>{
    const d=chart.querySelector('.drill[data-d="'+r.dataset.i+'"]'); if(!d) return;
    d.hidden=!d.hidden;
    if(!d.hidden) requestAnimationFrame(()=>d.querySelectorAll(".sfill").forEach(f=>f.style.width=f.dataset.w+"%"));
  }));
}
load();
</script>
</body>
</html>
