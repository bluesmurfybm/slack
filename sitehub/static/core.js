// 앱 상태 한 곳 — 다른 스크립트는 여기만 읽고 쓴다
const APP = {
  sites: [],            // GET ?all=1 결과 (하위 테이블·이력 건수·계약 상태 포함)
  staff: [],            // portal_users 이름·이메일 (블루소프트 담당자 선택용)
  unmatched: 0,
  lastSync: null,
  blumo: null,          // BLUMO 보고 상태 {report_at, report_age, paused, …} — sitehub_blumo_meta()
  filter: { q: "", status: "", hosting: "", stack: "", expiry: false },   // expiry: 만료 안내 대상(호스팅·도메인·SSL 30일 안)만
  view: "active",       // active=사용 중 · archive=보관함. 보관된 사이트는 archived=1 — 삭제 대신 여기로 옮긴다
  layout: "list",
  open: { id: null, tab: "basic" },   // 드로어에 열린 사이트 — 주소 ?site=&tab= 와 같이 움직인다
};

const LAYOUT_KEY = "sitehub-list-layout";
try {
  const saved = localStorage.getItem(LAYOUT_KEY);
  if (saved === "card" || saved === "list") APP.layout = saved;
} catch (e) { }

const $ = id => document.getElementById(id);
const esc = s => (s == null ? "" : String(s))
  .replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
const nl2br = s => esc(s).replace(/\n/g, "<br>");

/* ── API ── access_api.php 와 같은 모양: GET 은 쿼리, 쓰기는 POST JSON {action}.
   401 은 포털로 보낸다(화면 라우트는 리다이렉트, API 는 401 그대로 — readme 로그인 절). */
async function api(qs, opts) {
  const r = await fetch("api.php" + (qs || ""), Object.assign({ credentials: "same-origin", cache: "no-store" }, opts || {}));
  if (r.status === 401) {
    location.href = "../index.php?need_login=sitehub";
    throw new Error("unauthenticated");
  }
  let j = null;
  try { j = await r.json(); } catch (e) { throw new Error("응답을 읽지 못했습니다 (" + r.status + ")"); }
  if (!j || j.ok === false) throw new Error((j && j.error) || "요청이 실패했습니다");
  return j;
}
const postJSON = body => api("", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body || {}),
});

/* ── 토스트 / 시트 / 확인창 (dti core.js 와 같은 이름) ── */
let toastT;
function showToast(m) {
  const el = $("toast");
  el.textContent = m;
  el.classList.add("show");
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove("show"), 2200);
}
function openSheet(html) {
  if (html !== undefined) $("sheet").innerHTML = html;
  $("overlay").classList.add("open");
  const first = $("sheet").querySelector("input:not([type=hidden]),select,textarea");
  if (first) setTimeout(() => first.focus(), 40);
}
function closeSheet() { $("overlay").classList.remove("open"); }

let askResolver = null;
function askConfirm(title, text, okLabel) {
  $("askTitle").textContent = title;
  $("askText").textContent = text;
  $("askOk").textContent = okLabel || "확인";
  $("askOverlay").classList.add("open");
  return new Promise(r => { askResolver = r; });
}
function closeAsk(ok) {
  $("askOverlay").classList.remove("open");
  if (askResolver) { const r = askResolver; askResolver = null; r(ok); }
}
document.addEventListener("keydown", e => {
  if (e.key !== "Escape") return;
  if ($("askOverlay").classList.contains("open")) return closeAsk(false);
  if ($("overlay").classList.contains("open")) return closeSheet();
  if ($("drawer").classList.contains("open") && typeof closeDrawer === "function") closeDrawer();
});

