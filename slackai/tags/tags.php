<?php
/**
 * 태그 관리 화면 — ai_tags CRUD·순서(드래그)·병합·삭제. API: tags_api.php
 *  로그인 사용자: 추가/수정/순서/사용여부. 승인자·관리자: 병합/삭제. 강제 삭제(사용 중): 관리자.
 */
$__bwBase = '../';   // slackai/ 하위 폴더 페이지 — require_login()/header.php 리다이렉트 경로 계산용
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
<title>태그 관리 · WorkHub AI</title>
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
    <h1>🏷️ 태그 관리 <span class="badge" id="count"></span></h1>
    <div class="right">
      <?= admin_nav_html('tags') ?>
      <span class="muted small"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님<?= $adm['is_admin'] ? ' · 관리자' : ($adm['is_approver'] ? ' · 승인자' : '') ?></span>
      <button id="themeBtn" type="button" class="tip" data-tip="다크/라이트 전환">🌓</button>
      <a class="back" href="../lists.php">← 목록으로</a>
    </div>
  </div>

  <div class="form row" id="form">
    <span class="fmode" id="fmode">추가</span>
    <input type="color" id="fcolor" class="tip" data-tip="색상" value="#9aa0a6">
    <input id="fname" class="flex1" type="text" maxlength="60" placeholder="태그 이름 *">
    <input id="fslug" type="text" maxlength="60" placeholder="slug (비우면 자동)" style="width:150px">
    <select id="fparent" style="width:170px"><option value="">상위 없음</option></select>
    <input id="fkw" class="flex1" type="text" placeholder="키워드 (콤마 구분) — AI 분류 힌트">
    <input id="fdesc" class="flex1" type="text" maxlength="300" placeholder="설명">
    <button class="primary" id="save">추가</button>
    <button id="cancel" type="button" style="display:none">취소</button>
  </div>

  <div class="tools">
    <input id="search" type="text" placeholder="이름 · slug · 키워드 검색…" style="width:240px">
    <label class="fchk"><input type="checkbox" id="showOff" checked> 미사용 포함</label>
    <span class="spacer"></span>
    <span class="hint">⠿ 를 끌어 놓으면 순서가 저장됩니다 · 색 동그라미를 눌러 바로 바꿀 수 있습니다</span>
  </div>

  <div class="box"><div id="list"><div class="loading">불러오는 중…</div></div></div>
</div>

<script src="../ai/admin.js"></script>
<script>
const { $, esc, escA, api, toast } = ADM;
const CAN_MANAGE = <?= $adm['is_approver'] ? 'true' : 'false' ?>;   // 병합·삭제
const IS_ADMIN   = <?= $adm['is_admin'] ? 'true' : 'false' ?>;      // 강제 삭제
const API = "tags_api.php";
let DATA = [], editId = null, mergeId = null, dragId = null;

async function load(){
  try { DATA = (await api(API + "?all=1")).rows || []; }
  catch(e){ $("list").innerHTML = `<div class="err">불러오기 실패: ${esc(e.message)}</div>`; return; }
  buildParents();
  render();
}
function buildParents(){
  const sel = $("fparent"), cur = sel.value;
  sel.innerHTML = '<option value="">상위 없음</option>' +
    DATA.filter(t => t.id !== editId && t.parent_id === null).map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join("");
  sel.value = cur;
}
function nameOf(id){ const t = DATA.find(x => x.id === id); return t ? t.name : ""; }

