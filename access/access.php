<?php
/**
 * Coursemos EnvHub (access 모듈) — 목록 + 상세 + 편집 한 화면.
 *  - 마스터는 slack 모듈의 schools 테이블, 접속·배포 상세는 school_access. (access/db.php 참고)
 *  - 원본 엑셀: SVN_배포_디비정보(블루내부공유).xlsx
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';
access_require_login();   // 포털 로그인만 확인(표시할 사용자 정보는 공통 상단바가 그린다)
access_session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 공통 상단바(slack/header.php)는 "포털 루트까지 되짚는 접두사"를 $__bwBase 로 받는다.
// access/ 에서 그 파일을 그대로 쓰려면 파일 위치(slack/) 기준으로 잡아줘야 링크가 안 깨진다.
$__bwBase = '../slack/';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coursemos EnvHub</title>
<link rel="icon" href="../styles/favicon.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../slack/styles/header.css">
<link rel="stylesheet" href="../slack/styles/common.css">
<link rel="stylesheet" href="styles/access.css">
<!-- Editor.js: 설명성 칸의 서식(굵기·색·취소선·목록) 편집용. 버전을 못 박아 둔다.
     색상은 외부 플러그인을 쓰지 않는다 — 아래 ColorTool 주석 참고. -->
<script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@2.30.7/dist/editorjs.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/list@1.10.0/dist/list.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@sotaproject/strikethrough@1.0.1/dist/bundle.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/../slack/header.php'; ?>

<div class="wrap">
  <div class="head">
    <h1>🔑 Coursemos EnvHub <span class="badge" id="count"></span></h1>
  </div>

  <!-- 이 화면에서 제일 많이 쓰는 동작이라 검색을 맨 위 눈에 띄는 자리에 둔다 -->
  <div class="hero">
    <div class="hero-in">
      <svg class="hero-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input id="search" type="text" autocomplete="off"
             placeholder="학교명을 입력하세요 — 주소 · 계정 · DB 정보도 함께 찾습니다">
      <button id="searchClear" class="hero-clear" type="button" title="지우기" hidden>✕</button>
    </div>
    <div id="vers" class="hero-chips"></div>
  </div>

  <div class="tools">
    <span class="spacer"></span>
    <select id="fvpn" title="VPN 프로그램으로 거르기"></select>
    <label class="fchk"><input type="checkbox" id="onlyMissing"> 접속정보 없는 곳만</label>
    <label class="fchk"><input type="checkbox" id="showOff"> 미사용 포함</label>
    <button id="btnNew" class="primary" type="button">+ 대학 추가</button>
    <button id="btnImport" type="button">엑셀 가져오기</button>
    <div class="viewtog" id="viewtog">
      <button type="button" data-view="list" title="리스트 보기" aria-label="리스트 보기">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
      <button type="button" data-view="card" title="카드 보기" aria-label="카드 보기">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/>
          <rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg></button>
    </div>
  </div>

  <div class="box"><div id="list"><div class="empty">불러오는 중…</div></div></div>
  <p class="foot">· 대학명·버전·개발/운영 URL은
     <a href="../slack/schools/schools_admin.php">학교 사이트 관리</a>와 같은 데이터를 씁니다.</p>
</div>

<!-- 엑셀 업로드 -->
<div class="modal" id="mImport" hidden>
  <div class="modal-in">
    <h2>엑셀 가져오기</h2>
    <p class="mdesc">SVN_배포_디비정보 엑셀을 올리면 5개 시트(3.5 이하 / 3.9 / 3.9-saas / 4.5 / 그 외)를
       읽어 접속 정보를 채웁니다. 엑셀에 있는데 학교 목록에 없는 곳은 새로 등록되고,
       이미 있는 학교의 이름·URL은 덮어쓰지 않습니다.</p>
    <input type="file" id="fXlsx" accept=".xlsx">
    <label class="fchk"><input type="checkbox" id="fForce"> 기존 접속정보 덮어쓰기</label>
    <div class="modal-act">
      <button type="button" data-close>취소</button>
      <button type="button" class="primary" id="doImport">가져오기</button>
    </div>
  </div>
</div>

<!-- 편집 -->
<div class="modal" id="mEdit" hidden>
  <div class="modal-in wide">
    <h2 id="eTitle">접속 정보 수정</h2>
    <p class="mdesc" id="eHint"></p>
    <div class="egrid" id="eGrid"></div>
    <div class="modal-act">
      <button type="button" data-close>취소</button>
      <button type="button" class="primary" id="doSave">저장</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" hidden></div>

<script>
/* 상세/편집 화면 구성(공통 / 테스트 서버 / 운영 서버)은 PHP 에서 내려받아 한 곳에서만 관리한다 */
const GROUPS = <?= json_encode(access_field_groups(), JSON_UNESCAPED_UNICODE) ?>;
const ALLF = GROUPS.flatMap(g => g.fields);
/* 서식(Editor.js)으로 편집하는 칸. 나머지는 예전처럼 평문이다. */
const RICH = new Set(<?= json_encode(access_rich_cols(), JSON_UNESCAPED_UNICODE) ?>);
/* 편집 화면에서 가려 놓을 칸(비밀번호). 눈 아이콘으로 잠깐 열어 본다. */
const PWCOLS = new Set(<?= json_encode(access_password_cols(), JSON_UNESCAPED_UNICODE) ?>);
/* input 은 줄바꿈을 못 담는다. 화면에는 한 줄로 펴서 보여 주고, 손대지 않은 칸은
   저장할 때 원본으로 되돌린다(save 참고) — 안 건드린 값이 조용히 잘리면 안 되니까. */
const flatPw = s => (s || "").replace(/\s*\n+\s*/g, " ");
/* 필터는 버전(schools.ver) 기준. 한 학교에 접속정보가 두 벌인 경우가 있어서
   행 식별자는 school_id 가 아니라 접속정보 id 다(아래 rowKey 참고). */

