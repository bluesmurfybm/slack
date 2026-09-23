<?php
/**
 * AI 작업 로그 화면 — ai_jobs 비용 타일·일별 차트·필터·표·취소/재시도. API: jobs_api.php
 *  취소: 로그인 사용자. 재시도: 승인자·관리자. 열린 잡(대기/실행)이 있으면 5초마다 자동 갱신.
 */
$__bwBase = '../';
$__bwCurrent = 'slackai';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/admin_lib.php';
require_login();
$me  = current_user();
$adm = admin_me();
session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$initRid = isset($_GET['request_id']) ? admin_str($_GET['request_id'], 32) : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI 작업 로그 · WorkHub AI</title>
<script>(function(){var t=localStorage.getItem("ui_theme");if(t)document.documentElement.classList.add(t);})();</script>
<link rel="icon" href="../../styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/header.css">
<link rel="stylesheet" href="../styles/common.css">
<link rel="stylesheet" href="../styles/ai_admin.css">
</head>
<body>
<?php include __DIR__ . '/../header.php'; ?>
<div class="wrap">
  <div class="head">
    <h1>🤖 AI 작업 로그 <span class="badge" id="count"></span> <span class="live" id="live" style="display:none"><i></i>자동 갱신</span></h1>
    <div class="right">
      <?= admin_nav_html('jobs') ?>
      <span class="muted small"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님<?= $adm['is_admin'] ? ' · 관리자' : ($adm['is_approver'] ? ' · 승인자' : '') ?></span>
      <button id="themeBtn" type="button" class="tip" data-tip="다크/라이트 전환">🌓</button>
      <a class="back" href="../lists.php">← 목록으로</a>
    </div>
  </div>

  <div class="tiles">
    <div class="tile"><div class="l">오늘 비용</div><div class="v" id="tToday">—</div><div class="s" id="tTodayS"></div></div>
    <div class="tile"><div class="l">최근 7일 비용</div><div class="v" id="tWeek">—</div><div class="s">종료 시각 기준</div></div>
    <div class="tile run"><div class="l">실행 중</div><div class="v" id="tRun">—</div><div class="s" id="tQueued"></div></div>
    <div class="tile bad"><div class="l">오늘 실패</div><div class="v" id="tFail">—</div><div class="s">finished_at 기준</div></div>
  </div>

  <div class="chart">
    <div class="ch"><b>최근 14일 일별 비용 (USD)</b><span id="chartMax"></span></div>
    <div class="bars" id="bars"></div>
    <div class="blbl" id="blbl"></div>
    <div class="bykind" id="bykind"></div>
  </div>

  <div class="tools">
    <div id="stChips"></div>
    <select id="fkind" style="width:130px"><option value="">모든 종류</option></select>
    <input type="date" id="ffrom" class="tip" data-tip="생성일 시작"> <span class="muted">~</span> <input type="date" id="fto" class="tip" data-tip="생성일 끝">
    <input id="fq" type="text" placeholder="Rec ID · 제목 · 오류 검색" style="width:220px" value="<?= htmlspecialchars($initRid, ENT_QUOTES) ?>">
    <span class="spacer"></span>
    <select id="flimit" style="width:90px"><option value="50">50건</option><option value="100" selected>100건</option><option value="200">200건</option><option value="500">500건</option></select>
    <button id="reload" type="button" class="sm">🔄 새로고침</button>
  </div>

  <div class="box scroll"><div id="list"><div class="loading">불러오는 중…</div></div></div>
  <div class="pager" id="pager"></div>
</div>

<script src="../ai/admin.js"></script>
<script>
const { $, esc, escA, api, toast, fmtDt, fmtDur, fmtUsd, fmtNum } = ADM;
const CAN_RETRY = <?= $adm['is_approver'] ? 'true' : 'false' ?>;
const API = "jobs_api.php";
const STATUSES = ["queued","running","done","failed","cancelled"];
const ST_LABEL = { queued:"대기", running:"실행 중", done:"완료", failed:"실패", cancelled:"취소" };
let fstatus = "", offset = 0, RES = null, timer = null, expanded = new Set();

