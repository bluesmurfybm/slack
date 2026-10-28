// 시트 폼 — 사이트 추가/전체 수정, 접속정보·담당자·계약 행 추가/수정. 칸 정의(SH.groups / SH.subs)에서 폼을 만든다.

const EYE_ON = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>`;
const EYE_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.9 17.9A10.9 10.9 0 0 1 12 19c-7 0-11-7-11-7a20 20 0 0 1 5.1-5.9M9.9 4.2A10 10 0 0 1 12 4c7 0 11 7 11 7a20 20 0 0 1-3.2 4.2"/><path d="M1 1l22 22"/></svg>`;

/* 칸 하나의 입력 컨트롤. name 은 data-k 로 들고, 값은 readFields() 가 읽는다. */
function fieldControl(f, value) {
  const v = value === null || value === undefined ? "" : value;
  const k = esc(f.key);
  switch (f.type) {
    case "area":
      return `<textarea data-k="${k}" rows="3">${esc(v)}</textarea>`;
    case "pw": {
      const box = `<div class="pwwrap"><input type="password" data-k="${k}" value="${esc(v)}" autocomplete="new-password">
        <button type="button" class="eye" title="표시" onclick="toggleEye(this)">${EYE_OFF}</button></div>`;
      // gen: 키 생성 버튼 (감시 주소의 에이전트 키)
      return f.gen ? `<div class="sh-copyrow">${box}<button type="button" class="btn-mini" onclick="genKey(this)">키 생성</button></div>` : box;
    }
    case "select":
      return `<select data-k="${k}">${Object.entries(f.options || {}).map(([val, label]) =>
        `<option value="${esc(val)}"${String(v) === String(val) ? " selected" : ""}>${esc(label)}</option>`).join("")}</select>`;
    case "date":
      return `<input type="date" data-k="${k}" value="${esc(v)}">`;
    case "number":
      return `<input type="text" inputmode="numeric" data-k="${k}" value="${v === "" ? "" : esc(Number(v).toLocaleString("ko-KR"))}" placeholder="숫자">`;
    case "bool":
      return `<label class="check" style="height:40px;width:max-content"><input type="checkbox" data-k="${k}"${Number(v) ? " checked" : ""}> 예</label>`;
    case "staff": {
      const chosen = new Set(splitList(v));
      if (!APP.staff.length) return `<input type="text" data-k="${k}" value="${esc(v)}" placeholder="이메일, 쉼표로 여러 명">`;
      return `<div class="sh-staff" data-k="${k}">${APP.staff.map(p =>
        `<label class="${chosen.has(p.email) ? "on" : ""}"><input type="checkbox" value="${esc(p.email)}"${chosen.has(p.email) ? " checked" : ""} onchange="this.parentNode.classList.toggle('on', this.checked)"> ${esc(p.name)}</label>`).join("")}</div>`;
    }
    case "url":
      return `<input type="text" data-k="${k}" value="${esc(v)}" placeholder="https://">`;
    default:
      return `<input type="text" data-k="${k}" value="${esc(v)}">`;
  }
}

function fieldHtml(f, value) {
  // kinds 가 있는 칸(접속정보 만료일)은 그 종류를 골랐을 때만 보인다 — openSubForm 이 토글
  return `<div class="field"${f.kinds ? ` data-kinds="${esc(f.kinds.join(","))}"` : ""}><label>${esc(f.label)}${f.required ? ' <span style="color:var(--nega50)">*</span>' : ""}</label>
    ${fieldControl(f, value)}${f.hint ? `<span class="fhint">${esc(f.hint)}</span>` : ""}</div>`;
}

/* 컨트롤에서 값을 모은다. 비밀번호 칸은 비어 있으면 "그대로 두기"라 보내지 않는다(수정일 때). */
function readFields(root, fields, isEdit, sendEmptyPw) {
  const out = {};
  fields.forEach(f => {
    const el = root.querySelector(`[data-k="${f.key}"]`);
    if (!el) return;
    if (f.type === "bool") out[f.key] = el.checked ? 1 : 0;
    else if (f.type === "staff" && el.classList.contains("sh-staff")) out[f.key] = [...el.querySelectorAll("input:checked")].map(i => i.value).join(",");
    else if (f.type === "number") out[f.key] = el.value.replace(/[^\d-]/g, "");
    else if (f.type === "pw") { if (!(isEdit && !sendEmptyPw && el.value === "")) out[f.key] = el.value; }
    else out[f.key] = el.value;
  });
  return out;
}

// 에이전트 키 — 40자 무작위. 에이전트 주소가 비어 있으면 감시 주소의 도메인 + /blumo-agent.php 를 같이 채운다
function genKey(btn) {
  const form = btn.closest(".sheet-body");
  const el = btn.parentNode.querySelector("input");
  if (el.value && !confirm("키를 바꾸면 사이트에 올린 에이전트 파일도 다시 올려야 합니다. 바꿀까요?")) return;
  const a = new Uint8Array(20);
  crypto.getRandomValues(a);
  el.value = [...a].map(b => b.toString(16).padStart(2, "0")).join("");
  el.type = "text";
  const hl = form && form.querySelector('[data-k="health"]'), u = form && form.querySelector('[data-k="url"]');
  if (hl && !hl.value.trim() && u && u.value.trim()) {
    const m = u.value.trim().match(/^(https?:\/\/[^\/]+)/i);
    hl.value = (m ? m[1] : "https://" + u.value.trim().split("/")[0]) + "/blumo-agent.php";
  }
}

