// 관리자 신청 관리 — 목록 화면과 달리 검색 조건이 많고, 행에서 바로 상태를 옮긴다
const MANAGE = { q: "", status: "", applicant: "", site: "", account: "",
                 from: "", to: "", archived: "all", layout: "list" };

const MANAGE_FIELDS = ["q", "status", "applicant", "site", "account", "from", "to", "archived"];

function readManage() {
  for (const k of MANAGE_FIELDS) {
    const el = document.getElementById(`m-${k}`);
    if (el) MANAGE[k] = el.value.trim();
  }
  APP.page.manage = 1; // 조건이 바뀌면 첫 장부터 다시 본다
  renderAdmin();
}

function resetManage() {
  Object.assign(MANAGE, { q: "", status: "", applicant: "", site: "", account: "",
                          from: "", to: "", archived: "all" });
  APP.page.manage = 1;
  renderAdmin();
}

function manageRows() {
  const needle = MANAGE.q.toLowerCase();
  const day = r => (r.created_at || "").slice(0, 10);
  return APP.requests.filter(r => {
    if (MANAGE.status === "무료" ? !r.is_free
      : MANAGE.status && (r.is_free || r.status !== MANAGE.status)) return false;
    if (MANAGE.applicant && r.applicant_email !== MANAGE.applicant) return false;
    if (MANAGE.site && r.site !== MANAGE.site) return false;
    if (MANAGE.account === "무료" ? !r.is_free
      : MANAGE.account && (r.is_free || r.account_type !== MANAGE.account)) return false;
    if (MANAGE.archived === "yes" && !r.archived) return false;
    if (MANAGE.archived === "no" && r.archived) return false;
    if (MANAGE.from && day(r) < MANAGE.from) return false;
    if (MANAGE.to && day(r) > MANAGE.to) return false;
    if (!needle) return true;
    return [r.title, r.applicant, r.category_large, r.category_medium, r.url]
      .some(v => (v || "").toLowerCase().includes(needle));
  });
}

/* ---------- 행 동작 ---------- */

// 서버가 허용하는 전이만 버튼으로 낸다 — 화면에서 감추는 것으로 대신하지 않는다.
// 승인/반려는 한 쌍이라 둘 다 밖에 두고, 보관·삭제는 ⋯ 안으로 넣어 열 폭을 줄였다.
function manageActions(r) {
  const act = (label, action, done, cls = "btn-mini") =>
    `<button class="${cls}" onclick="manageAct(${r.id},'${action}','${done}')">${label}</button>`;
  const menu = (label, action, done) =>
    `<button type="button" onclick="manageAct(${r.id},'${action}','${done}')">${label}</button>`;

  const front = [];
  if (!r.is_free) {
    if (r.status === S.REQUESTED) {
      front.push(act("승인", "approve", "승인했습니다", "btn-mini primary"));
      front.push(`<button class="btn-mini danger"
        onclick="manageReject(${r.id},'reject')">반려</button>`);
    }
    if (r.status === S.CLAIMED) {
      front.push(act("청구승인", "claim-approve", "청구를 승인했습니다", "btn-mini primary"));
      front.push(`<button class="btn-mini danger"
        onclick="manageReject(${r.id},'claim-reject')">청구반려</button>`);
    }
    if (r.status === S.CLAIM_APPROVED) {
      front.push(act("환급완료", "refund", "환급완료로 바꿨습니다", "btn-mini primary"));
    }
  }

  const rest = [
    r.archived ? menu("보관 해제", "unarchive", "보관을 풀었습니다")
      : menu("보관", "archive", "보관했습니다"),
    `<button type="button" class="danger" onclick="manageDelete(${r.id})">삭제</button>`,
  ].join("");

  return front.join("") + `<span class="rowmenu" data-rid="${r.id}">
    <button type="button" class="kb" title="더보기"
      onclick="toggleRowMenu(${r.id},event)">⋯</button>
    <span class="rm-pop">${rest}</span></span>`;
}

