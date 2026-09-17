// 고른 값이 목록에서 사라졌으면(사이트 비활성 등) 빈 칸이 아니라 "전체"로 돌아간다
function selectValue(el, value) {
  el.value = value || "";
  if (el.selectedIndex < 0) el.selectedIndex = 0;
}

function buildFilters() {
  const site = document.getElementById("f-site");
  site.innerHTML = '<option value="">전체 플랫폼</option>' +
    APP.sites.map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join("");
  selectValue(site, APP.filter.site);

  const level = document.getElementById("f-level");
  level.innerHTML = '<option value="">전체 수준</option>' +
    (APP.me.levels || []).map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join("");
  selectValue(level, APP.filter.level);

  // 신청 목록은 요청상태로, 추천·필수 강의 탭은 등급으로 거른다 — 칸 하나를 돌려 쓴다
  const catalogPane = APP.view.pane === "catalog";
  const status = document.getElementById("f-status");
  status.innerHTML = catalogPane
    ? '<option value="">전체 등급</option><option value="필수">필수</option>'
      + '<option value="추천">추천</option>'
    : '<option value="">전체 상태</option><option value="무료">무료</option>'
      + STATUSES.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join("");
  selectValue(status, catalogPane ? APP.filter.grade : APP.filter.status);

  // "내 신청만"은 신청 목록에만 뜻이 있다
  document.getElementById("f-mine").parentElement.style.display = catalogPane ? "none" : "";
}

function applyFilters() {
  const pick = document.getElementById("f-status").value;
  APP.filter = {
    ...APP.filter,
    q: document.getElementById("f-q").value.trim(),
    site: document.getElementById("f-site").value,
    level: document.getElementById("f-level").value,
    mine: document.getElementById("f-mine").checked,
  };
  // 상태 칸은 탭에 따라 뜻이 달라진다 — 다른 탭의 값을 건드리지 않는다
  if (APP.view.pane === "catalog") APP.filter.grade = pick;
  else APP.filter.status = pick;
  APP.page.list = 1; // 조건이 바뀌면 첫 장부터 다시 본다
  renderList();
}

function visibleRequests() {
  const { q, site, level, status, mine } = APP.filter;
  const needle = q.toLowerCase();
  return APP.requests.filter(r => {
    if (site && r.site !== site) return false;
    if (level && r.level !== level) return false;
    if (status === "무료" ? !r.is_free : status && (r.is_free || r.status !== status)) return false;
    if (mine && !isMine(r)) return false;
    if (!needle) return true;
    return [r.title, r.applicant, r.category_large, r.category_medium]
      .some(v => (v || "").toLowerCase().includes(needle));
  });
}

// 기간이 얼마나 지났는지 — 환급 기한을 놓치지 않게 첫 칸에만 낸다
function periodPct(r) {
  if (!r.start_date || !r.end_date) return null;
  const day = s => new Date(`${s}T00:00:00`).getTime();
  const a = day(r.start_date), b = day(r.end_date);
  if (!(b > a)) return null;
  return Math.max(0, Math.min(100, Math.round((Date.now() - a) / (b - a) * 100)));
}

const monthDay = d => {
  const p = (d || "").split("-");
  return p.length === 3 ? `${+p[1]}/${+p[2]}` : "";
};

/* ---------- 내 학습 현황 ---------- */

// 네 칸은 절차 단계로 나눠 서로 겹치지 않게 한다. 예전처럼 "진행 중"과 알림 줄이
// 같은 건을 다르게 세면 두 숫자가 어긋난 것처럼 보인다.
//   수강 중   승인을 기다리거나 승인받아 듣는 중
//   청구 대기 청구를 넣고 돈을 기다리는 중
//   이수 완료 절차가 끝났거나 이수증을 올린 건
const STAGE_RUNNING = [S.REQUESTED, S.APPROVED];
const STAGE_CLAIM = [S.CLAIMED, S.CLAIM_APPROVED, S.CLAIM_REJECTED];
const STAGE_DONE = [S.REFUNDED, S.NO_REFUND];

// 무료 건은 요청상태가 없다 — 진행상태로 가른다
const stageOf = r => {
  if (r.is_free) return r.progress === "완료" ? "done" : "running";
  if (STAGE_DONE.includes(r.status) || (r.cert_count && r.status === S.REJECTED)) return "done";
  if (STAGE_CLAIM.includes(r.status)) return "claim";
  if (STAGE_RUNNING.includes(r.status)) return "running";
  return "";   // 수강반려 — 알림 줄이 따로 말한다
};

