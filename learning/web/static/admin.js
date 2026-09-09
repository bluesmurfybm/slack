// 좌측 사이드바 메뉴. 성격으로 묶는다 — 매일 여는 운영, 월 단위로 보는 리포트,
// 분기에 한 번 만지는 구성. 예전 가로 탭은 이 셋을 같은 무게로 늘어놓고 있었다.
// 설정 한 페이지에 세로로 쌓여 있던 플랫폼·정책·관리자는 각자 메뉴가 됐다.
const ADMIN_NAV = [
  ["운영", [
    ["manage", "신청 관리", "▤", true],
    ["archive", "보관함", "▣"],
  ]],
  ["리포트", [
    ["stats", "통계", "◨"],
  ]],
  ["구성", [
    ["categories", "강의 분류", "≡"],
    ["sites", "교육 플랫폼", "◈"],
    ["policy", "환급 정책", "₩"],
    ["admins", "관리자", "◉"],
  ]],
];

const ADMIN_PANELS = {
  manage: managePanel, archive: archivePanel, stats: statsPanel,
  categories: categoriesPanel, sites: sitesPanel, policy: policyPanel,
  admins: adminsPanel,
};

// 처리 대기 건수 — 어느 메뉴에 있어도 사이드바에 상주한다.
// 구성원 화면의 알림 줄과 같은 셈법이라 두 곳의 숫자가 어긋나지 않는다.
function adminTodoCount() {
  return APP.requests.filter(r => !r.archived &&
    [S.REQUESTED, S.CLAIMED, S.CLAIM_APPROVED].includes(r.status)).length;
}

function renderAdminNav() {
  const todo = adminTodoCount();
  document.getElementById("adminNav").innerHTML = ADMIN_NAV.map(([group, items]) =>
    `<div class="nav-grp">${esc(group)}</div>` + items.map(([key, label, icon, badge]) =>
      `<button type="button" class="${APP.view.tab === key ? "on" : ""}"
        onclick="setTab('${key}')">
        <span class="ic" aria-hidden="true">${icon}</span>
        <span class="nm">${esc(label)}</span>
        ${badge && todo ? `<span class="bdg">${todo}</span>` : ""}
      </button>`).join("")).join("");
}

function setTab(tab) {
  APP.view.tab = tab;
  renderAdmin();
}

