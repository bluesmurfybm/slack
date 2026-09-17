// 추천 · 필수 강의 — 구성원 탭과 관리자 패널을 함께 둔다.
// 신청 목록(list.js)과 섞지 않는다: 이쪽은 아직 아무도 신청하지 않은 카탈로그라
// 요청상태·환급액 같은 칸이 없고, 대신 등급·기한·내 상태가 축이다.

const G_REQ = "필수";
const G_PICK = "추천";

// 대상 범위 — 서버(routes/catalog.php)의 SCOPE_ALL / SCOPE_SOME 과 같은 문자열이어야 한다
const SC_ALL = "전사";
const SC_SOME = "지정";

const MY_NONE = "미신청";
const MY_WAIT = "승인 대기";
const MY_ING = "수강 중";
const MY_DONE = "이수 완료";

const MY_CLASS = {
  [MY_NONE]: "st-none", [MY_WAIT]: "st-wait", [MY_ING]: "st-ing", [MY_DONE]: "st-done",
};

/* ---------- 공용 ---------- */

// 오늘이 기한을 얼마나 남겼는지. 기한이 없으면 null
function dueLeft(c) {
  if (!c.due_date) return null;
  const end = new Date(`${c.due_date}T00:00:00`).getTime();
  return Math.round((end - new Date(new Date().toDateString()).getTime()) / 86400000);
}

// 남은 날에 따라 색이 바뀐다 — 급한 것만 눈에 걸리게
function ddayHTML(c) {
  const left = dueLeft(c);
  if (left == null) return "";
  if (left < 0) return `<span class="dday dd-ok">기한 종료</span>`;
  const cls = left <= 7 ? "dd-hot" : left <= 30 ? "dd-warn" : "dd-ok";
  return `<span class="dday ${cls}">${left === 0 ? "오늘 마감" : `D-${left}`}</span>`;
}

const gradeBadge = g =>
  `<span class="badge ${g === G_REQ ? "gr-req" : "gr-pick"}">${esc(g)}</span>`;

const catPath = c =>
  [c.category_large, c.category_medium].filter(Boolean).join(" › ");

function catMoney(c) {
  if (c.is_free) return "무료";
  return c.price ? won(c.price) : "미입력";
}

// 내가 대상인 필수 강의 중 아직 이수하지 않은 것
function pendingRequired() {
  return APP.catalog.filter(c =>
    c.grade === G_REQ && c.is_target && c.is_open && c.me.state !== MY_DONE);
}

/* ---------- 구성원 탭 ---------- */

// 신청 목록과 같은 틀(리스트/카드 + 같은 필터 줄)로 낸다. 필수·추천을 섹션으로 가르면
// 필터를 걸 때마다 두 덩이가 따로 움직여 무엇이 걸러졌는지 읽기 어렵다.
// 대신 정렬로 필수를 위에 올리고, 미이수 필수는 목록 위 알림 줄이 따로 짚는다.
function catalogTabHTML() {
  const rows = visibleCatalog();
  const all = myCatalog();

  if (!all.length) {
    return `<li class="empty"><div class="big">등록된 추천 강의가 없습니다</div>
      <div>관리자가 강의를 등록하면 여기에 보입니다.</div></li>`;
  }
  if (!rows.length) {
    return `<li class="empty"><div class="big">조건에 맞는 강의가 없습니다</div>
      <div>검색어나 필터를 바꿔 보세요.</div></li>`;
  }
  const cards = APP.view.layout === "card";
  return rows.map(cards ? catalogCardHTML : catalogRowHTML).join("");
}

// 내가 볼 수 있는 것 — 노출 중이고 내가 대상인 강의
const myCatalog = () => APP.catalog.filter(c => c.is_open && c.is_target);

// 필수가 먼저, 그다음 최근 등록순. 이미 이수한 건은 맨 뒤로 민다.
function visibleCatalog() {
  const { q, site, level, grade } = APP.filter;
  const needle = q.toLowerCase();
  return myCatalog().filter(c => {
    if (site && c.site !== site) return false;
    if (level && c.level !== level) return false;
    if (grade && c.grade !== grade) return false;
    if (!needle) return true;
    return [c.title, c.category_large, c.category_medium, c.reason]
      .some(v => (v || "").toLowerCase().includes(needle));
  }).sort((a, b) =>
    (a.me.state === MY_DONE) - (b.me.state === MY_DONE)
    || (b.grade === G_REQ) - (a.grade === G_REQ)
    || b.id - a.id);
}