// 이수한 강의의 강의시간을 더한다. 올해 신청한 건만.
function learnedMinutes(mine) {
  const year = String(new Date().getFullYear());
  return mine.filter(r => (r.created_at || "").startsWith(year) && stageOf(r) === "done")
    .reduce((sum, r) => sum + (Number(r.duration_min) || 0), 0);
}

// 필수 강의의 마감은 회사가 정한 이수 기한이다. 내가 적은 수강 종료일이 더 빨라도
// 그건 내 계획일 뿐이라 기한이 아니다 — 오른쪽 추천 카드와 같은 날짜를 보여야
// 같은 강의에 D-45 와 D-75 가 함께 뜨는 일이 없다. 종료일은 캡션에 그대로 남는다.
function dueDayOf(r) {
  return r.catalog_due || r.end_date || "";
}

function daysTo(day) {
  if (!day) return null;
  return Math.round((new Date(`${day}T00:00:00`).getTime()
    - new Date(new Date().toDateString()).getTime()) / 86400000);
}

function renderMyStrip() {
  const mine = APP.requests.filter(isMine);
  document.getElementById("msMine").classList.toggle("on", !!APP.filter.mine);
  renderStripHead(mine);
  renderStripNums(mine);
  renderStripRunning(mine);
}

function renderStripHead(mine) {
  const name = APP.me.name || "";
  const ava = document.getElementById("msAva");
  ava.textContent = (name || "?").slice(0, 1);
  ava.style.background = APP.me.color || colorFor(name).fg;
  document.getElementById("msName").textContent = name ? `${name} 님의 학습 현황` : "학습 현황";

  // 많이 쓴 곳부터 — 데이터가 들어온 순서에 부제가 흔들리지 않게
  const tally = new Map();
  for (const r of mine) if (r.site) tally.set(r.site, (tally.get(r.site) || 0) + 1);
  const sites = [...tally.entries()].sort((a, b) => b[1] - a[1]).map(([s]) => s);
  const siteText = sites.length > 2 ? `${sites[0]} 외 ${sites.length - 1}곳` : sites.join(" · ");
  document.getElementById("msSub").textContent =
    [`${new Date().getFullYear()}년`, siteText].filter(Boolean).join(" · ");

  const mins = learnedMinutes(mine);
  document.getElementById("msHours").innerHTML = mins
    ? `올해 학습 시간 <b>${esc(durationText(mins))}</b>` : "";
}

function renderStripNums(mine) {
  const n = stage => mine.filter(r => stageOf(r) === stage).length;
  const refunded = mine.filter(r => r.status === S.REFUNDED)
    .reduce((sum, r) => sum + (r.refund_amount || 0), 0);

  const claim = n("claim");
  const cols = [
    { label: "수강 중", value: n("running"), unit: "건", hue: "run" },
    { label: "이수 완료", value: n("done"), unit: "건", hue: "all" },
    // 내가 기다리는 돈이라 하나라도 있으면 눈에 걸리게 둔다
    { label: "청구 대기", value: claim, unit: "건", hue: "done", hot: !!claim },
    { label: "받은 환급액", value: refunded.toLocaleString("ko-KR"), unit: "원", hue: "paid" },
  ];

  document.getElementById("msNums").innerHTML = cols.map(c => `
    <div class="ms-col ${c.hue}${c.hot ? " hot" : ""}">
      <span class="ms-lb">${esc(c.label)}</span>
      <span class="ms-vl"><b>${esc(c.value)}</b><i>${esc(c.unit)}</i></span>
    </div>`).join("");
}

/* ---------- 진행 중인 강의 ---------- */

// 막대는 수강 기간이 얼마나 지났는지다 — 외부 플랫폼의 실제 진도는 알 수 없다.
// 라벨로 "기간"임을 못 박아 진도로 오해하지 않게 한다.
const RUNNING_N = 1;      // 처음 보여줄 개수. 나머지는 더보기로 — 첫 화면을 짧게 둔다
let RUNNING_OPEN = false;