// 한 번에 하나만 열린다. 렌더될 때마다 초기화하므로 지운 행의 메뉴가 살아남지 않는다.
let ROW_MENU = null;

function paintRowMenus() {
  document.querySelectorAll(".rowmenu").forEach(el =>
    el.classList.toggle("open", Number(el.dataset.rid) === ROW_MENU));
}

function toggleRowMenu(rid, e) {
  if (e) e.stopPropagation();
  ROW_MENU = ROW_MENU === rid ? null : rid;
  paintRowMenus();
}

// 바깥을 누르면 닫는다 — 헤더 사용자 메뉴와 같은 방식
document.addEventListener("click", () => {
  if (ROW_MENU == null) return;
  ROW_MENU = null;
  paintRowMenus();
});

async function manageAct(rid, action, done) {
  try {
    await postJSON(`/learningapi/requests/${rid}/${action}`);
    showToast(done);
    await reload();
  } catch (e) { showToast(e.message); }
}

async function manageReject(rid, action) {
  const label = action === "reject" ? "수강반려" : "청구반려";
  const reason = await askReason(label, "사유를 입력하면 신청자에게 그대로 보입니다.");
  if (!reason) return;
  try {
    await postJSON(`/learningapi/requests/${rid}/${action}`, { reason });
    showToast(`${label} 처리했습니다`);
    await reload();
  } catch (e) { showToast(e.message); }
}

