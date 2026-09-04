const ADMIN_TABS = ["manage", "categories", "settings", "stats", "archive"];

function setTab(tab) {
  APP.view.tab = tab;
  document.querySelectorAll("#adminTabs button").forEach((b, i) =>
    b.classList.toggle("on", ADMIN_TABS[i] === tab));
  renderAdmin();
}

function renderAdmin() {
  const panel = document.getElementById("adminPanel");
  panel.innerHTML = {
    manage: managePanel, categories: categoriesPanel, settings: settingsPanel,
    archive: archivePanel, stats: statsPanel,
  }[APP.view.tab]();
  if (APP.view.tab === "categories") {
    document.getElementById("cat-site").value = ADMIN_SITE || activeSiteNames()[0] || "";
  }
}

/* ---------- 설정 = 교육 플랫폼 + 환급 정책 ---------- */

// 둘은 서로 무관한 기능이라 각각 라운드 박스로 끊어 둔다
function settingsPanel() {
  return sitesPanel() + policyPanel() + adminsPanel();
}

/* ---------- 관리자 명단 ---------- */

function adminsPanel() {
  const a = APP.admins;
  if (!a) return "";
  const rows = a.members.map(m => `
    <li class="row">
      <div class="addbar">
        ${whoHTML(m.name)}
        <span class="muted grow">${esc(m.email)}</span>
        ${m.is_owner ? '<span class="badge st-approved">최고 관리자 · 고정</span>' : ""}
      </div>
      <div class="row-act">
        <label class="check">
          <input type="checkbox" id="adm-${esc(m.email)}"
            ${m.is_admin ? "checked" : ""}
            ${m.is_owner || !a.can_manage ? "disabled" : ""}> 관리자
        </label>
      </div>
    </li>`).join("");

  const foot = a.can_manage
    ? `<div class="addbar" style="margin-top:14px">
        <span class="muted grow">체크한 사람이 관리자가 됩니다. 해제한 사람도 같이 저장됩니다.</span>
        <button class="btn-submit" style="height:34px;padding:0 14px;font-size:13px"
          onclick="saveAdmins()">관리자 저장</button>
      </div>`
    : `<p class="muted" style="margin-top:12px">
        관리자 명단은 최고 관리자만 바꿀 수 있습니다.
      </p>`;

  return `<div class="adm">
    <h4>관리자</h4>
    <p class="muted" style="margin:-6px 0 12px">
      관리자는 수강승인·청구승인·환급완료와 설정을 다룰 수 있습니다.
      최고 관리자는 코드에 고정돼 있어 해제할 수 없습니다.
    </p>
    <ul class="list">${rows}</ul>
    ${foot}
  </div>`;
}

async function saveAdmins() {
  const emails = APP.admins.members
    .filter(m => m.is_owner || document.getElementById(`adm-${m.email}`)?.checked)
    .map(m => m.email);
  try {
    APP.admins = await putJSON("/learningapi/admins", { emails });
    showToast(`관리자 ${emails.length}명을 저장했습니다`);
    await loadAll();
  } catch (e) { showToast(e.message); }
}

function sitesPanel() {
  const rows = APP.sites.map(s => `
    <li class="row">
      <div class="addbar">
        <input class="grow" id="site-name-${s.id}" value="${esc(s.name)}">
        <input class="grow" id="site-url-${s.id}" value="${esc(s.url)}" placeholder="https://">
        ${s.active ? "" : '<span class="badge off">비활성</span>'}
      </div>
      <div class="row-act">
        <button class="btn-mini" onclick="saveSite(${s.id})">저장</button>
        ${s.active
      ? `<button class="btn-mini danger" onclick="toggleSite(${s.id},false)">비활성</button>`
      : `<button class="btn-mini" onclick="toggleSite(${s.id},true)">활성</button>`}
      </div>
    </li>`).join("");
  return `<div class="adm">
    <h4>교육 플랫폼</h4>
    <ul class="list">${rows}</ul>
    <div class="addbar">
      <input class="grow" id="new-site-name" placeholder="플랫폼 이름">
      <input class="grow" id="new-site-url" placeholder="https://">
      <button class="btn-mini" onclick="addSite()">추가</button>
    </div>
    <p class="muted" style="margin-top:10px">
      비활성으로 내리면 신청 화면에서 고를 수 없습니다. 지난 신청 건의 표시는 그대로 남습니다.
    </p>
  </div>`;
}