function renderStripRunning(mine) {
  const rows = mine.filter(r => stageOf(r) === "running")
    .sort((a, b) => {
      const [x, y] = [dueDayOf(a), dueDayOf(b)];
      if (!x !== !y) return x ? -1 : 1;       // 기한 없는 건은 뒤로
      return x.localeCompare(y);
    });

  const box = document.getElementById("msRunning");
  if (!rows.length) { box.innerHTML = ""; return; }

  const shown = RUNNING_OPEN ? rows : rows.slice(0, RUNNING_N);
  const rest = rows.length - shown.length;

  box.innerHTML = `<div class="msr-head">진행 중인 강의
      <span class="muted">마감 임박순</span></div>
    ${shown.map(runningRowHTML).join("")}
    ${rest > 0 || RUNNING_OPEN ? `<button type="button" class="msr-fold"
      onclick="toggleRunning()">${RUNNING_OPEN ? "접기 ⌃" : `${rest}건 더 보기 ⌄`}</button>` : ""}`;
}

function toggleRunning() {
  RUNNING_OPEN = !RUNNING_OPEN;
  renderStripRunning(APP.requests.filter(isMine));
}

function runningRowHTML(r) {
  const pct = periodPct(r);
  const due = dueDayOf(r);
  const left = daysTo(due);
  const tag = r.catalog_grade === "필수"
    ? `<span class="badge gr-req">필수</span>`
    : r.category_large
      ? `<span class="msr-cat">${esc(r.category_large)}</span>` : "";

  return `<div class="msr">
    <div class="msr-top">
      ${tag}
      <button class="msr-ttl" onclick="openDrawer(${r.id})"
        title="${esc(r.title)}">${esc(r.title)}</button>
      ${left == null ? "" : `<span class="msr-d ${left <= 7 ? "hot"
        : left <= 30 ? "warn" : ""}">${left < 0 ? "기한 지남"
        : left === 0 ? "오늘 마감" : `D-${left}`}</span>`}
    </div>
    <div class="msr-bar">
      <span class="msr-tr"><i style="width:${pct == null ? 0 : pct}%"></i></span>
      <span class="msr-pct">${pct == null ? "—" : `${pct}%`}</span>
      <span class="msr-cap">${esc(runningCaption(r, pct))}</span>
      ${r.url ? `<a class="btn-mini" href="${esc(r.url)}" target="_blank"
        rel="noopener">이어보기</a>` : ""}
    </div>
  </div>`;
}

function runningCaption(r, pct) {
  const total = durationText(r.duration_min);
  const when = r.end_date ? `${monthDay(r.end_date)} 종료` : "";
  const how = pct == null ? "기간 미정" : pct === 0 ? "시작 전" : `기간 ${pct}% 지남`;
  return [total && `총 ${total}`, how, when].filter(Boolean).join(" · ");
}

// 관리자 대기 칸 — 신청 관리 탭의 대기 카드와 같은 셈법을 쓴다
const ADMIN_TODO = [
  ["수강 승인", S.REQUESTED], ["청구 승인", S.CLAIMED], ["환급", S.CLAIM_APPROVED],
];

/* ---------- 관리자 처리 대기 줄 ---------- */

// 학습 현황 줄 아래에 한 줄. 관리자가 아니거나 처리할 게 없으면 줄 자체를 내지 않는다.
// 내 할 일은 따로 두지 않는다 — 신청 목록이 바로 아래에 있고, 급한 건은 목록의
// 상태 배지가 이미 말한다. 첫 화면 위쪽을 알림으로 채우면 정작 목록이 밀린다.
const STALE_DAYS = 3;

function renderTodoRow() {
  const box = document.getElementById("todoRow");
  box.innerHTML = adminBarHTML();
}

