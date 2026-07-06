<?php
require_once __DIR__ . '/auth.php';
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
<style>
  :root { --bg:#fff; --bg2:#f6f7f8; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --hint:#8b949e; --info:#0c447c; --info-bg:#e6f1fb; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1a1d21; --bg2:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --hint:#6b7177; --info:#85b7eb; --info-bg:#0c2740; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg2); color:var(--txt); margin:0; padding:24px; }
  .wrap { max-width:1100px; margin:0 auto; }
  .head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px; }
  .head h1 { font-size:18px; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; }
  .badge { font-size:12px; color:var(--info); background:var(--info-bg); padding:2px 10px; border-radius:8px; }
  a.back { font-size:13px; color:var(--info); text-decoration:none; }
  input,button,select { font-family:inherit; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-size:13px; height:34px; padding:0 10px; }
  button { cursor:pointer; }
  button.primary { background:var(--info); color:#fff; border-color:var(--info); }
  /* 입력 폼 */
  .form { display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:var(--bg); border:1px solid var(--line);
          border-radius:12px; padding:12px 14px; margin-bottom:14px; }
  .form .fname { flex:1; min-width:150px; }
  .form .fdev, .form .fops { flex:2; min-width:200px; }
  .form .fmode { font-size:13px; color:var(--muted); font-weight:600; margin-right:2px; }
  .form .fchk { display:flex; align-items:center; gap:5px; font-size:13px; color:var(--muted); white-space:nowrap; }
  .form .fchk input { width:16px; height:16px; }
  /* 필터 */
  .tools { display:flex; gap:8px; align-items:center; margin-bottom:10px; flex-wrap:wrap; }
  .chip { height:30px; padding:0 12px; border:1px solid var(--line); border-radius:16px; background:var(--bg); color:var(--muted); font-size:13px; cursor:pointer; }
  .chip.on { background:var(--info-bg); border-color:var(--info); color:var(--info); font-weight:600; }
  .spacer { flex:1; }
  /* 테이블 */
  .box { background:var(--bg); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th,td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:middle; }
  th { background:var(--bg2); color:var(--muted); font-weight:600; font-size:12px; position:sticky; top:0; }
  tr:last-child td { border-bottom:0; }
  td.name { font-weight:500; }
  .vtag { font-size:11px; color:var(--info); background:var(--info-bg); border-radius:8px; padding:1px 7px; }
  td a.link { color:var(--info); text-decoration:none; word-break:break-all; }
  td a.link:hover { text-decoration:underline; }
  .muted { color:var(--hint); }
  td.act { white-space:nowrap; text-align:right; }
  td.act button { height:28px; padding:0 12px; font-size:12px; margin-left:6px; vertical-align:middle; }
  td.act button:first-child { margin-left:0; }
  td.act .del:hover { border-color:#e24b4a; color:#e24b4a; }
  .tgl { height:28px; padding:0 14px; font-size:12px; border-radius:14px; background:var(--bg2); color:var(--muted);
         display:inline-flex; align-items:center; justify-content:center; line-height:1; vertical-align:middle;
         white-space:nowrap; min-width:60px; }
  .tgl.on { background:#e4f3e7; border-color:#bfe3c6; color:#1b5e20; font-weight:600; }
  tr.off { opacity:.55; }
  tr.off td.name { text-decoration:line-through; }
  .empty { padding:28px; text-align:center; color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>⚙ 학교 사이트 관리 <span class="badge" id="count"></span></h1>
    <div><span class="muted" style="font-size:12px;margin-right:12px"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님</span><a class="back" href="lists.php">← 목록으로</a></div>
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
