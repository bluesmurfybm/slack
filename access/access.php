<?php
/**
 * 학교 접속 정보 (access 모듈) — 목록 + 상세 + 편집 한 화면.
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
<title>학교 접속 정보</title>
<link rel="icon" href="../styles/favicon.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../slack/styles/header.css">
<link rel="stylesheet" href="../slack/styles/common.css">
<link rel="stylesheet" href="styles/access.css">
</head>
<body>
<?php include __DIR__ . '/../slack/header.php'; ?>

<div class="wrap">
  <div class="head">
    <h1>🔑 학교 접속 정보 <span class="badge" id="count"></span></h1>
  </div>

  <div class="tools">
    <div id="vers"></div>
    <span class="spacer"></span>
    <select id="fvpn" title="VPN 프로그램으로 거르기"></select>
    <label class="fchk"><input type="checkbox" id="onlyMissing"> 접속정보 없는 곳만</label>
    <label class="fchk"><input type="checkbox" id="showOff"> 미사용 포함</label>
    <input id="search" type="text" placeholder="대학명 · 주소 · 계정 검색…">
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
/* 상세/편집에 쓸 필드 정의는 PHP(access_fields)에서 내려받아 한 곳에서만 관리한다 */
const FIELDS = <?= json_encode(access_fields(), JSON_UNESCAPED_UNICODE) ?>;
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

/* 정작 plink 전용 칸은 거의 비어 있다 — 3.5 시트에는 그 칸 자체가 없고, 터널링 명령이
   '학사 DB'(52건) · '운영 DB'(17건) 설명 안에 같이 적혀 있다. 그래서 한 칸만 보지 않고
   아래 칸들을 훑어서 찾은 만큼 버튼을 만든다. */
const PLINK_SRC = [
  ["plink",    "plink"],
  ["dev_db",   "plink·개발"],
  ["ops_db",   "plink·운영"],
  ["haksa_db", "plink·학사"],
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
  return [r.name, r.ver, r.vpn, r.dev, r.ops, r.repo, r.plink,
          r.login_ops_id, r.login_ops, r.login_dev_id, r.login_dev, r.login_info,
          r.dev_db, r.ops_db, r.haksa_db]
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
    // 운영/테스트로 못 가른 원문만 남은 행은 그대로 한 덩어리로 복사시킨다
    (!r.login_ops && !r.login_dev && r.login_info) ? cpBtn(r.login_info, "로그인") : "",
    ...PLINK_SRC.map(([k, label]) => { const c = plinkCmd(r[k]); return c ? cpBtn(c, label) : ""; }),
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
  const cells = [];
  // VPN 은 프로그램명(vpn)과 접속 방법(vpn_note)이 짝이라 한 칸에 묶어 맨 앞에 둔다 —
  // 이게 안 켜져 있으면 아래 주소·계정이 전부 무용지물이라 제일 먼저 보여야 한다.
  if (r.vpn || r.vpn_note) cells.push(fieldCell("VPN", r.vpn, r.vpn_note, 0));
  if (r.dev || r.dev_note) cells.push(fieldCell("개발 URL", r.dev, r.dev_note, 1));
  if (r.ops || r.ops_note) cells.push(fieldCell("운영 URL", r.ops, r.ops_note, 1));
  if (r.log)               cells.push(fieldCell("로그 관리", r.log, "", 1));
  for (const f of FIELDS) {
    const v = r[f.key];
    const acct = f.acct ? (r[f.acct] || "") : "";
    if (!v && !acct) continue;
    // DB 설명 안에 섞여 있는 터널링 명령은 따로 뽑아 준다 — 통째로 복사하면 붙여 쓸 수 없다
    const plk = (f.key !== "plink") ? plinkCmd(v) : "";
    let extra = plk ? cpBtn(plk, "plink만") : "";
    if (f.key === "repo") {
      const ri = repoInfo(v);
      // 원문에 설명이 섞여 있어도 붙여 넣을 한 줄은 따로 준다
      if (ri.cmd && ri.cmd !== v) extra = cpBtn(ri.cmd, ri.kind === "git" ? "git clone" : "주소만");
    }
    // 로그인 칸은 계정을 라벨 옆에 적고 값(=비밀번호)만 복사시킨다
    cells.push(fieldCell(f.label + (acct ? ` · ${acct}` : ""), v, "", f.copy, extra));
  }
  const meta = r.opened ? `최초 오픈 ${esc(r.opened)}` : "";

  return `
    <div class="detail-head">
      <span class="dmeta">${meta || "&nbsp;"}</span>
      <span>
        <button class="edit" type="button" data-key="${escA(rowKey(r))}">수정</button>
        ${r.access_id ? `<button class="del" type="button" data-key="${escA(rowKey(r))}">접속정보 삭제</button>` : ""}
      </span>
    </div>
    ${cells.length ? `<div class="dgrid">${cells.join("")}</div>`
                   : '<div class="empty">등록된 접속 정보가 없습니다. [수정]에서 채워 넣으세요.</div>'}`;
}