function adminBarHTML() {
  if (!APP.me.is_admin) return "";

  // 보관한 건은 처리 대상이 아니다 — 관리 탭의 대기 카드와 같은 셈법을 쓴다
  const open = APP.requests.filter(r => !r.archived);
  const todo = ADMIN_TODO.map(([label, st]) =>
    [label, st, open.filter(r => r.status === st).length]);
  const total = todo.reduce((sum, [, , c]) => sum + c, 0);
  if (!total) return "";

  // 며칠 기다렸는지는 신청 관리 화면의 waitedDays(manage.js)를 그대로 쓴다
  const pending = ADMIN_TODO.map(([, st]) => st);
  const stale = open.filter(r =>
    pending.includes(r.status) && (waitedDays(r) || 0) >= STALE_DAYS).length;

  return `<div class="adminbar">
    <span class="ab-tag">관리자</span>
    <b class="ab-sum">처리 대기 ${total}건</b>
    <span class="ab-chips">${todo.filter(([, , c]) => c).map(([label, st, c]) =>
      `<button type="button" class="ab-chip" onclick="goManage('${esc(st)}')"
        title="${esc(st)} 상태만 걸어서 신청 관리로">${esc(label)}<b>${c}</b></button>`).join("")}
      ${stale ? `<span class="ab-stale">${STALE_DAYS}일 넘게 ${stale}건</span>` : ""}</span>
    <button type="button" class="ab-go" onclick="goManage('')">처리하러 가기 →</button>
  </div>`;
}

function goManage(st) {
  MANAGE.status = st || "";
  APP.page.manage = 1;
  setMode("admin");
  setTab("manage");
}

// "내 신청 내역" — 목록을 내 것만으로 좁힌다. 다시 누르면 전체로 돌아간다
function toggleMineOnly() {
  // 신청 목록 쪽 필터다 — 카탈로그 탭에서 누르면 안 보이는 곳이 바뀌므로 탭부터 옮긴다
  APP.view.pane = "list";
  const box = document.getElementById("f-mine");
  box.checked = !box.checked;
  document.getElementById("f-status").value = "";
  applyFilters();
}

// 알림 줄의 "확인" 은 언제나 "내 신청" 안에서 그 상태만 남긴다
function filterMine(st) {
  const same = APP.filter.status === st && APP.filter.mine;
  document.getElementById("f-status").value = same ? "" : st;
  document.getElementById("f-mine").checked = !same;
  applyFilters();
}

function renderPolicyNote() {
  const el = document.getElementById("policyNote");
  if (!APP.policy.partial_enabled) { el.style.display = "none"; return; }
  el.style.display = "";
  el.textContent = `수강료 중 ${won(APP.policy.partial_cap)}까지 환급됩니다. ` +
    "초과분은 본인 부담입니다.";
}

function setLayout(layout) {
  APP.view.layout = layout;
  try { localStorage.setItem(LAYOUT_KEY, layout); } catch (e) { }
  renderList();
}

// 리스트와 카드가 같은 조각을 나눠 쓴다 — 표시 규칙이 두 벌로 갈라지지 않게
function rowParts(r) {
  const titleText = esc(r.title);
  const cat = [r.category_large, r.category_medium].filter(Boolean).join(" › ");
  return {
    title: r.url
      ? `<a href="${esc(r.url)}" target="_blank" rel="noopener">${titleText}</a>`
      : titleText,
    // 학습수준을 분류 뒤에 그냥 붙이면 중분류처럼 읽힌다 — 칩으로 끊는다
    where: [
      siteBadge(r.site),
      cat ? `<span class="l-cat">${esc(cat)}</span>` : "",
      r.level ? `<span class="chip lv">${esc(r.level)}</span>` : "",
    ].filter(Boolean).join(""),
    meta: [
      whoHTML(r.applicant),
      durationText(r.duration_min),
      periodText(r),
    ].filter(Boolean).join('<span class="sep">·</span>'),
    scores: [
      r.rating != null
        ? `<span class="sc">평가 ${starsHTML(r.rating, { small: true })}</span>` : "",
      r.recommend != null
        ? `<span class="sc">추천 ${starsHTML(r.recommend, { small: true })}</span>` : "",
    ].filter(Boolean).join(""),
    price: esc(r.is_free ? "무료" : (r.price ? won(r.price) : "미입력")),
    refunded: !r.is_free && r.refund_amount && r.refund_amount !== r.price
      ? `환급 ${won(r.refund_amount)}` : "",
    certs: certChip(r),
  };
}

function cardHTML(r) {
  const p = rowParts(r);
  return `<li class="card${r.archived ? " dim" : ""}">
    <div class="card-top">${gradeTag(r)}${statusBadge(r)}
      <span class="card-rail">${railHTML(r, false, false)}</span></div>
    <div class="card-title">${p.title}</div>
    <div class="row-sub card-tags">${p.where}</div>
    <div class="row-sub">${p.meta}</div>
    ${p.scores ? `<div class="l-score">${p.scores}</div>` : ""}
    <div class="card-foot">
      <span class="c-price"><b>${p.price}</b>${p.refunded ? `<span>${p.refunded}</span>` : ""}</span>
      <span class="row-act">${p.certs}
        <button class="btn-mini" onclick="openDrawer(${r.id})">상세</button></span>
    </div>
  </li>`;
}