// 감시 주소를 한 번 열어 본다 — 정상 판정 문구가 비어 있으면 페이지 제목 앞머리로 채운다
async function probeMonitor(btn) {
  const form = btn.closest(".sheet-body");
  const u = form.querySelector('[data-k="url"]'), ex = form.querySelector('[data-k="expect"]'), out = form.querySelector(".sh-probe-out");
  if (!u.value.trim()) { u.focus(); return; }
  btn.disabled = true; btn.textContent = "여는 중…";
  out.textContent = "";
  try {
    const j = await postJSON({ action: "monitor_probe", url: u.value });
    out.innerHTML = `<b>HTTP ${j.code}</b> · ${Number(j.ms).toLocaleString("ko-KR")}ms · ${Number(j.size).toLocaleString("ko-KR")}bytes · 제목: ${esc(j.title || "(없음)")}`
      + (j.final_url && j.final_url.replace(/\/$/, "") !== j.url.replace(/\/$/, "") ? `<br><span class="sh-mon-warn">다른 주소로 넘어갑니다 → ${esc(j.final_url)}</span>` : "")
      + (j.size < 1000 ? `<br><span class="sh-mon-warn">응답이 작습니다 — 안내·이동 페이지면 실제 첫 화면 주소로 바꾸세요</span>` : "");
    u.value = j.url;
    if (ex && !ex.value.trim() && j.expect) ex.value = j.expect;
  } catch (e) {
    out.innerHTML = `<span class="sh-mon-warn">${esc(e.message)}</span>`;
  } finally {
    btn.disabled = false; btn.textContent = "주소 열어 확인";
  }
}

function toggleEye(btn) {
  const el = btn.parentNode.querySelector("input");
  const show = el.type === "password";
  el.type = show ? "text" : "password";
  btn.innerHTML = show ? EYE_ON : EYE_OFF;
  btn.title = show ? "숨기기" : "표시";
}

/* 숫자 칸은 입력하는 동안 천 단위 쉼표를 붙인다 */
document.addEventListener("input", e => {
  const el = e.target;
  if (!(el instanceof HTMLInputElement) || el.inputMode !== "numeric") return;
  const digits = el.value.replace(/[^\d]/g, "");
  el.value = digits ? Number(digits).toLocaleString("ko-KR") : "";
});

/* ── 사이트 추가 / 전체 수정 ── 기본 묶음만 받는다. 유지보수 방법은 드로어에서 칸별로 고친다. */
function openSiteForm(site) {
  const g = SH.groups.find(x => x.key === "basic");
  const isEdit = !!site;
  const fields = g.fields;
  openSheet(`
    <div class="sheet-head"><div><span class="k">${isEdit ? "사이트 수정" : "새 사이트"}</span><h2>${isEdit ? esc(site.name) : "사이트 추가"}</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="siteForm">
      <div class="two">${fieldHtml(fields.find(f => f.key === "name"), site?.name)}${fieldHtml(fields.find(f => f.key === "customer"), site?.customer)}</div>
      ${fields.filter(f => !["name", "customer"].includes(f.key)).map(f => fieldHtml(f, site?.[f.key])).join("")}
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">취소</button>
      <button type="button" class="btn-submit" id="siteSave" onclick="saveSiteForm(${isEdit ? site.id : 0})">${isEdit ? "저장" : "추가"}</button>
    </div>`);
}

async function saveSiteForm(siteId) {
  const g = SH.groups.find(x => x.key === "basic");
  const body = readFields($("siteForm"), g.fields, siteId > 0);
  const btn = $("siteSave");
  btn.disabled = true;
  try {
    const j = siteId > 0
      ? await postJSON(Object.assign({ action: "save", site_id: siteId }, body))
      : await postJSON(Object.assign({ action: "create" }, body));
    closeSheet();
    replaceSite(j.site);
    showToast(siteId > 0 ? "저장했습니다" : "사이트를 추가했습니다");
    if (!siteId) openDrawer(j.site.id, "accounts");   // 바로 접속정보부터 채우게 한다
    else if (APP.open.id === siteId && typeof refreshDrawer === "function") refreshDrawer();
  } catch (e) {
    showToast(e.message);
  } finally {
    btn.disabled = false;
  }
}

