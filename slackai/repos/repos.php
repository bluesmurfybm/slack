<?php
/**
 * 레포 매핑 화면 — ai_repos(고객사 → 로컬 작업 사본) CRUD + [🔎 점검](check_repo 잡 → 워커가 기록, 5초 폴링). API: repos_api.php
 *  승인자·관리자: 추가/수정/사용여부/점검. 관리자: 삭제. 그 외 사용자는 읽기 전용.
 *  PHP 는 local_path 를 디스크에서 확인하지 않는다 — 워커 호스트의 경로이므로 워커가 점검한다.
 */
$__bwBase = '../';
$__bwCurrent = 'slackai';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../ai/admin_lib.php';
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
<title>레포 매핑 · WorkHub AI</title>
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
    <h1>📁 레포 매핑 <span class="badge" id="count"></span></h1>
    <div class="right">
      <?= admin_nav_html('repos') ?>
      <span class="muted small"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님<?= $adm['is_admin'] ? ' · 관리자' : ($adm['is_approver'] ? ' · 승인자' : ' · 읽기 전용') ?></span>
      <button id="themeBtn" type="button" class="tip" data-tip="다크/라이트 전환">🌓</button>
      <a class="back" href="../lists.php">← 목록으로</a>
    </div>
  </div>

<?php if ($adm['is_approver']): ?>
  <div class="form" id="form">
    <div class="grid">
      <div class="field c3"><label>레포 이름 * <span class="fmode muted" id="fmode">(추가)</span></label><input id="fname" type="text" maxlength="120" placeholder="예: 서울대 LMS 4.5"></div>
      <div class="field c3"><label>학교 (schools)</label>
        <div style="display:flex;gap:6px"><input id="fschool" class="flex1" type="text" list="schoolList" placeholder="학교명 입력 → 선택" autocomplete="off"><button type="button" id="fromSchool" class="tip" data-tip="선택한 학교의 이름·버전·개발/운영 호스트를 폼에 채웁니다">🏫 가져오기</button></div>
        <datalist id="schoolList"></datalist>
      </div>
      <div class="field c2"><label>VCS</label><select id="fvcs"><option value="svn">svn</option><option value="git">git</option></select></div>
      <div class="field c2"><label>LMS 버전</label><input id="fver" type="text" list="verList" maxlength="20" placeholder="4.5"><datalist id="verList"><option value="3.5"><option value="3.9"><option value="4.5"></datalist></div>
      <div class="field c2"><label>사용</label><label class="fchk"><input type="checkbox" id="fact" checked> 활성 (분석 시 후보에 포함)</label></div>

      <div class="field c6"><label>로컬 작업 사본 경로 * <span class="desc">(워커 PC 기준 · 저장 시 검증하지 않고 [🔎 점검]으로 확인)</span></label><input id="fpath" type="text" maxlength="500" placeholder="G:\01_Bluesoft\2014_MSNU\03_Source\moodle" class="mono"></div>
      <div class="field c6"><label>원격 URL</label><input id="fremote" type="text" maxlength="500" placeholder="svn://… 또는 https://…git" class="mono"></div>

      <div class="field c3"><label>기본 브랜치</label><input id="fbranch" type="text" maxlength="80" placeholder="trunk / main"></div>
      <div class="field c5"><label>php 실행 파일 <span class="desc">(비우면 워커 PHP_BIN)</span></label><input id="fphp" type="text" maxlength="300" placeholder="D:\wamp64\bin\php\php8.2.29\php.exe" class="mono"></div>
      <div class="field c4"><label>제목 키워드 <span class="desc">(콤마 · 제목에 있으면 이 레포로 추정)</span></label><input id="fkw" type="text" placeholder="서울대, SNU, 스누"></div>

      <div class="field c6"><label>LMS URL 패턴 <span class="desc">(한 줄에 하나 · 문의의 LMS 주소 호스트와 대조)</span></label><textarea id="flms" rows="3" class="mono" placeholder="lms.snu.ac.kr&#10;dev-lms.snu.ac.kr"></textarea></div>
      <div class="field c6"><label>저장소 메모 <span class="desc">(플랜 시스템 프롬프트에 그대로 들어감 — 구조·규칙·주의점)</span></label><textarea id="fnotes" rows="3" placeholder="예: theme/snu 가 커스텀 테마, local/snu_* 플러그인이 학사 연동 담당"></textarea></div>
    </div>
    <div class="actions">
      <span class="hint" id="fhint"></span>
      <span class="spacer"></span>
      <button id="cancel" type="button" style="display:none">취소</button>
      <button class="primary" id="save">추가</button>
    </div>
  </div>