/* 한 학교가 시트별로 여러 행을 가질 수 있어서 행 식별자는 school_id 가 아니다.
   접속정보가 있으면 그 id, 아직 없으면 학교 id 로 구분한다. */
const rowKey = r => (r.access_id ? "a" + r.access_id : "s" + r.school_id);

let DATA = [], fver = "all", fvpn = "all", openKey = "";
/* 리스트/카드 보기. 취향 문제라 이 브라우저에만 남긴다(읽기 실패해도 리스트로 동작). */
let view = "list";
try { if (localStorage.getItem("access_view") === "card") view = "card"; } catch (e) {}
/* 편집·추가가 같은 모달을 쓴다. formRow 가 기존 행이면 수정, school_id 가 0 이면 신규. */
let formRow = null;
const $ = id => document.getElementById(id);
const esc  = s => (s ?? "").toString().replace(/[&<>]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;"}[c]));
const escA = s => esc(s).replace(/"/g, "&quot;");

/* ── 클립보드 ────────────────────────────────────────────────
   개발 URL이 http:// 라 포털도 http 로 열어 두는 경우가 많다. 그때는 보안 컨텍스트가
   아니어서 navigator.clipboard 가 아예 없으므로 execCommand 폴백이 반드시 필요하다. */
async function copyText(txt, btn) {
  if (!txt) return;
  let ok = false;
  if (navigator.clipboard && window.isSecureContext) {
    try { await navigator.clipboard.writeText(txt); ok = true; } catch (e) { ok = false; }
  }
  if (!ok) {
    const ta = document.createElement("textarea");
    ta.value = txt;
    ta.setAttribute("readonly", "");
    ta.style.cssText = "position:fixed;top:0;left:0;width:1px;height:1px;opacity:0";
    document.body.appendChild(ta);
    ta.select(); ta.setSelectionRange(0, ta.value.length);
    try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
    document.body.removeChild(ta);
  }
  if (btn) {
    const old = btn.textContent;
    btn.classList.add(ok ? "done" : "fail");
    btn.textContent = ok ? "완료" : "실패";
    setTimeout(() => { btn.classList.remove("done", "fail"); btn.textContent = old; }, 900);
  }
  toast(ok ? "복사했습니다" : "복사하지 못했습니다. 직접 선택해 주세요.");
}
let toastT = null;
function toast(msg) {
  const t = $("toast");
  t.textContent = msg; t.hidden = false;
  clearTimeout(toastT);
  toastT = setTimeout(() => { t.hidden = true; }, 1800);
}

/* ── Editor.js 값 ────────────────────────────────────────────
   저장값은 Editor.js 의 블록 JSON 이다. 다만 엑셀에서 가져온 273행은 전부 평문이라
   "JSON 으로 안 읽히면 평문" 으로 보고 양쪽을 다 받는다. 서식이 필요 없던 칸을
   그대로 두는 것도 같은 이유 — 마이그레이션 없이 섞여 있어도 동작한다. */
function ejParse(v) {
  v = (v || "").trim();
  if (v.charAt(0) !== "{") return null;
  try {
    const d = JSON.parse(v);
    return Array.isArray(d.blocks) ? d : null;
  } catch (e) { return null; }
}

/* 태그를 걷어낸 순수 텍스트 — 복사 버튼과 검색이 쓴다 */
function stripTags(html) {
  const d = document.createElement("div");
  d.innerHTML = html || "";
  return (d.textContent || "").replace(/\u00a0/g, " ");
}

/* 목록 항목은 문자열이거나 {content, items} 중첩 구조다(@editorjs/list 1.x) */
function ejItemText(it) {
  if (typeof it === "string") return stripTags(it);
  const own = stripTags((it && it.content) || "");
  const sub = ((it && it.items) || []).map(ejItemText).filter(Boolean);
  return sub.length ? own + "\n" + sub.join("\n") : own;
}

function ejToText(v) {
  const d = ejParse(v);
  if (!d) return v || "";
  const out = [];
  for (const b of d.blocks) {
    const t = b && b.type, dat = (b && b.data) || {};
    if (t === "list") (dat.items || []).forEach(it => { const s = ejItemText(it); if (s) out.push(s); });
    else out.push(stripTags(dat.text || ""));
  }
  return out.join("\n").replace(/\n{3,}/g, "\n\n").trim();
}

/* 화면에 그릴 때 쓰는 새니타이저. 서식 태그만 남기고 속성은 색상 style 만 통과시킨다 —
   저장값이 결국 남이 쓴 HTML 이므로 그대로 innerHTML 에 넣지 않는다. */
/* FONT 가 들어 있는 이유: 글자 색을 <font style="color:…"> 로 감싸기 때문이다(아래 ColorTool).
   빼 두면 저장은 되는데 화면에 그릴 때 태그가 벗겨져 색이 사라진다. MARK 는 형광펜용. */
const EJ_TAGS = { B: 1, STRONG: 1, I: 1, EM: 1, U: 1, S: 1, STRIKE: 1, MARK: 1, SPAN: 1, FONT: 1, BR: 1, UL: 1, OL: 1, LI: 1 };
// 이 태그들은 껍데기만 벗기면 안에 있던 코드가 글자로 남는다 — 통째로 버린다
const EJ_DROP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, LINK: 1, META: 1, TEMPLATE: 1, NOSCRIPT: 1 };
function ejSanitize(html) {
  const root = document.createElement("div");
  root.innerHTML = html || "";
  (function walk(node) {
    Array.prototype.slice.call(node.children).forEach(el => {
      if (EJ_DROP[el.tagName]) { el.remove(); return; }
      walk(el);
      if (!EJ_TAGS[el.tagName]) { el.replaceWith.apply(el, el.childNodes); return; }
      Array.prototype.slice.call(el.attributes).forEach(a => {
        if (a.name === "style") {
          const keep = (a.value.match(/(?:^|;)\s*(?:color|background-color)\s*:\s*[^;]+/gi) || [])
            .map(x => x.replace(/^;/, "").trim()).join(";");
          if (keep) el.setAttribute("style", keep); else el.removeAttribute("style");
        } else if (a.name === "class") {
          if (!/^[\w\- ]+$/.test(a.value)) el.removeAttribute("class");
        } else {
          el.removeAttribute(a.name);
        }
      });
    });
  })(root);
  return root.innerHTML;
}

function ejListHtml(items) {
  return (items || []).map(it => {
    if (typeof it === "string") return "<li>" + ejSanitize(it) + "</li>";
    const own = ejSanitize((it && it.content) || "");
    const sub = (it && it.items && it.items.length) ? "<ul>" + ejListHtml(it.items) + "</ul>" : "";
    return "<li>" + own + sub + "</li>";
  }).join("");
}

function ejToHtml(v) {
  const d = ejParse(v);
  if (!d) return esc(v || "").replace(/\n/g, "<br>");   // 평문은 줄바꿈만 살려서
  return d.blocks.map(b => {
    const t = b && b.type, dat = (b && b.data) || {};
    if (t === "list") {
      const tag = dat.style === "ordered" ? "ol" : "ul";
      return "<" + tag + ">" + ejListHtml(dat.items) + "</" + tag + ">";
    }
    const inner = ejSanitize(dat.text || "");
    return inner ? "<p>" + inner + "</p>" : "";
  }).join("");
}

/* 어떤 칸이든 "복사·검색에 쓸 평문" 으로 바꿔 준다 */
const val = (r, k) => (RICH.has(k) ? ejToText(r[k]) : (r[k] || ""));

/* ── 원문에서 "바로 쓸 한 줄"만 뽑기 ──────────────────────────
   엑셀 칸에는 주소 밑에 "변경!", "사용 불가:", 설명이 같이 적혀 있는 경우가 많다. 목록의
   복사 버튼은 실제로 붙여 넣어 쓸 부분만 주고, 원문 전체는 상세 패널의 복사 버튼으로 준다. */
function pickLine(raw, pats, opt) {
  opt = opt || {};
  const lines = (raw || "").split("\n").map(s => s.trim()).filter(Boolean);
  for (const pat of pats) {                         // 앞선 패턴일수록 우선
    for (const l of lines) {
      if (opt.skip && opt.skip.test(l)) continue;   // "사용 불가:" 처럼 못 쓴다고 적힌 줄은 건너뜀
      const m = l.match(pat);
      if (m) return (m[1] || m[0]).trim();
    }
  }
  return opt.fallback ? (lines[0] || "") : "";
}
/* svn:// → git clone … → .git URL → ubgit 경로 순. "git : https://…" 처럼 앞에 라벨이
   붙은 형태가 흔해서 줄 시작(^)으로 묶지 않는다.
   못 찾으면 첫 줄이라도 준다 — 주소 대신 "전주대 것과 동일" 같이 적힌 칸이 있어서. */
const repoCmd = raw => pickLine(raw, [
  /(svn:\/\/\S+)/i,
  /(git\s+clone\s+\S+)/i,
  /((?:ssh|https?):\/\/\S*\.git\b)/i,
  /((?:ssh|https?):\/\/\S*\/ubgit\/\S+)/i,
], { skip: /사용\s*불가/, fallback: true });
/* plink 칸에는 명령 없이 DB 접속 정보만 적힌 경우도 많다. 그때는 빈 값을 돌려 목록의
   plink 복사 버튼을 아예 안 띄운다(엉뚱한 줄을 명령인 척 복사시키지 않기 위함). */
const plinkCmd = raw => pickLine(raw, [
  /((?:[A-Za-z]:\\)?plink(?:\.exe)?\s+-ssh\s.*)/i,
  /(-ssh\s+-l\s.*)/i,
]);
/* 저장소 종류(svn/git)와 "바로 붙여 넣을 수 있는" 명령을 만든다.
   엑셀엔 주소만 적힌 경우가 많아서(예: "git : https://…/ubgit/pnulxp.git") git 이면
   'git clone ' 을 붙여 준다. svn 은 클라이언트에 주소를 그대로 넣으므로 손대지 않는다. */
function repoInfo(raw) {
  const url = repoCmd(raw);
  if (!url) return { kind: "", addr: "", cmd: "" };
  if (/^svn:\/\//i.test(url))     return { kind: "svn", addr: url, cmd: url };
  if (/^git\s+clone\b/i.test(url)) return { kind: "git", addr: url.replace(/^git\s+clone\s+/i, ""), cmd: url };
  // 주소만 있는 git — .git 으로 끝나거나 ubgit 경로거나, 칸에 "git" 이라고 적혀 있으면
  if (/\.git\b|\/ubgit\//i.test(url) || /(^|[\s(])git\s*[:：]/i.test(raw || "")) {
    return { kind: "git", addr: url, cmd: "git clone " + url };
  }
  return { kind: "", addr: url, cmd: url };
}

/* 터널링 명령은 전용 칸이 아니라 DB 설명 안에 섞여 있다('학사 DB' 52건 · '운영 DB' 17건).
   그래서 아래 칸들을 훑어서 찾은 만큼 버튼을 만든다. plink 전용 칸은 값이 1건뿐이라
   비고로 합쳤으므로 비고도 함께 본다. */
const PLINK_SRC = [
  ["dev_db",   "plink·개발"],
  ["ops_db",   "plink·운영"],
  ["haksa_db", "plink·학사"],
  ["note",     "plink·비고"],
];

/* ── 로딩/렌더 ─────────────────────────────────────────────── */
async function load() {
  const all = $("showOff").checked ? "?all=1" : "";
  try {
    const j = await (await fetch("access_api.php" + all, { cache: "no-store" })).json();
    DATA = j.rows || [];
  } catch (e) { DATA = []; }
  buildVers();
  buildVpnFilter();
  render();
}

function buildVers() {
  const n = {};
  for (const r of DATA) n[r.ver || ""] = (n[r.ver || ""] || 0) + 1;
  const vers = Object.keys(n).sort((a, b) => (parseFloat(a) || 99) - (parseFloat(b) || 99));
  const list = [["all", "전체", DATA.length], ...vers.map(v => [v, v || "미분류", n[v]])];
  $("vers").innerHTML = list.map(([v, label, c]) =>
    `<button type="button" class="chip${v === fver ? " on" : ""}" data-v="${escA(v)}">${esc(label)}<b>${c}</b></button>`).join("");
  $("vers").querySelectorAll(".chip").forEach(b =>
    b.addEventListener("click", () => { fver = b.dataset.v; buildVers(); render(); }));
}

/* VPN 필터. 'FortiClient, Arcon' 처럼 두 개를 같이 쓰는 곳이 있어서 값 전체가 아니라
   쉼표로 나눈 프로그램 하나하나를 후보로 만들고, 고를 때도 포함 여부로 본다. */
function buildVpnFilter() {
  const n = {};
  let none = 0;
  for (const r of DATA) {
    const ps = vpnList(r);
    if (!ps.length) { none++; continue; }
    for (const p of ps) n[p] = (n[p] || 0) + 1;
  }
  const opts = [
    ["all",  `VPN 전체 (${DATA.length})`],
    ["none", `VPN 불필요 (${none})`],
    ...Object.keys(n).sort().map(p => ["p:" + p, `${p} (${n[p]})`]),
  ];
  $("fvpn").innerHTML = opts.map(([v, l]) =>
    `<option value="${escA(v)}"${v === fvpn ? " selected" : ""}>${esc(l)}</option>`).join("");
}
const vpnList = r => (r.vpn || "").split(",").map(t => t.trim()).filter(Boolean);
function vpnMatch(r) {
  if (fvpn === "all")  return true;
  const ps = vpnList(r);
  if (fvpn === "none") return ps.length === 0;
  return ps.includes(fvpn.slice(2));
}

function matches(r, q) {
  if (!q) return true;
  return [r.name, r.ver, r.vpn, r.dev, r.ops, r.repo,
          r.login_ops_id, r.login_ops, r.login_dev_id, r.login_dev,
          val(r, "note"), val(r, "dev_db"), val(r, "ops_db"), val(r, "haksa_db")]
    .some(v => (v || "").toLowerCase().includes(q));
}

function render() {
  const q = $("search").value.trim().toLowerCase();
  const onlyMissing = $("onlyMissing").checked;
  const list = DATA.filter(r =>
    (fver === "all" || (r.ver || "") === fver) &&
    vpnMatch(r) &&
    (!onlyMissing || !r.access_id) &&
    matches(r, q));

  $("count").textContent = list.length + "건";
  const box = $("list");
  if (!list.length) { box.innerHTML = '<div class="empty">데이터가 없습니다.</div>'; return; }

  box.innerHTML = view === "card"
    ? `<div class="cards">` +
        list.map(r => cardHtml(r) +
          (openKey === rowKey(r) ? `<div class="dwide">${detailBody(r)}</div>` : "")).join("") +
      `</div>`
    : `<table><thead><tr>
        <th class="c-name">대학(기관)명</th>
        <th class="c-ver">버전</th>
        <th class="c-vpn">VPN</th>
        <th class="c-repo">svn / git</th>
        <th class="c-site">사이트</th>
        <th class="c-copy">빠른 복사</th>
        <th class="c-act"></th>
      </tr></thead><tbody>` +
      list.map(r => rowHtml(r) +
        (openKey === rowKey(r) ? `<tr class="d"><td colspan="7">${detailBody(r)}</td></tr>` : "")).join("") +
      `</tbody></table>`;
  bind(box);
}

/* 한 행이 쓰는 조각들 — 표와 카드가 같은 값을 쓰도록 한 곳에서 만든다 */
function rowParts(r) {
  const repo = repoInfo(r.repo);
  const site = [
    r.dev ? `<a class="link" href="${escA(r.dev)}" target="_blank" rel="noopener">개발 ↗</a>` : "",
    r.ops ? `<a class="link" href="${escA(r.ops)}" target="_blank" rel="noopener">운영 ↗</a>` : "",
  ].filter(Boolean).join("");

  // 사이트 주소는 복사 대상이 아니다 — '사이트' 칸의 링크를 누르면 되기 때문
  // 운영·테스트 계정이 같으면(대부분) 칩 하나로 묶고, 다르면 각 버튼 앞에 따로 붙인다
  const oneAcct = r.login_ops_id && r.login_ops_id === r.login_dev_id;
  const quick = [
    oneAcct ? acctChip(r.login_ops_id) : "",
    r.login_ops ? (oneAcct ? "" : acctChip(r.login_ops_id)) + cpBtn(r.login_ops, "운영 로그인") : "",
    r.login_dev ? (oneAcct ? "" : acctChip(r.login_dev_id)) + cpBtn(r.login_dev, "테스트 로그인") : "",
    ...PLINK_SRC.map(([k, label]) => { const c = plinkCmd(val(r, k)); return c ? cpBtn(c, label) : ""; }),
  ].filter(Boolean).join("");

  // 저장소는 종류 칩과 복사 버튼이면 충분하다 — 주소 자체는 상세에서 본다
  const repoCell = repo.addr
    ? `<span class="repo-l">` +
      `${repo.kind ? `<span class="rtag ${repo.kind}">${repo.kind}</span>` : ""}` +
      cpBtn(repo.cmd, repo.kind === "git" ? "git clone 복사" : "주소 복사") +
      `</span>`
    : "";

  return { site, quick, repoCell, repoTitle: r.repo || "" };
}

function cardHtml(r) {
  const key = rowKey(r);
  const p = rowParts(r);
  return `<div class="card${r.active ? "" : " off"}${openKey === key ? " open" : ""}" data-key="${escA(key)}">
    <div class="card-top">
      ${r.ver ? `<span class="vtag">${esc(r.ver)}</span>` : ""}
      ${r.vpn ? `<span class="ptag" title="${escA(r.vpn_note || r.vpn)}">${esc(r.vpn)}</span>` : ""}
      <span class="card-sp"></span>
      ${p.repoCell ? `<span class="card-repo" title="${escA(p.repoTitle)}">${p.repoCell}</span>` : ""}
    </div>
    <div class="card-nm">${esc(r.name)}</div>
    ${r.access_id ? "" : '<div class="sub warn">접속정보 없음</div>'}
    <div class="card-site">${p.site || '<span class="muted">사이트 주소 없음</span>'}</div>
    <div class="card-cp">${p.quick || '<span class="muted">복사할 계정 정보 없음</span>'}</div>
    <div class="card-foot"><button class="more" type="button">${openKey === key ? "접기" : "상세"}</button></div>
  </div>`;
}

function rowHtml(r) {
  const key = rowKey(r);
  const p = rowParts(r);
  const dash = '<span class="muted">—</span>';
  return `<tr class="r${r.active ? "" : " off"}${openKey === key ? " open" : ""}" data-key="${escA(key)}">
    <td class="c-name">
      <span class="nm">${esc(r.name)}</span>
      ${r.access_id ? "" : '<span class="sub warn">접속정보 없음</span>'}
    </td>
    <td class="c-ver">${r.ver ? `<span class="vtag">${esc(r.ver)}</span>` : ""}</td>
    <td class="c-vpn">${r.vpn ? `<span class="ptag" title="${escA(r.vpn_note || r.vpn)}">${esc(r.vpn)}</span>` : dash}</td>
    <td class="c-repo" title="${escA(p.repoTitle)}">${p.repoCell || dash}</td>
    <td class="c-site">${p.site || dash}</td>
    <td class="c-copy"><div class="cpwrap">${p.quick || dash}</div></td>
    <td class="c-act"><button class="more" type="button">${openKey === key ? "접기" : "상세"}</button></td>
  </tr>`;
}

/* 계정 칩 — 사이트 대부분이 csmsathena 아니면 admin 이라, 그 둘과 '그 외 고유 계정'을
   색으로 갈라 두면 목록에서 훑기만 해도 어느 계정으로 붙어야 하는지 바로 보인다. */
function acctChip(id) {
  if (!id) return "";
  const k = /^csmsathena$/i.test(id) ? "csms" : (/^admin$/i.test(id) ? "admin" : "other");
  return `<span class="atag ${k}" title="계정 ${escA(id)}">${esc(id)}</span>`;
}

/* 복사 버튼 — 값은 data-cp 에 담고, 클릭은 위임으로 한 번만 잡는다 */
function cpBtn(val, label) {
  return `<button class="cp" type="button" data-cp="${escA(val)}">${esc(label)}</button>`;
}

function detailBody(r) {
  const meta = r.opened ? `최초 오픈 ${esc(r.opened)}` : "";

  // 공통 / 테스트 서버 / 운영 서버 — 값이 하나도 없는 묶음은 통째로 생략한다
  const sections = GROUPS.map(g => {
    const cells = g.fields.map(f => groupCell(r, f)).filter(Boolean);
    if (!cells.length) return "";
    return `<div class="dsec">${esc(g.title)}</div><div class="dgrid">${cells.join("")}</div>`;
  }).filter(Boolean).join("");

  return `
    <div class="detail-head">
      <span class="dmeta">${meta || "&nbsp;"}</span>
      <span>
        <button class="edit" type="button" data-key="${escA(rowKey(r))}">수정</button>
        ${r.access_id ? `<button class="del" type="button" data-key="${escA(rowKey(r))}">접속정보 삭제</button>` : ""}
      </span>
    </div>
    ${sections || '<div class="empty">등록된 접속 정보가 없습니다. [수정]에서 채워 넣으세요.</div>'}`;
}

/* 묶음 안의 칸 하나 — 값이 비어 있으면 아예 안 그린다 */
function groupCell(r, f) {
  const v = r[f.key];
  if (!v) return "";
  if (f.key === "opened") return "";          // 위 meta 줄에 이미 나온다
  if (f.type === "url") {
    return fieldCell(f.label, v, "", f.copy, `<a class="link" href="${escA(v)}" target="_blank" rel="noopener">열기 ↗</a>`);
  }
  if (f.key === "vpn") {                       // 프로그램명과 접속 방법을 한 칸에 묶는다
    return fieldCell(f.label, v, "", 0, "", null, r.vpn_note);
  }
  if (f.key === "vpn_note") return "";         // 위에서 같이 그렸다

  const plain = val(r, f.key);
  // DB 설명 안에 섞여 있는 터널링 명령은 따로 뽑아 준다 — 통째로 복사하면 붙여 쓸 수 없다
  let extra = "";
  const plk = plinkCmd(plain);
  if (plk) extra = cpBtn(plk, "plink만");
  if (f.key === "repo") {
    const ri = repoInfo(v);
    // 원문에 설명이 섞여 있어도 붙여 넣을 한 줄은 따로 준다
    if (ri.cmd && ri.cmd !== v) extra = cpBtn(ri.cmd, ri.kind === "git" ? "git clone" : "주소만");
  }
  return fieldCell(f.label, v, "", f.copy, extra, f.key);
}

/* 비밀번호는 줄 수만 유지한 채 길이를 드러내지 않게 고정 길이 점으로 가린다 */
const pwMask = v => (v || "").split("\n").map(() => "\u2022".repeat(8)).join("\n");

/* key 가 서식 칸이면 값은 블록 JSON 이라 HTML 로 그리고, 복사 버튼에는 평문을 담는다.
   비밀번호 칸은 가린 채 그리되 복사 버튼은 원문을 그대로 준다 — 눈으로 볼 일보다
   붙여 넣을 일이 많아서 굳이 열지 않아도 쓸 수 있어야 한다. */
function fieldCell(label, value, note, copyable, extraBtns, key, richNote) {
  const rich = key && RICH.has(key);
  const isPw = key && PWCOLS.has(key);
  const plain = rich ? ejToText(value) : (value || "");
  const body = [
    value ? (rich  ? `<div class="val rich">${ejToHtml(value)}</div>`
           : isPw  ? `<pre class="val pw" data-pw="${escA(value)}">${esc(pwMask(value))}</pre>`
                   : `<pre class="val">${esc(value)}</pre>`) : "",
    note     ? `<pre class="val note">${esc(note)}</pre>` : "",
    richNote ? `<div class="val note rich">${ejToHtml(richNote)}</div>` : "",
  ].join("");
  const eye = (isPw && value)
    ? `<button class="cp deye" type="button" title="표시/숨김">보기</button>` : "";
  return `<div class="dcell">
    <div class="dlabel"><span>${esc(label)}</span>
      <span class="dbtns">${extraBtns || ""}${eye}${copyable && plain ? cpBtn(plain, "복사") : ""}</span></div>
    ${body}
  </div>`;
}

function bind(box) {
  box.querySelectorAll(".cp").forEach(b =>
    b.addEventListener("click", e => { e.stopPropagation(); copyText(b.dataset.cp, b); }));
  box.querySelectorAll("tr.r, .card").forEach(el =>
    el.addEventListener("click", () => {
      openKey = (openKey === el.dataset.key) ? "" : el.dataset.key;
      render();
    }));
  box.querySelectorAll(".deye").forEach(b =>
    b.addEventListener("click", e => {
      e.stopPropagation();
      const pre = b.closest(".dcell").querySelector("pre.val.pw");
      if (!pre) return;
      const shown = pre.classList.toggle("shown");
      pre.textContent = shown ? pre.dataset.pw : pwMask(pre.dataset.pw);
      b.textContent = shown ? "숨기기" : "보기";
    }));
  box.querySelectorAll(".edit").forEach(b =>
    b.addEventListener("click", e => { e.stopPropagation(); openEdit(b.dataset.key); }));
  box.querySelectorAll(".del").forEach(b =>
    b.addEventListener("click", e => { e.stopPropagation(); delAccess(b.dataset.key); }));
  box.querySelectorAll("tr.d, .dwide").forEach(el => el.addEventListener("click", e => e.stopPropagation()));
}

/* ── 편집 ──────────────────────────────────────────────────── */
const MASTER = [
  ["name", "대학(기관)명 *", "input"],
  ["ver",  "버전",          "input"],
  ["dev",  "개발 URL",      "input"],
  ["ops",  "운영 URL",      "input"],
  ["log",  "로그 관리 URL",  "input"],
];
function openEdit(key) {
  const r = DATA.find(x => rowKey(x) === key);
  if (r) openForm(r);
}

/* 추가 — 빈 행을 만들어 같은 폼을 띄운다. 저장하면 schools 에도 같이 들어간다. */
function openCreate() {
  const blank = { school_id: 0, access_id: 0, name: "", ver: "", dev: "", ops: "", log: "" };
  for (const f of ALLF) blank[f.key] = "";
  openForm(blank);
}

function openForm(r) {
  formRow = r;
  const isNew = !r.school_id;
  $("eTitle").textContent = isNew ? "대학 추가" : r.name + " — 접속 정보 수정";
  $("eHint").textContent = isNew
    ? "저장하면 학교 사이트 관리(schools)에도 같이 등록됩니다."
    : "";
  $("doSave").textContent = isNew ? "추가" : "저장";
  $("eGrid").innerHTML = [
    `<div class="esec">학교 정보 <span class="ehint">(학교 사이트 관리와 공유되는 값)</span></div>`,
    ...MASTER.map(([k, l, t]) => cellHtml(k, l, t, r[k])),
    // url 타입은 위 '학교 정보'(schools)에서 이미 고치므로 폼에서는 건너뛴다
    ...GROUPS.map(g => `<div class="esec">${esc(g.title)}</div>` +
      g.fields.filter(f => f.type !== "url")
              .map(f => cellHtml(f.key, f.label, f.type, r[f.key])).join("")),
  ].join("");
  openModal("mEdit");
  bindEyes();
  mountEditors(r);
  const first = $("eGrid").querySelector("input[data-k=name]");
  if (first) first.focus();
}

function cellHtml(key, label, type, value) {
  // 서식 칸은 <label> 로 감싸지 않는다 — 라벨 클릭이 에디터 포커스를 가로챈다
  if (type === "rich" || RICH.has(key)) {
    return `<div class="ecell full">
      <span>${esc(label)}</span>
      <div class="ejholder" data-ej="${escA(key)}"></div>
    </div>`;
  }
  const v = esc(value || "");
  if (type === "pw" || PWCOLS.has(key)) {
    return `<label class="ecell">
      <span>${esc(label)}</span>
      <div class="pwbox">
        <input data-k="${escA(key)}" type="password" value="${escA(flatPw(value))}" autocomplete="off">
        <button type="button" class="eye" data-eye="${escA(key)}" title="표시/숨김">${EYE_OFF}</button>
      </div>
    </label>`;
  }
  return `<label class="ecell${type === "area" ? " full" : ""}">
    <span>${esc(label)}</span>
    ${type === "area" ? `<textarea data-k="${escA(key)}" rows="3">${v}</textarea>`
                      : `<input data-k="${escA(key)}" type="text" value="${escA(value || "")}">`}
  </label>`;
}

const EYE_ON = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>`;
const EYE_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7c1.6 0 3 .4 4.3 1M22 12s-3.6 7-10 7c-1.6 0-3-.4-4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M3 3l18 18"/></svg>`;

/* 눈 아이콘 — 그 칸만 잠깐 연다. 모달을 닫으면 다시 가려진 상태로 돌아간다. */
function bindEyes() {
  $("eGrid").querySelectorAll(".eye[data-eye]").forEach(b => {
    b.addEventListener("click", e => {
      e.preventDefault();
      const el = $("eGrid").querySelector(`input[data-k="${b.dataset.eye}"]`);
      if (!el) return;
      const shown = el.type === "password";
      el.type = shown ? "text" : "password";
      b.innerHTML = shown ? EYE_ON : EYE_OFF;
      b.title = shown ? "숨기기" : "표시";
    });
  });
}

/* ── 글자 색 도구 ────────────────────────────────────────────
   editorjs-text-color-plugin 은 자기 팝업을 shadow DOM 으로 띄우는데, Editor.js 2.30 의
   인라인 툴바가 팝오버로 바뀌면서 그 안의 클릭을 팝오버가 먼저 가로챈다. 그래서 색이
   아예 안 찍힌다. 2.30 이 지원하는 MenuConfig(children + onActivate)로 직접 만들었다.
   저장 마크업은 그 플러그인과 같은 <font style="color:…"> 이라 기존 값과 호환된다. */
const EJ_COLORS = [
  ["빨강", "#E24A4A"], ["주황", "#E8890C"], ["초록", "#1B9E4B"],
  ["파랑", "#0C6FD1"], ["보라", "#7A3AA8"], ["먹색", "#1F2328"], ["회색", "#8B949E"],
];

/* 팝오버 항목을 누르는 사이에 선택 영역이 풀리는 경우가 있어, 에디터 안에서 잡힌
   마지막 범위를 따로 들고 있다가 색을 입힐 때 쓴다. */
let ejRange = null;
document.addEventListener("selectionchange", () => {
  const sel = window.getSelection();
  if (!sel || !sel.rangeCount || sel.isCollapsed) return;
  const n = sel.getRangeAt(0).commonAncestorContainer;
  const el = n.nodeType === 1 ? n : n.parentElement;
  if (el && el.closest && el.closest(".ejholder")) ejRange = sel.getRangeAt(0).cloneRange();
});

const ICON_COLOR = `<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16 12 4l6 12"/><path d="M8.5 12h7"/><path d="M4 20h16"/></svg>`;

class ColorTool {
  static get isInline() { return true; }
  static get title() { return "글자 색"; }
  // Editor.js 가 저장할 때 남길 태그. 플러그인과 같은 규칙(style 유지).
  static get sanitize() { return { font: { style: true } }; }

  constructor({ api }) { this.api = api; }

  render() {
    return {
      icon: ICON_COLOR,
      title: "글자 색",
      children: {
        searchable: false,
        // 자식 팝오버가 열리면 글자 선택이 풀린다. Editor.js 내장 'Convert to' 도 같은
        // 이유로 열 때 저장하고 닫을 때 되돌린다 — 그 방식을 그대로 따른다.
        onOpen: () => { try { this.api.selection.save(); } catch (e) {} },
        items: [
          ...EJ_COLORS.map(([name, hex]) => ({
            icon: `<span class="ejswatch" style="background:${hex}"></span>`,
            title: name,
            closeOnActivate: true,
            onActivate: () => this.apply(hex),
          })),
          { icon: "✕", title: "색 지우기", closeOnActivate: true, onActivate: () => this.clear() },
        ],
      },
    };
  }

  /* 메뉴를 여는 사이 풀린 선택을 되돌린다. Editor.js 가 저장해 둔 범위를 먼저 쓰고,
     그게 안 되면 우리가 들고 있던 마지막 범위로 되살린다. */
  restoreSelection() {
    try { this.api.selection.restore(); } catch (e) {}
    const sel = window.getSelection();
    if (sel && sel.rangeCount && !sel.isCollapsed) return sel.getRangeAt(0);
    if (ejRange) {
      sel.removeAllRanges();
      sel.addRange(ejRange);
      return ejRange;
    }
    return null;
  }

  /* MenuConfig 로 동작하므로 surround 는 쓰이지 않지만, 인라인 툴 규약상 있어야 한다 */
  surround() {}
  checkState() { return !!this.api.selection.findParentTag("FONT"); }

  apply(hex) {
    const range = this.restoreSelection();
    if (!range || range.collapsed) return;
    const cur = this.api.selection.findParentTag("FONT");
    if (cur) { cur.style.color = hex; this.dropSelection(cur); return; }   // 색만 교체
    const font = document.createElement("font");
    font.style.color = hex;
    try {
      font.appendChild(range.extractContents());
      range.insertNode(font);
    } catch (e) { return; }                       // 여러 블록에 걸친 선택 등
    this.dropSelection(font);
  }

  clear() {
    this.restoreSelection();
    const font = this.api.selection.findParentTag("FONT");
    if (!font || !font.parentNode) return;
    const parent = font.parentNode;
    const last = font.lastChild;
    while (font.firstChild) parent.insertBefore(font.firstChild, font);
    parent.removeChild(font);
    parent.normalize();
    this.dropSelection(last);
  }

  /* 작업이 끝나면 선택을 풀고 커서만 그 뒤에 둔다.
     선택을 남겨 두면 인라인 툴바가 계속 떠 있고, 저장해 둔 범위(onOpen 의 save)가 다시
     복원되면서 다른 곳을 선택할 수 없게 된다. 저장 범위도 커서 위치로 덮어써 둔다. */
  dropSelection(node) {
    ejRange = null;
    const sel = window.getSelection();
    if (!sel) return;
    try {
      const r = document.createRange();
      if (node && node.parentNode) r.setStartAfter(node); else r.setStart(sel.anchorNode, sel.anchorOffset);
      r.collapse(true);
      sel.removeAllRanges();
      sel.addRange(r);
    } catch (e) {
      sel.removeAllRanges();
    }
    try { this.api.selection.save(); } catch (e) {}
  }
}

/* ── Editor.js 인스턴스 관리 ──────────────────────────────── */
let EDITORS = {};

/* 평문 → Editor.js 블록. 엑셀에서 온 값은 전부 평문이라 첫 편집 때 이 경로를 탄다. */
function textToDoc(t) {
  const lines = (t || "").split("\n").map(l => l.trim());
  const blocks = lines.filter(Boolean).map(l => ({ type: "paragraph", data: { text: esc(l) } }));
  return { blocks };
}

function mountEditors(r) {
  if (typeof EditorJS === "undefined") return;   // CDN 을 못 불러온 환경
  const tools = {
    list: { class: window.List, inlineToolbar: true },
    Color: { class: ColorTool },
    strikethrough: { class: window.Strikethrough },
  };
  $("eGrid").querySelectorAll(".ejholder").forEach(el => {
    const k = el.dataset.ej;
    EDITORS[k] = new EditorJS({
      holder: el,
      minHeight: 24,
      placeholder: "",
      data: ejParse(r[k]) || textToDoc(r[k]),
      tools,
      inlineToolbar: ["bold", "Color", "strikethrough"],
    });
  });
}

function destroyEditors() {
  Object.values(EDITORS).forEach(i => { try { i.destroy(); } catch (e) {} });
  EDITORS = {};
}

/* 각 에디터의 현재 내용을 저장용 문자열로. 빈 내용은 "" 로 둬서 '값 없음' 판정이 유지된다. */
async function collectEditors(body) {
  for (const k of Object.keys(EDITORS)) {
    let out = null;
    try { out = await EDITORS[k].save(); } catch (e) { out = null; }
    const blocks = (out && out.blocks) ? out.blocks.filter(b => {
      const d = b.data || {};
      return (d.text && stripTags(d.text).trim()) || (d.items && d.items.length);
    }) : [];
    body[k] = blocks.length ? JSON.stringify({ blocks }) : "";
  }
}

async function save() {
  if (!formRow) return;
  const isNew = !formRow.school_id;
  const body = isNew
    ? { action: "create" }
    : { action: "save", school_id: formRow.school_id, access_id: formRow.access_id };
  $("eGrid").querySelectorAll("[data-k]").forEach(el => { body[el.dataset.k] = el.value; });
  // 비밀번호 칸은 원본이 여러 줄일 수 있는데 input 에는 한 줄로 펴서 담았다.
  // 사용자가 그대로 뒀으면(펴 놓은 값과 같으면) 원본을 되돌려 나머지 줄을 잃지 않는다.
  PWCOLS.forEach(k => {
    if (body[k] === flatPw(formRow[k])) body[k] = formRow[k] || "";
  });
  if (!(body.name || "").trim()) { toast("대학(기관)명을 입력하세요."); return; }
  $("doSave").disabled = true;
  await collectEditors(body);
  try {
    const j = await (await fetch("access_api.php", {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body),
    })).json();
    if (!j.ok) throw new Error(j.error || "실패");
    closeModal();
    if (isNew) {
      // 추가한 행을 바로 펼쳐서 무엇이 들어갔는지 확인할 수 있게 한다
      openKey = "a" + j.access_id;
      fver = "all"; fvpn = "all";
      $("search").value = "";
    }
    await load();
    toast(isNew ? "추가했습니다" : "저장했습니다");
  } catch (e) { toast((isNew ? "추가" : "저장") + " 실패: " + e.message); }
  finally { $("doSave").disabled = false; }
}

async function delAccess(key) {
  const r = DATA.find(x => rowKey(x) === key);
  if (!r || !r.access_id) return;
  if (!confirm(`'${r.name}' 의 접속 정보를 지울까요?\n(학교 자체는 학교 사이트 관리에 그대로 남습니다)`)) return;
  try {
    const j = await (await fetch("access_api.php", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete", access_id: r.access_id }),
    })).json();
    if (!j.ok) throw new Error(j.error || "실패");
    await load();
    toast("삭제했습니다");
  } catch (e) { toast("삭제 실패: " + e.message); }
}

