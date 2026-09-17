let EDIT_ID = null;
let FROM_CATALOG = null;   // 추천·필수 강의에서 연 신청이면 그 카탈로그
let ACCOUNT = "개인계정";

// values 는 문자열 배열이거나 {value, label} 배열이다
function fillSelect(id, values, current, placeholder) {
  const el = document.getElementById(id);
  const items = values.map(v => (typeof v === "string" ? { value: v, label: v } : v));
  // 지난 신청이 쓰던 사이트·분류가 비활성으로 내려갔을 수 있다. 조용히 다른 값으로 바뀌면
  // 수정 저장에서 값이 뒤바뀌므로, 목록에 없으면 그 값을 그대로 살려 둔다.
  if (current && !items.some(o => o.value === current)) {
    items.push({ value: current, label: `${current} (지금은 고를 수 없음)` });
  }
  el.innerHTML = (placeholder ? `<option value="">${esc(placeholder)}</option>` : "") +
    items.map(o => `<option value="${esc(o.value)}">${esc(o.label)}</option>`).join("");
  if (current != null) el.value = current;
  if (el.selectedIndex < 0) el.selectedIndex = 0;
}

function activeSiteNames() {
  return APP.sites.filter(s => s.active).map(s => s.name);
}

// ★ 는 이름 뒤에 붙인다 — 앞에 두면 옵션 목록의 첫 글자가 어긋나 읽기 어렵다
const starred = (name, c) => ({ value: name, label: c.recommended ? `${name} ★` : name });

function largesOf(site) {
  return APP.categories
    .filter(c => c.site === site && !c.medium && c.active)
    .sort((a, b) => a.sort_order - b.sort_order)
    .map(c => starred(c.large, c));
}

function mediumsOf(site, large) {
  return APP.categories
    .filter(c => c.site === site && c.large === large && c.medium && c.active)
    .sort((a, b) => a.sort_order - b.sort_order)
    .map(c => starred(c.medium, c));
}

function showCatHint(site) {
  const any = APP.categories.some(c => c.site === site && c.active && c.recommended);
  document.getElementById("catHint").style.display = any ? "" : "none";
}

function onSiteChange(keep) {
  const site = document.getElementById("i-site").value;
  fillSelect("i-large", largesOf(site), keep?.large ?? "", "선택");
  showCatHint(site);
  onLargeChange(keep);
}

function onLargeChange(keep) {
  const site = document.getElementById("i-site").value;
  const large = document.getElementById("i-large").value;
  fillSelect("i-medium", mediumsOf(site, large), keep?.medium ?? "", "선택");
}

function pickAccount(v) {
  ACCOUNT = v;
  document.querySelectorAll("#i-account button").forEach(b =>
    b.classList.toggle("on", b.dataset.v === v));
  document.getElementById("accountNote").style.display = v === "회사계정" ? "" : "none";
}

function onFreeChange() {
  const free = document.getElementById("i-free").checked;
  const price = document.getElementById("i-price");
  price.disabled = free;
  if (free) price.value = "";
  document.getElementById("capNote").style.display =
    (!free && APP.policy.partial_enabled) ? "" : "none";
}

function buildDurationSelects(min) {
  const m = Number(min) || 0;
  const hours = Array.from({ length: 201 }, (_, i) => i);
  const mins = Array.from({ length: 12 }, (_, i) => i * 5);
  document.getElementById("i-hours").innerHTML =
    hours.map(h => `<option value="${h}">${h}시간</option>`).join("");
  document.getElementById("i-minutes").innerHTML =
    mins.map(x => `<option value="${x}">${x}분</option>`).join("");
  document.getElementById("i-hours").value = Math.floor(m / 60);
  document.getElementById("i-minutes").value = Math.round((m % 60) / 5) * 5;
}