function params(){
  const p = new URLSearchParams();
  if(fstatus) p.set("status", fstatus);
  if($("fkind").value) p.set("kind", $("fkind").value);
  if($("ffrom").value) p.set("from", $("ffrom").value);
  if($("fto").value) p.set("to", $("fto").value);
  const q = $("fq").value.trim(); if(q) p.set("q", q);
  p.set("limit", $("flimit").value); p.set("offset", offset);
  return p.toString();
}
async function load(){
  try { RES = await api(API + "?" + params()); }
  catch(e){ $("list").innerHTML = `<div class="err">불러오기 실패: ${esc(e.message)}</div>`; return; }
  renderTiles(RES.totals); renderChart(RES.totals); renderChips(); renderKinds(); renderTable(); renderPager();
  // 열린 잡(대기/실행)이 있으면 5초 자동 갱신
  const open = RES.totals.open > 0;
  $("live").style.display = open ? "" : "none";
  clearTimeout(timer); if(open) timer = setTimeout(load, 5000);
}
function renderTiles(t){
  $("tToday").textContent = fmtUsd(t.today_cost_usd, 2); $("tTodayS").textContent = `전체 누적 ${fmtNum(t.by_kind.reduce((a,k)=>a+k.count,0))}건`;
  $("tWeek").textContent = fmtUsd(t.week_cost_usd, 2);
  $("tRun").textContent = fmtNum(t.running); $("tQueued").textContent = `대기 ${fmtNum(t.queued)}건`;
  $("tFail").textContent = fmtNum(t.failed_today);
}
function renderChart(t){
  const days = t.by_day || [];
  const max = Math.max(0, ...days.map(d => Number(d.cost_usd)));
  $("chartMax").textContent = max > 0 ? `최대 ${fmtUsd(max, 2)}/일 · 점선 = ${fmtUsd(max/2, 2)}` : "비용 기록 없음";
  $("bars").innerHTML = days.map(d => {
    const v = Number(d.cost_usd), h = max > 0 ? Math.max(v > 0 ? 3 : 0, Math.round(v / max * 100)) : 0;
    return `<div class="bcol"><div class="bar${v>0?'':' zero'}" style="height:${h}%"></div><div class="tt">${esc(d.day)} · ${d.count}건 · ${fmtUsd(v, 3)}</div></div>`;
  }).join("");
  $("blbl").innerHTML = days.map((d, i) => `<span>${(i % 2 === days.length % 2) ? esc(d.day.slice(5).replace("-","/")) : ""}</span>`).join("");
  $("bykind").innerHTML = (t.by_kind || []).map(k => `<span><b>${esc(k.kind)}</b> ${fmtNum(k.count)}건 · ${fmtUsd(k.cost_usd, 2)}</span>`).join("");
}
function renderChips(){
  const html = [["","전체"], ...STATUSES.map(s => [s, ST_LABEL[s]])].map(([v,l]) => {
    const n = v === "running" ? RES.totals.running : v === "queued" ? RES.totals.queued : null;
    return `<button type="button" class="chip${v===fstatus?' on':''}" data-v="${v}">${l}${n!==null?`<span class="n">${n}</span>`:''}</button>`;
  }).join(" ");
  $("stChips").innerHTML = html;
  $("stChips").querySelectorAll(".chip").forEach(b => b.addEventListener("click", () => { fstatus = b.dataset.v; offset = 0; load(); }));
}
function renderKinds(){
  const sel = $("fkind"); if(sel.options.length > 1) return;
  (RES.kinds || []).forEach(k => { const o = document.createElement("option"); o.value = k; o.textContent = k; sel.appendChild(o); });
}
function stHtml(j){
  let h = `<span class="st st-${esc(j.status)}">${ST_LABEL[j.status] || esc(j.status)}</span>`;
  if(j.cancel_requested && j.status === "running") h += ` <span class="small" style="color:var(--bad)">취소 요청</span>`;
  if(j.attempts > 1) h += ` <span class="small muted">${j.attempts}/${j.max_attempts}회</span>`;
  if(j.progress && (j.status === "running" || j.status === "failed")) h += `<span class="prog" title="${escA(j.progress)}">${esc(j.progress)}</span>`;
  if(j.status === "queued" && j.run_after) h += `<span class="prog">재시도 예정 ${fmtDt(j.run_after)}</span>`;
  return h;
}
function renderTable(){
  const rows = RES.rows || [];
  $("count").textContent = `${fmtNum(RES.total)}건 · ${fmtUsd(RES.totals.cost_usd, 2)}`;
  const box = $("list");
  if(!rows.length){ box.innerHTML = '<div class="empty">조건에 맞는 잡이 없습니다.</div>'; return; }
  box.innerHTML = `<table><thead><tr>
      <th style="width:60px">#</th><th style="width:90px">종류</th><th>문의</th><th style="width:120px">상태</th><th style="width:130px">요청자</th>
      <th style="width:190px">생성 / 시작 → 종료</th><th style="width:80px" class="right">비용</th><th style="width:90px" class="right">토큰</th><th style="width:220px">오류</th><th style="width:90px"></th>
    </tr></thead><tbody>` +
    rows.map(j => `<tr>
      <td class="mono muted">${j.id}</td>
      <td><span class="kind">${esc(j.kind)}</span>${j.repo_id ? `<div class="small muted">repo #${j.repo_id}</div>` : ''}${j.ref_id && j.kind!=='check_repo' ? `<div class="small muted">ref #${j.ref_id}</div>` : ''}</td>
      <td>${j.request_id ? `<a class="link" href="../lists.php?id=${encodeURIComponent(j.request_id)}" title="${escA(j.request_id)}"><span class="ellipsis" style="max-width:360px">${esc(j.title || j.request_id)}</span></a><div class="small muted mono">${esc(j.request_id)}</div>` : '<span class="muted">—</span>'}</td>
      <td>${stHtml(j)}</td>
      <td class="small"><span class="ellipsis" title="${escA(j.requested_by||"")}">${esc(j.requested_by || "")}</span>${j.worker ? `<div class="muted mono ellipsis" title="${escA(j.worker)}">${esc(j.worker)}</div>` : ''}</td>
      <td class="small nowrap"><div>${fmtDt(j.created_at)}</div><div class="muted">${j.started_at ? fmtDt(j.started_at) : "—"} → ${j.finished_at ? fmtDt(j.finished_at) : (j.status==="running" ? "…" : "—")}${j.duration_sec!==null ? ` <b>${fmtDur(j.duration_sec)}</b>` : ''}</div></td>
      <td class="right mono">${j.cost_usd !== null ? fmtUsd(j.cost_usd) : '<span class="muted">—</span>'}${j.model ? `<div class="small muted ellipsis" style="max-width:80px" title="${escA(j.model)}">${esc(j.model)}</div>` : ''}</td>
      <td class="right mono small">${j.tokens_in !== null || j.tokens_out !== null ? `${fmtNum(j.tokens_in||0)}<span class="muted">/</span>${fmtNum(j.tokens_out||0)}` : '<span class="muted">—</span>'}</td>
      <td class="err">${j.error ? (expanded.has(j.id) ? `<pre>${esc(j.error)}</pre><a href="#" class="small" data-fold="${j.id}">접기</a>` : `<span class="ellipsis" data-exp="${j.id}" title="${escA(j.error)}">${esc(j.error)}</span>`) : ''}</td>
      <td class="act">
        ${(j.status==="queued" || (j.status==="running" && !j.cancel_requested)) ? `<button class="sm danger" data-act="cancel" data-id="${j.id}">취소</button>` : ''}
        ${CAN_RETRY && (j.status==="failed" || j.status==="cancelled") ? `<button class="sm" data-act="retry" data-id="${j.id}">재시도</button>` : ''}
      </td>
    </tr>`).join("") + `</tbody></table>`;
  box.querySelectorAll("button[data-act]").forEach(b => b.addEventListener("click", () => (b.dataset.act === "cancel" ? cancel : retry)(+b.dataset.id)));
  box.querySelectorAll("[data-exp]").forEach(s => s.addEventListener("click", () => { expanded.add(+s.dataset.exp); renderTable(); }));
  box.querySelectorAll("[data-fold]").forEach(s => s.addEventListener("click", e => { e.preventDefault(); expanded.delete(+s.dataset.fold); renderTable(); }));
}
function renderPager(){
  const lim = +RES.limit, from = RES.total ? RES.offset + 1 : 0, to = Math.min(RES.total, RES.offset + lim);
  $("pager").innerHTML = `<span>${from}–${to} / ${fmtNum(RES.total)}</span>
    <button class="sm" id="pgPrev"${RES.offset<=0?' disabled':''}>이전</button><button class="sm" id="pgNext"${to>=RES.total?' disabled':''}>다음</button>`;
  $("pgPrev").addEventListener("click", () => { offset = Math.max(0, offset - lim); load(); });
  $("pgNext").addEventListener("click", () => { offset = offset + lim; load(); });
}
async function cancel(id){
  const j = (RES.rows||[]).find(x => x.id === id); if(!j) return;
  if(!confirm(j.status === "running" ? `실행 중인 잡 #${id}(${j.kind}) 에 취소를 요청할까요? 워커가 현재 단계를 중단합니다.` : `대기 중인 잡 #${id}(${j.kind}) 을 취소할까요?`)) return;
  try { const r = await api(API, { action:"cancel", id }); toast(r.result === "cancelled" ? "취소됨" : "취소 요청됨 — 워커가 중단하면 상태가 바뀝니다"); await load(); }
  catch(e){ alert("취소 실패: " + e.message); }
}
async function retry(id){
  const j = (RES.rows||[]).find(x => x.id === id); if(!j) return;
  if(!confirm(`잡 #${id}(${j.kind}${j.request_id ? ", " + j.request_id : ""}) 을 같은 입력으로 다시 등록할까요?`)) return;
  try { const r = await api(API, { action:"retry", id }); toast(`재시도 잡 #${r.new_id} 등록`); await load(); }
  catch(e){ alert("재시도 실패: " + e.message); }
}
["fkind","ffrom","fto","flimit"].forEach(id => $(id).addEventListener("change", () => { offset = 0; load(); }));
let qt = null; $("fq").addEventListener("input", () => { clearTimeout(qt); qt = setTimeout(() => { offset = 0; load(); }, 300); });
$("reload").addEventListener("click", () => load());
load();
</script>
</body>
</html>
