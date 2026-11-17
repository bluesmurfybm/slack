function renderTabs() {
  document.getElementById("tabs").innerHTML = APP.sheets.map((s) => {
    const p = progressOf(s.counts);
    const complete = p.target > 0 && p.done === p.target;
    return `<button type="button" class="tab${s.id === APP.sheetId ? " on" : ""}${complete ? " complete" : ""}" onclick="selectSheet(${s.id})">
      ${esc(s.title)} <span class="n">${p.done}/${p.target}</span></button>`;
  }).join("") + `<button type="button" class="tab add" onclick="openSheetForm()" title="새 할일">+</button>`;

  const has = APP.sheets.length > 0;
  document.getElementById("emptyAll").style.display = has ? "none" : "block";
  document.getElementById("sheetArea").style.display = has ? "block" : "none";
  document.getElementById("csvBtn").style.display = has ? "" : "none";
}

async function selectSheet(id) {
  APP.sheetId = id;
  APP.filters = { ver: null, status: null };
  closeColMenu();
  document.getElementById("q").value = "";
  history.replaceState(null, "", "?sheet=" + id);
  renderTabs();
  try {
    const detail = await api(`/sheets/${id}`);
    if (APP.sheetId !== id) return;
    APP.rows = detail.rows;
    syncCurrentSheet(detail);
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
    return;
  }
  renderSheet();
}

function syncCurrentSheet(detail) {
  const i = APP.sheets.findIndex((s) => s.id === detail.id);
  const sheet = { ...detail };
  delete sheet.rows;
  if (i >= 0) APP.sheets[i] = sheet; else APP.sheets.push(sheet);
}

function renderSheet() {
  const s = currentSheet();
  if (!s) return;
  renderTabs();
  const desc = document.getElementById("sheetDesc");
  desc.textContent = s.description || "설명 없음";
  desc.classList.toggle("blank", !s.description);

  const p = progressOf(s.counts);
  const pct = p.target ? Math.round((p.done / p.target) * 100) : 0;
  document.getElementById("progressBar").style.width = pct + "%";
  const excluded = s.counts[APP.boot.status_excluded];
  document.getElementById("progressText").textContent =
    `${APP.boot.status_done} ${p.done} / 대상 ${p.target} (${pct}%)` + (excluded ? ` · 해당없음 ${excluded}` : "");

  renderStatusFilter();
  renderGrid();
}

function openSheetForm(id) {
  const s = id ? APP.sheets.find((x) => x.id === id) : null;
  APP.editingSheetId = s ? s.id : null;
  document.getElementById("sheetFormTitle").textContent = s ? "이름·설명 수정" : "새 할일";
  document.getElementById("f-title").value = s ? s.title : "";
  document.getElementById("f-desc").value = s ? s.description : "";
  document.getElementById("sheetOverlay").classList.add("open");
  document.getElementById("f-title").focus();
}
function closeSheetForm() {
  document.getElementById("sheetOverlay").classList.remove("open");
}

async function submitSheetForm() {
  const body = {
    title: document.getElementById("f-title").value.trim(),
    description: document.getElementById("f-desc").value.trim(),
  };
  if (!body.title) { showToast("이름을 입력해 주세요"); return; }
  try {
    const id = APP.editingSheetId;
    const detail = id ? await sendJSON("PUT", `/sheets/${id}`, body) : await sendJSON("POST", "/sheets", body);
    syncCurrentSheet(detail);
    closeSheetForm();
    if (id) renderSheet(); else await selectSheet(detail.id);
  } catch (e) {
    showToast(e.message);
  }
}

function askDeleteSheet() {
  const s = currentSheet();
  if (!s) return;
  document.getElementById("confirmText").textContent = `"${s.title}" 과(와) 대학별 상태·메모가 모두 지워집니다.`;
  document.getElementById("confirmOverlay").classList.add("open");
}
function closeConfirm() {
  document.getElementById("confirmOverlay").classList.remove("open");
}

async function deleteSheet() {
  const id = APP.sheetId;
  try {
    await api(`/sheets/${id}`, { method: "DELETE" });
  } catch (e) {
    showToast(e.message);
    return;
  }
  closeConfirm();
  APP.sheets = APP.sheets.filter((s) => s.id !== id);
  APP.sheetId = null;
  APP.rows = [];
  if (APP.sheets.length) await selectSheet(APP.sheets[0].id);
  else { history.replaceState(null, "", location.pathname); renderTabs(); }
}