/* ── 클립보드 ── access.php 의 copyText 그대로.
   운영 URL 이 http 라 포털도 http 로 여는 경우가 있고, 그때는 보안 컨텍스트가 아니어서
   navigator.clipboard 가 없다 — execCommand 폴백이 반드시 필요하다. */
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
    btn.classList.add(ok ? "on" : "danger");
    btn.textContent = ok ? "완료" : "실패";
    setTimeout(() => { btn.classList.remove("on", "danger"); btn.textContent = old; }, 900);
  }
  showToast(ok ? "복사했습니다" : "복사하지 못했습니다. 직접 선택해 주세요.");
}
// data-copy 를 가진 버튼은 한 곳에서 처리한다 (목록·카드·드로어·시트 공통)
document.addEventListener("click", e => {
  const b = e.target.closest("[data-copy]");
  if (!b) return;
  e.preventDefault(); e.stopPropagation();
  copyText(b.dataset.copy, b);
});

/* ── 표시 도우미 ── */
const today = () => new Date().toISOString().slice(0, 10);
const fmtAmount = n => (n === null || n === undefined || n === "" ? "" : Number(n).toLocaleString("ko-KR") + "원");
const splitList = s => (s || "").split(",").map(t => t.trim()).filter(Boolean);
// 호스팅·서버/스택 같은 태그 칸 — 쉼표(,)로 나누고, 엑셀 가져오기 때 쓴 가운뎃점(·)도 같은 구분자로 본다.
// 필터는 대소문자·띄어쓰기 차이를 한 값으로 묶는다(CIBOARD = CIboard, 'mariadb 10.x' = 'MariaDB 10.x').
const splitTags = s => (s || "").split(/\s*[,·、]\s*/).map(t => t.trim()).filter(Boolean);
const tagKey = t => t.toLowerCase().replace(/\s+/g, "");
const optLabel = (f, v) => (f && f.options && f.options[v] !== undefined ? f.options[v] : (v == null ? "" : String(v)));
const siteById = id => APP.sites.find(s => s.id === Number(id)) || null;

// 계약 상태 배지 — 서버가 파생한 contract_status 를 그대로 그린다
function statusBadge(s, withDday) {
  const cs = s.contract_status || { code: "none", label: "미계약", dday: null };
  const cls = { active: "lv-recommended", soon: "warn", expired: "lv-required", none: "off" }[cs.code] || "off";
  const kindWord = { free: "무상", case: "건별", hosting: "호스팅만" }[cs.kind];
  let txt = kindWord ? (cs.code === "active" ? kindWord : kindWord + " " + cs.label) : cs.label;
  if (withDday !== false && cs.dday !== null && cs.dday !== undefined && cs.code !== "none") {
    txt += " " + (cs.dday < 0 ? "D+" + (-cs.dday) : cs.dday === 0 ? "D-day" : "D-" + cs.dday);
  }
  return `<span class="badge sh-st sh-st-${cs.code}${cs.kind === "free" ? " sh-free" : ""} ${cls}">${esc(txt)}</span>`;
}

// 만료 배지 — 호스팅·도메인·SSL 만료일. 서버가 준 {dday, code, kind, expires_on} 를 그린다(계약 상태 배지와 같은 색)
const ddayText = d => (d < 0 ? "D+" + -d : d === 0 ? "D-day" : "D-" + d);
function expiryBadge(x, withKind) {
  if (!x) return "";
  const short = { hosting: "호스팅", domain: "도메인", ssl: "SSL" };
  const cls = x.code === "expired" ? "sh-st-expired" : x.code === "soon" ? "sh-st-soon" : "sh-exp-ok";
  const kind = withKind ? (short[x.kind] || x.kind) + " " : "";
  const txt = x.code === "expired" ? `${kind}만료됨 ${ddayText(x.dday)}` : `${kind}만료 ${ddayText(x.dday)}`;
  return `<span class="badge ${cls}" title="만료일 ${esc(x.expires_on || "")}">${esc(txt)}</span>`;
}

/* ── BLUMO 감시 원 ── 감시 대상(monitor=1)만. 서버가 준 monitor_status.code: up 초록 · down 빨강 ·
   unknown/empty/paused 회색(점검 전·BLUMO 보고 끊김·감시 주소 없음·BLUMO 에서 멈춤). 글자 없이 원만, 뜻은 title 로. */
