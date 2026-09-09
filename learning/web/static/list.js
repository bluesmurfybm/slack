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

  const status = document.getElementById("f-status");
  status.innerHTML = '<option value="">전체 상태</option>' +
    '<option value="무료">무료</option>' +
    STATUSES.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join("");
  selectValue(status, APP.filter.status);
}

function applyFilters() {
  APP.filter = {
    q: document.getElementById("f-q").value.trim(),
    site: document.getElementById("f-site").value,
    level: document.getElementById("f-level").value,
    status: document.getElementById("f-status").value,
    mine: document.getElementById("f-mine").checked,
  };
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

// 내 학습 현황 카드 — 흰 바탕에 파랑은 액센트로만 쓴다.
// 숫자 넷 중 첫 칸(수강 중)이 주역이라 그 칸만 강의 이름과 기간 진척을 함께 낸다.
const IN_PROGRESS = [S.REQUESTED, S.APPROVED, S.CLAIMED, S.CLAIM_APPROVED];

// 카드 아래 알림 줄 — 내가 움직여야 하는 것을 위에 둔다.
// 먼저 걸리는 하나만 낸다: 줄을 여러 개 쌓으면 무엇부터 할지가 다시 흐려진다.
// hue 는 이 알림이 가리키는 칸의 머리선 색이다 — 줄과 칸이 한 몸임을 색으로 잇는다.
// 반려만 예외로 발간을 쓴다: 반려는 어느 칸에도 세지 않는 상태이고,
// 그 하나는 색이 급함을 대신 말해줘야 하는 자리라서다.
const NOTICES = [
  { sts: [S.REJECTED, S.CLAIM_REJECTED], hue: "no",
    text: n => `반려된 신청 ${n}건이 있습니다` },
  { sts: [S.APPROVED], hue: "run",
    text: n => `수강 중인 신청 ${n}건 — 이수 후 수강료를 청구하세요` },
  { sts: [S.REQUESTED], hue: "run",
    text: n => `내 신청 ${n}건이 수강 승인 대기 중입니다` },
  { sts: [S.CLAIMED], hue: "run",
    text: n => `내 청구 ${n}건이 청구 승인 대기 중입니다` },
  // 곧 환급 완료로 넘어갈 건이라 "환급 완료" 칸의 초록을 쓴다
  { sts: [S.CLAIM_APPROVED], hue: "done",
    text: n => `환급을 기다리는 신청 ${n}건이 있습니다` },
];

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

function renderMyStrip() {
  const mine = APP.requests.filter(isMine);
  const n = f => mine.filter(f).length;
  const at = st => mine.filter(r => !r.is_free && r.status === st).length;
  const refunded = mine.filter(r => r.status === S.REFUNDED)
    .reduce((sum, r) => sum + (r.refund_amount || 0), 0);

  document.getElementById("msMine").classList.toggle("on", !!APP.filter.mine);
  renderStripHead(mine);
  renderStripNums(mine, n, refunded);
  renderStripNote(at);
}

// 머리 — 누구의 현황인지, 올해 어느 플랫폼을 쓰고 있는지
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
}

// 숫자 넷. 첫 칸만 진척 줄을 달고, 나머지는 값과 라벨만 낸다
function renderStripNums(mine, n, refunded) {
  const inflight = mine.filter(r => !r.is_free && IN_PROGRESS.includes(r.status));
  // hue 는 칸 머리선 색. 알림 줄이 이 색을 물려받아 어느 칸의 이야기인지 알린다
  const cols = [
    { label: "수강 중", value: inflight.length, unit: "건", hue: "run", lead: true },
    { label: "내 강좌", value: mine.length, unit: "건", hue: "all" },
    { label: "환급 완료", value: n(r => r.status === S.REFUNDED), unit: "건", hue: "done" },
    { label: "받은 환급액", value: refunded.toLocaleString("ko-KR"), unit: "원", hue: "paid" },
  ];

  // 기한이 가장 급한 것을 대표로 세운다 — 끝나는 날이 빠른 순
  const soonest = inflight.filter(r => r.end_date)
    .sort((a, b) => a.end_date.localeCompare(b.end_date))[0] || inflight[0];

  document.getElementById("msNums").innerHTML = cols.map(c => `
    <div class="ms-col ${c.hue}${c.lead ? " lead" : ""}">
      <span class="ms-lb">${esc(c.label)}</span>
      <span class="ms-vl"><b>${esc(c.value)}</b><i>${esc(c.unit)}</i></span>
      ${c.lead && soonest ? stripLead(soonest) : ""}
    </div>`).join("");
}

// 막대만 보면 무엇의 몇 %인지 알 수 없다 — 올렸을 때 말로 풀어준다.
// 목록 행의 rail 과 같은 .tip>em 툴팁을 쓴다: 이 화면의 설명 방식이 한 가지로 남게.
function periodTip(r, pct) {
  if (pct === 0) return "수강 기간이 아직 시작되지 않았습니다";
  if (pct === 100) return "수강 기간이 끝났습니다";
  return `수강 기간의 ${pct}%가 지났습니다`;
}