function render(){
  const q = $("search").value.trim().toLowerCase();
  const showOff = $("showOff").checked;
  const list = DATA.filter(t => (showOff || t.active) &&
    (!q || t.name.toLowerCase().includes(q) || (t.slug||"").toLowerCase().includes(q) || (t.keywords||"").toLowerCase().includes(q)));
  $("count").textContent = `${list.length}건 · 사용 ${DATA.reduce((a,t)=>a+t.usage,0)}건`;
  const box = $("list");
  if(!list.length){ box.innerHTML = '<div class="empty">태그가 없습니다.</div>'; return; }
  const maxUse = Math.max(1, ...DATA.map(t => t.usage));
  box.innerHTML = `<table><thead><tr>
      <th style="width:28px"></th><th style="width:40px">색</th><th style="width:18%">이름</th><th style="width:11%">slug</th>
      <th style="width:11%">상위</th><th>키워드</th><th style="width:14%">설명</th><th style="width:130px">사용</th>
      <th style="width:70px">사용여부</th><th style="width:${CAN_MANAGE?170:70}px">관리</th>
    </tr></thead><tbody>` +
    list.map(t => `<tr data-id="${t.id}"${t.active?'':' class="off"'}>
      <td><span class="handle" title="끌어서 순서 변경">⠿</span></td>
      <td><input type="color" class="swatch" data-id="${t.id}" value="${escA(t.color)}" title="${escA(t.color)}"></td>
      <td class="name"><span class="${t.parent_id!==null?'child':''}">${esc(t.name)}</span></td>
      <td class="mono">${esc(t.slug)}</td>
      <td class="small muted">${t.parent_id!==null ? esc(nameOf(t.parent_id)) : '—'}</td>
      <td>${(t.keywords||"").split(",").filter(Boolean).slice(0,8).map(k=>`<span class="kw">${esc(k)}</span>`).join("")}${(t.keywords||"").split(",").filter(Boolean).length>8?'<span class="kw">…</span>':''}</td>
      <td class="small muted">${esc(t.description||"")}</td>
      <td><div class="usage"><div class="bar"><i style="width:${Math.round(t.usage/maxUse*100)}%"></i></div><span class="n">${t.usage}</span></div></td>
      <td><button class="tgl${t.active?' on':''}" data-act="toggle" data-id="${t.id}">${t.active?'사용':'미사용'}</button></td>
      <td class="act">
        <button class="sm" data-act="edit" data-id="${t.id}">수정</button>
        ${CAN_MANAGE ? `<button class="sm" data-act="merge" data-id="${t.id}">병합</button><button class="sm danger" data-act="del" data-id="${t.id}">삭제</button>` : ''}
      </td>
    </tr>${mergeId===t.id ? mergeRowHtml(t) : ''}`).join("") + `</tbody></table>`;
  bind(box);
}
function mergeRowHtml(t){
  const opts = DATA.filter(x => x.id !== t.id).map(x => `<option value="${x.id}">${esc(x.name)} (${x.usage}건)</option>`).join("");
  return `<tr class="mergeRow" data-merge="${t.id}"><td colspan="10"><div class="inline-form">
      <b>'${esc(t.name)}'</b> (${t.usage}건) 을(를) 다음 태그로 병합 → <select id="mergeInto">${opts}</select>
      <button class="sm primary" data-act="mergeGo" data-id="${t.id}">병합 실행</button>
      <button class="sm" data-act="mergeCancel">취소</button>
      <span class="hint">요청의 태그 연결이 대상 태그로 옮겨지고(중복은 하나로) 이 태그는 삭제됩니다. 하위 태그는 대상 태그 아래로 갑니다.</span>
    </div></td></tr>`;
}

function bind(box){
  box.querySelectorAll("button[data-act]").forEach(b => b.addEventListener("click", () => {
    const id = +b.dataset.id, act = b.dataset.act;
    if(act==="edit") startEdit(id);
    else if(act==="toggle") toggle(id);
    else if(act==="merge"){ mergeId = id; render(); }
    else if(act==="mergeCancel"){ mergeId = null; render(); }
    else if(act==="mergeGo") merge(id, +$("mergeInto").value);
    else if(act==="del") del(id);
  }));
  box.querySelectorAll("input.swatch").forEach(i => i.addEventListener("change", async () => {
    try { await api(API, { action:"update", id:+i.dataset.id, color:i.value }); toast("색상 저장"); await load(); }
    catch(e){ toast("색상 저장 실패: " + e.message, true); }
  }));
  // ---- 드래그 순서 변경 (HTML5 DnD) — 행은 ⠿ 핸들을 누른 동안만 draggable (항상 draggable 이면 Firefox 에서 행 안 input 이 안 먹음)
  box.querySelectorAll("tr[data-id]").forEach(tr => {
    const handle = tr.querySelector(".handle");
    handle.addEventListener("mousedown", () => { tr.draggable = true; });
    tr.addEventListener("mouseup", () => { if(dragId === null) tr.draggable = false; });
    tr.addEventListener("dragstart", e => {
      dragId = +tr.dataset.id; tr.classList.add("dragging");
      e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", String(dragId));
    });
    tr.addEventListener("dragend", () => { dragId = null; tr.draggable = false; box.querySelectorAll("tr").forEach(r => r.classList.remove("dragging","drop-before","drop-after")); });
    tr.addEventListener("dragover", e => {
      if(dragId === null || +tr.dataset.id === dragId) return;
      e.preventDefault(); e.dataTransfer.dropEffect = "move";
      const r = tr.getBoundingClientRect(), before = (e.clientY - r.top) < r.height / 2;
      box.querySelectorAll("tr").forEach(x => x.classList.remove("drop-before","drop-after"));
      tr.classList.add(before ? "drop-before" : "drop-after");
    });
    tr.addEventListener("dragleave", () => tr.classList.remove("drop-before","drop-after"));
    tr.addEventListener("drop", e => {
      e.preventDefault();
      if(dragId === null || +tr.dataset.id === dragId) return;
      const r = tr.getBoundingClientRect(), before = (e.clientY - r.top) < r.height / 2;
      reorder(dragId, +tr.dataset.id, before);
    });
  });
}