const MON_WORD = { up: "정상", down: "장애", unknown: "확인 필요", empty: "감시 주소 없음", paused: "자동 점검 멈춤" };
function monitorDot(ms, extraTitle) {
  if (!ms) return "";
  const code = MON_WORD[ms.code] ? ms.code : "unknown";
  const title = "BLUMO " + MON_WORD[code] + (ms.text ? "\n" + ms.text : "") + (extraTitle ? "\n" + extraTitle : "");
  return `<span class="sh-mdot sh-mdot-${code}" role="img" aria-label="BLUMO ${esc(MON_WORD[code])}" title="${esc(title)}"></span>`;
}
// 감시 주소 하나의 원 — 감시 설정 탭의 행
function watchDot(w) {
  const code = w && MON_WORD[w.code] ? w.code : "unknown";
  return `<span class="sh-mdot sh-mdot-${code}" role="img" aria-label="${esc(MON_WORD[code])}" title="${esc(w ? w.text : "")}"></span>`;
}

// 보관 배지 — 보관 날짜까지 (목록 행·카드·드로어 머리 공통)
const archiveBadge = s => s.archived
  ? `<span class="badge off sh-archived" title="${esc(s.archive_note || "보관됨")}">보관${s.archived_at ? " " + esc(String(s.archived_at).slice(0, 10)) : ""}</span>`
  : "";

/* ── 수정일 · 수정자 ── updated_by 는 portal_users.email, 엑셀 가져오기는 'import:xlsx', 사람이 아니면 'system' (audit_lib.php).
   updated_by 가 비어 있으면 이 기능 전에 고친 행이라 누가 고쳤는지 모른다 — 날짜만 보인다. */
function whoName(by) {
  if (!by) return "";
  if (by === "import:xlsx") return "엑셀 가져옴";
  if (by === "system") return "시스템";
  const p = APP.staff.find(x => x.email === by);
  return p && p.name ? p.name : String(by).replace(/@.*/, "");
}
const parseDT = v => (v ? new Date(String(v).replace(" ", "T")) : null);
function agoText(v) {
  const d = parseDT(v);
  if (!d || isNaN(d)) return "";
  const sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
  if (sec < 60) return "방금";
  if (sec < 3600) return Math.floor(sec / 60) + "분 전";
  if (sec < 86400) return Math.floor(sec / 3600) + "시간 전";
  const days = Math.floor(sec / 86400);
  if (days < 31) return days + "일 전";
  if (days < 365) return Math.floor(days / 30) + "개월 전";
  return Math.floor(days / 365) + "년 전";
}
// 행 하나의 도장 — {text, title, imported}. 엑셀에서 들어온 뒤 손대지 않았으면 '엑셀 가져옴', 고쳤으면 '수정 날짜 · 이름'
function stampOf(r) {
  if (!r) return null;
  const at = r.updated_at || r.created_at || "";
  if (!at) return null;
  const day = String(at).slice(0, 10);
  const full = String(at).slice(0, 16);
  if (r.updated_by === "import:xlsx") return { text: `엑셀 가져옴 ${day}`, title: `엑셀 가져오기 ${full} — 그 뒤로 고친 적 없음`, imported: true };
  const who = whoName(r.updated_by);
  const verb = r.updated_at && r.updated_at !== r.created_at ? "수정" : "등록";
  return { text: `${verb} ${day}${who ? " · " + who : ""}`, title: `${verb} ${full}${who ? " · " + who : " · 수정자 기록 없음(변경 이력 기능 이전)"}`, imported: false, at, who };
}
const stampHtml = r => {
  const st = stampOf(r);
  return st ? `<span class="sh-stamp${st.imported ? " is-import" : ""}" title="${esc(st.title)}">${esc(st.text)}</span>` : "";
};

// 복사 버튼 — 값이 없으면 버튼도 없다
const cpBtn = (val, label, title) => val
  ? `<button type="button" class="btn-mini" data-copy="${esc(val)}" title="${esc(title || (label + " 복사"))}">${esc(label)}</button>`
  : "";

