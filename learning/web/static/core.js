// 앱 상태 한 곳 — 도메인 스크립트는 여기만 읽고 쓴다
const APP = {
  me: {},
  requests: [],
  sites: [],
  categories: [],
  policy: {},
  admins: null,
  view: { mode: "user", tab: "manage", layout: "list" },
  filter: { q: "", site: "", level: "", status: "", mine: false },
  page: { list: 1, manage: 1 },
  size: { list: 30, manage: 30 },
};

/* ---------- 페이징 ---------- */

const LAYOUT_KEY = "learning-list-layout";
try {
  const saved = localStorage.getItem(LAYOUT_KEY);
  if (saved === "list" || saved === "card") APP.view.layout = saved;
} catch (e) { }

const PAGE_SIZES = [10, 20, 30, 50, 100];
const PAGE_KEY = "learning-page-size";

try {
  const saved = JSON.parse(localStorage.getItem(PAGE_KEY) || "null");
  for (const k of ["list", "manage"]) {
    if (PAGE_SIZES.includes(saved?.[k])) APP.size[k] = saved[k];
  }
} catch (e) { }

// 필터를 걸어 건수가 줄면 빈 페이지에 남을 수 있다 — 마지막 페이지로 당겨 준다
function pageSlice(rows, key) {
  const size = APP.size[key];
  const pages = Math.max(1, Math.ceil(rows.length / size));
  if (APP.page[key] > pages) APP.page[key] = pages;
  const from = (APP.page[key] - 1) * size;
  return { page: APP.page[key], pages, from, total: rows.length,
           rows: rows.slice(from, from + size) };
}

function setPage(key, n) {
  APP.page[key] = n;
  rerenderFor(key);
}

function setPageSize(key, n) {
  APP.size[key] = Number(n);
  APP.page[key] = 1;
  try { localStorage.setItem(PAGE_KEY, JSON.stringify(APP.size)); } catch (e) { }
  rerenderFor(key);
}

function rerenderFor(key) {
  if (key === "list") renderList(); else renderAdmin();
}

function pagerHTML(key, info) {
  const { page, pages, from, total } = info;
  const btn = (label, to, on, disabled) =>
    `<button class="pg${on ? " on" : ""}"${disabled ? " disabled" : ""}
      onclick="setPage('${key}',${to})">${label}</button>`;

  const win = 5;
  let start = Math.max(1, page - Math.floor(win / 2));
  const end = Math.min(pages, start + win - 1);
  start = Math.max(1, end - win + 1);

  const nums = [];
  for (let i = start; i <= end; i++) nums.push(btn(i, i, i === page));

  return `<div class="pager">
    <span class="muted">${total ? `${from + 1}–${from + info.rows.length}` : 0} / ${total}건</span>
    <div class="pgs">
      ${btn("«", 1, false, page === 1)}${btn("‹", page - 1, false, page === 1)}
      ${nums.join("")}
      ${btn("›", page + 1, false, page === pages)}${btn("»", pages, false, page === pages)}
    </div>
    <label class="check">
      <select onchange="setPageSize('${key}', this.value)">
        ${PAGE_SIZES.map(n =>
          `<option value="${n}"${APP.size[key] === n ? " selected" : ""}>${n}</option>`).join("")}
      </select> 개씩
    </label>
  </div>`;
}

const esc = s => (s == null ? "" : String(s))
  .replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));

const USER_COLORS = {
  "김호영": { bg: "#F5D9D4", fg: "#A8392B" }, "박성철": { bg: "#F7E0CC", fg: "#B0642A" },
  "안정민": { bg: "#F4E8C6", fg: "#94741C" }, "조성훈": { bg: "#E7ECCB", fg: "#6C782C" },
  "진소현": { bg: "#D5E7D1", fg: "#3B6B40" }, "김태주": { bg: "#CEE8E0", fg: "#2C6D62" },
  "김지안": { bg: "#D1E4EE", fg: "#2A6184" }, "김아랑": { bg: "#D8DCF0", fg: "#3A46A0" },
  "박화랑": { bg: "#E3D9F0", fg: "#68429F" }, "유병문": { bg: "#EED7EC", fg: "#883C84" },
  "유승인": { bg: "#F3D7E1", fg: "#A83964" }, "이한재": { bg: "#E6DCD0", fg: "#78593B" },
  "이준영": { bg: "#DBDFE3", fg: "#485663" }
};
function colorFor(name) {
  if (USER_COLORS[name]) return USER_COLORS[name];
  let h = 0;
  for (const ch of (name || "?")) h = (h * 31 + ch.charCodeAt(0)) % 360;
  return { bg: `hsl(${h},32%,87%)`, fg: `hsl(${h},42%,33%)` };
}

