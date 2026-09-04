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

function renderAdminStats() {
  const box = document.getElementById("adminStats");
  if (!APP.me.is_admin) { box.style.display = "none"; return; }
  const count = s => APP.requests.filter(r => r.status === s && !r.archived).length;
  const cards = [
    ["수강승인 대기", count(S.REQUESTED), S.REQUESTED],
    ["청구승인 대기", count(S.CLAIMED), S.CLAIMED],
    ["환급 대기", count(S.CLAIM_APPROVED), S.CLAIM_APPROVED],
  ];
  box.style.display = "";
  box.innerHTML = cards.map(([label, n, st]) => `
    <button class="stat${APP.filter.status === st ? " on" : ""}"
      onclick="filterByStatus('${esc(st)}')">
      <b>${n}</b><span>${esc(label)}</span>
    </button>`).join("");
}

// 같은 카드를 다시 누르면 전체로 돌아간다 — 필터를 푸는 길이 카드 자신이다
function filterByStatus(st) {
  document.getElementById("f-status").value = APP.filter.status === st ? "" : st;
  applyFilters();
}

function renderPolicyNote() {
  const el = document.getElementById("policyNote");
  if (!APP.policy.partial_enabled) { el.style.display = "none"; return; }
  el.style.display = "";
  el.textContent = `수강료 중 ${won(APP.policy.partial_cap)}까지 환급됩니다. ` +
    "초과분은 본인 부담입니다.";
}

// 열을 넷으로 나눠 계층을 세운다 — 상태 / 강의·메타 / 금액 / 액션.
// 예전엔 한 칸에 세 줄이 쌓이고 구분자가 다섯 개라 눈이 쉴 곳이 없었다.
function rowHTML(r) {
  const titleText = esc(r.title);
  const title = r.url
    ? `<a href="${esc(r.url)}" target="_blank" rel="noopener">${titleText}</a>`
    : titleText;

  // 학습수준을 분류 뒤에 그냥 붙이면 중분류처럼 읽힌다 — 칩으로 끊는다
  const cat = [r.category_large, r.category_medium].filter(Boolean).join(" › ");
  const where = [
    siteBadge(r.site),
    cat ? `<span class="l-cat">${esc(cat)}</span>` : "",
    r.level ? `<span class="chip lv">${esc(r.level)}</span>` : "",
  ].filter(Boolean).join("");

  const meta = [
    whoHTML(r.applicant),
    durationText(r.duration_min),
    periodText(r),
  ].filter(Boolean).join('<span class="sep">·</span>');

  const scores = [
    r.rating != null ? `평가 ${starsHTML(r.rating, { small: true })}` : "",
    r.recommend != null ? `추천 ${starsHTML(r.recommend, { small: true })}` : "",
  ].filter(Boolean).join("");

  const refunded = !r.is_free && r.refund_amount && r.refund_amount !== r.price;

  return `<li class="row lrow${r.archived ? " dim" : ""}">
    <div class="c-state">
      ${statusBadge(r)}
      ${railHTML(r, false, false)}
    </div>
    <div class="c-body">
      <div class="l-title">${title}</div>
      <div class="l-where">${where}</div>
      <div class="l-meta">${meta}</div>
      ${scores ? `<div class="l-score">${scores}</div>` : ""}
    </div>
    <div class="c-price">
      <b>${esc(r.is_free ? "무료" : (r.price ? won(r.price) : "미입력"))}</b>
      ${refunded ? `<span>환급 ${won(r.refund_amount)}</span>` : ""}
    </div>
    <div class="row-act">
      ${r.cert_count ? `<span class="chip ghost">이수증 ${r.cert_count}</span>` : ""}
      <button class="btn-mini" onclick="openDrawer(${r.id})">상세</button>
    </div>
  </li>`;
}

function renderList() {
  renderAdminStats();
  renderPolicyNote();
  const all = visibleRequests();
  const info = pageSlice(all, "list");
  document.getElementById("listCount").textContent =
    `${all.length}건 / 전체 ${APP.requests.length}건`;
  document.getElementById("board").innerHTML = info.rows.length
    ? info.rows.map(rowHTML).join("")
    : `<li class="empty"><div class="big">신청이 없습니다</div>
       <div>오른쪽 위 '＋ 강의 신청'으로 시작하세요.</div></li>`;
  document.getElementById("listPager").innerHTML = all.length ? pagerHTML("list", info) : "";
}