async function addSite() {
  const name = document.getElementById("new-site-name").value.trim();
  const url = document.getElementById("new-site-url").value.trim();
  if (!name) { showToast("플랫폼 이름을 입력해 주세요"); return; }
  try {
    await postJSON("/learningapi/sites", { name, url, sort_order: APP.sites.length });
    showToast("추가했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function saveSite(sid) {
  try {
    await putJSON(`/learningapi/sites/${sid}`, {
      name: document.getElementById(`site-name-${sid}`).value.trim(),
      url: document.getElementById(`site-url-${sid}`).value.trim(),
    });
    showToast("저장했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function toggleSite(sid, on) {
  try {
    if (on) await putJSON(`/learningapi/sites/${sid}`, { active: true });
    else await api(`/learningapi/sites/${sid}`, { method: "DELETE" });
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 강의 분류 ---------- */

let ADMIN_SITE = "";

function pickCatSite() {
  ADMIN_SITE = document.getElementById("cat-site").value;
  renderAdmin();
}

function categoriesPanel() {
  const site = ADMIN_SITE || activeSiteNames()[0] || "";
  ADMIN_SITE = site;
  const mine = APP.categories.filter(c => c.site === site)
    .sort((a, b) => a.sort_order - b.sort_order);
  const larges = mine.filter(c => !c.medium);

  const blocks = larges.map(l => {
    const kids = mine.filter(c => c.medium && c.large === l.large);
    const kidRows = kids.map(c => catRow(c, true)).join("");
    return `<div class="adm">
      <ul class="list">${catRow(l, false)}${kidRows}</ul>
      <div class="addbar">
        <input class="grow" id="new-med-${l.id}" placeholder="중분류 이름">
        <button class="btn-mini" onclick="addMedium(${l.id})">중분류 추가</button>
      </div>
    </div>`;
  }).join("");

  const picked = mine.filter(c => c.recommended).length;
  return `<div class="adm">
    <h4>강의 분류</h4>
    <div class="addbar">
      <select id="cat-site" onchange="pickCatSite()">
        ${APP.sites.map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join("")}
      </select>
      <input class="grow" id="new-large" placeholder="대분류 이름">
      <button class="btn-mini" onclick="addLarge()">대분류 추가</button>
      <button class="btn-mini" onclick="openCategoryBrowser(false)">📚 한눈에 보기</button>
    </div>
    <div class="addbar" style="margin-top:12px">
      <span class="muted grow">추천 <b id="recCount">${picked}</b>개 선택됨 —
        체크만 해두고 여기서 한 번에 저장합니다. 저장하지 않으면 반영되지 않습니다.</span>
      <button class="btn-mini" onclick="clearRecommended()">전체 해제</button>
      <button class="btn-submit" style="height:34px;padding:0 14px;font-size:13px"
        onclick="saveRecommended()">추천 일괄 저장</button>
    </div>
  </div>${blocks || '<div class="adm"><p class="muted">분류가 없습니다.</p></div>'}`;
}

function catRow(c, isMedium) {
  const field = isMedium ? "medium" : "large";
  return `<li class="row">
    <div class="addbar">
      ${isMedium ? '<span class="muted" style="width:14px">└</span>'
      : '<span class="badge st-approved">대분류</span>'}
      <input class="grow" id="cat-${c.id}" value="${esc(c[field])}">
      <label class="check"><input type="checkbox" id="rec-${c.id}"
        ${c.recommended ? "checked" : ""} onchange="countRecommended()"> 추천</label>
      ${c.active ? "" : '<span class="badge off">비활성</span>'}
    </div>
    <div class="row-act">
      <button class="btn-mini" onclick="saveCategory(${c.id}, '${field}')">저장</button>
      ${c.active
      ? `<button class="btn-mini danger" onclick="toggleCategory(${c.id},false)">비활성</button>`
      : `<button class="btn-mini" onclick="toggleCategory(${c.id},true)">활성</button>`}
    </div>
  </li>`;
}

const addLarge = () => addCategory("new-large", "");
// 대분류 이름을 onclick 문자열에 심으면 따옴표가 든 이름에서 깨진다 — id 로 찾아 쓴다
const addMedium = largeId => addCategory(`new-med-${largeId}`,
  APP.categories.find(c => c.id === largeId).large);

async function addCategory(inputId, large) {
  const input = document.getElementById(inputId);
  const value = input.value.trim();
  if (!value) { showToast("이름을 입력해 주세요"); return; }
  try {
    await postJSON("/learningapi/categories", {
      site: ADMIN_SITE,
      large: large || value,
      medium: large ? value : "",
      sort_order: APP.categories.length,
    });
    showToast("추가했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function saveCategory(cid, field) {
  try {
    await putJSON(`/learningapi/categories/${cid}`, {
      [field]: document.getElementById(`cat-${cid}`).value.trim(),
      recommended: document.getElementById(`rec-${cid}`).checked,
    });
    showToast("저장했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function toggleCategory(cid, on) {
  try {
    if (on) await putJSON(`/learningapi/categories/${cid}`, { active: true });
    else await api(`/learningapi/categories/${cid}`, { method: "DELETE" });
    await reload();
  } catch (e) { showToast(e.message); }
}

function recommendBoxes() {
  return [...document.querySelectorAll('#adminPanel input[id^="rec-"]')];
}

function countRecommended() {
  const el = document.getElementById("recCount");
  if (el) el.textContent = recommendBoxes().filter(b => b.checked).length;
}

function clearRecommended() {
  recommendBoxes().forEach(b => { b.checked = false; });
  countRecommended();
}

async function saveRecommended() {
  // 화면에 보이는 체크 상태를 그대로 반영한다 — 해제한 것도 같이 저장된다
  const ids = recommendBoxes().filter(b => b.checked)
    .map(b => Number(b.id.slice("rec-".length)));
  try {
    await putJSON("/learningapi/categories/recommended", { site: ADMIN_SITE, ids });
    showToast(`추천 ${ids.length}개를 저장했습니다`);
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 환급 정책 ---------- */

const POLICY_ROWS = [
  ["partial_enabled", "partial_cap", "부분환급", "건당 상한까지만 환급합니다. 상한 이하는 전액입니다.", "원"],
  ["annual_amount_enabled", "annual_amount_limit", "연간 환급 한도", "1인이 한 해에 받을 수 있는 총액입니다.", "원"],
  ["annual_count_enabled", "annual_count_limit", "연간 신청 건수", "1인이 한 해에 신청할 수 있는 건수입니다.", "건"],
  ["claim_deadline_enabled", "claim_deadline_days", "청구 기한", "강의 종료일로부터 며칠 안에 청구해야 하는지입니다.", "일"],
];

function policyPanel() {
  const p = APP.policy;
  const rows = POLICY_ROWS.map(([flag, value, label, hint, unit]) => `
    <div class="polrow">
      <label class="check"><input type="checkbox" id="p-${flag}"
        ${p[flag] ? "checked" : ""} onchange="onPolicyToggle('${flag}','${value}')"> 사용</label>
      <span class="lbl"><b>${esc(label)}</b><span>${esc(hint)}</span></span>
      <input type="number" min="0" id="p-${value}" value="${p[value] || ""}"
        ${p[flag] ? "" : "disabled"}>
      <span class="muted">${esc(unit)}</span>
    </div>`).join("");
  return `<div class="adm">
    <h4>환급 정책</h4>
    ${rows}
    <div class="addbar" style="margin-top:14px">
      <button class="btn-submit" onclick="savePolicy()">저장</button>
      <span class="muted">${p.updated_at ? `마지막 변경 ${esc(p.updated_at)} · ${esc(p.updated_by || "")}` : "아직 바꾼 적이 없습니다"}</span>
    </div>
    <p class="muted" style="margin-top:10px">
      꺼 두면 그 항목은 검사하지 않습니다. 기본값은 네 항목 모두 꺼짐입니다.
    </p>
  </div>`;
}

function onPolicyToggle(flag, value) {
  document.getElementById(`p-${value}`).disabled =
    !document.getElementById(`p-${flag}`).checked;
}

async function savePolicy() {
  const body = {};
  for (const [flag, value] of POLICY_ROWS) {
    body[flag] = document.getElementById(`p-${flag}`).checked;
    body[value] = Number(document.getElementById(`p-${value}`).value) || 0;
  }
  try {
    APP.policy = await putJSON("/learningapi/policy", body);
    showToast("정책을 저장했습니다");
    renderAdmin();
    renderPolicyNote();
  } catch (e) { showToast(e.message); }
}

/* ---------- 보관함 ---------- */

function archivePanel() {
  const rows = APP.requests.filter(r => r.archived);
  return `<div class="adm">
    <h4>보관함</h4>
    <ul class="list">${rows.length ? rows.map(r => `
      <li class="row">
        <div class="row-main">
          <div class="row-title">
            ${siteBadge(r.site)}
            <button class="linkish" onclick="openDrawer(${r.id})">${esc(r.title)}</button>
          </div>
          <div class="row-sub">${statusBadge(r)}<span class="sep">·</span>
            ${whoHTML(r.applicant)}<span class="sep">·</span>
            <span class="when">${esc(r.created_at)}</span></div>
        </div>
        <div class="row-act">
          <button class="btn-mini" onclick="unarchive(${r.id})">보관 해제</button>
        </div>
      </li>`).join("")
      : '<li class="empty"><div class="big">보관한 신청이 없습니다</div></li>'}
    </ul>
  </div>`;
}

async function unarchive(rid) {
  try {
    await postJSON(`/learningapi/requests/${rid}/unarchive`);
    showToast("보관을 풀었습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}