// 미이수 필수가 있을 때만 — 없는 알림은 알림이 아니다
function requiredAlertHTML() {
  const undone = myCatalog().filter(c => c.grade === G_REQ && c.me.state !== MY_DONE);
  if (!undone.length) return "";
  const withDue = undone.filter(c => c.due_date).sort((a, b) => a.due_date.localeCompare(b.due_date));
  const head = withDue[0];
  const when = head
    ? ` · 가장 빠른 기한 ${esc(head.due_date)} (${dueLeft(head) < 0 ? "기한 종료"
        : `D-${dueLeft(head)}`})` : "";
  return `<div class="cat-alert">
    <span class="ca-ic">!</span>
    <span class="grow">아직 이수하지 않은 필수 강의가 ${undone.length}건 있습니다${when}</span>
  </div>`;
}

/* ---------- 목록의 행 · 카드 (신청 목록과 같은 틀) ---------- */

function catParts(c) {
  const req = c.grade === G_REQ;
  const cat = catPath(c);
  return {
    title: c.url
      ? `<a href="${esc(c.url)}" target="_blank" rel="noopener">${esc(c.title)}</a>`
      : esc(c.title),
    where: [
      siteBadge(c.site),
      cat ? `<span class="l-cat">${esc(cat)}</span>` : "",
      c.level ? `<span class="chip lv">${esc(c.level)}</span>` : "",
    ].filter(Boolean).join(""),
    meta: [
      durationText(c.duration_min),
      req && c.due_date ? `이수 기한 ${esc(c.due_date)}` : "",
      peersText(c),
    ].filter(Boolean).join('<span class="sep">·</span>'),
    price: esc(catMoney(c)),
  };
}

function catalogRowHTML(c) {
  const p = catParts(c);
  const req = c.grade === G_REQ;
  return `<li class="row lrow${c.me.state === MY_DONE ? " dim" : ""}">
    <div class="c-state">
      ${gradeBadge(c.grade)}
      ${req ? ddayHTML(c) : ""}
      <span class="badge ${MY_CLASS[c.me.state]}">${esc(c.me.state)}</span>
    </div>
    <div class="c-body">
      <div class="l-title">${p.title}</div>
      <div class="l-where">${p.where}</div>
      <div class="l-meta">${p.meta}</div>
      ${c.reason ? `<div class="l-why">
        <b>${req ? "지정 사유" : "추천 사유"}</b> ${esc(c.reason)}</div>` : ""}
    </div>
    <div class="c-price"><b>${p.price}</b></div>
    <div class="row-act">${catActionHTML(c)}</div>
  </li>`;
}

function catalogCardHTML(c) {
  const p = catParts(c);
  const req = c.grade === G_REQ;
  return `<li class="card${c.me.state === MY_DONE ? " dim" : ""}">
    <div class="card-top">${gradeBadge(c.grade)}${req ? ddayHTML(c) : ""}
      <span class="badge ${MY_CLASS[c.me.state]}">${esc(c.me.state)}</span></div>
    <div class="card-title">${p.title}</div>
    <div class="row-sub card-tags">${p.where}</div>
    <div class="row-sub">${p.meta}</div>
    ${c.reason ? `<div class="l-why">
      <b>${req ? "지정 사유" : "추천 사유"}</b> ${esc(c.reason)}</div>` : ""}
    <div class="card-foot">
      <span class="c-price"><b>${p.price}</b></span>
      <span class="row-act">${catActionHTML(c)}</span>
    </div>
  </li>`;
}

// 13명 회사에서 "누가 듣고 있는지"가 가장 센 동기다.
// 신청 목록이 이미 전원에게 공개돼 있으므로 새로 드러나는 정보는 없다.
function peersText(c) {
  const { done, ing } = c.peers;
  if (c.me.state === MY_DONE) {
    return `${esc(c.me.at)} 이수${c.me.rating ? ` · 내 평가 ${c.me.rating.toFixed(1)}` : ""}`;
  }
  if (c.me.state === MY_WAIT) return `${esc(c.me.at)} 신청 · 승인 대기 중`;
  if (c.me.state === MY_ING) return `${esc(c.me.at)} 신청 · 수강 중`;
  const parts = [];
  if (done) parts.push(`${done}명 이수`);
  if (ing) parts.push(`${ing}명 수강 중`);
  return parts.join(" · ") || "아직 신청자가 없습니다";
}