<?php else: ?>
  <div class="form"><span class="hint">읽기 전용 — 레포 등록·수정은 승인자 또는 관리자만 할 수 있습니다.</span></div>
<?php endif; ?>

  <div class="tools">
    <input id="search" type="text" placeholder="이름 · 학교 · 경로 검색…" style="width:240px">
    <label class="fchk"><input type="checkbox" id="showOff" checked> 미사용 포함</label>
    <span class="spacer"></span>
    <?php if ($adm['is_approver']): ?><button id="discover" type="button" class="sm tip" data-tip="G:\01_Bluesoft\*\03_Source 아래 작업사본의 svn/git 주소를 school_access 의 주소와 맞춰 레포를 자동 등록·갱신합니다(워커 필요)">📥 school_access 로 자동 등록</button><span id="discoverMsg" class="hint"></span><?php endif; ?>
    <span class="hint">점검 = 워커가 경로 존재·VCS 종류·HEAD 리비전을 확인해 기록합니다 (워커가 켜져 있어야 결과가 옵니다)</span>
    <button id="reload" type="button" class="sm">🔄</button>
  </div>

  <div class="box scroll"><div id="list"><div class="loading">불러오는 중…</div></div></div>
</div>

<script src="../ai/admin.js"></script>
<script>
const { $, esc, escA, api, toast, fmtDt } = ADM;
const CAN_WRITE = <?= $adm['is_approver'] ? 'true' : 'false' ?>;
const IS_ADMIN  = <?= $adm['is_admin'] ? 'true' : 'false' ?>;
const API = "repos_api.php";
let DATA = [], SCHOOLS = [], editId = null;
const POLL = {};   // repo id → {prev,last_checked, until, timer}

