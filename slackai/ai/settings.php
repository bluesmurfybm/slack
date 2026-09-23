<?php
/**
 * AI 설정 화면 — ai_settings 를 그룹별 폼으로. 값이 바뀌면 바로 저장(settings_api.php POST {k,v}).
 *  승인자·관리자만. 워커는 잡을 집을 때마다 ai_settings 를 읽으므로 저장 즉시 반영된다.
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
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI 설정 · WorkHub AI</title>
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
<div class="wrap" style="max-width:980px">
  <div class="head">
    <h1>⚙️ AI 설정</h1>
    <div class="right">
      <?= admin_nav_html('settings') ?>
      <span class="muted small"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님<?= $adm['is_admin'] ? ' · 관리자' : ($adm['is_approver'] ? ' · 승인자' : '') ?></span>
      <button id="themeBtn" type="button" class="tip" data-tip="다크/라이트 전환">🌓</button>
      <a class="back" href="../lists.php">← 목록으로</a>
    </div>
  </div>

<?php if (!$adm['is_approver']): ?>
  <div class="warn-box">승인자 또는 관리자만 AI 설정을 볼 수 있습니다. (config.php slackai.approvers · ai_settings.approvers · portal_admin)</div>
<?php else: ?>
  <div id="pausedBox" class="warn-box" style="display:none">⏸ 워커가 일시 중지 상태입니다 — 새 잡을 집지 않습니다. 아래 "워커 일시 중지"를 끄면 재개됩니다.</div>
  <div id="groups"><div class="loading">불러오는 중…</div></div>
  <p class="hint">값을 바꾸면 바로 저장됩니다. 워커는 잡을 집을 때마다 설정을 다시 읽으므로 즉시 반영됩니다. 워커 .env 의 같은 이름 값은 이 설정이 덮어씁니다.</p>
<?php endif; ?>
</div>

<script src="../ai/admin.js"></script>
<?php if ($adm['is_approver']): ?>
<script>
const { $, esc, escA, api, toast, fmtDt } = ADM;
const API = "settings_api.php";
const GROUPS = [
  { title: "🤖 자동화", items: [
    { k:"paused",             label:"워커 일시 중지",           warn:true, desc:"켜면 워커가 새 잡을 집지 않습니다(진행 중인 잡은 마무리). 문제가 생겼을 때 전체를 멈추는 스위치." },
    { k:"auto_triage",        label:"신규 문의 자동 분석",       desc:"Slack 신규 항목이 들어오면 triage(요약·태그·유사사례·레포 판별) 잡을 자동 등록합니다." },
    { k:"auto_plan",          label:"자동 플랜 생성",           desc:"AI 분석이 끝나고 레포가 확정되면(또는 패널에서 레포를 고르면) 플랜 프롬프트를 자동으로 만들어 플랜까지 실행합니다. 이미 승인·실행 중인 플랜이 있으면 건드리지 않습니다. 끄면 패널의 [플랜 생성] 버튼으로만." },
    { k:"plan_model_auto",    label:"자동 플랜 모델",           desc:"자동 플랜(분석 직후·레포 선택 직후)에 쓰는 모델. 기본 Haiku 로 토큰 비용을 줄이고, 더 깊은 조사가 필요하면 패널의 플랜 섹션에서 Sonnet/Opus 를 골라 [🔁 플랜 재생성] 으로 따로 조회합니다." },
    { k:"auto_review",        label:"커밋 후 자동 검토",         desc:"커밋이 끝나면 다른 AI(reviewer 순서) 검토 잡을 자동 등록합니다. 끄면 [🧪 검토 요청] 버튼으로만." },
    { k:"retriage_on_update", label:"본문 갱신 시 재분석",       desc:"기존 문의의 제목·본문이 바뀌면 다시 분석합니다(내용 해시가 같으면 건너뜀). 상태·담당자 변경만으로는 재분석하지 않습니다." },
    { k:"enqueue_on_full",    label:"전체 동기화에서도 신규 등록", desc:"full 동기화(전체 재수집)에서 발견된 신규 항목도 분석 큐에 넣습니다. 기본 OFF — 대량 등록·비용 폭주 방지." },
    { k:"post_to_slack",      label:"Slack 스레드에 결과 게시",   desc:"요약·플랜·검토 결과를 해당 Rec 의 Slack 스레드 댓글로 남깁니다. 운영 채널을 건드리므로 테스트 단계에서는 OFF 권장." },
  ]},
  { title: "💵 예산 (USD)", items: [
    { k:"budget_plan_usd",    label:"플랜 1회 상한",  desc:"claude -p --max-budget-usd 로 전달. 초과 시 부분 결과가 있으면 budget_hit 표시로 저장, 없으면 실패." },
    { k:"budget_execute_usd", label:"실행 1회 상한",  desc:"승인 모달에서 건별로 조정할 수 있는 기본값. 실행 잡은 재시도하지 않습니다(max_attempts 1)." },
    { k:"budget_review_usd",  label:"검토 1회 상한",  desc:"타 AI 검토(codex/openai/gemini/claude) 1회 비용 상한." },
    { k:"max_daily_usd",      label:"일일 총 상한",   desc:"오늘 종료된 잡의 비용 합이 이 값 이상이면 plan/execute/review 잡을 'daily budget' 으로 실패시킵니다. triage 등 저비용 잡은 계속 돕니다." },
  ]},
  { title: "🔍 검색", items: [
    { k:"similar_min",      label:"유사도 최소값",        desc:"TF-IDF 유사도가 이 값(0~1) 미만인 과거 문의는 후보에서 제외." },
    { k:"similar_limit",    label:"유사 후보 수",          desc:"TF-IDF 후보 상위 N건을 LLM 재정렬에 넘깁니다(1~100). 클수록 토큰 증가." },
    { k:"comment_scan_sec", label:"댓글 수 전체 스캔 주기(초)", desc:"0 이면 전체 동기화 때만 댓글 수를 스캔합니다(이벤트 수신이 대신함). 양수면 워커가 그 주기로 스캔." },
  ]},
  { title: "🛠 워커", items: [
    { k:"heartbeat_stale_sec", label:"heartbeat 정체 판정(초)", desc:"실행 중 잡의 heartbeat 가 이 시간 동안 없으면 화면에 '응답 없음(stale)' 으로 표시합니다(10~3600)." },
    { k:"reviewer",            label:"검토 AI 우선순위",         desc:"콤마 구분, 앞에서부터 사용 가능한 첫 백엔드를 씁니다. 허용: codex, openai, gemini, claude (claude 는 실행 모델과 다른 모델로 검토)." , wide:true },
  ]},
];
let ROWS = {}, SCHEMA = {}, CFG_APPROVERS = [];

async function load(){
  try { const j = await api(API); ROWS = j.rows; SCHEMA = j.schema; CFG_APPROVERS = j.config_approvers || []; }
  catch(e){ $("groups").innerHTML = `<div class="err">불러오기 실패: ${esc(e.message)}</div>`; return; }
  render();
}
function meta(k){
  const r = ROWS[k]; if(!r) return `<span class="muted">기본값 없음</span>`;
  return `${esc(r.updated_by || "")}${r.updated_at ? " · " + fmtDt(r.updated_at, true) : ""} <span class="status" id="st_${k}"></span>`;
}
function ctrl(it){
  const t = (SCHEMA[it.k] || {}).type, v = ROWS[it.k] ? ROWS[it.k].v : "";
  if(t === "bool") return `<button type="button" class="switch${v==="1"?' on':''}${it.warn?' warn':''}" data-k="${it.k}" role="switch" aria-checked="${v==="1"}"></button><span class="small muted" id="lbl_${it.k}" style="width:28px">${v==="1"?"ON":"OFF"}</span>`;
  if(t === "money") return `<input type="number" step="0.01" min="0" data-k="${it.k}" value="${escA(v)}"> <span class="muted">USD</span>`;
  if(t === "ratio") return `<input type="number" step="0.01" min="0" max="1" data-k="${it.k}" value="${escA(v)}">`;
  if(t === "int")   return `<input type="number" step="1" data-k="${it.k}" value="${escA(v)}">`;
  if(t === "choice"){ const sc = SCHEMA[it.k] || {}, labels = sc.labels || {}; return `<select data-k="${it.k}">${(sc.allowed||[]).map(o => `<option value="${escA(o)}"${o===v?" selected":""}>${esc(labels[o] || o)}</option>`).join("")}</select>`; }
  return `<input type="text" data-k="${it.k}" value="${escA(v)}"${it.wide?' class="wide"':''}>`;
}
function render(){
  $("pausedBox").style.display = (ROWS.paused && ROWS.paused.v === "1") ? "" : "none";
  let html = GROUPS.map(g => `<div class="sgroup"><h2>${g.title}</h2>` + g.items.map(it => `
    <div class="srow" data-row="${it.k}">
      <div class="sl"><b>${esc(it.label)}<span class="k">${it.k}</span></b><div class="d">${esc(it.desc)}</div></div>
      <div class="sc">${ctrl(it)}</div>
      <div class="sm">${meta(it.k)}</div>
    </div>`).join("") + `</div>`).join("");
  // 승인자 목록 편집기
  const appr = (() => { try { return JSON.parse((ROWS.approvers||{}).v || "[]") || []; } catch(e){ return []; } })();
  html += `<div class="sgroup"><h2>👤 승인자</h2>
    <div class="srow" data-row="approvers" style="grid-template-columns:1fr">
      <div class="sl"><b>플랜 승인·커밋·학습 노트 승인 권한<span class="k">approvers</span></b>
        <div class="d">여기 등록된 포털 이메일 + config.php 의 slackai.approvers + portal_admin 이 승인자입니다. 두 목록이 모두 비면 관리자만 승인할 수 있습니다.</div></div>
      <div class="emails" id="emails">
        ${CFG_APPROVERS.map(e => `<span class="em fixed tip" data-tip="config.php 고정 — 화면에서 제거 불가">${esc(e)}</span>`).join("")}
        ${appr.map(e => `<span class="em">${esc(e)}<button type="button" data-rm="${escA(e)}" title="제거">✕</button></span>`).join("")}
        <input type="email" id="newEmail" placeholder="이메일 추가 후 Enter" style="width:240px;height:30px">
        <button type="button" class="sm" id="addEmail">추가</button>
      </div>
      <div class="sm">${meta("approvers")}</div>
    </div></div>`;
  $("groups").innerHTML = html;

  $("groups").querySelectorAll(".switch").forEach(b => b.addEventListener("click", () => save(b.dataset.k, b.classList.contains("on") ? "0" : "1")));
  $("groups").querySelectorAll("select[data-k]").forEach(sl => sl.addEventListener("change", () => save(sl.dataset.k, sl.value)));
  $("groups").querySelectorAll("input[data-k]").forEach(i => {
    i.addEventListener("change", () => save(i.dataset.k, i.value));
    i.addEventListener("keydown", e => { if(e.key === "Enter") i.blur(); });
  });
  $("groups").querySelectorAll("button[data-rm]").forEach(b => b.addEventListener("click", () => saveApprovers(appr.filter(e => e !== b.dataset.rm))));
  const add = () => { const v = $("newEmail").value.trim().toLowerCase(); if(!v) return; if(appr.includes(v)){ toast("이미 있는 이메일입니다.", true); return; } saveApprovers([...appr, v]); };
  $("addEmail").addEventListener("click", add);
  $("newEmail").addEventListener("keydown", e => { if(e.key === "Enter"){ e.preventDefault(); add(); } });
}
function mark(k, ok, msg){
  const st = $("st_" + k); if(!st) return;
  st.className = "status " + (ok ? "saved" : "err"); st.textContent = msg;
  if(ok) setTimeout(() => { if(st.textContent === msg) st.textContent = ""; }, 2500);
}
async function save(k, v){
  try {
    const j = await api(API, { k, v });
    ROWS[k] = j.row || ROWS[k];
    if(j.row){ const row = $("groups").querySelector(`[data-row="${k}"] .sm`); if(row) row.innerHTML = meta(k); }
    const sw = $("groups").querySelector(`.switch[data-k="${k}"]`);
    if(sw){ sw.classList.toggle("on", j.v === "1"); sw.setAttribute("aria-checked", j.v === "1"); const l = $("lbl_" + k); if(l) l.textContent = j.v === "1" ? "ON" : "OFF"; }
    const inp = $("groups").querySelector(`input[data-k="${k}"]`); if(inp && j.v !== undefined) inp.value = j.v;
    if(k === "paused") $("pausedBox").style.display = j.v === "1" ? "" : "none";
    mark(k, true, j.changed && j.changed.length ? "저장됨 ✓" : "변경 없음");
  } catch(e){
    mark(k, false, e.message);
    toast(e.message, true);
    const inp = $("groups").querySelector(`input[data-k="${k}"]`); if(inp && ROWS[k]) inp.value = ROWS[k].v;   // 원래 값 복원
  }
}
async function saveApprovers(list){
  try { const j = await api(API, { k:"approvers", v:list }); ROWS.approvers = j.row || ROWS.approvers; toast("승인자 목록 저장"); render(); }
  catch(e){ toast(e.message, true); }
}
load();
</script>
<?php endif; ?>
</body>
</html>