// cat 이 있으면 추천·필수 강의에서 연 것이다. 강의 정보는 서버가 카탈로그 값으로
// 강제하므로 화면에서도 잠가 둔다 — 고쳐 봐야 저장되지 않는데 입력칸만 열려 있으면
// 고친 대로 저장된 줄 안다.
function openForm(req, cat) {
  EDIT_ID = req ? req.id : null;
  FROM_CATALOG = cat || null;
  const src = req || cat || null;
  document.getElementById("formTitle").textContent =
    req ? "신청 수정" : cat ? `${cat.grade} 강의 신청` : "강의 신청";
  document.getElementById("formSave").textContent = req ? "수정" : "신청";

  fillSelect("i-site", activeSiteNames(), src?.site ?? activeSiteNames()[0] ?? "");
  fillSelect("i-level", APP.me.levels || [], src?.level ?? "", "선택");
  onSiteChange({ large: src?.category_large, medium: src?.category_medium });

  document.getElementById("i-title").value = src?.title || "";
  document.getElementById("i-url").value = src?.url || "";
  document.getElementById("i-applicant").value = req?.applicant || APP.me.name || "";
  document.getElementById("i-price").value = src && !src.is_free ? (src.price || "") : "";
  document.getElementById("i-free").checked = !!src?.is_free;
  document.getElementById("i-start").value = req?.start_date || "";
  document.getElementById("i-end").value = req?.end_date || "";
  buildDurationSelects(src?.duration_min);
  pickAccount(req?.account_type || "개인계정");

  document.getElementById("capNote").textContent =
    `수강료 중 ${won(APP.policy.partial_cap)}까지 환급됩니다. 초과분은 본인 부담입니다.`;
  onFreeChange();
  lockCatalogFields(cat);

  document.getElementById("titleSuggest").innerHTML =
    [...new Set(APP.requests.map(r => r.title))]
      .map(t => `<option value="${esc(t)}"></option>`).join("");

  openSheet();
  setTimeout(() => document.getElementById("i-title").focus(), 60);
}

// 관리자가 정한 강의 정보는 만지지 못하게 한다. 무료 체크와 수강료는 onFreeChange 가
// 따로 건드리므로 잠금을 나중에 걸어야 덮어써지지 않는다.
const CATALOG_LOCKED = ["i-site", "i-level", "i-large", "i-medium", "i-title", "i-url",
                        "i-hours", "i-minutes", "i-price", "i-free"];

function lockCatalogFields(cat) {
  for (const id of CATALOG_LOCKED) document.getElementById(id).disabled = !!cat;
  document.getElementById("catalogNote").style.display = cat ? "" : "none";
  if (cat) {
    document.getElementById("catalogNote").innerHTML =
      `<b>${esc(cat.grade)} 강의</b> — 강의 정보는 관리자가 등록한 값이라 고칠 수 없습니다. ` +
      `수강 기간과 계정 구분만 확인해 주세요.` +
      (cat.grade === "필수"
        ? " 필수 강의는 수강 승인 없이 바로 수강 상태로 등록됩니다."
        : "") +
      (cat.reason ? `<br><span class="muted">${esc(cat.reason)}</span>` : "");
  }
}

function readForm() {
  const free = document.getElementById("i-free").checked;
  const hours = Number(document.getElementById("i-hours").value) || 0;
  const minutes = Number(document.getElementById("i-minutes").value) || 0;
  return {
    site: document.getElementById("i-site").value,
    category_large: document.getElementById("i-large").value,
    category_medium: document.getElementById("i-medium").value,
    level: document.getElementById("i-level").value,
    title: document.getElementById("i-title").value.trim(),
    url: document.getElementById("i-url").value.trim(),
    account_type: ACCOUNT,
    duration_min: hours * 60 + minutes,
    is_free: free,
    price: free ? 0 : (Number(document.getElementById("i-price").value) || 0),
    start_date: document.getElementById("i-start").value,
    end_date: document.getElementById("i-end").value,
    // 서버가 이 id 로 강의 정보를 다시 채운다 — 화면 값은 보여주기용일 뿐이다
    catalog_id: FROM_CATALOG ? FROM_CATALOG.id : 0,
  };
}

async function saveForm() {
  const body = readForm();
  if (!body.site) { showToast("교육 플랫폼을 골라 주세요"); return; }
  if (!body.title) { showToast("강의명을 입력해 주세요"); return; }
  if (body.start_date && body.end_date && body.start_date > body.end_date) {
    showToast("종료일이 시작일보다 빠릅니다"); return;
  }
  try {
    if (EDIT_ID) await putJSON(`/learningapi/requests/${EDIT_ID}`, body);
    else await postJSON("/learningapi/requests", body);
    closeSheet();
    showToast(EDIT_ID ? "수정했습니다"
      : FROM_CATALOG?.grade === "필수" ? "신청했습니다 — 바로 수강하실 수 있습니다"
      : "신청했습니다");
    await reload();
  } catch (e) {
    showToast(e.message);
  }
}