function stripLead(r) {
  const pct = periodPct(r);
  const end = monthDay(r.end_date);
  return `<div class="ms-lead">
    ${pct == null ? "" : `<span class="tip">
      <span class="ms-bar"><i style="width:${pct}%"></i></span>
      <em>${esc(periodTip(r, pct))}<br>${esc(periodText(r))}</em></span>`}
    <span class="ms-cap" title="${esc(r.title)}">${esc(r.title)}${
      end ? `<span class="ms-end"> · ${esc(end)} 종료</span>` : ""}</span>
  </div>`;
}

// 알림 줄 — 걸리는 것이 없으면 줄 자체를 내지 않는다
function renderStripNote(at) {
  const box = document.getElementById("msNote");
  const hit = NOTICES
    .map(nt => ({ nt, sts: nt.sts.filter(st => at(st)) }))
    .find(x => x.sts.length);

  if (!hit) { box.style.display = "none"; return; }

  const total = hit.sts.reduce((sum, st) => sum + at(st), 0);
  box.style.display = "";
  box.className = `ms-note ${hit.nt.hue}`;
  box.innerHTML = `<span class="ms-dot"></span>
    <span class="grow">${esc(hit.nt.text(total))}</span>
    <button type="button" class="ms-go" onclick="filterMine('${esc(hit.sts[0])}')">확인</button>`;
}

// 현황 카드 접기 — 매일 보는 화면이라 다 본 사람은 접어두고 목록부터 볼 수 있게 한다.
// 기본은 펼침이고, 고른 상태는 그 사람 브라우저에만 남는다(layout·page size 와 같은 방식).
const STRIP_KEY = "learning-strip-fold";

function applyStripFold(fold) {
  document.getElementById("myStrip").classList.toggle("fold", fold);
  document.getElementById("msFold").setAttribute("aria-expanded", String(!fold));
}

function toggleStrip() {
  const fold = !document.getElementById("myStrip").classList.contains("fold");
  applyStripFold(fold);
  try { localStorage.setItem(STRIP_KEY, fold ? "1" : "0"); } catch (e) { }
}

// 스크립트가 body 끝에서 돌므로 여기서 바로 DOM 을 만져도 된다
try {
  if (localStorage.getItem(STRIP_KEY) === "1") applyStripFold(true);
} catch (e) { }

// 관리자 알림 줄 — 구성원 화면에서도 처리할 일이 몇 건인지 한 줄로 알린다.
// 관리 탭의 대기 카드와 같은 숫자지만, 여기서는 "넘어갈 길"이 목적이라 카드가 아니라 줄로 둔다.
const ADMIN_TODO = [
  ["수강 승인", S.REQUESTED], ["청구 승인", S.CLAIMED], ["환급", S.CLAIM_APPROVED],
];

function renderAdminBar() {
  const box = document.getElementById("adminBar");
  if (!APP.me.is_admin) { box.style.display = "none"; return; }

  // 보관한 건은 처리 대상이 아니다 — 관리 탭의 대기 카드와 같은 셈법을 쓴다
  const n = st => APP.requests.filter(r => r.status === st && !r.archived).length;
  const todo = ADMIN_TODO.map(([label, st]) => [label, st, n(st)]);
  const total = todo.reduce((sum, [, , c]) => sum + c, 0);

  // 처리할 것이 없으면 줄을 내지 않는다 — 빈 알림은 알림이 아니다
  if (!total) { box.style.display = "none"; return; }

  box.style.display = "";
  box.innerHTML = `<span class="ab-tag">관리자</span>
    <b class="ab-sum">처리 대기 ${total}건</b>
    <span class="ab-chips">${todo.filter(([, , c]) => c).map(([label, st, c]) =>
      `<button type="button" class="ab-chip" onclick="goManage('${esc(st)}')"
        title="${esc(st)} 상태만 걸어서 신청 관리로">${esc(label)}<b>${c}</b></button>`).join("")}</span>
    <button type="button" class="ab-go" onclick="goManage('')">처리하러 가기 →</button>`;
}

// 신청 관리 탭으로 넘긴다 — 상태를 주면 그 상태만 걸어 둔 채로 연다
function goManage(st) {
  MANAGE.status = st || "";
  APP.page.manage = 1;
  setMode("admin");
  setTab("manage");
}

// "내 신청 내역" — 목록을 내 것만으로 좁힌다. 다시 누르면 전체로 돌아간다
function toggleMineOnly() {
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
    <div class="card-top">${statusBadge(r)}
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

function renderList() {
  renderMyStrip();
  renderAdminBar();
  renderPolicyNote();
  const all = visibleRequests();
  const info = pageSlice(all, "list");
  document.getElementById("listCount").textContent =
    `${all.length}건 / 전체 ${APP.requests.length}건`;
  const cards = APP.view.layout === "card";
  document.getElementById("vList").classList.toggle("on", !cards);
  document.getElementById("vCard").classList.toggle("on", cards);

  const board = document.getElementById("board");
  board.className = cards ? "cards" : "list";
  board.innerHTML = info.rows.length
    ? info.rows.map(cards ? cardHTML : rowHTML).join("")
    : `<li class="empty"><div class="big">신청이 없습니다</div>
       <div>오른쪽 위 '＋ 강의 신청'으로 시작하세요.</div></li>`;
  document.getElementById("listPager").innerHTML = all.length ? pagerHTML("list", info) : "";
}