function catActionHTML(c) {
  if (c.me.state === MY_DONE) return `<span class="cat-done">✓ 이수 완료</span>`;
  if (c.me.state === MY_NONE) {
    return `<button class="btn-p-sm" onclick="applyCatalog(${c.id})">신청하기</button>`;
  }
  return `${c.url && c.me.state === MY_ING
      ? `<a class="btn-mini" href="${esc(c.url)}" target="_blank" rel="noopener">이어보기</a>` : ""}
    <button class="btn-mini" onclick="openDrawer(${c.me.request_id})">신청 보기</button>`;
}

/* ---------- 신청 ---------- */

// 강의 정보는 서버가 카탈로그 값으로 강제한다 — 폼은 잠그고 수강 기간·계정만 받는다
function applyCatalog(cid) {
  const c = APP.catalog.find(x => x.id === cid);
  if (!c) return;
  openForm(null, c);
}

/* ---------- 관리자 패널 ---------- */

let CAT_FILTER = { q: "", grade: "", open: false };

function catalogPanel() {
  const rows = adminCatalogRows();
  const req = APP.catalog.filter(c => c.grade === G_REQ);
  const open = APP.catalog.filter(c => c.is_open);
  const hot = req.filter(c => { const d = dueLeft(c); return c.is_open && d != null && d >= 0 && d <= 7; });
  const hotLeft = hot.reduce((n, c) => n + (c.target_count - c.done_count), 0);
  const rate = req.length
    ? Math.round(req.reduce((s, c) => s + (c.target_count ? c.done_count / c.target_count : 0), 0)
        / req.length * 100)
    : 0;

  return `<div class="adm">
    <div class="adm-head">
      <h4>추천 · 필수 강의
        <span class="muted">필수 ${req.length} · 추천 ${APP.catalog.length - req.length}</span>
      </h4>
      <button class="btn-submit adm-add" onclick="openCatalogForm()">＋ 강의 등록</button>
    </div>
    <div class="cat-tiles">
      <div class="cat-tile"><div class="k">노출 중</div><div class="v">${open.length}<small>건</small></div></div>
      <div class="cat-tile"><div class="k">필수 평균 이수율</div><div class="v">${rate}<small>%</small></div></div>
      <div class="cat-tile"><div class="k">기한 임박 (D-7 이내)</div>
        <div class="v">${hot.length}<small>건${hotLeft ? ` · 미이수 ${hotLeft}명` : ""}</small></div></div>
    </div>
    <div class="addbar cat-filters">
      <span class="cat-q">
        <input id="cat-q" placeholder="강의명으로 찾기" value="${esc(CAT_FILTER.q)}"
          oninput="setCatFilter('q', this.value)">
        <button type="button" id="cat-qx" class="cat-qx" title="지우기" aria-label="검색어 지우기"
          onclick="clearCatQuery()"${CAT_FILTER.q ? "" : ' style="display:none"'}>×</button>
      </span>
      <select id="cat-grade" onchange="setCatFilter('grade', this.value)">
        <option value="">전체 등급</option>
        <option value="${G_REQ}"${CAT_FILTER.grade === G_REQ ? " selected" : ""}>필수</option>
        <option value="${G_PICK}"${CAT_FILTER.grade === G_PICK ? " selected" : ""}>추천</option>
      </select>
      <label class="check"><input type="checkbox"${CAT_FILTER.open ? " checked" : ""}
        onchange="setCatFilter('open', this.checked)"> 노출 중만</label>
    </div>
    <div id="catList">${catalogListHTML(rows)}</div>
  </div>`;
}

function catalogListHTML(rows) {
  if (!rows.length) return `<p class="muted">조건에 맞는 강의가 없습니다.</p>`;
  return `<table class="cat-tb">
    <thead><tr><th style="width:56px">등급</th><th>강의</th><th style="width:150px">대상</th>
      <th style="width:110px">이수 기한</th><th style="width:170px">이수 현황</th>
      <th style="width:64px">노출</th><th style="width:96px"></th></tr></thead>
    <tbody>${rows.map(catRowHTML).join("")}</tbody></table>`;
}

