<?php
$__bwBase = '../';   // slack/ 하위 폴더 페이지 — require_login()/header.php 리다이렉트 경로 계산용
require_once __DIR__ . '/../auth.php';
require_login();
$me = current_user();
session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>학교 사이트 관리</title>
<link rel="icon" href="../../styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/header.css">
<link rel="stylesheet" href="../styles/schools_admin.css">
</head>
<body>
<?php include __DIR__ . '/../header.php'; ?>
<div class="wrap">
  <div class="head">
    <h1>⚙ 학교 사이트 관리 <span class="badge" id="count"></span></h1>
    <div><span class="muted" style="font-size:12px;margin-right:12px"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님</span><a class="back" href="../lists.php">← 목록으로</a></div>
  </div>

  <div class="form">
    <span class="fmode" id="fmode">추가</span>
    <input class="fname" id="fname" type="text" placeholder="대학(기관)명 *">
    <input id="fver" type="text" list="verlist" placeholder="버전 예:4.5" style="width:120px">
    <datalist id="verlist"></datalist>
    <input class="fdev" id="fdev" type="text" placeholder="개발 URL">
    <input class="fops" id="fops" type="text" placeholder="운영 URL">
    <label class="fchk"><input type="checkbox" id="fact" checked> 사용</label>
    <button class="primary" id="save">추가</button>
    <button id="cancel" type="button" style="display:none">취소</button>
  </div>

  <div class="tools">
    <div id="vers"></div>
    <span class="spacer"></span>
    <input id="search" type="text" placeholder="대학명 검색…" style="width:200px;">
  </div>

  <div class="box"><div id="list"><div class="empty">불러오는 중…</div></div></div>
</div>

<script>
let DATA = [], fver = "all", editId = null;
const VER_BASE = ["3.5","3.9","4.5"];   // 추가 폼에서 항상 제안할 버전
const $ = id => document.getElementById(id);
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escA(s){ return esc(s).replace(/"/g,"&quot;"); }

async function load(){
  try{ DATA = (await (await fetch("schools.php?all=1",{cache:"no-store"})).json()).rows || []; }
  catch(e){ DATA = []; }
  buildVers();
  render();
}
function buildVers(){
  const uniq=[...new Set(DATA.map(s=>s.ver).filter(Boolean))].sort((a,b)=>parseFloat(a)-parseFloat(b));
  const vers=[["all","전체"], ...uniq.map(v=>[v,v])];
  $("vers").innerHTML = vers.map(([v,l])=>`<button type="button" class="chip${v===fver?' on':''}" data-v="${v}">${l}</button>`).join("");
  $("vers").querySelectorAll(".chip").forEach(b=>b.addEventListener("click",()=>{ fver=b.dataset.v; buildVers(); render(); }));
  // 폼 자동완성: 지정 버전(3.5/3.9/4.5)만 제안
  const dl=$("verlist"); if(dl) dl.innerHTML = VER_BASE.map(v=>`<option value="${v}">`).join("");
}
function render(){
  const q = $("search").value.trim().toLowerCase();
  const list = DATA.filter(s => (fver==="all"||s.ver===fver) && (!q||s.name.toLowerCase().includes(q)));
  $("count").textContent = list.length + "건";
  const box = $("list");
  if(!list.length){ box.innerHTML = '<div class="empty">데이터가 없습니다.</div>'; return; }
  box.innerHTML = `<table><thead><tr>
      <th style="width:22%">대학명</th><th style="width:7%">버전</th>
      <th style="width:25%">개발 URL</th><th style="width:25%">운영 URL</th>
      <th style="width:8%">사용</th><th style="width:13%">관리</th>
    </tr></thead><tbody>` +
    list.map(s=>`<tr${s.active?'':' class="off"'}>
      <td class="name">${esc(s.name)}</td>
      <td><span class="vtag">${esc(s.ver)}</span></td>
      <td>${s.dev?`<a class="link" href="${escA(s.dev)}" target="_blank" rel="noopener">${esc(s.dev)}</a>`:'<span class="muted">—</span>'}</td>
      <td>${s.ops?`<a class="link" href="${escA(s.ops)}" target="_blank" rel="noopener">${esc(s.ops)}</a>`:'<span class="muted">—</span>'}</td>
      <td><button class="tgl${s.active?' on':''}" data-id="${s.id}" data-a="${s.active?0:1}">${s.active?'사용':'미사용'}</button></td>
      <td class="act"><button class="edit" data-id="${s.id}">수정</button><button class="del" data-id="${s.id}">삭제</button></td>
    </tr>`).join("") + `</tbody></table>`;
  box.querySelectorAll(".edit").forEach(b=>b.addEventListener("click",()=>startEdit(+b.dataset.id)));
  box.querySelectorAll(".del").forEach(b=>b.addEventListener("click",()=>del(+b.dataset.id)));
  box.querySelectorAll(".tgl").forEach(b=>b.addEventListener("click",()=>toggle(+b.dataset.id,+b.dataset.a)));
}
function startEdit(id){
  const s = DATA.find(x=>x.id===id); if(!s) return;
  editId = id;
  $("fname").value=s.name; $("fver").value=s.ver||""; $("fdev").value=s.dev||""; $("fops").value=s.ops||""; $("fact").checked=!!s.active;
  $("fmode").textContent="수정"; $("save").textContent="저장"; $("cancel").style.display="";
  $("fname").focus(); window.scrollTo({top:0,behavior:"smooth"});
}
function resetForm(){
  editId=null;
  $("fname").value=""; $("fdev").value=""; $("fops").value=""; $("fact").checked=true;
  $("fmode").textContent="추가"; $("save").textContent="추가"; $("cancel").style.display="none";
}
async function save(){
  const name=$("fname").value.trim();
  if(!name){ alert("대학명을 입력하세요."); $("fname").focus(); return; }
  const body={ action: editId?"update":"create", id:editId, name, ver:$("fver").value.trim(), dev:$("fdev").value.trim(), ops:$("fops").value.trim(), active:$("fact").checked?1:0 };
  $("save").disabled=true;
  try{
    const j = await (await fetch("schools.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    resetForm(); await load();
  }catch(e){ alert("저장 실패: "+e.message); }
  finally{ $("save").disabled=false; }
}
async function del(id){
  const s=DATA.find(x=>x.id===id);
  if(!confirm(`'${s?s.name:id}' 삭제할까요?`)) return;
  try{
    const j = await (await fetch("schools.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"delete",id})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    if(editId===id) resetForm();
    await load();
  }catch(e){ alert("삭제 실패: "+e.message); }
}
async function toggle(id, to){
  try{
    const j = await (await fetch("schools.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"toggle",id,active:to})})).json();
    if(!j.ok) throw new Error(j.error||"실패");
    await load();
  }catch(e){ alert("사용여부 변경 실패: "+e.message); }
}
$("save").addEventListener("click", save);
$("cancel").addEventListener("click", resetForm);
$("search").addEventListener("input", render);
$("fname").addEventListener("keydown", e=>{ if(e.key==="Enter") save(); });
$("fops").addEventListener("keydown", e=>{ if(e.key==="Enter") save(); });
buildVers();
load();
</script>
</body>
</html>