/* ── 하위 행 추가 / 수정 (접속정보 · 담당자 · 계약) ── */
function openSubForm(table, siteId, rowId) {
  const def = SH.subs[table];
  const site = siteById(siteId);
  if (!def || !site) return;
  const row = rowId ? (site[table] || []).find(r => r.id === Number(rowId)) : null;
  if (rowId && !row) { showToast("항목을 찾을 수 없습니다"); return; }
  const isEdit = !!row;
  const wide = f => f.type === "area";
  const narrow = def.fields.filter(f => !wide(f));
  const pairs = [];
  for (let i = 0; i < narrow.length; i += 2) pairs.push(narrow.slice(i, i + 2));
  const pwNote = isEdit && table === "accounts" ? `<span class="fhint">비밀번호를 지우고 저장하면 비밀번호가 비워집니다. 눈 아이콘으로 값을 확인할 수 있습니다.</span>`
    : isEdit && table === "monitors" ? `<span class="fhint">에이전트 키를 비워 두면 기존 키를 그대로 둡니다.</span>` : "";
  const probe = table === "monitors" ? `<div class="sh-probe"><button type="button" class="btn-mini" onclick="probeMonitor(this)">주소 열어 확인</button>
      <span class="sh-probe-out fhint">주소를 한 번 열어 응답과 페이지 제목을 봅니다. 정상 판정 문구가 비어 있으면 제목으로 채웁니다.</span></div>` : "";
  openSheet(`
    <div class="sheet-head"><div><span class="k">${esc(site.name)}</span><h2>${esc(def.one)} ${isEdit ? "수정" : "추가"}</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="subForm">
      ${pairs.map((p, i) => `<div class="two">${p.map(f => fieldHtml(f, row ? (f.type === "pw" && table !== "accounts" ? "" : row[f.key]) : defaultFor(f))).join("")}</div>${i === 0 ? probe : ""}`).join("")}
      ${pwNote}
      ${def.fields.filter(wide).map(f => fieldHtml(f, row?.[f.key])).join("")}
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">취소</button>
      <button type="button" class="btn-submit" id="subSave" onclick="saveSubForm('${table}', ${siteId}, ${isEdit ? row.id : 0})">${isEdit ? "저장" : "추가"}</button>
    </div>`);
  const form = $("subForm");
  if (table === "monitors") {   // 에이전트 주소 칸 옆에 파일 받기 — 키가 저장돼 있어야 만든다
    const hl = form.querySelector('[data-k="health"]');
    if (hl && hl.closest(".field")) {
      const note = isEdit && row.health_key
        ? `<a class="btn-mini ghost" href="api.php?agent=${row.id}" title="키가 들어간 blumo-agent.php — 사이트 최상위에 올린다">blumo-agent.php 받기</a>`
        : `<span class="fhint">키를 만들어 저장하면 blumo-agent.php 를 받을 수 있습니다.</span>`;
        hl.closest(".field").insertAdjacentHTML("beforeend", `<div style="margin-top:6px">${note}</div>`);
    }
  }
  const kindSel = form.querySelector('select[data-k="kind"]');
  const applyKinds = () => form.querySelectorAll("[data-kinds]").forEach(el => { el.hidden = !kindSel || !el.dataset.kinds.split(",").includes(kindSel.value); });
  if (kindSel) { kindSel.addEventListener("change", applyKinds); applyKinds(); }
}

function defaultFor(f) {
  if (f.type === "select") {
    if (f.key === "kind" && f.options.admin !== undefined) return "admin";
    if (f.key === "kind" && f.options.annual !== undefined) return "annual";
    return Object.keys(f.options || {})[0] || "";
  }
  if (f.type === "bool") return f.key === "vat_included" ? 0 : 0;
  return "";
}

async function saveSubForm(table, siteId, id) {
  const def = SH.subs[table];
  const body = readFields($("subForm"), def.fields, id > 0, table === "accounts");
  const btn = $("subSave");
  btn.disabled = true;
  try {
    const j = await postJSON(Object.assign({ action: "sub_save", table, site_id: siteId, id }, body));
    closeSheet();
    replaceSite(j.site);
    if (typeof refreshDrawer === "function") refreshDrawer();
    showToast(id > 0 ? "저장했습니다" : def.one + " 추가 완료");
  } catch (e) {
    showToast(e.message);
  } finally {
    btn.disabled = false;
  }
}

function subLabel(table, r) {
  if (!r) return "";
  const opt = key => { const f = SH.subs[table].fields.find(x => x.key === key); return f && f.options ? (f.options[r[key]] || r[key]) : r[key]; };
  if (table === "accounts") return r.label || opt("kind");
  if (table === "contacts") return r.name;
  if (table === "costs") return ((r.paid_on || "?") + " " + (r.item || "")).trim();
  if (table === "monitors") return (r.label ? r.label + " · " : "") + (r.url || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
  return opt("kind") + " " + (r.start_date || "?") + " ~ " + (r.end_date || "?");
}

async function deleteSub(table, siteId, id) {
  const def = SH.subs[table];
  const site = siteById(siteId);
  const label = subLabel(table, (site && site[table] || []).find(r => r.id === Number(id)));
  if (!(await askConfirm(def.one + " 삭제", (label ? "‘" + label + "’ 항목을 지웁니다." : "이 항목을 지웁니다.") + " 되돌릴 수 없습니다.", "삭제"))) return;
  try {
    const j = await postJSON({ action: "sub_delete", table, id });
    replaceSite(j.site);
    if (typeof refreshDrawer === "function") refreshDrawer();
    showToast("삭제했습니다");
  } catch (e) {
    showToast(e.message);
  }
}
