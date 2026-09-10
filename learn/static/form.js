let EDIT_ID = null;
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

function openForm(req) {
  EDIT_ID = req ? req.id : null;
  document.getElementById("formTitle").textContent = req ? "신청 수정" : "강의 신청";
  document.getElementById("formSave").textContent = req ? "수정" : "신청";

  fillSelect("i-site", activeSiteNames(), req?.site ?? activeSiteNames()[0] ?? "");
  fillSelect("i-level", APP.me.levels || [], req?.level ?? "", "선택");
  onSiteChange({ large: req?.category_large, medium: req?.category_medium });

  document.getElementById("i-title").value = req?.title || "";
  document.getElementById("i-url").value = req?.url || "";
  document.getElementById("i-applicant").value = req?.applicant || APP.me.name || "";
  document.getElementById("i-price").value = req && !req.is_free ? (req.price || "") : "";
  document.getElementById("i-free").checked = !!req?.is_free;
  document.getElementById("i-start").value = req?.start_date || "";
  document.getElementById("i-end").value = req?.end_date || "";
  buildDurationSelects(req?.duration_min);
  pickAccount(req?.account_type || "개인계정");

  document.getElementById("capNote").textContent =
    `수강료 중 ${won(APP.policy.partial_cap)}까지 환급됩니다. 초과분은 본인 부담입니다.`;
  onFreeChange();

  document.getElementById("titleSuggest").innerHTML =
    [...new Set(APP.requests.map(r => r.title))]
      .map(t => `<option value="${esc(t)}"></option>`).join("");

  openSheet();
  setTimeout(() => document.getElementById("i-title").focus(), 60);
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
    showToast(EDIT_ID ? "수정했습니다" : "신청했습니다");
    await reload();
  } catch (e) {
    showToast(e.message);
  }
}