function adminCatalogRows() {
  const { q, grade, open } = CAT_FILTER;
  const needle = q.trim().toLowerCase();
  return APP.catalog.filter(c => {
    if (grade && c.grade !== grade) return false;
    if (open && !c.is_open) return false;
    if (!needle) return true;
    return [c.title, c.category_large, c.category_medium, c.reason]
      .some(v => (v || "").toLowerCase().includes(needle));
  });
}

// 목록만 갈아끼운다. 패널 전체를 다시 그리면 입력칸이 교체돼 한글 조합(자음+모음)이
// 그 자리에서 끊기고, 커서 위치도 잃는다.
function setCatFilter(key, value) {
  CAT_FILTER[key] = value;
  const list = document.getElementById("catList");
  if (list) list.innerHTML = catalogListHTML(adminCatalogRows());
  const x = document.getElementById("cat-qx");
  if (x) x.style.display = CAT_FILTER.q ? "" : "none";
}

function clearCatQuery() {
  const el = document.getElementById("cat-q");
  el.value = "";
  setCatFilter("q", "");
  el.focus();
}

function catRowHTML(c) {
  const pct = c.target_count ? Math.round(c.done_count / c.target_count * 100) : 0;
  const bar = pct >= 100 ? "ok" : pct < 50 ? "low" : "";
  return `<tr${c.is_open ? "" : ' class="off"'}>
    <td>${gradeBadge(c.grade)}</td>
    <td>
      <div class="cat-tname">${esc(c.title)}</div>
      <div class="cat-tmeta">${siteBadge(c.site)}
        <span class="cat-path">${esc(catPath(c) || "—")}</span>
        ${c.level ? `<span class="cat-lv">${esc(c.level)}</span>` : ""}</div>
    </td>
    <td>${targetCellHTML(c)}</td>
    <td>${c.due_date
      ? `<div class="cat-tname sm">${esc(c.due_date)}</div>${ddayHTML(c)}`
      : '<span class="muted">—</span>'}</td>
    <td>${c.grade === G_REQ
      ? `<div class="cat-prog"><span class="pb ${bar}"><i style="width:${pct}%"></i></span>
         <b>${c.done_count} / ${c.target_count}</b><span class="muted">${pct}%</span></div>`
      : `<span class="muted">신청 ${c.done_count + c.peers.ing}건</span>`}</td>
    <td><button type="button" class="switch${c.is_open ? " on" : ""}" role="switch"
      aria-checked="${c.is_open}" aria-label="노출"
      onclick="toggleCatalog(${c.id}, ${c.active ? "false" : "true"})"></button></td>
    <td class="row-act">
      ${c.grade === G_REQ
        ? `<button class="btn-mini" onclick="openCompletion(${c.id})">현황</button>` : ""}
      <button class="btn-mini" onclick="openCatalogForm(${c.id})">수정</button>
    </td>
  </tr>`;
}

