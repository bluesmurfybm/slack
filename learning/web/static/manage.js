// 관리자 신청 관리 — 목록 화면과 달리 검색 조건이 많고, 행에서 바로 상태를 옮긴다
const MANAGE = { q: "", status: "", applicant: "", site: "", account: "",
                 from: "", to: "", archived: "all" };

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

// 서버가 허용하는 전이만 버튼으로 낸다 — 화면에서 감추는 것으로 대신하지 않는다
function manageActions(r) {
  const b = [];
  const act = (label, action, done, cls = "btn-mini") =>
    `<button class="${cls}" onclick="manageAct(${r.id},'${action}','${done}')">${label}</button>`;
  if (!r.is_free) {
    if (r.status === S.REQUESTED) {
      b.push(act("승인", "approve", "승인했습니다", "btn-mini primary"));
      b.push(`<button class="btn-mini danger"
        onclick="manageReject(${r.id},'reject')">반려</button>`);
    }
    if (r.status === S.CLAIMED) {
      b.push(act("청구승인", "claim-approve", "청구를 승인했습니다", "btn-mini primary"));
      b.push(`<button class="btn-mini danger"
        onclick="manageReject(${r.id},'claim-reject')">청구반려</button>`);
    }
    if (r.status === S.CLAIM_APPROVED) {
      b.push(act("환급완료", "refund", "환급완료로 바꿨습니다", "btn-mini primary"));
    }
  }
  b.push(r.archived ? act("보관해제", "unarchive", "보관을 풀었습니다")
    : act("보관", "archive", "보관했습니다"));
  b.push(`<button class="btn-mini danger" onclick="manageDelete(${r.id})">삭제</button>`);
  return b.join("");
}

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

// 처리해야 할 것부터 보여준다 — 누르면 그 상태만 남는다
function manageStats() {
  const n = st => APP.requests.filter(r => r.status === st && !r.archived).length;
  const cards = [
    ["수강승인 대기", S.REQUESTED], ["청구승인 대기", S.CLAIMED], ["환급 대기", S.CLAIM_APPROVED],
  ];
  return `<div class="stats" style="margin-bottom:14px">${cards.map(([label, st]) => `
    <button class="stat${MANAGE.status === st ? " on" : ""}"
      onclick="filterManageByStatus('${esc(st)}')">
      <b>${n(st)}건</b><span>${esc(label)}</span>
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

  const head = `<div class="ledger-head" style="margin-top:14px">
    <span class="title">신청 관리
      <span class="muted">${all.length}건 / 전체 ${APP.requests.length}건</span></span>
    <button class="btn-mini excel" onclick="exportManageCsv()">⬇ 엑셀 내려받기</button>
  </div>
  <div class="mhead">
    <span>상태</span><span>강의</span><span>신청자</span>
    <span class="r">수강료 / 환급</span><span class="r">신청일</span><span>처리</span>
  </div>`;

  const cat = r => [r.category_large, r.category_medium].filter(Boolean).join(" > ");
  const body = rows.length ? rows.map(r => `
    <li class="row mrow${r.archived ? " dim" : ""}">
      <div class="c-st">${statusBadge(r)}${r.archived
        ? '<span class="badge off">보관</span>' : ""}</div>
      <div class="c-title">
        <button class="linkish" onclick="openDrawer(${r.id})">${esc(r.title)}</button>
        <div class="sub">
          ${siteBadge(r.site)}
          ${cat(r) ? `<span class="muted">${esc(cat(r))}</span>` : ""}
          ${r.cert_count ? `<span class="chip ghost">이수증 ${r.cert_count}</span>` : ""}
        </div>
      </div>
      <div class="c-who">${whoHTML(r.applicant)}
        <span class="chip ghost">${esc(r.is_free ? "무료" : r.account_type)}</span></div>
      <div class="c-money money">${esc(moneyText(r))}</div>
      <div class="c-when when">${esc((r.created_at || "").slice(0, 10))}</div>
      <div class="row-act">${manageActions(r)}</div>
    </li>`).join("")
    : '<li class="empty"><div class="big">조건에 맞는 신청이 없습니다</div></li>';

  const pager = all.length ? pagerHTML("manage", info) : "";
  return `${manageStats()}${search}${head}<ul class="list">${body}</ul>${pager}`;
}