/* ---------- HTTP ---------- */

async function api(path, opts) {
  const r = await fetch(path, Object.assign({ credentials: "same-origin" }, opts || {}));
  if (r.status === 401) {
    // 개발 모드에서는 "/" 가 401 이어도 화면을 내주므로 그리로 보내면 무한 새로고침이 된다.
    if (APP.me.dev_login) throw new Error("포털에서 먼저 로그인해 주세요");
    location.href = APP.me.portal_url || "/";
    throw new Error("unauthenticated");
  }
  if (!r.ok) {
    let msg = "요청이 실패했습니다";
    try {
      const body = await r.json();
      msg = typeof body.detail === "string" ? body.detail
        : (body.detail?.[0]?.msg || msg).replace(/^Value error, /, "");
    } catch (e) { }
    throw new Error(msg);
  }
  return r.status === 204 ? null : r.json();
}

const sendJSON = (method, path, body) => api(path, {
  method,
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body || {}),
});
const postJSON = (path, body) => sendJSON("POST", path, body);
const putJSON = (path, body) => sendJSON("PUT", path, body);

/* ---------- 상태 ---------- */

const S = {
  REQUESTED: "수강승인요청", APPROVED: "수강승인", REJECTED: "수강반려",
  CLAIMED: "수강료청구", CLAIM_APPROVED: "청구승인", CLAIM_REJECTED: "청구반려",
  REFUNDED: "환급완료", NO_REFUND: "환급불필요",
};
const STATUSES = Object.values(S);

const STATUS_CLASS = {
  [S.REQUESTED]: "st-requested", [S.APPROVED]: "st-approved",
  [S.REJECTED]: "st-rejected", [S.CLAIMED]: "st-claimed",
  [S.CLAIM_APPROVED]: "st-claim-approved", [S.CLAIM_REJECTED]: "st-rejected",
  [S.REFUNDED]: "st-refunded", [S.NO_REFUND]: "st-no-refund",
};
// 배지에 마우스를 올렸을 때 띄울 시각이 어느 컬럼에서 오는지
const STATUS_AT = {
  [S.REQUESTED]: "created_at", [S.APPROVED]: "approved_at",
  [S.REJECTED]: "rejected_at", [S.CLAIMED]: "claimed_at",
  [S.CLAIM_APPROVED]: "claim_approved_at", [S.CLAIM_REJECTED]: "claim_rejected_at",
  [S.REFUNDED]: "refunded_at", [S.NO_REFUND]: "approved_at",
};

// 상태별 파스텔. 통계의 상태 막대가 쓴다 — 막대마다 상태 이름이 글자로 붙어 있어
// 색이 정체성을 지지 않는다.
const STATUS_COLOR = {
  [S.REQUESTED]: "#f0b070", [S.APPROVED]: "#7fb0ef", [S.REJECTED]: "#eb96bb",
  [S.CLAIMED]: "#ecc85e", [S.CLAIM_APPROVED]: "#79c8b4", [S.CLAIM_REJECTED]: "#eb96bb",
  [S.REFUNDED]: "#7fcb9b", [S.NO_REFUND]: "#b7c0ca", "": "#ad9cea",
};
const PAID_RAIL = [S.REQUESTED, S.APPROVED, S.CLAIMED, S.CLAIM_APPROVED, S.REFUNDED];
const COMPANY_RAIL = [S.REQUESTED, S.NO_REFUND];

const isCompany = r => r.account_type === "회사계정";
const isMine = r => r.applicant_email === APP.me.email;

/* ---------- 교육 플랫폼 색 ---------- */