function fieldCell(label, val, note, copyable, extraBtns) {
  const body = [
    val  ? `<pre class="val">${esc(val)}</pre>` : "",
    note ? `<pre class="val note">${esc(note)}</pre>` : "",
  ].join("");
  return `<div class="dcell">
    <div class="dlabel"><span>${esc(label)}</span>
      <span class="dbtns">${extraBtns || ""}${copyable && val ? cpBtn(val, "복사") : ""}</span></div>
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
const DETAIL_EXTRA = [
  ["opened",       "최초 오픈",     "input"],
  ["vpn",          "VPN 프로그램 (비우면 불필요)", "input"],
  ["vpn_note",     "VPN 접속 방법", "area"],
  ["login_ops_id", "운영 계정 ID",   "input"],
  ["login_dev_id", "테스트 계정 ID", "input"],
  ["dev_note",     "개발 URL 메모", "area"],
  ["ops_note",     "운영 URL 메모", "area"],
];

function openEdit(key) {
  const r = DATA.find(x => rowKey(x) === key);
  if (r) openForm(r);
}

/* 추가 — 빈 행을 만들어 같은 폼을 띄운다. 저장하면 schools 에도 같이 들어간다. */
function openCreate() {
  const blank = { school_id: 0, access_id: 0, name: "", ver: "", dev: "", ops: "", log: "" };
  for (const f of FIELDS) blank[f.key] = "";
  for (const [k] of DETAIL_EXTRA) blank[k] = "";
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
    `<div class="esec">접속 · 배포 정보</div>`,
    ...DETAIL_EXTRA.map(([k, l, t]) => cellHtml(k, l, t, r[k])),
    ...FIELDS.map(f => cellHtml(f.key, f.label, "area", r[f.key])),
  ].join("");
  openModal("mEdit");
  const first = $("eGrid").querySelector("input[data-k=name]");
  if (first) first.focus();
}

function cellHtml(key, label, type, val) {
  const v = esc(val || "");
  return `<label class="ecell${type === "area" ? " full" : ""}">
    <span>${esc(label)}</span>
    ${type === "area" ? `<textarea data-k="${escA(key)}" rows="3">${v}</textarea>`
                      : `<input data-k="${escA(key)}" type="text" value="${escA(val || "")}">`}
  </label>`;
}

async function save() {
  if (!formRow) return;
  const isNew = !formRow.school_id;
  const body = isNew
    ? { action: "create" }
    : { action: "save", school_id: formRow.school_id, access_id: formRow.access_id };
  $("eGrid").querySelectorAll("[data-k]").forEach(el => { body[el.dataset.k] = el.value; });
  if (!(body.name || "").trim()) { toast("대학(기관)명을 입력하세요."); return; }
  $("doSave").disabled = true;
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
function closeModal() { document.querySelectorAll(".modal").forEach(m => m.hidden = true); }
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
$("search").addEventListener("input", render);
$("fvpn").addEventListener("change", () => { fvpn = $("fvpn").value; render(); });
$("onlyMissing").addEventListener("change", render);
$("showOff").addEventListener("change", load);

load();
</script>
</body>
</html>