function renderAdmin() {
  const panel = ADMIN_PANELS[APP.view.tab] || managePanel;
  renderAdminNav();
  document.getElementById("adminPanel").innerHTML = panel();
  if (APP.view.tab === "categories") {
    document.getElementById("cat-site").value = ADMIN_SITE || activeSiteNames()[0] || "";
  }
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
let CAT_LARGE = 0;

// 저장 전 변경분. 행마다 저장 버튼을 두지 않는 이유는 중분류가 40개면 저장 버튼도 40개가 되고,
// 무엇이 저장됐는지 추적이 안 되기 때문이다. 이름과 추천만 모아 두고 한 번에 보낸다.
// (노출/숨김은 곧바로 처리한다 — 되돌리기에 담기면 지금 상태가 무엇인지 헷갈린다.)
let CAT_EDIT = { name: {}, rec: {} };

const catDirty = () => Object.keys(CAT_EDIT.name).length + Object.keys(CAT_EDIT.rec).length;

// 화면에 낼 이름·추천은 "저장 전 값이 있으면 그것"이다 — 목록과 편집칸이 같은 규칙을 쓴다
const catName = c => (c.id in CAT_EDIT.name ? CAT_EDIT.name[c.id] : (c.medium || c.large));
const catRec = c => (c.id in CAT_EDIT.rec ? CAT_EDIT.rec[c.id] : !!c.recommended);

function resetCatEdit() {
  CAT_EDIT = { name: {}, rec: {} };
}

function pickCatSite() {
  ADMIN_SITE = document.getElementById("cat-site").value;
  CAT_LARGE = 0;
  resetCatEdit();
  renderAdmin();
}

function pickLarge(id) {
  CAT_LARGE = id;
  renderAdmin();
}

// 이름은 입력 중에 담아만 둔다 — 글자마다 서버를 부르지 않는다
function editCatName(id, el) {
  const row = APP.categories.find(c => c.id === id);
  const field = row.medium ? "medium" : "large";
  if (el.value.trim() === (row[field] || "")) delete CAT_EDIT.name[id];
  else CAT_EDIT.name[id] = el.value.trim();
  paintCatBar(el.closest(".crow"));
}

function toggleCatRec(id) {
  const row = APP.categories.find(c => c.id === id);
  const next = !catRec(row);
  if (next === !!row.recommended) delete CAT_EDIT.rec[id];
  else CAT_EDIT.rec[id] = next;
  renderAdmin();
}

// 저장 바만 다시 그린다 — 입력 중에 renderAdmin 을 부르면 커서가 날아간다
function paintCatBar(rowEl) {
  const bar = document.getElementById("catSaveBar");
  if (bar) bar.innerHTML = catSaveBarInner();
  if (rowEl) rowEl.classList.toggle("edited", Number(rowEl.dataset.cid) in CAT_EDIT.name);
}

function catSaveBarInner() {
  const names = Object.keys(CAT_EDIT.name).length;
  const recs = Object.keys(CAT_EDIT.rec).length;
  const parts = [names ? `이름 ${names}` : "", recs ? `추천 ${recs}` : ""]
    .filter(Boolean).join(", ");
  const off = parts ? "" : "disabled";
  return `<span class="grow">${parts
    ? `변경 ${names + recs}건 — ${esc(parts)}`
    : "바뀐 것이 없습니다"}</span>
    <button class="btn-mini" ${off} onclick="revertCats()">되돌리기</button>
    <button class="btn-mini primary" ${off} onclick="saveCats()">저장</button>`;
}

function revertCats() {
  resetCatEdit();
  renderAdmin();
}

// 이름은 건별 PUT, 추천은 사이트 단위 PUT 하나로 보낸다(서버가 목록 상태 그대로 맞춘다)
async function saveCats() {
  const site = ADMIN_SITE;
  const total = catDirty();
  try {
    for (const [id, value] of Object.entries(CAT_EDIT.name)) {
      if (!value) throw new Error("이름은 비워 둘 수 없습니다");
      const row = APP.categories.find(c => c.id === Number(id));
      await putJSON(`/learningapi/categories/${id}`,
        { [row.medium ? "medium" : "large"]: value });
    }
    if (Object.keys(CAT_EDIT.rec).length) {
      const ids = APP.categories.filter(c => c.site === site)
        .filter(catRec).map(c => c.id);
      await putJSON("/learningapi/categories/recommended", { site, ids });
    }
    resetCatEdit();
    showToast(`${total}건을 저장했습니다`);
    await reload();
  } catch (e) { showToast(e.message); }
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

// 숨기기(비활성)와 삭제는 다른 일이다. 숨기면 새 신청에서 안 보이지만 지난 신청의
// 분류 이름은 남고, 삭제는 분류 자체를 없앤다 — 그래서 쓰는 중이면 서버가 막는다.
async function purgeCategory(cid) {
  const c = APP.categories.find(x => x.id === cid);
  if (!c) return;
  const isLarge = !c.medium;
  const kids = isLarge
    ? APP.categories.filter(x => x.site === c.site && x.large === c.large && x.medium).length
    : 0;
  const ok = await askConfirm(
    isLarge ? "대분류 삭제" : "중분류 삭제",
    `“${c.medium || c.large}” 을(를) 지웁니다.` +
    (kids ? ` 아래 중분류 ${kids}개도 함께 사라집니다.` : "") +
    " 이 분류를 쓰는 신청이 있으면 지워지지 않습니다.",
    "삭제");
  if (!ok) return;
  try {
    const got = await api(`/learningapi/categories/${cid}/purge`, { method: "DELETE" });
    delete CAT_EDIT.name[cid];
    delete CAT_EDIT.rec[cid];
    if (isLarge) CAT_LARGE = 0;
    showToast(`${got.deleted}개를 지웠습니다`);
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

/* ---------- 강의 분류 화면 ---------- */

function catRow(c) {
  const rec = catRec(c);
  return `<div class="crow${c.id in CAT_EDIT.name ? " edited" : ""}${
    c.active ? "" : " off"}" data-cid="${c.id}">
    <input class="nm" id="cat-${c.id}" value="${esc(catName(c))}"
      oninput="editCatName(${c.id},this)">
    <button type="button" class="star${rec ? " on" : ""}" title="추천으로 지정"
      aria-pressed="${rec}" onclick="toggleCatRec(${c.id})">${rec ? "★" : "☆"}</button>
    ${c.active
      ? `<button type="button" class="offbtn" onclick="toggleCategory(${c.id},false)">노출</button>`
      : `<button type="button" class="offbtn is-off" onclick="toggleCategory(${c.id},true)">숨김</button>`}
    <button type="button" class="delbtn" title="삭제"
      onclick="purgeCategory(${c.id})">×</button>
  </div>`;
}

// 좌우로 나눈다. 예전에는 대분류 카드가 세로로 계속 쌓여서 중분류 하나를 고치려면
// 화면 끝까지 스크롤해야 했고, 저장 버튼이 분류 개수만큼 있었다.
function categoriesPanel() {
  const site = ADMIN_SITE || activeSiteNames()[0] || "";
  ADMIN_SITE = site;
  const mine = APP.categories.filter(c => c.site === site)
    .sort((a, b) => a.sort_order - b.sort_order);
  const larges = mine.filter(c => !c.medium);

  // 고른 대분류가 사라졌으면(사이트 변경·비활성) 첫 번째로 돌아간다
  if (!larges.some(l => l.id === CAT_LARGE)) CAT_LARGE = larges[0] ? larges[0].id : 0;
  const cur = larges.find(l => l.id === CAT_LARGE);
  const kids = cur ? mine.filter(c => c.medium && c.large === cur.large) : [];

  const head = `<div class="adm">
    <h4>강의 분류</h4>
    <div class="addbar">
      <select id="cat-site" onchange="pickCatSite()">
        ${APP.sites.map(s =>
          `<option value="${esc(s.name)}"${s.name === site ? " selected" : ""}>${
            esc(s.name)}</option>`).join("")}
      </select>
      <span class="muted grow">대분류 ${larges.length} ·
        중분류 ${mine.filter(c => c.medium).length} ·
        추천 ${mine.filter(catRec).length}</span>
      <button class="btn-mini" onclick="openCategoryBrowser(false)">📚 한눈에 보기</button>
    </div>
  </div>`;

  const left = `<div class="catl">
    <div class="hd">대분류 ${larges.length}</div>
    ${larges.map(l => `<button type="button" class="${l.id === CAT_LARGE ? "on" : ""}${
      l.active ? "" : " off"}" onclick="pickLarge(${l.id})">
      <span class="nm">${esc(catName(l))}</span>
      <span class="n">${l.active
        ? mine.filter(c => c.medium && c.large === l.large).length : "비활성"}</span>
    </button>`).join("")}
    <div class="addrow">
      <input id="new-large" placeholder="대분류 이름"
        onkeydown="if(event.key==='Enter')addLarge()">
      <button class="btn-mini primary" onclick="addLarge()">＋ 대분류 추가</button>
    </div>
  </div>`;

  const right = cur ? `<div class="catr">
    <div class="hd">
      <input class="t" id="cat-${cur.id}" data-cid="${cur.id}"
        value="${esc(catName(cur))}" oninput="editCatName(${cur.id},this)">
      <span class="muted">중분류 ${kids.length} · 추천 ${kids.filter(catRec).length}</span>
      <span class="sp">${cur.active
        ? `<button class="btn-mini" onclick="toggleCategory(${cur.id},false)">숨기기</button>`
        : `<button class="btn-mini" onclick="toggleCategory(${cur.id},true)">숨김 해제</button>`}
        <button class="btn-mini danger" onclick="purgeCategory(${cur.id})">삭제</button></span>
    </div>
    ${kids.map(catRow).join("") || '<p class="crow-empty">중분류가 없습니다.</p>'}
    <div class="addrow">
      <input id="new-med-${cur.id}" placeholder="중분류 이름"
        onkeydown="if(event.key==='Enter')addMedium(${cur.id})">
      <button class="btn-mini primary" onclick="addMedium(${cur.id})">＋ 중분류 추가</button>
    </div>
    <div class="savebar" id="catSaveBar">${catSaveBarInner()}</div>
  </div>` : '<div class="catr"><p class="crow-empty">대분류를 먼저 추가해 주세요.</p></div>';

  return `${head}<div class="cat">${left}${right}</div>`;
}

/* ---------- 환급 정책 ---------- */

const POLICY_ROWS = [
  ["partial_enabled", "partial_cap", "부분환급", "건당 상한까지만 환급합니다. 상한 이하는 전액입니다.", "원"],
  ["annual_amount_enabled", "annual_amount_limit", "연간 환급 한도", "1인이 한 해에 받을 수 있는 총액입니다.", "원"],
  ["annual_count_enabled", "annual_count_limit", "연간 신청 건수", "1인이 한 해에 신청할 수 있는 건수입니다.", "건"],
  ["claim_deadline_enabled", "claim_deadline_days", "청구 기한", "강의 종료일로부터 며칠 안에 청구해야 하는지입니다.", "일"],
];

// 저장 전 변경분. 예전에는 화면 입력값을 저장 순간에 긁어모았기 때문에, 무엇을 바꿨는지
// 저장 전에 알 수 없었고 되돌릴 길도 없었다. 바꾼 항목만 여기 담아 둔다.
let POL_EDIT = {};

const polVal = f => (f in POL_EDIT ? POL_EDIT[f] : APP.policy[f]);
const polDirty = () => Object.keys(POL_EDIT).length;

// 원래 값으로 되돌아온 항목은 변경에서 빼야 "변경 1건"이 거짓말을 하지 않는다
function setPol(field, value) {
  const same = field.endsWith("_enabled")
    ? !!value === !!APP.policy[field]
    : Number(value || 0) === Number(APP.policy[field] || 0);
  if (same) delete POL_EDIT[field];
  else POL_EDIT[field] = value;
}

function togglePolicy(flag) {
  setPol(flag, !polVal(flag));
  renderAdmin();
}

// 숫자는 입력 중에 담아만 두고 저장 바만 다시 그린다 — 커서가 날아가지 않게
function editPolicyNum(field, el) {
  setPol(field, Number(el.value) || 0);
  const bar = document.getElementById("polSaveBar");
  if (bar) bar.innerHTML = polSaveBarInner();
}

function polSaveBarInner() {
  const n = polDirty();
  const p = APP.policy;
  const last = p.updated_at
    ? `마지막 저장 ${esc(p.updated_at)}${p.updated_by ? ` · ${esc(p.updated_by)}` : ""}`
    : "아직 바꾼 적이 없습니다";
  const off = n ? "" : "disabled";
  return `<span class="grow">${n ? `변경 ${n}건 · ` : ""}${last}</span>
    <button class="btn-mini" ${off} onclick="revertPolicy()">되돌리기</button>
    <button class="btn-mini primary" ${off} onclick="savePolicy()">저장</button>`;
}

function revertPolicy() {
  POL_EDIT = {};
  renderAdmin();
}

// 스위치로 바꾼 이유: 체크박스 + 빈 입력칸은 "끈 것"과 "안 채운 것"이 같아 보인다.
// 스위치를 내리면 값 칸이 회색으로 죽어 그 항목을 검사하지 않는다는 게 드러난다.
function policyPanel() {
  const rows = POLICY_ROWS.map(([flag, value, label, hint, unit]) => {
    const on = !!polVal(flag);
    const dirty = flag in POL_EDIT || value in POL_EDIT;
    return `<div class="srow${dirty ? " edited" : ""}">
      <span class="lb">
        <b>${esc(label)}</b>
        <span class="ds">${esc(hint)}</span>
      </span>
      <span class="sp">
        <span class="fld${on ? "" : " dim"}">
          <input type="number" min="0" id="p-${value}" value="${polVal(value) || ""}"
            ${on ? "" : "disabled"} oninput="editPolicyNum('${value}',this)">
          <i>${esc(unit)}</i>
        </span>
        <button type="button" class="switch${on ? " on" : ""}" role="switch"
          aria-checked="${on}" aria-label="${esc(label)} 사용"
          onclick="togglePolicy('${flag}')"></button>
      </span>
    </div>`;
  }).join("");

  // 다른 관리자 화면(.adm)과 같은 상자·같은 머리글을 쓴다 — 설정만 다른 틀을 쓰면
  // 사이드바를 옮겨 다닐 때 화면이 갈아치워지는 것처럼 보인다.
  return `<div class="adm">
    <h4>환급 정책</h4>
    <p class="muted" style="margin:-6px 0 12px">
      꺼 두면 그 항목은 검사하지 않습니다. 기본값은 네 항목 모두 꺼짐입니다.
    </p>
    <div class="setrows">${rows}</div>
    <div class="savebar" id="polSaveBar">${polSaveBarInner()}</div>
  </div>`;
}

// 서버는 전체 객체를 받는다 — 바꾸지 않은 항목도 지금 값 그대로 실어 보낸다
async function savePolicy() {
  const n = polDirty();
  const body = {};
  for (const [flag, value] of POLICY_ROWS) {
    body[flag] = !!polVal(flag);
    body[value] = Number(polVal(value)) || 0;
  }
  try {
    APP.policy = await putJSON("/learningapi/policy", body);
    POL_EDIT = {};
    showToast(`정책 ${n}건을 저장했습니다`);
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
