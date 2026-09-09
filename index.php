<?php
/**
 * 로그인 여부를 서버에서 먼저 판단해 화면을 그린다.
 *  - 클라이언트에서 /api/me.php 를 fetch 해서 뒤늦게 전환하면, 이미 로그인된 사용자에게도
 *    로그인 화면이 한 번 번쩍였다가 대시보드로 바뀌는 깜빡임이 생긴다. 그걸 없애기 위해
 *    PHP가 세션을 먼저 확인하고 처음부터 올바른 화면 상태로 HTML을 내려준다.
 */
require_once __DIR__ . '/auth.php';
// 로그인 상태에 따라 매번 다르게 그려지는 페이지라 브라우저/중간 캐시에 절대 남으면 안 됨.
// 캐시된 옛 버전이 남아 있으면 "저장 후 대시보드로 안 돌아간다"/"그리드가 가끔 안 보인다" 처럼
// 예전 코드가 실행되는 것처럼 보이는 현상이 생긴다.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$__u = current_portal_user();
$__current = $__u ? [
    'name'        => $__u['name'],
    'email'       => $__u['email'],
    'has_token'   => !empty($__u['slack_token_enc']),
    'needs_setup' => needs_setup($__u),
    'color'       => user_color($__u),
] : null;
$__cfg   = require __DIR__ . '/config.php';
$__links = ['book' => $__cfg['links']['book'], 'slack' => 'slack/lists.php', 'magazine' => $__cfg['links']['magazine'], 'learning' => $__cfg['links']['learning'], 'access' => 'access/access.php', 'moodle' => 'moodle/'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>blue-iWorks</title>
<link rel="icon" href="styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="styles/topbar.css">
<link rel="stylesheet" href="styles/default.css">
</head>
<body>

<!-- ================= LOGIN ================= -->
<section id="login" class="<?= $__current ? 'hidden' : '' ?>">
  <div class="login-x">X</div>
  <div class="login-card">
    <div class="logo"><b>blue</b><span class="dash">-</span>iWorks</div>
    <div class="login-tag">Bluesoft e<em>X</em>perience · 사내 업무 포털</div>
    <div class="fld">
      <label>이메일</label>
      <input id="lg-email" type="email" placeholder="name@bluesoft.co.kr" autocomplete="username">
    </div>
    <div class="fld">
      <label>비밀번호</label>
      <input id="lg-pw" type="password" placeholder="비밀번호" autocomplete="current-password"
        onkeydown="if(event.key==='Enter')doLogin()">
    </div>
    <div class="login-opts">
      <label><input id="lg-save-id" type="checkbox"> 아이디 저장</label>
      <label><input id="lg-remember" type="checkbox"> 자동 로그인</label>
    </div>
    <button class="btn-primary" onclick="doLogin()">로그인</button>
  </div>
</section>

<!-- ================= APP (dashboard + profile) ================= -->
<div id="app" class="<?= $__current ? '' : 'hidden' ?>">
  <div class="topbar">
    <div class="topbar-in">
      <div class="logo" style="cursor:pointer" onclick="showDash()"><b>blue</b><span class="dash">-</span>iWorks</div>
      <div class="top-right">
        <div class="user-menu" id="userMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" id="tb-avatar"></span>
            <span class="nm" id="tb-name"></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="userDd">
            <a href="javascript:void(0)" onclick="closeUserMenu();showProfile()">👤 마이페이지</a>
            <div class="dd-sep"></div>
            <div class="dd-label">업무 시스템</div>
            <a href="" id="dd-book" target="_blank" rel="noopener">📚 도서구매신청</a>
            <a href="" id="dd-slack" target="_blank" rel="noopener">📥 업무현황판</a>
            <a href="" id="dd-magazine" target="_blank" rel="noopener">📰 DTI 발표</a>
            <a href="" id="dd-learning" target="_blank" rel="noopener">🎓 BlueLearn</a>
            <a href="" id="dd-moodle" target="_blank" rel="noopener">🧭 무들 동향</a>
            <div class="dd-sep"></div>
            <a href="javascript:void(0)" onclick="closeUserMenu();logout()">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- dashboard -->
  <div id="view-dash" class="wrap">
    <div class="hero">
      <h1 id="hero-hi">환영합니다</h1>
      <p>사용할 업무 시스템을 선택하세요.</p>
    </div>
    <div class="grid" id="tiles"></div>
  </div>

  <!-- profile -->
  <div id="view-profile" class="wrap hidden">
    <div class="page-head">
      <h1>마이페이지</h1>
    </div>
    <div class="form-card">
      <div class="fld">
        <label>이름</label>
        <input id="pf-name" type="text" disabled>
      </div>
      <div class="fld">
        <label>이메일</label>
        <input id="pf-email" type="email" disabled>
      </div>
      <div class="fld">
        <label>고유 색상</label>
        <div class="color-row">
          <input id="pf-color" type="color" value="#1C5DE5" oninput="buildSwatches(this.value)" disabled>
          <div class="swatches" id="pf-swatches"></div>
        </div>
        <div class="hintline">아바타·뱃지에 표시되는 색상입니다.</div>
      </div>
      <div class="fld">
        <label>새 비밀번호</label>
        <div class="pw-wrap">
          <input id="pf-pw" type="password" placeholder="변경하려면 입력 (비우면 유지)" disabled>
          <button class="eye" onclick="toggleEye('pf-pw',this)" title="표시/숨김"></button>
        </div>
        <div class="hintline">초기 비밀번호는 blue$123 입니다.</div>
      </div>

      <div class="token-note">
        🔒 <b>슬랙 연동 토큰</b>은 민감정보입니다. 본인만 입력·수정하며, 저장 후에는 화면에 원문이 다시 표시되지 않습니다.
        토큰이 유출되면 즉시 재발급하세요.
      </div>
      <div class="fld">
        <label>슬랙 연동 토큰</label>
        <div class="pw-wrap">
          <input id="pf-token" type="password" placeholder="xoxp-... (본인 토큰 붙여넣기)" disabled>
          <button class="eye" onclick="toggleEye('pf-token',this)" title="표시/숨김"></button>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn-primary" id="pf-editbtn" style="width:auto;padding:11px 22px;margin:0" onclick="startEditProfile()">수정</button>
        <button class="btn-ghost hidden" id="pf-cancel" onclick="cancelEditProfile()">취소</button>
        <button class="btn-primary hidden" id="pf-save" style="width:auto;padding:11px 22px;margin:0" onclick="saveProfile()">저장</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ===== 타일 링크 ===== */
/* config.php 의 links 설정을 그대로 씀(서버가 단일 소스) */
const LINKS = <?= json_encode($__links, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

let current = <?= $__current ? json_encode($__current, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>; // {name, email, has_token, needs_setup}

function avatarColor(name){
  let h=0; for(const ch of (name||"?")) h=(h*31+ch.charCodeAt(0))%360;
  return `hsl(${h},58%,52%)`;
}
const esc=s=>(s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

/* ---- 아이디 저장: 저장된 이메일 있으면 로그인 폼에 미리 채워둠 ---- */
const SAVED_EMAIL_KEY="blueiwork_saved_email";
(function(){
  const saved=localStorage.getItem(SAVED_EMAIL_KEY);
  if(saved){
    document.getElementById("lg-email").value=saved;
    document.getElementById("lg-save-id").checked=true;
  }
})();

/* ---- login ---- */
async function doLogin(){
  const email=document.getElementById("lg-email").value.trim();
  const pw=document.getElementById("lg-pw").value;
  const saveId=document.getElementById("lg-save-id").checked;
  const remember=document.getElementById("lg-remember").checked;
  try{
    const r=await fetch("api/login.php",{method:"POST",headers:{"Content-Type":"application/json"},
      body:JSON.stringify({email,password:pw,remember})});
    if(!r.ok) throw 0;
    if(saveId) localStorage.setItem(SAVED_EMAIL_KEY,email);
    else localStorage.removeItem(SAVED_EMAIL_KEY);
    current=await r.json();
    document.getElementById("login").classList.add("hidden");
    document.getElementById("app").classList.remove("hidden");
    renderShell(); enterApp();
  }catch(e){ toast("이메일 또는 비밀번호가 올바르지 않습니다"); }
}

/* 로그인 직후/세션 복원 직후 공통 진입 로직 */
function enterApp(){
  // 초기 비밀번호를 안 바꿨으면 다른 건 다 무시하고 무조건 정보수정으로 — 대시보드/book/slack 전부 막힘
  if(current.needs_setup){
    showProfile("초기 비밀번호를 변경해야 계속 사용할 수 있습니다");
    return;
  }
  const params=new URLSearchParams(location.search);
  if(params.has("need_token")){
    history.replaceState(null,"",location.pathname);
    showProfile("슬랙 연동 토큰을 먼저 등록해야 업무현황판을 사용할 수 있습니다", true);
    return;
  }
  if(params.get("view")==="profile"){
    history.replaceState(null,"",location.pathname);
    showProfile();
    return;
  }
  showDash();
}

async function logout(){
  try{ await fetch("api/logout.php",{method:"POST"}); }catch(e){}
  current=null;
  document.getElementById("lg-pw").value="";
  document.getElementById("app").classList.add("hidden");
  document.getElementById("login").classList.remove("hidden");
}

function renderShell(){
  const first=current.name.slice(0,1);
  const av=document.getElementById("tb-avatar");
  av.textContent=first; av.style.background=current.color||avatarColor(current.name);
  document.getElementById("tb-name").textContent=current.name;
  document.getElementById("hero-hi").textContent=`${current.name}님, 환영합니다`;
  document.getElementById("dd-book").href=LINKS.book;
  document.getElementById("dd-slack").href=LINKS.slack;
  document.getElementById("dd-magazine").href=LINKS.magazine;
  document.getElementById("dd-learning").href=LINKS.learning;
  document.getElementById("dd-moodle").href=LINKS.moodle;
}

function toggleUserMenu(e){
  if(e) e.stopPropagation();
  document.getElementById("userMenu").classList.toggle("open");
}
function closeUserMenu(){
  document.getElementById("userMenu").classList.remove("open");
}
document.addEventListener("click",(e)=>{
  const um=document.getElementById("userMenu");
  if(um && !um.contains(e.target)) um.classList.remove("open");
});

/* ---- views ---- */
function showDash(){
  // 초기 비밀번호 미변경 상태면 대시보드로 못 나감 — 토큰 미등록만으로는 막지 않음(book은 접근 가능해야 함)
  if(current && current.needs_setup){
    showProfile("초기 비밀번호를 변경해야 계속 사용할 수 있습니다");
    return;
  }
  document.getElementById("view-profile").classList.add("hidden");
  document.getElementById("view-dash").classList.remove("hidden");
  renderTiles();
}
const SWATCH_COLORS=["#B6574A","#BA7D4D","#A58838","#818C46","#548058","#458278","#457797","#5A64AD","#8164AB","#9B5797","#B25D7E","#8C7055","#606D79"];
function hexOrDefault(c){ return /^#[0-9a-fA-F]{6}$/.test(c||"") ? c : "#1C5DE5"; }
let profileEditing=false;
function buildSwatches(selected){
  document.getElementById("pf-swatches").innerHTML=SWATCH_COLORS.map(c=>
    `<button type="button" class="swatch${c.toLowerCase()===selected.toLowerCase()?" on":""}" style="background:${c}" onclick="pickSwatch('${c}')" title="${c}"${profileEditing?"":" disabled"}></button>`
  ).join("");
}
function pickSwatch(c){
  if(!profileEditing) return;
  document.getElementById("pf-color").value=c;
  buildSwatches(c);
}

/* 마이페이지: 기본은 보기 전용, "수정" 눌러야 입력칸이 활성화된다.
   단, 초기 비번 미변경/토큰 요구 상황이면 볼 것도 없이 바로 수정 모드로 연다. */
function setProfileMode(editing){
  profileEditing=editing;
  ["pf-name","pf-email","pf-color","pf-pw","pf-token"].forEach(id=>{
    document.getElementById(id).disabled=!editing;
  });
  buildSwatches(document.getElementById("pf-color").value);
  document.getElementById("pf-editbtn").classList.toggle("hidden", editing);
  document.getElementById("pf-cancel").classList.toggle("hidden", !editing);
  document.getElementById("pf-save").classList.toggle("hidden", !editing);
  if(editing) document.getElementById("pf-cancel").disabled = !!(current && current.needs_setup);
}
function startEditProfile(){ setProfileMode(true); }
function cancelEditProfile(){
  document.getElementById("pf-name").value=current.name;
  document.getElementById("pf-email").value=current.email;
  document.getElementById("pf-pw").value="";
  document.getElementById("pf-color").value=hexOrDefault(current.color);
  document.getElementById("pf-token").value="";
  setProfileMode(false);
}

function showProfile(alertMsg, forceEdit){
  document.getElementById("view-dash").classList.add("hidden");
  document.getElementById("view-profile").classList.remove("hidden");
  document.getElementById("pf-name").value=current.name;
  document.getElementById("pf-email").value=current.email;
  document.getElementById("pf-pw").value="";
  document.getElementById("pf-color").value=hexOrDefault(current.color);
  const tokenEl=document.getElementById("pf-token");
  tokenEl.value="";
  tokenEl.placeholder = current.has_token ? "저장됨 · 변경하려면 새 토큰 입력" : "xoxp-... (본인 토큰 붙여넣기)";
  setProfileMode(!!(current && current.needs_setup) || !!forceEdit);
  if(alertMsg) toast(alertMsg);
}

const arrow=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M17 7H8M17 7v9"/></svg>`;
function renderTiles(){
  const bookIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>`;
  const slackIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
  const magazineIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9h4"/><path d="M18 14h-8M18 18h-8M18 6h-8v4h8V6Z"/></svg>`;
  const learningIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/><path d="M22 10v6"/></svg>`;
  const accessIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.1-8.1M17 6l2.5 2.5M14.5 8.5 17 11"/></svg>`;
  const moodleIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2.3 6.1-6.1 2.3 2.3-6.1z"/></svg>`;
  const plusIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>`;
  document.getElementById("tiles").innerHTML=`
    <a class="tile" href="${LINKS.book}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:#2E6BF0">${bookIcon}</span>
      <div><h3>도서구매신청</h3><p>읽고 싶은 책을 신청하고 처리 현황을 확인합니다.</p></div>
    </a>
    <a class="tile" href="${LINKS.slack}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:#1F9D76">${slackIcon}</span>
      <div><h3>업무현황판</h3><p>유지보수 요청 현황을 확인하고 관리합니다.</p></div>
    </a>
    <a class="tile" href="${LINKS.magazine}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:#7B5CF0">${magazineIcon}</span>
      <div><h3>DTI 발표</h3><p>매거진을 읽고 지식을 공유합니다.</p></div>
    </a>
    <a class="tile" href="${LINKS.learning}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:#F2711C">${learningIcon}</span>
      <div><h3>BlueLearn</h3><p>역량 강화를 위한 강의를 신청하고 수강료를 지원받습니다.</p></div>
    </a>
    <a class="tile" href="${LINKS.access}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:#0F7B8A">${accessIcon}</span>
      <div><h3>Coursemos EnvHub</h3><p>대학별 svn·git, 계정, DB, plink 정보를 찾아 복사합니다.</p></div>
    </a>
    <a class="tile" href="${LINKS.moodle}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:#D6336C">${moodleIcon}</span>
      <div><h3>무들 동향</h3><p>무들 PAG·트래커·릴리스 변화를 매주 모아 코스모스 관점으로 요약합니다.</p></div>
    </a>
    <div class="tile soon">
      <span class="badge-soon">준비중</span>
      <span class="ic">${plusIcon}</span>
      <div><h3>추가 예정</h3><p>새로운 사내 시스템이 이 자리에 추가됩니다.</p></div>
    </div>`;
}

/* ---- profile save ---- */
function toggleEye(id,btn){
  const el=document.getElementById(id);
  el.type = el.type==="password" ? "text" : "password";
}
async function saveProfile(){
  function v(id){ return document.getElementById(id).value.trim(); }
  const wasForced=!!(current && current.needs_setup);   // 강제 진입(초기 비번 미변경) 상태였는지
  const name=v("pf-name"), email=v("pf-email");
  if(!name){toast("이름을 입력하세요");return;}
  if(!email){toast("이메일을 입력하세요");return;}

  const body={ name, email, color:document.getElementById("pf-color").value };
  const pw=v("pf-pw"); if(pw) body.password=pw;
  const tk=v("pf-token"); if(tk) body.slack_token=tk; // 빈칸이면 미전송=기존 토큰 유지

  const r=await fetch("api/me.php",{method:"PUT",headers:{"Content-Type":"application/json"},
    body:JSON.stringify(body)});
  if(!r.ok){ toast("저장 실패"); return; }
  current=await r.json();
  renderShell();
  if(current.needs_setup){
    // 아직도 초기 비번 그대로 — 수정 모드 유지하고 계속 안내
    setProfileMode(true);
    toast("초기 비밀번호를 변경해야 계속 사용할 수 있습니다");
  }else if(wasForced){
    // 강제 진입 상태를 막 해소함 — 대시보드로 보내줌
    toast("회원정보가 저장되었습니다");
    showDash();
  }else{
    // 마이페이지에 자발적으로 들어와 수정한 경우 — 보기 모드로 돌아가 그대로 머무름
    setProfileMode(false);
    toast("회원정보가 저장되었습니다");
  }
}

let toastT;
function toast(m){const el=document.getElementById("toast");el.textContent=m;el.classList.add("show");
  clearTimeout(toastT);toastT=setTimeout(()=>el.classList.remove("show"),2200);}

/* eye icon inject */
document.querySelectorAll('.eye').forEach(b=>{
  b.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>`;
});

/* ---- 로그인 여부는 PHP가 이미 판단해서 화면/현재사용자(current)를 내려줬다 ----
   반드시 스크립트의 모든 선언(const/let/function) 다음, 맨 마지막에 실행해야 한다.
   위쪽에서 실행하면 아직 초기화 안 된 뒤쪽의 const/let(arrow, toastT 등)을
   먼저 참조하게 돼 TDZ ReferenceError로 스크립트 전체가 죽는다. */
if(current){ renderShell(); enterApp(); }
</script>
</body>
</html>
