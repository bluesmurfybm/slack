<?php
/**
 * Coursemos PwdLog — 슬랙에서 대학 관리자 계정을 요청한 이력. API: api.php
 * 계정 값은 여기에 없다(누가·언제·어느 대학·운영/테스트·결과만).
 */
require_once __DIR__ . '/guard.php';
csmspwdlog_require_login();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 공통 상단바(slack/header.php)는 그 파일 위치(slack/) 기준으로 포털 루트를 되짚는다
$__bwBase = '../slack/';
$__bwCurrent = 'csmspwdlog';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coursemos PwdLog</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/csmspwdlog.svg">
<?php require_once __DIR__ . '/../core/pwa.php'; echo pwa_head_tags('../'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../slack/styles/header.css?v=<?= filemtime(__DIR__ . '/../slack/styles/header.css') ?>">
<link rel="stylesheet" href="../slack/styles/common.css?v=<?= filemtime(__DIR__ . '/../slack/styles/common.css') ?>">
<link rel="stylesheet" href="styles/style.css?v=<?= filemtime(__DIR__ . '/styles/style.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../slack/header.php'; ?>

<div class="wrap">
  <div class="head">
    <h1>🔐 Coursemos PwdLog <span class="badge" id="count"></span></h1>
    <p class="desc">슬랙 채널에 대학 사이트 주소를 올려 관리자 계정을 DM 으로 받아 간 이력입니다. 계정 값은 저장하지 않습니다.</p>
  </div>

  <div class="tools">
    <select id="fresult" title="결과로 거르기"><option value="">모든 결과</option></select>
    <input type="date" id="ffrom" title="시작일"> <span class="muted">~</span> <input type="date" id="fto" title="종료일">
    <input id="fq" type="text" placeholder="요청자 · 대학 · 주소 검색" autocomplete="off">
    <span class="spacer"></span>
    <select id="flimit" title="한 번에 볼 건수"><option value="50">50건</option><option value="100" selected>100건</option><option value="200">200건</option><option value="500">500건</option></select>
    <button id="reload" type="button">새로고침</button>
  </div>

  <div class="box"><div id="list"><div class="empty">불러오는 중…</div></div></div>
  <div class="pager" id="pager"></div>
</div>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c]));
const ENV_LABEL = { ops: "운영", dev: "테스트" };
let offset = 0, RES = null;

function params(){
  const p = new URLSearchParams();
  if($("fresult").value) p.set("result", $("fresult").value);
  if($("ffrom").value) p.set("from", $("ffrom").value);
  if($("fto").value) p.set("to", $("fto").value);
  const q = $("fq").value.trim(); if(q) p.set("q", q);
  p.set("limit", $("flimit").value); p.set("offset", offset);
  return p.toString();
}
async function load(){
  try {
    const r = await fetch("api.php?" + params(), { headers: { "Accept": "application/json" } });
    if(r.status === 401){ location.href = "../index.php?need_login=csmspwdlog"; return; }
    const data = await r.json();
    if(!r.ok) throw new Error(data.detail || ("HTTP " + r.status));
    RES = data;
  } catch(e){
    $("list").innerHTML = `<div class="err">불러오기 실패: ${esc(e.message)}</div>`;
    return;
  }
  renderResults(); renderTable(); renderPager();
}
function renderResults(){
  const sel = $("fresult"); if(sel.options.length > 1) return;
  Object.entries(RES.results || {}).forEach(([v, l]) => { const o = document.createElement("option"); o.value = v; o.textContent = l; sel.appendChild(o); });
}
function who(r){
  const name = r.portal_name || r.slack_name || r.slack_user_id;
  const sub = [r.portal_name && r.slack_name && r.portal_name !== r.slack_name ? r.slack_name : "", r.slack_email].filter(Boolean).join(" · ");
  return `<div class="nm">${esc(name)}</div>${sub ? `<div class="sub">${esc(sub)}</div>` : ""}`;
}
function renderTable(){
  const rows = RES.rows || [];
  $("count").textContent = `${Number(RES.total).toLocaleString()}건`;
  if(!rows.length){ $("list").innerHTML = '<div class="empty">조건에 맞는 요청이 없습니다.</div>'; return; }
  $("list").innerHTML = `<table><thead><tr>
      <th class="c-at">요청 시각</th><th class="c-who">요청자</th><th class="c-school">대학</th><th class="c-ver">버전</th>
      <th class="c-env">구분</th><th class="c-url">입력한 주소</th><th class="c-res">결과</th>
    </tr></thead><tbody>` +
    rows.map(r => `<tr>
      <td class="c-at">${esc(String(r.created_at).slice(0, 16))}</td>
      <td class="c-who">${who(r)}</td>
      <td class="c-school">${r.school_name ? esc(r.school_name) : '<span class="muted">—</span>'}</td>
      <td class="c-ver">${esc(r.ver)}</td>
      <td class="c-env">${r.env ? `<span class="tag env-${esc(r.env)}">${ENV_LABEL[r.env] || esc(r.env)}</span>` : ""}</td>
      <td class="c-url" title="${esc(r.url_raw)}">${esc(r.host)}</td>
      <td class="c-res"><span class="tag res-${esc(r.result)}">${esc((RES.results || {})[r.result] || r.result)}</span>${r.detail ? `<div class="sub">${esc(r.detail)}</div>` : ""}</td>
    </tr>`).join("") + `</tbody></table>`;
}
function renderPager(){
  const lim = +RES.limit, from = RES.total ? RES.offset + 1 : 0, to = Math.min(RES.total, RES.offset + lim);
  $("pager").innerHTML = `<span>${from}–${to} / ${Number(RES.total).toLocaleString()}</span>
    <button id="pgPrev" type="button"${RES.offset <= 0 ? " disabled" : ""}>이전</button><button id="pgNext" type="button"${to >= RES.total ? " disabled" : ""}>다음</button>`;
  $("pgPrev").addEventListener("click", () => { offset = Math.max(0, offset - lim); load(); });
  $("pgNext").addEventListener("click", () => { offset = offset + lim; load(); });
}
["fresult", "ffrom", "fto", "flimit"].forEach(id => $(id).addEventListener("change", () => { offset = 0; load(); }));
let qt = null; $("fq").addEventListener("input", () => { clearTimeout(qt); qt = setTimeout(() => { offset = 0; load(); }, 300); });
$("reload").addEventListener("click", () => load());
load();
</script>
</body>
</html>