/* ── 엑셀 가져오기 ─────────────────────────────────────────── */
async function doImport() {
  const f = $("fXlsx").files[0];
  if (!f) { toast("엑셀 파일을 선택하세요."); return; }
  const fd = new FormData();
  fd.append("xlsx", f);
  if ($("fForce").checked) fd.append("force", "1");
  $("doImport").disabled = true;
  try {
    const j = await (await fetch("access_import.php", { method: "POST", body: fd })).json();
    if (!j.ok) throw new Error(j.error || "실패");
    closeModal();
    await load();
    toast(`${j.total}건 가져왔습니다` + (j.new_schools ? ` (학교 ${j.new_schools}개 신규)` : ""));
  } catch (e) { toast("가져오기 실패: " + e.message); }
  finally { $("doImport").disabled = false; }
}

/* ── 모달 ──────────────────────────────────────────────────── */
function openModal(id) { $(id).hidden = false; }
function closeModal() {
  destroyEditors();
  document.querySelectorAll(".modal").forEach(m => m.hidden = true);
}
document.querySelectorAll(".modal").forEach(m => {
  m.addEventListener("click", e => { if (e.target === m || e.target.hasAttribute("data-close")) closeModal(); });
});
document.addEventListener("keydown", e => { if (e.key === "Escape") closeModal(); });

function setView(v) {
  view = v;
  $("viewtog").querySelectorAll("button").forEach(b => b.classList.toggle("on", b.dataset.view === v));
  try { localStorage.setItem("access_view", v); } catch (e) {}
  render();
}
$("viewtog").querySelectorAll("button").forEach(b => {
  b.addEventListener("click", () => setView(b.dataset.view));
  b.classList.toggle("on", b.dataset.view === view);
});

$("btnNew").addEventListener("click", openCreate);
$("btnImport").addEventListener("click", () => openModal("mImport"));
$("doImport").addEventListener("click", doImport);
$("doSave").addEventListener("click", save);
$("search").addEventListener("input", () => {
  $("searchClear").hidden = !$("search").value;
  render();
});
$("searchClear").addEventListener("click", () => {
  $("search").value = "";
  $("searchClear").hidden = true;
  render();
  $("search").focus();
});
// 들어오자마자 바로 학교명을 칠 수 있게
$("search").focus();
$("fvpn").addEventListener("change", () => { fvpn = $("fvpn").value; render(); });
$("onlyMissing").addEventListener("change", render);
$("showOff").addEventListener("change", load);

load();
</script>
</body>
</html>