// 사람 아이콘. 숫자 옆에 두어 "눌러서 볼 수 있다"를 알린다.
const PEOPLE_ICON = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
  <path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>`;

// 대상이 '지정'이면 누가 대상인지 아이콘 옆 작은 팝으로 본다.
// 툴팁은 스쳐 지나가면 사라지고, 모달은 이름 두엇 보자고 열기엔 너무 크다.
function targetCellHTML(c) {
  const head = `<div class="cat-tname sm">${esc(c.target_scope)}</div>`;
  if (c.target_scope !== SC_SOME || !(c.target_emails || []).length) {
    return head + `<div class="muted">${c.target_count}명</div>`;
  }
  const byEmail = new Map((APP.admins?.members || []).map(m => [m.email.toLowerCase(), m.name]));
  const names = c.target_emails.map(e => byEmail.get(e) || e);

  return head + `<span class="tgt-wrap">
    <button type="button" class="tgt-btn" title="대상자 보기"
      onclick="toggleTargets(event, ${c.id})">${PEOPLE_ICON}<span>${names.length}명</span></button>
    <span class="tgt-pop" id="tgtPop-${c.id}">
      ${names.map(n => `<i>${esc(n)}</i>`).join("")}
    </span>
  </span>`;
}

function toggleTargets(e, cid) {
  // 바깥 클릭으로 닫는 핸들러가 문서에 걸려 있다 — 여기서 멈추지 않으면 열자마자 닫힌다
  e.stopPropagation();
  const el = document.getElementById(`tgtPop-${cid}`);
  const wasOpen = el.classList.contains("on");
  closeTargets();
  if (!wasOpen) el.classList.add("on");
}

function closeTargets() {
  for (const el of document.querySelectorAll(".tgt-pop.on")) el.classList.remove("on");
}

document.addEventListener("click", closeTargets);
document.addEventListener("keydown", e => { if (e.key === "Escape") closeTargets(); });

async function toggleCatalog(cid, on) {
  try {
    await putJSON(`/learningapi/catalog/${cid}`, { active: on });
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 이수 현황 ---------- */

async function openCompletion(cid) {
  let data;
  try {
    data = await api(`/learningapi/catalog/${cid}/completion`);
  } catch (e) { showToast(e.message); return; }

  const c = data.catalog;
  const done = data.people.filter(p => p.state === MY_DONE).length;
  document.getElementById("catsTitle").textContent = "이수 현황";
  document.getElementById("catsBody").innerHTML = `
    <div class="cat-cmph">
      <div class="cat-tname">${esc(c.title)}</div>
      <div class="muted">${esc(c.target_scope)} ${data.people.length}명 중
        <b>${done}명 이수</b>${c.due_date ? ` · 기한 ${esc(c.due_date)}` : ""}</div>
    </div>
    <ul class="list">${data.people.map(p => `<li class="row">
      <div class="row-main">
        <div class="row-title">${whoHTML(p.name)}
          <span class="muted">${esc(p.email)}</span></div>
      </div>
      <div class="row-act">
        ${p.at ? `<span class="when">${esc(p.at)}</span>` : ""}
        <span class="badge ${MY_CLASS[p.state]}">${esc(p.state)}</span>
      </div></li>`).join("")}</ul>`;
  document.getElementById("catsOverlay").classList.add("open");
}

/* ---------- 등록 · 수정 드로어 ---------- */

let CAT_EDIT_ID = null;

function openCatalogForm(cid) {
  const c = cid ? APP.catalog.find(x => x.id === cid) : null;
  CAT_EDIT_ID = c ? c.id : null;

  document.getElementById("cfTitle").textContent =
    c ? "추천 · 필수 강의 수정" : "추천 · 필수 강의 등록";
  document.getElementById("cfSave").textContent = c ? "수정" : "등록";

  fillSelect("cf-site", activeSiteNames(), c?.site ?? activeSiteNames()[0] ?? "");
  fillSelect("cf-level", APP.me.levels || [], c?.level ?? "", "선택");
  cfSiteChange({ large: c?.category_large, medium: c?.category_medium });

  document.getElementById("cf-title").value = c?.title || "";
  document.getElementById("cf-url").value = c?.url || "";
  document.getElementById("cf-price").value = c && !c.is_free ? (c.price || "") : "";
  document.getElementById("cf-free").checked = !!c?.is_free;
  document.getElementById("cf-reason").value = c?.reason || "";
  document.getElementById("cf-due").value = c?.due_date || "";
  document.getElementById("cf-from").value = c?.open_from || "";
  document.getElementById("cf-to").value = c?.open_to || "";
  document.getElementById("cf-sort").value = c?.sort_order ?? APP.catalog.length;
  document.getElementById("cf-active").checked = c ? !!c.active : true;
  buildCfDuration(c?.duration_min);

  pickGrade(c?.grade || G_PICK);
  pickScope(c?.target_scope || SC_ALL, c?.target_emails || []);
  cfFreeChange();
  document.getElementById("cfDelete").style.display = c ? "" : "none";

  document.getElementById("cfOverlay").classList.add("open");
  setTimeout(() => document.getElementById("cf-title").focus(), 60);
}

function closeCatalogForm() {
  document.getElementById("cfOverlay").classList.remove("open");
}

function buildCfDuration(min) {
  const m = Number(min) || 0;
  const hrs = Array.from({ length: 101 }, (_, i) => i);
  const mins = Array.from({ length: 12 }, (_, i) => i * 5);
  document.getElementById("cf-hours").innerHTML =
    hrs.map(x => `<option value="${x}">${x}시간</option>`).join("");
  document.getElementById("cf-minutes").innerHTML =
    mins.map(x => `<option value="${x}">${x}분</option>`).join("");
  document.getElementById("cf-hours").value = Math.floor(m / 60);
  document.getElementById("cf-minutes").value = Math.round((m % 60) / 5) * 5;
}

function cfSiteChange(pre) {
  const site = document.getElementById("cf-site").value;
  const larges = [...new Set(APP.categories
    .filter(c => c.site === site && c.active && !c.medium).map(c => c.large))];
  fillSelect("cf-large", larges, pre?.large ?? "", "선택");
  cfLargeChange(pre?.medium);
}

function cfLargeChange(pre) {
  const site = document.getElementById("cf-site").value;
  const large = document.getElementById("cf-large").value;
  const meds = APP.categories
    .filter(c => c.site === site && c.large === large && c.medium && c.active)
    .map(c => c.medium);
  fillSelect("cf-medium", meds, pre ?? "", "선택");
}

function cfFreeChange() {
  const free = document.getElementById("cf-free").checked;
  const price = document.getElementById("cf-price");
  price.disabled = free;
  if (free) price.value = "";
}

let CF_GRADE = G_PICK;
function pickGrade(v) {
  CF_GRADE = v;
  document.querySelectorAll("#cf-grade button").forEach(b =>
    b.classList.toggle("on", b.dataset.v === v));
  // 필수는 기한이 있어야 필수다 — 없으면 그냥 강한 추천일 뿐이다
  document.getElementById("cfDueWrap").classList.toggle("need", v === G_REQ);
  document.getElementById("cfGradeNote").style.display = v === G_REQ ? "" : "none";
}

let CF_SCOPE = SC_ALL;
function pickScope(v, emails) {
  CF_SCOPE = v;
  document.querySelectorAll("#cf-scope button").forEach(b =>
    b.classList.toggle("on", b.dataset.v === v));
  const box = document.getElementById("cfTargets");
  box.style.display = v === SC_SOME ? "" : "none";
  if (emails) {
    box.innerHTML = (APP.admins?.members || []).map(m => `
      <label class="check"><input type="checkbox" value="${esc(m.email)}"
        ${emails.includes(m.email.toLowerCase()) ? "checked" : ""}> ${esc(m.name)}</label>`).join("");
  }
}

function readCatalogForm() {
  const free = document.getElementById("cf-free").checked;
  const hours = Number(document.getElementById("cf-hours").value) || 0;
  const minutes = Number(document.getElementById("cf-minutes").value) || 0;
  const targets = [...document.querySelectorAll("#cfTargets input:checked")].map(x => x.value);
  return {
    site: document.getElementById("cf-site").value,
    category_large: document.getElementById("cf-large").value,
    category_medium: document.getElementById("cf-medium").value,
    level: document.getElementById("cf-level").value,
    title: document.getElementById("cf-title").value.trim(),
    url: document.getElementById("cf-url").value.trim(),
    duration_min: hours * 60 + minutes,
    is_free: free,
    price: free ? 0 : (Number(document.getElementById("cf-price").value) || 0),
    grade: CF_GRADE,
    reason: document.getElementById("cf-reason").value.trim(),
    due_date: document.getElementById("cf-due").value,
    target_scope: CF_SCOPE,
    target_emails: targets,
    open_from: document.getElementById("cf-from").value,
    open_to: document.getElementById("cf-to").value,
    sort_order: Number(document.getElementById("cf-sort").value) || 0,
    active: document.getElementById("cf-active").checked,
  };
}

async function saveCatalogForm() {
  const body = readCatalogForm();
  if (!body.title) { showToast("강의명을 입력해 주세요"); return; }
  if (!body.reason) { showToast("사유를 입력해 주세요 — 카드에 그대로 노출됩니다"); return; }
  if (body.grade === G_REQ && !body.due_date) { showToast("필수 강의는 이수 기한이 필요합니다"); return; }
  try {
    if (CAT_EDIT_ID) await putJSON(`/learningapi/catalog/${CAT_EDIT_ID}`, body);
    else await postJSON("/learningapi/catalog", body);
    closeCatalogForm();
    showToast(CAT_EDIT_ID ? "수정했습니다" : "등록했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function deleteCatalog() {
  if (!CAT_EDIT_ID) return;
  if (!await askConfirm("강의 삭제",
    "목록에서 완전히 지웁니다. 신청한 사람이 있으면 지워지지 않습니다.", "삭제")) return;
  try {
    await api(`/learningapi/catalog/${CAT_EDIT_ID}`, { method: "DELETE" });
    closeCatalogForm();
    showToast("삭제했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 첫 화면 오른쪽 사이드바 ---------- */

// 카탈로그 탭까지 들어가야만 보이면 아무도 안 본다 — 학습 현황 옆에 세워 둔다.
const SIDE_N = 2;          // 처음 보여줄 장수. 나머지는 접어 둔다
let SIDE_OPEN = false;

function catalogPreviewHTML() {
  const mine = APP.catalog.filter(c => c.is_open && c.is_target);

  // 필수가 먼저, 그다음 최근 등록순. 이미 이수한 건은 뺀다 —
  // 여기는 "아직 안 들은 것을 권하는 자리"다.
  const rows = mine
    .filter(c => c.me.state !== MY_DONE)
    .sort((a, b) => (b.grade === G_REQ) - (a.grade === G_REQ) || b.id - a.id);

  const shown = SIDE_OPEN ? rows : rows.slice(0, SIDE_N);
  const rest = rows.length - shown.length;

  // 보여 줄 게 없어도 박스와 제목은 남긴다. 통째로 사라지면 왼쪽 칸만 덩그러니 남아
  // 화면이 무너진 것처럼 보이고, 이런 자리가 있다는 것 자체를 알 수 없다.
  return `<div class="cat-side">
    <div class="cs-head">
      <b>추천 · 필수 강의</b>
      ${rows.length ? `<span class="cs-n">${rows.length}</span>` : ""}
      ${mine.length
        ? `<button type="button" class="cs-more" onclick="setPane('catalog')">전체 보기 →</button>`
        : ""}
    </div>
    ${rows.length ? `${shown.map(csItemHTML).join("")}
      ${rest > 0 || SIDE_OPEN ? `<button type="button" class="cs-fold" onclick="toggleSide()">
        ${SIDE_OPEN ? "접기 ⌃" : `${rest}건 더 보기 ⌄`}</button>` : ""}`
      : csEmptyHTML(mine.length > 0)}
  </div>`;
}

// 두 가지 빈 상태를 가른다. 아직 아무것도 지정되지 않은 것과, 지정된 걸 다 들은 것은
// 사용자가 할 일이 다르다 — 전자는 기다리면 되고, 후자는 이미 끝냈다는 뜻이다.
function csEmptyHTML(allDone) {
  return `<div class="cs-empty">
    <span class="cse-ic">${allDone
      ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>`
      : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5Z"/>
           <path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/></svg>`}</span>
    <b>${allDone ? "권장 강의를 모두 이수했습니다" : "지정된 추천 · 필수 강의가 없습니다"}</b>
    <span>${allDone
      ? "새 강의가 등록되면 여기에 표시됩니다"
      : "관리자가 강의를 등록하면 여기에 표시됩니다"}</span>
  </div>`;
}

function toggleSide() {
  SIDE_OPEN = !SIDE_OPEN;
  document.getElementById("catPreview").innerHTML = catalogPreviewHTML();
}

function csItemHTML(c) {
  const req = c.grade === G_REQ;
  const mine = c.me.state !== MY_NONE;
  return `<div class="cs-i${req ? " req" : ""}">
    <div class="cs-top">
      ${gradeBadge(c.grade)}
      ${req ? ddayHTML(c) : `<span class="muted">${esc(c.site)} ·
        ${esc(durationText(c.duration_min) || "시간 미정")}</span>`}
      <span class="cs-price">${esc(catMoney(c))}</span>
    </div>
    <div class="cs-ttl">${esc(c.title)}</div>
    ${req ? `<div class="cs-meta">${siteBadge(c.site)}
      <span class="muted">${esc(durationText(c.duration_min) || "시간 미정")}</span></div>` : ""}
    ${mine
      ? `<div class="cs-state"><span class="badge ${MY_CLASS[c.me.state]}">${esc(c.me.state)}</span>
         ${c.url ? `<a class="btn-mini" href="${esc(c.url)}" target="_blank"
            rel="noopener">이어보기</a>` : ""}</div>`
      : `<button class="cs-go${req ? " req" : ""}"
          onclick="applyCatalog(${c.id})">신청하기</button>`}
  </div>`;
}

/* ---------- 신청 행에 붙는 등급 배지 ---------- */

// 서버가 신청 응답에 catalog_grade 를 실어 준다 — 대상에서 빠진 뒤의 지난 신청에도 남는다
function gradeTag(r) {
  return r.catalog_grade ? gradeBadge(r.catalog_grade) : "";
}