async function manageDelete(rid) {
  const r = APP.requests.find(x => x.id === rid);
  const ok = await askConfirm("신청 삭제",
    `“${r ? r.title : rid}” 을(를) 이수증·이력까지 함께 지웁니다. 되돌릴 수 없습니다.`, "삭제");
  if (!ok) return;
  try {
    await api(`/learningapi/requests/${rid}`, { method: "DELETE" });
    showToast("삭제했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 엑셀(CSV) 추출 ---------- */

const CSV_COLUMNS = [
  ["신청번호", r => r.id],
  ["신청일", r => r.created_at],
  ["플랫폼", r => r.site],
  ["대분류", r => r.category_large],
  ["중분류", r => r.category_medium],
  ["학습수준", r => r.level],
  ["강의명", r => r.title],
  ["수강주소", r => r.url],
  ["신청자", r => r.applicant],
  ["이메일", r => r.applicant_email],
  ["계정구분", r => (r.is_free ? "무료" : r.account_type)],
  ["수강료", r => (r.is_free ? 0 : r.price)],
  ["환급액", r => r.refund_amount],
  ["강의시간(분)", r => r.duration_min],
  ["시작일", r => r.start_date],
  ["종료일", r => r.end_date],
  ["요청상태", r => (r.is_free ? "무료" : r.status)],
  ["수강승인일시", r => r.approved_at],
  ["수강반려일시", r => r.rejected_at],
  ["수강반려사유", r => r.reject_reason],
  ["청구일시", r => r.claimed_at],
  ["청구승인일시", r => r.claim_approved_at],
  ["청구반려일시", r => r.claim_rejected_at],
  ["청구반려사유", r => r.claim_reject_reason],
  ["환급완료일시", r => r.refunded_at],
  ["이수증", r => r.cert_count],
  ["강의평가", r => (r.rating ?? "")],
  ["추천도", r => (r.recommend ?? "")],
  ["후기", r => r.review_note],
  ["보관", r => (r.archived ? "Y" : "")],
];

const csvCell = v => {
  const s = v == null ? "" : String(v);
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

function exportManageCsv() {
  const rows = manageRows();
  if (!rows.length) { showToast("추출할 건이 없습니다"); return; }
  const lines = [CSV_COLUMNS.map(c => csvCell(c[0])).join(",")];
  for (const r of rows) lines.push(CSV_COLUMNS.map(c => csvCell(c[1](r))).join(","));

  // BOM 이 없으면 엑셀이 한글을 깨서 연다
  const blob = new Blob(["﻿" + lines.join("\r\n")],
    { type: "text/csv;charset=utf-8;" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = `BlueLearn_${today()}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(a.href);
  showToast(`${rows.length}건을 내려받았습니다`);
}

/* ---------- 화면 ---------- */

/* ---------- 목록 조각 ---------- */

// 상태를 배지가 아니라 점 + 글자로 낸다. 색이 먼저 말하는 것은 "관리자가 손대야 하나"다 —
// 앰버는 내 차례, 파랑은 사원이 움직일 차례, 초록은 끝, 발간은 반려, 회색은 손댈 게 없다.
const MTONE = {
  [S.REQUESTED]: "amber", [S.CLAIMED]: "amber", [S.CLAIM_APPROVED]: "amber",
  [S.APPROVED]: "blue",
  [S.REFUNDED]: "green",
  [S.REJECTED]: "red", [S.CLAIM_REJECTED]: "red",
  [S.NO_REFUND]: "gray",
};

// 이 상태들만 관리자 손이 필요하다 — 행 왼쪽에 앰버 줄이 붙고 대기 일수가 나온다
const NEEDS_ADMIN = [S.REQUESTED, S.CLAIMED, S.CLAIM_APPROVED];

// 지금 상태가 된 날로부터 며칠 지났는지. 오래 묵은 건이 눈에 띄어야 한다.
function waitedDays(r) {
  const at = (r[STATUS_AT[r.status]] || r.created_at || "").slice(0, 10);
  if (!at) return null;
  const then = new Date(`${at}T00:00:00`).getTime();
  const today = new Date(new Date().toDateString()).getTime();
  return Math.max(0, Math.round((today - then) / 86400000));
}

// 환급 예정액은 서버가 신청 시점 상한으로 계산해 실어 보낸다(expected_refund).
// 여기서 다시 계산하지 않는 이유는 정책이 바뀔 때 두 곳이 갈라지기 때문이다.
function refundNote(r) {
  if (r.is_free) return "";
  if (r.status === S.REFUNDED) return `환급 ${won(r.refund_amount)}`;
  if ([S.REJECTED, S.CLAIM_REJECTED, S.NO_REFUND].includes(r.status)) return "환급 —";
  return r.expected_refund ? `환급 예정 ${won(r.expected_refund)}` : "";
}

// 표와 카드가 같은 조각을 나눠 쓴다 — 표시 규칙이 두 벌로 갈라지지 않게
function manageParts(r) {
  const cat = [r.category_large, r.category_medium].filter(Boolean).join(" > ");
  const waited = !r.is_free && NEEDS_ADMIN.includes(r.status) ? waitedDays(r) : null;
  return {
    tone: r.is_free ? "gray" : (MTONE[r.status] || "gray"),
    label: r.is_free ? "무료" : r.status,
    due: !r.is_free && NEEDS_ADMIN.includes(r.status) && !r.archived,
    cat, waited,
    price: esc(r.is_free ? "무료" : (r.price ? won(r.price) : "미입력")),
    refund: refundNote(r),
    when: esc((r.created_at || "").slice(0, 10)),
  };
}

const mstate = p => `<span class="stl t-${p.tone}"><span class="dot"></span>${esc(p.label)}</span>`;

function manageRowHTML(r) {
  const p = manageParts(r);
  return `<li class="row mrow${p.due ? " due" : ""}${r.archived ? " dim" : ""}">
    <div class="c-st">${mstate(p)}${r.archived
      ? '<span class="badge off">보관</span>' : ""}</div>
    <div class="c-title">
      <button class="linkish" onclick="openDrawer(${r.id})">${esc(r.title)}</button>
      <div class="sub">
        ${siteBadge(r.site)}
        ${whoHTML(r.applicant)}
        <span class="muted">${esc(r.is_free ? "무료" : r.account_type)}</span>
        ${certChip(r)}
      </div>
      ${p.cat ? `<div class="sub2">${esc(p.cat)}</div>` : ""}
    </div>
    <div class="c-money">
      <b>${p.price}</b>${p.refund ? `<i>${esc(p.refund)}</i>` : ""}
    </div>
    <div class="c-when">
      <span>${p.when}</span>${p.waited ? `<i>${p.waited}일 대기</i>` : ""}
    </div>
    <div class="row-act">${manageActions(r)}</div>
  </li>`;
}

function manageCardHTML(r) {
  const p = manageParts(r);
  return `<li class="mcard${p.due ? " due" : ""}${r.archived ? " dim" : ""}">
    <div class="mc-top">${mstate(p)}${r.archived
      ? '<span class="badge off">보관</span>' : ""}
      ${p.waited ? `<i class="mc-wait">${p.waited}일 대기</i>` : ""}</div>
    <button class="linkish mc-title" onclick="openDrawer(${r.id})">${esc(r.title)}</button>
    <div class="mc-sub">${siteBadge(r.site)}${p.cat
      ? `<span class="muted">${esc(p.cat)}</span>` : ""}</div>
    <div class="mc-who">${whoHTML(r.applicant)}
      <span class="muted">${esc(r.is_free ? "무료" : r.account_type)}</span>${certChip(r)}</div>
    <div class="mc-foot">
      <span class="mc-money"><b>${p.price}</b>${p.refund ? `<i>${esc(p.refund)}</i>` : ""}</span>
      <span class="mc-when">${p.when}</span>
    </div>
    <div class="mc-act">${manageActions(r)}</div>
  </li>`;
}

// 보기 상태는 그 사람 브라우저에만 남긴다 — 구성원 목록의 setLayout 과 같은 방식
const MANAGE_LAYOUT_KEY = "learning-manage-layout";
try {
  const saved = localStorage.getItem(MANAGE_LAYOUT_KEY);
  if (saved === "list" || saved === "card") MANAGE.layout = saved;
} catch (e) { }

function setManageLayout(layout) {
  MANAGE.layout = layout;
  try { localStorage.setItem(MANAGE_LAYOUT_KEY, layout); } catch (e) { }
  renderAdmin();
}

const VIEW_ICONS = {
  list: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" /></svg>`,
  card: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" />
    <rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>`,
};

function manageViewToggle() {
  return `<div class="viewtoggle">${["list", "card"].map(v =>
    `<button type="button" class="${MANAGE.layout === v ? "on" : ""}"
      title="${v === "list" ? "표로 보기" : "카드로 보기"}"
      onclick="setManageLayout('${v}')">${VIEW_ICONS[v]}</button>`).join("")}</div>`;
}

// 처리해야 할 것부터 보여준다 — 누르면 그 상태만 남는다.
// 카드 세 장을 칩 줄로 눌러 담았다: 세로 80px 을 목록에 돌려주고, "전체"로 돌아갈 길도
// 칩 하나로 생긴다(예전에는 고른 카드를 다시 눌러야만 풀렸다).
function manageStats() {
  // 대기 큐는 "처리할 일"이라 보관한 건을 언제나 뺀다.
  // 반면 "전체"는 상태 필터를 푸는 칩이라, 지금 걸린 보관 조건을 따라야 목록 건수와 맞는다.
  const n = st => APP.requests.filter(r => r.status === st && !r.archived).length;
  const inScope = r => MANAGE.archived === "yes" ? r.archived
    : MANAGE.archived === "no" ? !r.archived : true;
  const cells = [
    ["전체", "", APP.requests.filter(inScope).length, true],
    ["수강 승인", S.REQUESTED, n(S.REQUESTED)],
    ["청구 승인", S.CLAIMED, n(S.CLAIMED)],
    ["환급 지급", S.CLAIM_APPROVED, n(S.CLAIM_APPROVED)],
  ];
  return `<div class="queue">${cells.map(([label, st, c, all]) => `
    <button type="button" class="qb${all ? " all" : ""}${MANAGE.status === st ? " on" : ""}"
      onclick="filterManageByStatus('${esc(st)}')">
      <span class="n">${c}</span><span class="l">${esc(label)}</span>
    </button>`).join("")}</div>`;
}

function filterManageByStatus(st) {
  MANAGE.status = MANAGE.status === st ? "" : st;
  APP.page.manage = 1;
  renderAdmin();
}

function managePanel() {
  const all = manageRows();
  const info = pageSlice(all, "manage");
  const rows = info.rows;
  const applicants = [...new Map(APP.requests
    .filter(r => r.applicant_email)
    .map(r => [r.applicant_email, r.applicant])).entries()]
    .sort((a, b) => a[1].localeCompare(b[1], "ko"));

  const opt = (v, label, cur) =>
    `<option value="${esc(v)}"${cur === v ? " selected" : ""}>${esc(label)}</option>`;

  const search = `<div class="adm">
    <h4>검색</h4>
    <div class="addbar">
      <input class="grow" id="m-q" placeholder="강의명·신청인·분류·주소"
        value="${esc(MANAGE.q)}" onkeydown="if(event.key==='Enter')readManage()">
      <select id="m-status">${opt("", "전체 상태", MANAGE.status)}
        ${opt("무료", "무료", MANAGE.status)}
        ${STATUSES.map(s => opt(s, s, MANAGE.status)).join("")}</select>
      <select id="m-applicant">${opt("", "전체 신청자", MANAGE.applicant)}
        ${applicants.map(([em, nm]) => opt(em, nm, MANAGE.applicant)).join("")}</select>
      <select id="m-site">${opt("", "전체 플랫폼", MANAGE.site)}
        ${APP.sites.map(s => opt(s.name, s.name, MANAGE.site)).join("")}</select>
    </div>
    <div class="addbar" style="margin-top:8px">
      <select id="m-account">${opt("", "전체 계정", MANAGE.account)}
        ${(APP.me.account_types || []).map(a => opt(a, a, MANAGE.account)).join("")}
        ${opt("무료", "무료 강의", MANAGE.account)}</select>
      <select id="m-archived">${opt("all", "보관 포함", MANAGE.archived)}
        ${opt("no", "보관 제외", MANAGE.archived)}${opt("yes", "보관만", MANAGE.archived)}</select>
      <span class="muted">신청일</span>
      <input type="date" id="m-from" value="${esc(MANAGE.from)}">
      <span class="muted">~</span>
      <input type="date" id="m-to" value="${esc(MANAGE.to)}">
      <button class="btn-mini primary" onclick="readManage()">검색</button>
      <button class="btn-mini" onclick="resetManage()">초기화</button>
    </div>
  </div>`;

  const cards = MANAGE.layout === "card";
  const head = `<div class="ledger-head" style="margin-top:14px">
    <span class="title">신청 관리
      <span class="muted">${all.length}건 / 전체 ${APP.requests.length}건</span></span>
    <span class="lh-act">
      <button class="btn-mini excel" onclick="exportManageCsv()">⬇ 엑셀 내려받기</button>
      ${manageViewToggle()}
    </span>
  </div>
  ${cards ? "" : `<div class="mhead">
    <span>상태</span><span>강의 · 신청자</span>
    <span class="r">수강료 / 환급</span><span class="r">신청일</span><span class="r">처리</span>
  </div>`}`;

  const empty = '<li class="empty"><div class="big">조건에 맞는 신청이 없습니다</div></li>';
  const body = rows.length
    ? rows.map(cards ? manageCardHTML : manageRowHTML).join("")
    : empty;

  const pager = all.length ? pagerHTML("manage", info) : "";
  // 카드 보기에서도 빈 상태만은 목록 상자에 담는다 — 격자에 홀로 놓이면 자리를 못 잡는다
  const shell = cards && rows.length ? `<ul class="mcards">${body}</ul>`
    : `<ul class="list">${body}</ul>`;
  // 지운 행의 ⋯ 메뉴가 살아남지 않게 렌더마다 닫는다
  ROW_MENU = null;
  return `${manageStats()}${search}${head}${shell}${pager}`;
}