// 열을 넷으로 나눠 계층을 세운다 — 상태 / 강의·메타 / 금액 / 액션.
// 예전엔 한 칸에 세 줄이 쌓이고 구분자가 다섯 개라 눈이 쉴 곳이 없었다.
function rowHTML(r) {
  const p = rowParts(r);
  return `<li class="row lrow${r.archived ? " dim" : ""}">
    <div class="c-state">
      ${gradeTag(r)}
      ${statusBadge(r)}
      ${railHTML(r, false, false)}
    </div>
    <div class="c-body">
      <div class="l-title">${p.title}</div>
      <div class="l-where">${p.where}</div>
      <div class="l-meta">${p.meta}</div>
      ${p.scores ? `<div class="l-score">${p.scores}</div>` : ""}
    </div>
    <div class="c-price">
      <b>${p.price}</b>
      ${p.refunded ? `<span>${p.refunded}</span>` : ""}
    </div>
    <div class="row-act">
      ${p.certs}
      <button class="btn-mini" onclick="openDrawer(${r.id})">상세</button>
    </div>
  </li>`;
}

// 구성원 화면의 두 탭. 현황 카드와 관리자 줄은 두 탭 모두에 남는다 —
// 어느 탭에 있든 내가 처리할 일의 개수는 같은 자리에서 보여야 한다.
function setPane(pane) {
  APP.view.pane = pane;
  APP.page.list = 1;
  buildFilters();   // 상태 칸의 뜻이 탭마다 다르다
  renderList();
}

function renderPaneTabs() {
  const undone = pendingRequired().length;
  const tabs = [
    ["list", "신청 목록", 0],
    ["catalog", "추천 · 필수 강의", undone],
  ];
  document.getElementById("paneTabs").innerHTML = tabs.map(([key, label, n]) =>
    `<button type="button" class="pane-tab${APP.view.pane === key ? " on" : ""}"
      onclick="setPane('${key}')">${esc(label)}
      ${n ? `<span class="pane-n">${n}</span>` : ""}</button>`).join("");
}

function renderList() {
  renderMyStrip();
  renderTodoRow();
  renderPolicyNote();
  renderPaneTabs();

  // 사이드바는 탭과 무관하게 늘 서 있다. 탭을 옮길 때 오른쪽이 사라지면 화면이 통째로
  // 흔들리고, 무엇이 없어진 건지 알 수 없다.
  document.getElementById("catPreview").innerHTML = catalogPreviewHTML();

  const cards0 = APP.view.layout === "card";
  document.getElementById("vList").classList.toggle("on", !cards0);
  document.getElementById("vCard").classList.toggle("on", cards0);

  if (APP.view.pane === "catalog") {
    const rows = visibleCatalog();
    document.getElementById("listCount").textContent =
      `${rows.length}건 / 전체 ${myCatalog().length}건`;
    document.getElementById("ledgerTitle").textContent = "추천 · 필수 강의";
    document.getElementById("catAlert").innerHTML = requiredAlertHTML();
    const board = document.getElementById("board");
    board.className = cards0 ? "cards" : "list";
    board.innerHTML = catalogTabHTML();
    document.getElementById("listPager").innerHTML = "";
    return;
  }
  document.getElementById("ledgerTitle").textContent = "신청 목록";
  document.getElementById("catAlert").innerHTML = "";

  const all = visibleRequests();
  const info = pageSlice(all, "list");
  document.getElementById("listCount").textContent =
    `${all.length}건 / 전체 ${APP.requests.length}건`;
  const cards = cards0;
  const board = document.getElementById("board");
  board.className = cards ? "cards" : "list";
  board.innerHTML = info.rows.length
    ? info.rows.map(cards ? cardHTML : rowHTML).join("")
    : `<li class="empty"><div class="big">신청이 없습니다</div>
       <div>오른쪽 위 '＋ 강의 신청'으로 시작하세요.</div></li>`;
  document.getElementById("listPager").innerHTML = all.length ? pagerHTML("list", info) : "";
}