/* ── 주소 상태 ── ?view=archive&site=<id>&tab=<key>. 검색·필터는 주소에 남기지 않는다(새로고침에 살아남을 만큼 중요하지 않다).
   보관함은 남긴다 — 보관된 사이트 링크를 열면 그 목록 안에서 보여야 하므로. */
function readURL() {
  const p = new URLSearchParams(location.search);
  APP.view = p.get("view") === "archive" ? "archive" : "active";
  APP.open.id = p.get("site") ? Number(p.get("site")) : null;
  APP.open.tab = p.get("tab") || "basic";
}
function writeURL(push) {
  const p = new URLSearchParams();
  if (APP.view === "archive") p.set("view", "archive");
  if (APP.open.id) { p.set("site", APP.open.id); if (APP.open.tab !== "basic") p.set("tab", APP.open.tab); }
  const url = location.pathname + (p.toString() ? "?" + p : "");
  if (url === location.pathname + location.search) return;
  (push ? history.pushState : history.replaceState).call(history, null, "", url);
}

/* ── 화면 자동 동기화 ── 서버 예약 실행은 두지 않기로 했다. 대신 SiteHub·통계 화면을 열어 둔 사람이 있으면 마지막 동기화가
   SH.autoSyncMin 분 넘게 지났을 때 뒤에서 증분 동기화한다(1분마다 확인, 탭이 가려져 있으면 쉰다).
   여러 명이 동시에 열어도 sync.php 의 flock 때문에 Slack 호출은 한 번 — 나머지는 skipped:'locked' 로 바로 끝난다.
   "언제 마지막으로 했나" 는 서버가 준 sync_age(초)로 맞춘다 — PC 시계가 틀려도 안 어긋나게. */
const AUTO = { lastAt: null, running: false, onChanged: null, timer: null };

function autoSyncSeen(ageSec) {
  if (ageSec === null || ageSec === undefined) return;
  const at = Date.now() - ageSec * 1000;
  if (AUTO.lastAt === null || at > AUTO.lastAt) AUTO.lastAt = at;
}

function autoSyncInit(ageSec, onChanged) {
  AUTO.onChanged = onChanged;
  if (ageSec === null || ageSec === undefined) AUTO.lastAt = 0; else autoSyncSeen(ageSec);
  if (!SH.canSync || !SH.autoSyncMin || AUTO.timer) return;
  AUTO.timer = setInterval(autoSyncTick, 60000);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) autoSyncTick(); });
  autoSyncTick();
}

async function autoSyncTick() {
  if (AUTO.running || document.hidden || !SH.canSync || !SH.autoSyncMin) return;
  if (typeof syncing !== "undefined" && syncing) return;           // 버튼으로 돌리는 중
  if (AUTO.lastAt && Date.now() - AUTO.lastAt < SH.autoSyncMin * 60000) return;
  AUTO.running = true;
  const btn = $("btnSync");
  if (btn) { btn.disabled = true; btn.textContent = "동기화 중…"; }
  try {
    const r = await fetch("sync.php", { credentials: "same-origin", cache: "no-store" });
    if (r.status === 401) return;
    const j = await r.json();
    if (j.ok && j.skipped === "locked") {
      AUTO.lastAt = Date.now() - (SH.autoSyncMin - 1) * 60000;      // 남이 돌리는 중 — 1분 뒤 다시 확인
    } else if (j.ok) {
      AUTO.lastAt = Date.now();
      if (j.meta && typeof applyMeta === "function") applyMeta(j.meta);   // 목록 화면: 미매칭 수 · 마지막 동기화 시각
      if (j.changed > 0 && AUTO.onChanged) await AUTO.onChanged(j);
    } else {
      AUTO.lastAt = Date.now();                                    // 실패해도 매 분 두드리지 않는다 — 다음 주기에 다시
      if (!j.schema) showToast("이력 자동 동기화 실패 — " + (j.error || "알 수 없는 오류"));
    }
  } catch (e) {
    AUTO.lastAt = Date.now();
  } finally {
    AUTO.running = false;
    if (btn) { btn.disabled = false; btn.textContent = "이력 동기화"; }
  }
}