// 플랫폼마다 한 세트씩. fg 는 글자·테두리, bar 는 차트 막대, bg 는 배지 바탕이다.
// fg/bg 조합은 모두 대비 4.5:1 을 넘긴다(작은 글자 기준).
const SITE_THEMES = [
  { fg: "#146c42", bar: "#7fcb9b", bg: "#dcf3e6" },
  { fg: "#5b3fd8", bar: "#ad9cea", bg: "#e9e4fb" },
  { fg: "#1565c0", bar: "#7fb0ef", bg: "#dceafa" },
  { fg: "#8a5215", bar: "#f0b070", bg: "#faeeda" },
  { fg: "#a83964", bar: "#eb96bb", bg: "#fae2ea" },
];
const NO_SITE = { fg: "#575e64", bar: "#b7c0ca", bg: "#eceff1" };

function siteTheme(name) {
  const i = APP.sites.findIndex(s => s.name === name);
  return i < 0 ? NO_SITE : SITE_THEMES[i % SITE_THEMES.length];
}

// 이수증 — 개수는 아이콘 옆에, 파일명은 올렸을 때 팝오버로
const CERT_ICON = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <circle cx="12" cy="8" r="5" /><path d="M8.5 12.6 7 22l5-2.8 5 2.8-1.5-9.4" /></svg>`;

function certChip(r) {
  if (!r.cert_count) return "";
  const names = (r.cert_names || []).map(esc).join("<br>")
    || `이수증 ${r.cert_count}건`;
  return `<span class="tip certchip">${CERT_ICON}<b>${r.cert_count}</b>
    <em class="multi">${names}</em></span>`;
}

function siteBadge(name) {
  const t = siteTheme(name);
  return `<span class="badge site" style="background:${t.bg};color:${t.fg}">${esc(name)}</span>`;
}

function tip(html, when) {
  return when ? `<span class="tip">${html}<em>${esc(when)}</em></span>` : html;
}

// 시각은 상세 드로어에서만 띄운다 — 목록에서는 배지마다 팝업이 떠 방해가 된다
function statusBadge(r, withTime = false) {
  if (r.is_free) return '<span class="badge st-free">무료</span>';
  const cls = STATUS_CLASS[r.status] || "st-requested";
  const html = `<span class="badge ${cls}">${esc(r.status)}</span>`;
  return withTime ? tip(html, r[STATUS_AT[r.status]]) : html;
}

// 반려는 레일을 멈춘다 — 어디까지 갔다가 멈췄는지는 그대로 보여준다
function railState(r) {
  const rail = isCompany(r) ? COMPANY_RAIL : PAID_RAIL;
  if (r.status === S.REJECTED) return { rail, at: 0, stopped: true };
  if (r.status === S.CLAIM_REJECTED) {
    return { rail, at: rail.indexOf(S.CLAIMED), stopped: true };
  }
  return { rail, at: rail.indexOf(r.status), stopped: false };
}

function railHTML(r, withTime = false, withCount = true) {
  if (r.is_free) return "";
  const { rail, at, stopped } = railState(r);
  if (at < 0) return "";

  const parts = rail.map((step, i) => {
    const cls = i < at ? "done" : i === at ? (stopped ? "stop" : "on") : "";
    // 멈춘 점은 그 단계가 아니라 실제 상태(반려)의 시각을 보여줘야 한다
    const here = i === at && stopped ? r.status : step;
    const when = withTime ? r[STATUS_AT[here]] : "";
    const label = esc(step) + (i === at && stopped ? ` — ${esc(r.status)}` : "")
      + (when ? ` · ${esc(when)}` : "");
    const dot = `<span class="tip"><i class="${cls}"></i><em>${label}</em></span>`;
    return (i ? `<u class="${i <= at ? "done" : ""}"></u>` : "") + dot;
  });
  return `<span class="railwrap"><span class="rail">${parts.join("")}</span>
    ${withCount ? `<span>${at + 1}/${rail.length}</span>` : ""}</span>`;
}

// 지금 공이 누구에게 있는지 한 줄로 알려준다
const NEXT_STEP = {
  [S.REQUESTED]: "관리자가 수강승인 또는 수강반려를 처리할 차례입니다.",
  [S.APPROVED]: "신청자가 이수증을 올리고 수강료를 청구할 차례입니다.",
  [S.CLAIMED]: "관리자가 청구승인 또는 청구반려를 처리할 차례입니다.",
  [S.CLAIM_APPROVED]: "입금 후 관리자가 환급완료로 바꾸면 끝납니다.",
  [S.REFUNDED]: "환급까지 끝난 건입니다.",
  [S.NO_REFUND]: "회사계정 결제라 환급 절차 없이 승인으로 끝난 건입니다.",
  [S.REJECTED]: "수강반려로 끝난 건입니다.",
  [S.CLAIM_REJECTED]: "신청자가 사유를 확인하고 다시 청구할 수 있습니다.",
};

function nextStepText(r) {
  if (r.is_free) return "무료 강의라 승인·청구 절차가 없습니다.";
  return NEXT_STEP[r.status] || "";
}

/* ---------- 값 표시 ---------- */

const won = n => (n == null ? "" : Number(n).toLocaleString("ko-KR") + "원");

// duration_min 은 분 하나로 저장한다 — 화면에서만 시/분으로 조립한다
function durationText(min) {
  const m = Number(min) || 0;
  if (!m) return "";
  const h = Math.floor(m / 60), rest = m % 60;
  return [h ? `${h}시간` : "", rest ? `${rest}분` : ""].filter(Boolean).join(" ") || "0분";
}

function moneyText(r) {
  if (r.is_free) return "무료";
  if (!r.price) return "미입력";
  if (r.refund_amount && r.refund_amount !== r.price) {
    return `${won(r.price)} → 환급 ${won(r.refund_amount)}`;
  }
  return won(r.price);
}

function periodText(r) {
  if (!r.start_date && !r.end_date) return "";
  return `${r.start_date || "?"} ~ ${r.end_date || "?"}`;
}

function whoHTML(name) {
  const c = colorFor(name);
  return `<span class="who"><span class="dot" style="background:${c.bg};color:${c.fg}">
    ${esc((name || "?").slice(0, 1))}</span>${esc(name || "")}</span>`;
}

/* ---------- 별점 ---------- */

// 값이 없으면 아무것도 그리지 않는다 — 0 은 "0점"이 아니라 "미입력"이다
function starsHTML(value, { editable = false, field = "", small = false } = {}) {
  if (value == null && !editable) return "";
  const v = Number(value) || 0;
  const stars = [1, 2, 3, 4, 5].map(i => {
    const fill = Math.max(0, Math.min(1, v - (i - 1))) * 100;
    const hit = editable
      ? `<i class="h" data-v="${i - 0.5}"></i><i class="f" data-v="${i}"></i>` : "";
    return `<span class="s"><span class="fill" style="width:${fill}%"></span>${hit}</span>`;
  }).join("");
  const cls = ["stars", editable ? "rw" : "", small ? "sm" : ""].filter(Boolean).join(" ");
  const attr = editable ? ` data-field="${field}"` : "";
  return `<span class="starline"><span class="${cls}"${attr}>${stars}</span>` +
    `<span class="v">${v ? v.toFixed(1) : "미평가"}</span></span>`;
}

/* ---------- 오버레이 ---------- */

let toastT;
function showToast(m) {
  const el = document.getElementById("toast");
  el.textContent = m;
  el.classList.add("show");
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove("show"), 2600);
}

function openSheet() { document.getElementById("overlay").classList.add("open"); }
function closeSheet() { document.getElementById("overlay").classList.remove("open"); }

let reasonResolver = null;
function askReason(title, hint) {
  document.getElementById("reasonTitle").textContent = title;
  document.getElementById("reasonHint").textContent = hint;
  document.getElementById("reasonInput").value = "";
  document.getElementById("reasonOverlay").classList.add("open");
  setTimeout(() => document.getElementById("reasonInput").focus(), 50);
  return new Promise(r => { reasonResolver = r; });
}
function submitReason() {
  const v = document.getElementById("reasonInput").value.trim();
  if (!v) { showToast("사유를 입력해 주세요"); return; }
  closeReason(v);
}
function closeReason(v) {
  document.getElementById("reasonOverlay").classList.remove("open");
  if (reasonResolver) { const r = reasonResolver; reasonResolver = null; r(v); }
}

let confirmResolver = null;
function askConfirm(title, hint, okLabel = "확인") {
  document.getElementById("confirmTitle").textContent = title;
  document.getElementById("confirmHint").textContent = hint;
  document.getElementById("confirmOk").textContent = okLabel;
  document.getElementById("confirmOverlay").classList.add("open");
  return new Promise(r => { confirmResolver = r; });
}
function closeConfirm(v) {
  document.getElementById("confirmOverlay").classList.remove("open");
  if (confirmResolver) { const r = confirmResolver; confirmResolver = null; r(v); }
}

const today = () => new Date().toISOString().slice(0, 10);
