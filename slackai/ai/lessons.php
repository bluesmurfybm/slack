<?php
/**
 * 학습 노트 화면 — ai_lessons 제안/승인/반려 탭, 종류·레포 필터, 카드. API: lessons_api.php
 *  승인·반려·편집: 승인자·관리자. 삭제: 관리자. 승인된 노트만 워커가 다음 플랜 프롬프트에 넣는다.
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
<title>학습 노트 · WorkHub AI</title>
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
    <h1>📚 학습 노트 <span class="badge" id="count"></span></h1>
    <div class="right">
      <?= admin_nav_html('lessons') ?>
      <span class="muted small"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님<?= $adm['is_admin'] ? ' · 관리자' : ($adm['is_approver'] ? ' · 승인자' : '') ?></span>
      <button id="themeBtn" type="button" class="tip" data-tip="다크/라이트 전환">🌓</button>
      <a class="back" href="../lists.php">← 목록으로</a>
    </div>
  </div>

  <div class="tabs" id="tabs"></div>
  <div class="tools">
    <div id="kindChips"></div>
    <select id="frepo" style="width:180px"><option value="">모든 레포</option></select>
    <select id="fscope" style="width:110px"><option value="">모든 범위</option><option value="repo">repo</option><option value="tag">tag</option><option value="global">global</option></select>
    <input id="fq" type="text" placeholder="제목 · 내용 · Rec 검색" style="width:220px">
    <span class="spacer"></span>
    <span class="hint">승인된 노트만 다음 플랜 프롬프트에 주입됩니다 (같은 레포·태그 우선, weight 순)</span>
    <button id="reload" type="button" class="sm">🔄</button>
  </div>

  <div id="cards" class="cards"><div class="loading">불러오는 중…</div></div>
</div>

<script src="../ai/admin.js"></script>
<script>
const { $, esc, escA, api, toast, fmtDt, md } = ADM;
const CAN_APPROVE = <?= $adm['is_approver'] ? 'true' : 'false' ?>;
const IS_ADMIN    = <?= $adm['is_admin'] ? 'true' : 'false' ?>;
const API = "lessons_api.php";
const TABS = [["proposed","제안"],["approved","승인"],["rejected","반려"],["all","전체"]];
const KIND_LABEL = { file_miss:"파일 누락", approach:"접근 방식", style:"코딩 스타일", test:"테스트", estimate:"예상 시간", tag_correction:"태그 교정", review_finding:"검토 지적", manual:"수동", other:"기타" };
let ftab = "proposed", fkind = "", RES = null, editing = null;

function params(){
  const p = new URLSearchParams({ status: ftab });
  if(fkind) p.set("kind", fkind);
  if($("frepo").value) p.set("repo_id", $("frepo").value);
  if($("fscope").value) p.set("scope", $("fscope").value);
  const q = $("fq").value.trim(); if(q) p.set("q", q);
  return p.toString();
}
async function load(){
  try { RES = await api(API + "?" + params()); }
  catch(e){ $("cards").innerHTML = `<div class="err">불러오기 실패: ${esc(e.message)}</div>`; return; }
  renderTabs(); renderKinds(); renderRepos(); renderCards();
}
function renderTabs(){
  $("tabs").innerHTML = TABS.map(([v,l]) => `<button type="button" class="tab${v===ftab?' on':''}" data-v="${v}">${l}<span class="n">${RES.counts[v] ?? 0}</span></button>`).join("");
  $("tabs").querySelectorAll(".tab").forEach(b => b.addEventListener("click", () => { ftab = b.dataset.v; load(); }));
}
function renderKinds(){
  const kinds = RES.kinds || [];
  $("kindChips").innerHTML = [["","모든 종류",null], ...kinds.map(k => [k.kind, KIND_LABEL[k.kind] || k.kind, k.count])]
    .map(([v,l,n]) => `<button type="button" class="chip${v===fkind?' on':''}" data-v="${v}">${esc(l)}${n!==null?`<span class="n">${n}</span>`:''}</button>`).join(" ");
  $("kindChips").querySelectorAll(".chip").forEach(b => b.addEventListener("click", () => { fkind = b.dataset.v; load(); }));
}
function renderRepos(){
  const sel = $("frepo"), cur = sel.value;
  sel.innerHTML = '<option value="">모든 레포</option>' + (RES.repos || []).map(r => `<option value="${r.id}">${esc(r.name)}</option>`).join("");
  sel.value = cur;
}
function scopeHtml(l){
  if(l.scope === "repo") return `<span class="scope">📁 ${esc(l.repo_name || ("repo #" + l.repo_id))}</span>`;
  if(l.scope === "tag")  return `<span class="scope">🏷️ ${esc(l.tag_name || ("tag #" + l.tag_id))}</span>`;
  return `<span class="scope">🌐 전체</span>`;
}
function renderCards(){
  const rows = RES.rows || [];
  $("count").textContent = rows.length + "건";
  const box = $("cards");
  if(!rows.length){ box.innerHTML = `<div class="empty" style="grid-column:1/-1">${ftab==="proposed" ? "검토할 제안이 없습니다." : "노트가 없습니다."}</div>`; return; }
  box.innerHTML = rows.map(l => {
    const w = Math.round(l.weight * 100);
    const isEdit = editing === l.id;
    return `<div class="card" data-id="${l.id}">
      <div class="chd">
        <span class="kind">${esc(KIND_LABEL[l.kind] || l.kind)}</span>${scopeHtml(l)}<span class="st st-${esc(l.status)}">${({proposed:"제안",approved:"승인",rejected:"반려"})[l.status] || esc(l.status)}</span>
        <span class="spacer"></span>
        <span class="wbar tip" data-tip="weight ${l.weight.toFixed(3)} — 높을수록 먼저 주입"><span class="bar"><i style="width:${w}%"></i></span>${l.weight.toFixed(2)}</span>
        <span class="tip" data-tip="근거(evidence) 건수 — 같은 교훈이 반복되면 +1">근거 ${l.evidence_count}</span>
        <span class="tip" data-tip="플랜 프롬프트에 주입된 횟수">사용 ${l.used_count}</span>
      </div>
      ${isEdit ? `<div class="edit">
          <input id="eTitle" type="text" maxlength="300" value="${escA(l.title)}" placeholder="제목">
          <textarea id="eLesson" rows="4" placeholder="지시형 1~2문장 (마크다운 일부 지원)">${esc(l.lesson_md || "")}</textarea>
          <div class="inline-form">weight <input id="eWeight" type="number" min="0" max="1" step="0.05" value="${l.weight}" style="width:90px">
            범위 <select id="eScope" style="width:90px"><option value="repo"${l.scope==='repo'?' selected':''}>repo</option><option value="tag"${l.scope==='tag'?' selected':''}>tag</option><option value="global"${l.scope==='global'?' selected':''}>global</option></select>
            <span class="spacer"></span><button class="sm" data-act="editCancel">취소</button><button class="sm primary" data-act="editSave" data-id="${l.id}">저장</button></div>
        </div>` : `
        <div class="ttl">${esc(l.title)}</div>
        <div class="md">${md(l.lesson_md)}</div>`}
      ${l.request_id ? `<div class="req">문의: <a href="../lists.php?id=${encodeURIComponent(l.request_id)}" title="${escA(l.request_id)}">${esc(l.request_title || l.request_id)}</a>${l.plan_id ? ` · plan #${l.plan_id}` : ''}${l.commit_id ? ` · commit #${l.commit_id}` : ''}</div>` : ''}
      ${l.evidence_md ? `<details><summary>근거 보기</summary><div class="md">${md(l.evidence_md)}</div></details>` : ''}
      <div class="cft">
        <span>#${l.id} · ${fmtDt(l.created_at, true)}${l.approved_by ? ` · ${l.status==="approved"?"승인":"결정"} ${esc(l.approved_by)} ${fmtDt(l.approved_at)}` : ''}</span>
        <span class="btns">
          ${CAN_APPROVE && l.status !== "approved" ? `<button class="sm primary" data-act="approve" data-id="${l.id}">✅ 승인</button>` : ''}
          ${CAN_APPROVE && l.status !== "rejected" ? `<button class="sm" data-act="reject" data-id="${l.id}">⛔ 반려</button>` : ''}
          ${CAN_APPROVE && !isEdit ? `<button class="sm" data-act="edit" data-id="${l.id}">✏️ 편집</button>` : ''}
          ${IS_ADMIN ? `<button class="sm danger" data-act="del" data-id="${l.id}">삭제</button>` : ''}
        </span>
      </div>
    </div>`;
  }).join("");
  box.querySelectorAll("button[data-act]").forEach(b => b.addEventListener("click", () => {
    const id = +b.dataset.id, act = b.dataset.act;
    if(act==="approve") decide(id, "approve"); else if(act==="reject") decide(id, "reject");
    else if(act==="edit"){ editing = id; renderCards(); } else if(act==="editCancel"){ editing = null; renderCards(); }
    else if(act==="editSave") saveEdit(id); else if(act==="del") del(id);
  }));
}
async function decide(id, action){
  const l = RES.rows.find(x => x.id === id); if(!l) return;
  if(action === "reject" && !confirm(`'${l.title}' 을(를) 반려할까요? 반려된 노트는 플랜에 쓰이지 않습니다.`)) return;
  try { await api(API, { action, id }); toast(action === "approve" ? "승인됨 — 다음 플랜부터 주입됩니다" : "반려됨"); await load(); }
  catch(e){ alert("실패: " + e.message); }
}
async function saveEdit(id){
  const body = { action:"update", id, title:$("eTitle").value.trim(), lesson_md:$("eLesson").value, weight:$("eWeight").value, scope:$("eScope").value };
  if(!body.title){ alert("제목을 입력하세요."); return; }
  try { await api(API, body); editing = null; toast("저장됨"); await load(); }
  catch(e){ alert("저장 실패: " + e.message); }
}
async function del(id){
  const l = RES.rows.find(x => x.id === id); if(!l) return;
  if(!confirm(`'${l.title}' 노트를 삭제할까요? (복구 불가)`)) return;
  try { await api(API, { action:"delete", id }); toast("삭제됨"); await load(); }
  catch(e){ alert("삭제 실패: " + e.message); }
}
["frepo","fscope"].forEach(id => $(id).addEventListener("change", load));
let qt = null; $("fq").addEventListener("input", () => { clearTimeout(qt); qt = setTimeout(load, 300); });
$("reload").addEventListener("click", load);
load();
</script>
</body>
</html>