async function reorder(fromId, targetId, before){
  const ids = DATA.map(t => t.id).filter(i => i !== fromId);
  let at = ids.indexOf(targetId); if(!before) at++;
  ids.splice(at, 0, fromId);
  // 화면 먼저 반영
  DATA.sort((a,b) => ids.indexOf(a.id) - ids.indexOf(b.id));
  render();
  try { await api(API, { action:"reorder", order: ids }); toast("순서 저장"); await load(); }
  catch(e){ toast("순서 저장 실패: " + e.message, true); await load(); }
}

function startEdit(id){
  const t = DATA.find(x => x.id === id); if(!t) return;
  editId = id; mergeId = null;
  buildParents();
  $("fcolor").value = t.color; $("fname").value = t.name; $("fslug").value = t.slug || "";
  $("fparent").value = t.parent_id === null ? "" : String(t.parent_id);
  $("fkw").value = t.keywords || ""; $("fdesc").value = t.description || "";
  $("fmode").textContent = "수정"; $("save").textContent = "저장"; $("cancel").style.display = "";
  $("fname").focus(); window.scrollTo({ top:0, behavior:"smooth" });
  render();
}
function resetForm(){
  editId = null;
  $("fcolor").value = "#9aa0a6"; $("fname").value = ""; $("fslug").value = ""; $("fparent").value = ""; $("fkw").value = ""; $("fdesc").value = "";
  $("fmode").textContent = "추가"; $("save").textContent = "추가"; $("cancel").style.display = "none";
  buildParents();
}
async function save(){
  const name = $("fname").value.trim();
  if(!name){ alert("태그 이름을 입력하세요."); $("fname").focus(); return; }
  const body = { action: editId ? "update" : "create", id: editId, name, slug: $("fslug").value.trim(), color: $("fcolor").value,
                 parent_id: $("fparent").value ? +$("fparent").value : null, keywords: $("fkw").value.trim(), description: $("fdesc").value.trim() };
  $("save").disabled = true;
  try { await api(API, body); toast(editId ? "저장됨" : "추가됨"); resetForm(); await load(); }
  catch(e){ alert("저장 실패: " + e.message); }
  finally { $("save").disabled = false; }
}
async function toggle(id){
  try { await api(API, { action:"toggle", id }); await load(); }
  catch(e){ toast("사용여부 변경 실패: " + e.message, true); }
}
async function merge(fromId, intoId){
  const f = DATA.find(x => x.id === fromId), t = DATA.find(x => x.id === intoId);
  if(!f || !t) return;
  if(!confirm(`'${f.name}' (${f.usage}건) → '${t.name}' 으로 병합합니다.\n'${f.name}' 태그는 삭제됩니다. 계속할까요?`)) return;
  try { const j = await api(API, { action:"merge", from_id:fromId, into_id:intoId }); mergeId = null; toast(`병합 완료 · ${j.moved}건 이동`); await load(); }
  catch(e){ alert("병합 실패: " + e.message); }
}
async function del(id){
  const t = DATA.find(x => x.id === id); if(!t) return;
  if(!confirm(`'${t.name}' 태그를 삭제할까요?\n사용 중인 요청: ${t.usage}건`)) return;
  try { await api(API, { action:"delete", id }); toast("삭제됨"); if(editId===id) resetForm(); await load(); }
  catch(e){
    if(e.code === "in_use"){
      const n = (e.extra && e.extra.usage) || t.usage;
      if(IS_ADMIN){
        if(confirm(`사용 중인 태그입니다 (${n}건).\n강제 삭제하면 해당 요청들의 태그 연결도 함께 삭제됩니다.\n\n다른 태그로 [병합]하는 것을 권장합니다. 그래도 강제 삭제할까요?`)){
          try { await api(API, { action:"delete", id, force:1 }); toast(`강제 삭제 · 연결 ${n}건 제거`); await load(); }
          catch(e2){ alert("삭제 실패: " + e2.message); }
        }
      } else {
        alert(`사용 중인 태그입니다 (${n}건). [병합]으로 다른 태그에 합치거나 관리자에게 강제 삭제를 요청하세요.`);
      }
    } else alert("삭제 실패: " + e.message);
  }
}
$("save").addEventListener("click", save);
$("cancel").addEventListener("click", resetForm);
$("search").addEventListener("input", render);
$("showOff").addEventListener("change", render);
["fname","fslug","fkw","fdesc"].forEach(id => $(id).addEventListener("keydown", e => { if(e.key==="Enter") save(); }));
load();
</script>
</body>
</html>
