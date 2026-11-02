// 앱 상태 한 곳 — 다른 스크립트는 여기만 읽고 쓴다
const APP = {
  // BOOK_ME 는 index.php 가 인라인 <script> 에 const 로 둔다 — const 는 window 속성이 아니라 이름으로 본다
  me: Object.assign({ email: "", name: "", color: "", is_admin: false, portal_url: ".." },
    typeof BOOK_ME !== "undefined" ? BOOK_ME : {}),
  members: [],          // 포털 계정 명단 [{name, email, color}] — 신청자 칩·뱃지 색의 원본
  data: [],             // 신청 전체. 정렬·필터는 render() 가 매번 한다
  years: [],
  year: new Date().getFullYear(),   // 'all' 이면 전체 연도
  filterApplicant: "",
  editingId: null,
  pendingDelete: null,
};

/* ---------- API ---------- */

// 화면은 FastAPI 시절 경로(/bookapi/…)를 그대로 쓰고, 여기서 api.php?p=… 로 바꾼다
function bookApiURL(path) {
  const [p, qs] = String(path).replace(/^\/bookapi/, "").split("?");
  return "api.php?p=" + encodeURIComponent(p || "/") + (qs ? "&" + qs : "");
}

async function api(path, opts) {
  const r = await fetch(bookApiURL(path), Object.assign({ credentials: "same-origin" }, opts || {}));
  if (r.status === 401) {
    location.href = "../index.php?need_login=book";
    throw new Error("unauthenticated");
  }
  if (!r.ok) {
    let msg = "요청이 실패했습니다";
    try { msg = (await r.json()).detail || msg; } catch (e) { }
    throw new Error(msg);
  }
  return r.status === 204 ? null : r.json();
}

const sendJSON = (method, path, body) => api(path, {
  method,
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body),
});

/* ---------- 공통 유틸 ---------- */

const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const fmt = (n) => (n == null ? "—" : Number(n).toLocaleString("ko-KR"));
const normTitle = (t) => String(t || "").replace(/\s+/g, "").toLowerCase();

function shortDate(ds) {
  if (!ds) return "—";
  const p = ds.split("-");
  return `${parseInt(p[1], 10)}월 ${parseInt(p[2], 10)}일`;
}
function todayISO() {
  const t = new Date();
  return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, "0")}-${String(t.getDate()).padStart(2, "0")}`;
}

// 직무관련 도서는 단가의 절반만 회사가 낸다 — 목록·합계에 보이는 금액은 이 값이다
function displayPrice(d) {
  if (d.price == null) return null;
  return d.gubun === "직무관련" ? Math.round(d.price / 2) : d.price;
}
function formatPriceInput(el) {
  const digits = el.value.replace(/[^\d]/g, "");
  el.value = digits ? Number(digits).toLocaleString("ko-KR") : "";
}
function parsePriceInput(v) {
  const digits = String(v || "").replace(/[^\d]/g, "");
  return digits ? parseInt(digits, 10) : null;
}

/* ---------- 사람 색 ---------- */

// 포털 계정의 고유색을 쓴다. 계정이 없는 이름(퇴사자 등)은 이름 해시로 색을 만든다
function memberOf(nameOrEmail) {
  const key = String(nameOrEmail || "").trim().toLowerCase();
  return APP.members.find((m) => m.email.toLowerCase() === key || m.name.trim().toLowerCase() === key) || null;
}
function colorFor(d) {
  const m = memberOf(d.applicant_email) || memberOf(d.applicant);
  if (m && m.color) return m.color;
  let h = 0;
  for (const ch of (d.applicant || "?")) h = (h * 31 + ch.charCodeAt(0)) % 360;
  return `hsl(${h},36%,48%)`;
}
function whoHTML(d) {
  const c = colorFor(d);
  const name = String(d.applicant || "").trim() || "미지정";
  return `<span class="who"><span class="dot" style="background:${esc(c)}">${esc(name.slice(0, 1))}</span>${esc(name)}</span>`;
}

/* ---------- 토스트 · 겹창 ---------- */

let toastT;
function showToast(m) {
  const el = document.getElementById("toast");
  el.textContent = m;
  el.classList.add("show");
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove("show"), 2200);
}

function toggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById("hdrUserMenu").classList.toggle("open");
}
document.addEventListener("click", (e) => {
  const um = document.getElementById("hdrUserMenu");
  if (um && !um.contains(e.target)) um.classList.remove("open");
});