async function load(){
  try { DATA = (await api(API + "?all=1")).rows || []; }
  catch(e){ $("list").innerHTML = `<div class="err">불러오기 실패: ${esc(e.message)}</div>`; return; }
  render();
}
async function loadSchools(){
  if(!CAN_WRITE) return;
  try { SCHOOLS = (await (await fetch("../schools/schools.php", { cache:"no-store" })).json()).rows || []; } catch(e){ SCHOOLS = []; }
  $("schoolList").innerHTML = SCHOOLS.map(s => `<option value="${escA(s.name)}">${esc(s.ver ? "v" + s.ver : "")}</option>`).join("");
}
function schoolByName(name){ name = name.trim(); return SCHOOLS.find(s => s.name === name) || null; }
function hostOf(u){ u = (u||"").trim(); if(!u) return ""; try { return new URL(/^https?:\/\//i.test(u) ? u : "https://" + u).hostname; } catch(e){ return u.replace(/^https?:\/\//i,"").split("/")[0]; } }

function ckHtml(r){
  const p = POLL[r.id];
  if(p) return `<span class="ck ck-polling">⏳ 점검 중…</span>`;
  const st = r.check_status || "unchecked";
  const label = st === "ok" ? "✅ 정상" : st === "fail" ? "❌ 실패" : "⚪ 미점검";
  const tip = [r.check_message, r.last_checked ? "점검: " + r.last_checked : null].filter(Boolean).join("\n");
  return `<span class="ck ck-${esc(st)}${tip ? ' tip' : ''}"${tip ? ` data-tip="${escA(tip)}"` : ''}>${label}</span>`
       + (r.last_checked ? `<div class="small muted nowrap">${fmtDt(r.last_checked)}${r.head_revision ? ` · <span class="mono">${esc(r.head_revision)}</span>` : ''}</div>` : '')
       + (st === "fail" && r.check_message ? `<div class="small" style="color:var(--bad);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escA(r.check_message)}">${esc(r.check_message)}</div>` : '');
}
function render(){
  const q = $("search").value.trim().toLowerCase(), showOff = $("showOff").checked;
  const list = DATA.filter(r => (showOff || r.active) && (!q || r.name.toLowerCase().includes(q) || (r.school_name||"").toLowerCase().includes(q) || (r.local_path||"").toLowerCase().includes(q)));
  $("count").textContent = list.length + "건";
  const box = $("list");
  if(!list.length){ box.innerHTML = '<div class="empty">등록된 레포가 없습니다. 위 폼에서 추가하세요.</div>'; return; }
  box.innerHTML = `<table><thead><tr>
      <th style="width:20%">이름 / 학교</th><th style="width:50px">VCS</th><th>로컬 경로</th><th style="width:60px">버전</th>
      <th style="width:170px">점검</th><th style="width:70px">사용</th><th style="width:${CAN_WRITE ? 210 : 40}px">관리</th>
    </tr></thead><tbody>` +
    list.map(r => `<tr${r.active ? '' : ' class="off"'} data-id="${r.id}">
      <td class="name">${esc(r.name)}${r.school_name ? `<div class="small muted">🏫 ${esc(r.school_name)}</div>` : ''}${r.ref_count ? `<div class="small muted">참조 ${r.ref_count}건</div>` : ''}</td>
      <td><span class="vcs">${esc(r.vcs)}</span>${r.default_branch ? `<div class="small muted mono">${esc(r.default_branch)}</div>` : ''}</td>
      <td><span class="mono ellipsis" title="${escA(r.local_path)}">${esc(r.local_path)}</span>${r.remote_url ? `<span class="small muted ellipsis" title="${escA(r.remote_url)}">${esc(r.remote_url)}</span>` : ''}
          ${(r.match_rules.lms_patterns||[]).length ? `<div>${r.match_rules.lms_patterns.slice(0,4).map(p=>`<span class="kw">${esc(p)}</span>`).join("")}${r.match_rules.lms_patterns.length>4?'<span class="kw">…</span>':''}</div>` : ''}</td>
      <td>${r.version ? `<span class="vtag">${esc(r.version)}</span>` : '<span class="muted">—</span>'}</td>
      <td>${ckHtml(r)}</td>
      <td>${CAN_WRITE ? `<button class="tgl${r.active?' on':''}" data-act="toggle" data-id="${r.id}">${r.active?'사용':'미사용'}</button>` : `<span class="tgl${r.active?' on':''}">${r.active?'사용':'미사용'}</span>`}</td>
      <td class="act">${CAN_WRITE ? `
        <button class="sm" data-act="check" data-id="${r.id}"${POLL[r.id] ? ' disabled' : ''}>🔎 점검</button>
        <button class="sm" data-act="edit" data-id="${r.id}">수정</button>
        ${IS_ADMIN ? `<button class="sm danger" data-act="del" data-id="${r.id}">삭제</button>` : ''}` : ''}</td>
    </tr>`).join("") + `</tbody></table>`;
  box.querySelectorAll("button[data-act]").forEach(b => b.addEventListener("click", () => {
    const id = +b.dataset.id, act = b.dataset.act;
    if(act==="edit") startEdit(id); else if(act==="toggle") toggle(id); else if(act==="check") check(id); else if(act==="del") del(id);
  }));
}

function startEdit(id){
  const r = DATA.find(x => x.id === id); if(!r || !CAN_WRITE) return;
  editId = id;
  $("fname").value = r.name; $("fschool").value = r.school_name || ""; $("fvcs").value = r.vcs; $("fver").value = r.version || "";
  $("fact").checked = !!r.active; $("fpath").value = r.local_path; $("fremote").value = r.remote_url || ""; $("fbranch").value = r.default_branch || "";
  $("fphp").value = r.php_bin || ""; $("fkw").value = (r.match_rules.title_keywords||[]).join(", "); $("flms").value = (r.match_rules.lms_patterns||[]).join("\n");
  $("fnotes").value = r.notes || "";
  $("fmode").textContent = `(수정 #${id})`; $("save").textContent = "저장"; $("cancel").style.display = "";
  $("fname").focus(); window.scrollTo({ top:0, behavior:"smooth" });
}
function resetForm(){
  editId = null;
  ["fname","fschool","fver","fpath","fremote","fbranch","fphp","fkw","flms","fnotes"].forEach(id => $(id).value = "");
  $("fvcs").value = "svn"; $("fact").checked = true; $("fhint").textContent = "";
  $("fmode").textContent = "(추가)"; $("save").textContent = "추가"; $("cancel").style.display = "none";
}
async function fromSchool(){
  const s = schoolByName($("fschool").value);
  if(!s){ toast("목록에서 학교를 먼저 선택하세요.", true); return; }
  try {   // school_access.repo 의 svn/git 주소 → 원격 URL / VCS
    const urls = (await api(API + "?school_repo=" + s.id)).urls || [];
    if(urls.length && !$("fremote").value.trim()){ $("fremote").value = urls[0]; $("fvcs").value = /^svn/i.test(urls[0]) ? "svn" : "git"; }
    if(urls.length > 1) toast(`school_access 주소 ${urls.length}개 중 첫 번째를 넣었습니다: ${urls.join(" , ")}`);
  } catch(e){ /* 주소 없음 — 무시 */ }
  if(!$("fname").value.trim()) $("fname").value = s.name + (s.ver ? ` LMS ${s.ver}` : "");
  if(s.ver) $("fver").value = s.ver;
  const hosts = [hostOf(s.dev), hostOf(s.ops)].filter(Boolean);
  const cur = $("flms").value.split(/\n/).map(x => x.trim()).filter(Boolean);
  hosts.forEach(h => { if(!cur.includes(h)) cur.push(h); });
  $("flms").value = cur.join("\n");
  const kws = $("fkw").value.split(",").map(x => x.trim()).filter(Boolean);
  if(!kws.includes(s.name)) kws.push(s.name);
  $("fkw").value = kws.join(", ");
  $("fhint").textContent = `학교 #${s.id} 에서 이름·버전·호스트(${hosts.join(", ") || "없음"})를 채웠습니다.`;
}
async function save(){
  const name = $("fname").value.trim(), path = $("fpath").value.trim();
  if(!name){ alert("레포 이름을 입력하세요."); $("fname").focus(); return; }
  if(!path){ alert("로컬 작업 사본 경로를 입력하세요."); $("fpath").focus(); return; }
  const sName = $("fschool").value.trim(), s = sName ? schoolByName(sName) : null;
  if(sName && !s && !confirm(`'${sName}' 은(는) 학교 목록에 없습니다. 학교 연결 없이 저장할까요?`)) return;
  const body = { action: editId ? "update" : "create", id: editId, name, school_id: s ? s.id : null, vcs: $("fvcs").value, version: $("fver").value.trim(),
    active: $("fact").checked ? 1 : 0, local_path: path, remote_url: $("fremote").value.trim(), default_branch: $("fbranch").value.trim(), php_bin: $("fphp").value.trim(),
    match_rules: { lms_patterns: $("flms").value.split(/\n/).map(x => x.trim()).filter(Boolean), title_keywords: $("fkw").value.split(",").map(x => x.trim()).filter(Boolean) },
    notes: $("fnotes").value };
  $("save").disabled = true;
  try {
    const j = await api(API, body);
    toast(editId ? (j.recheck ? "저장됨 · 경로가 바뀌어 다시 점검이 필요합니다" : "저장됨") : "추가됨 · [🔎 점검]으로 경로를 확인하세요");
    resetForm(); await load();
  } catch(e){ alert("저장 실패: " + e.message); }
  finally { $("save").disabled = false; }
}
async function toggle(id){
  try { await api(API, { action:"toggle", id }); await load(); } catch(e){ toast("변경 실패: " + e.message, true); }
}
async function del(id){
  const r = DATA.find(x => x.id === id); if(!r) return;
  if(!confirm(`'${r.name}' 레포를 삭제할까요?\n경로: ${r.local_path}`)) return;
  try { await api(API, { action:"delete", id }); toast("삭제됨"); if(editId===id) resetForm(); await load(); }
  catch(e){
    if(e.code === "in_use"){
      if(r.active && confirm(e.message + "\n\n지금 '미사용'으로 전환할까요?")) await toggle(id);
      else alert(e.message);
    } else alert("삭제 실패: " + e.message);
  }
}
/* 점검: POST check → 5초마다 GET 해서 last_checked 가 바뀌면 종료 (최대 2분) */
async function check(id){
  const r = DATA.find(x => x.id === id); if(!r) return;
  try {
    const j = await api(API, { action:"check", id });
    POLL[id] = { prev: r.last_checked, until: Date.now() + 120000 };
    toast(j.duplicate ? "이미 점검 잡이 대기 중입니다 — 결과를 기다립니다" : `점검 잡 #${j.job_id} 등록 — 워커 결과 대기`);
    render();
    POLL[id].timer = setInterval(() => pollOne(id), 5000);
  } catch(e){ alert("점검 요청 실패: " + e.message); }
}
async function pollOne(id){
  const p = POLL[id]; if(!p) return;
  try {
    const row = (await api(API + "?id=" + id)).row;
    if(row && row.last_checked !== p.prev && row.check_status !== "unchecked"){
      clearInterval(p.timer); delete POLL[id];
      toast(row.check_status === "ok" ? `✅ '${row.name}' 점검 정상${row.head_revision ? " · " + row.head_revision : ""}` : `❌ '${row.name}' 점검 실패: ${row.check_message || ""}`, row.check_status !== "ok");
      await load(); return;
    }
  } catch(e){ /* 일시 오류는 다음 폴링에서 */ }
  if(Date.now() > p.until){
    clearInterval(p.timer); delete POLL[id];
    toast("점검 결과가 2분 내에 오지 않았습니다. 워커가 실행 중인지 확인하세요 (작업 로그 참고).", true);
    await load();
  }
}
/* school_access 기반 자동 등록: discover_repos 잡 → 5초마다 상태 확인 → 끝나면 목록 새로고침 */
let DISC_TIMER = null;
function discMsg(j){
  const el = $("discoverMsg"); if(!el) return;
  if(!j){ el.textContent = ""; return; }
  const r = j.result || {};
  el.textContent = j.status === "done" ? `마지막 자동 등록 ${j.finished_at||""}: 스캔 ${r.scanned??"?"} · 매칭 ${r.matched??"?"} (신규 ${r.created??0}, 갱신 ${r.updated??0}) · 미매칭 ${(r.unmatched||[]).length}`
    : j.status === "failed" ? `자동 등록 실패: ${j.error||""}` : `자동 등록 ${j.status === "running" ? "진행 중" : "대기 중"}… ${j.progress||""}`;
  el.title = (r.unmatched||[]).length ? "미매칭 작업사본:
" + r.unmatched.join("
") : "";
}
async function discoverPoll(){
  try {
    const j = (await api(API + "?discover_status=1")).job; discMsg(j);
    if(j && (j.status === "queued" || j.status === "running")){ if(!DISC_TIMER) DISC_TIMER = setInterval(discoverPoll, 5000); return; }
    if(DISC_TIMER){ clearInterval(DISC_TIMER); DISC_TIMER = null; $("discover").disabled = false; await load(); if(j && j.status === "done") toast("자동 등록 완료 — 목록을 새로고침했습니다"); }
  } catch(e){ /* 다음 폴링 */ }
}
async function discover(){
  if(!confirm("로컬 작업사본을 훑어 school_access 주소와 맞는 레포를 자동 등록·갱신합니다.
(직접 입력한 이름·메모는 유지, 같은 학교 중복 사본은 최근 것만 '사용')
진행할까요?")) return;
  try {
    const j = await api(API, { action:"discover" });
    toast(j.duplicate ? "이미 자동 등록 작업이 대기/진행 중입니다" : `자동 등록 잡 #${j.job_id} 등록 — 워커가 처리합니다`);
    $("discover").disabled = true; discoverPoll(); if(!DISC_TIMER) DISC_TIMER = setInterval(discoverPoll, 5000);
  } catch(e){ alert("자동 등록 요청 실패: " + e.message); }
}
if(CAN_WRITE){
  $("discover").addEventListener("click", discover);
  discoverPoll();
  $("save").addEventListener("click", save);
  $("cancel").addEventListener("click", resetForm);
  $("fromSchool").addEventListener("click", fromSchool);
  $("fschool").addEventListener("change", () => { const s = schoolByName($("fschool").value); if(s) $("fhint").textContent = `학교 #${s.id} 선택 (v${s.ver||"?"}) — [🏫 가져오기]로 이름·버전·호스트를 채울 수 있습니다.`; });
  ["fname","fpath","fremote","fbranch","fphp","fkw","fver"].forEach(id => $(id).addEventListener("keydown", e => { if(e.key==="Enter") save(); }));
}
$("search").addEventListener("input", render);
$("showOff").addEventListener("change", render);
$("reload").addEventListener("click", load);
loadSchools(); load();
</script>
</body>
</html>
