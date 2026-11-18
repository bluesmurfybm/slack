const isArchived = (s) => !!s.archived_at;
const activeSheets = () => APP.sheets.filter((s) => !isArchived(s));
const archivedSheets = () => APP.sheets.filter(isArchived);

function renderTabs() {
  // 보관한 탭은 줄에 넣지 않는다. 다만 보관한 것을 보는 중이면 그것만 끝에 노란 탭으로 보인다.
  const cur = currentSheet();
  const shown = activeSheets().concat(cur && isArchived(cur) ? [cur] : []);
  const tab = (s) => {
    const p = progressOf(s.counts);
    const complete = p.target > 0 && p.done === p.target;
    const on = s.id === APP.sheetId;
    const archived = isArchived(s);
    return `<div class="tab${on ? " on" : ""}${complete ? " complete" : ""}${archived ? " archived" : ""}" data-sheet="${s.id}">
      <button type="button" class="tab-main" onclick="selectSheet(${s.id})">${archived ? "📦 " : ""}${esc(s.title)} <span class="n">${p.done}/${p.target}</span></button>
      <button type="button" class="tab-more" onclick="openTabMenu(event, ${s.id})" aria-label="${esc(s.title)} 메뉴" title="메뉴">⋮</button>
    </div>`;
  };
  document.getElementById("tabs").innerHTML = shown.map(tab).join("")
    + `<button type="button" class="tab add" onclick="openSheetForm()" title="새 할일">+</button>`;

  const nArchived = archivedSheets().length;
  const box = document.getElementById("archiveBtn");
  box.querySelector(".n").textContent = nArchived;
  box.classList.toggle("empty", !nArchived);

  const has = APP.sheets.length > 0;
  document.getElementById("emptyAll").style.display = has ? "none" : "block";
  document.getElementById("sheetArea").style.display = has && APP.sheetId ? "block" : "none";
  document.getElementById("csvBtn").style.display = has && APP.sheetId ? "" : "none";
}

async function selectSheet(id) {
  APP.sheetId = id;
  APP.filters = { ver: null, status: null };
  closeColMenu();
  closeTabMenu();
  closeArchive();
  closeHistory();
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
  if (typeof resetSyncCountdown === "function") resetSyncCountdown();
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

  const note = document.getElementById("archivedNote");
  note.style.display = isArchived(s) ? "" : "none";
  if (isArchived(s)) note.querySelector(".an-sub").textContent = `${s.archived_at.slice(0, 16)} 에 보관한 할일입니다. 보관한 채로 보고 고칠 수 있습니다.`;

  const p = progressOf(s.counts);
  const pct = p.target ? Math.round((p.done / p.target) * 100) : 0;
  document.getElementById("progressBar").style.width = pct + "%";
  const excluded = s.counts[APP.boot.status_excluded];
  document.getElementById("progressText").textContent =
    `${APP.boot.status_done} ${p.done} / 대상 ${p.target} (${pct}%)` + (excluded ? ` · 해당없음 ${excluded}` : "");

  renderStatusFilter();
  renderGrid();
}

/* ---------- 탭 메뉴 (⋮) ---------- */

function openTabMenu(e, id) {
  e.stopPropagation();
  closeColMenu();
  closeHistory();
  const s = APP.sheets.find((x) => x.id === id);
  if (!s) return;
  APP.menuSheetId = id;
  const menu = document.getElementById("tabMenu");
  menu.innerHTML = `<div class="cm-title">${esc(s.title)}</div>
    <button type="button" class="cm-item" onclick="closeTabMenu(); openSheetForm(${id})">✎ 이름·설명 수정</button>
    <button type="button" class="cm-item" onclick="closeTabMenu(); copySheetLink(${id})">🔗 링크 복사</button>
    <button type="button" class="cm-item" onclick="closeTabMenu(); setArchived(${id}, ${isArchived(s) ? "false" : "true"})">${isArchived(s) ? "📂 보관함에서 꺼내기" : "📦 보관"}</button>
    <div class="cm-sep"></div>
    <button type="button" class="cm-item danger" onclick="closeTabMenu(); askDeleteSheet(${id})">🗑 삭제</button>`;
  const at = e.currentTarget.getBoundingClientRect();
  menu.classList.add("open");
  menu.style.top = at.bottom + 4 + "px";
  menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - menu.offsetWidth - 8)) + "px";
}

function closeTabMenu() {
  APP.menuSheetId = null;
  document.getElementById("tabMenu").classList.remove("open");
}

document.getElementById("tabMenu").addEventListener("click", (e) => e.stopPropagation());
document.addEventListener("click", () => { if (APP.menuSheetId) closeTabMenu(); });

/* ---------- 보관 ---------- */

async function setArchived(id, archived) {
  let detail;
  try {
    detail = await sendJSON("PUT", `/sheets/${id}`, { archived });
  } catch (e) {
    showToast(e.message);
    return;
  }
  syncCurrentSheet(detail);
  showToast(archived ? `"${detail.title}" 을(를) 보관했습니다` : `"${detail.title}" 을(를) 꺼냈습니다`);
  if (archived && APP.sheetId === id) {
    // 보고 있던 탭을 보관하면 남은 탭 중 첫 번째로 옮긴다
    const next = activeSheets()[0];
    if (next) await selectSheet(next.id);
    else { APP.sheetId = null; APP.rows = []; history.replaceState(null, "", location.pathname); renderTabs(); }
  } else if (APP.sheetId === id) {
    renderSheet();
  } else {
    renderTabs();
  }
  if (document.getElementById("archiveOverlay").classList.contains("open")) renderArchive();
}

function openArchive() {
  closeTabMenu();
  renderArchive();
  document.getElementById("archiveOverlay").classList.add("open");
}
function closeArchive() {
  document.getElementById("archiveOverlay").classList.remove("open");
}

function renderArchive() {
  const list = archivedSheets().slice().sort((a, b) => natural(b.archived_at, a.archived_at));
  const el = document.getElementById("archiveList");
  if (!list.length) {
    el.innerHTML = `<div class="arc-none">보관한 할일이 없어요.<br>탭의 ⋮ 메뉴에서 <b>보관</b> 하면 여기에 모입니다.</div>`;
    return;
  }
  el.innerHTML = list.map((s) => {
    const p = progressOf(s.counts);
    const pct = p.target ? Math.round((p.done / p.target) * 100) : 0;
    // 줄 어디를 눌러도 보관한 채로 그대로 본다(꺼내지 않는다). 단추 클릭은 줄 클릭으로 번지지 않게 막는다.
    return `<div class="arc-row${s.id === APP.sheetId ? " on" : ""}" onclick="selectSheet(${s.id})" title="보관한 채로 보기">
      <div class="arc-main">
        <button type="button" class="arc-title">${esc(s.title)}</button>
        <div class="arc-sub">${esc(APP.boot.status_done)} ${p.done} / 대상 ${p.target} (${pct}%) · ${esc(s.archived_at.slice(0, 16))} 보관</div>
      </div>
      <div class="arc-act" onclick="event.stopPropagation()">
        <button type="button" class="btn-mini primary" onclick="selectSheet(${s.id})">보기</button>
        <button type="button" class="btn-mini" onclick="copySheetLink(${s.id})" title="링크 복사">🔗</button>
        <button type="button" class="btn-mini" onclick="setArchived(${s.id}, false)">꺼내기</button>
        <button type="button" class="btn-mini danger" onclick="closeArchive(); askDeleteSheet(${s.id})">삭제</button>
      </div>
    </div>`;
  }).join("");
}

/* ---------- 이름·설명 ---------- */

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
    if (!id) await selectSheet(detail.id);
    else if (id === APP.sheetId) renderSheet();
    else renderTabs();
    if (document.getElementById("archiveOverlay").classList.contains("open")) renderArchive();
  } catch (e) {
    showToast(e.message);
  }
}

/* ---------- 삭제 ---------- */

function askDeleteSheet(id) {
  const s = APP.sheets.find((x) => x.id === id);
  if (!s) return;
  APP.deletingSheetId = id;
  document.getElementById("confirmText").textContent = `"${s.title}" 과(와) 대학별 상태·메모가 모두 지워집니다.`;
  document.getElementById("confirmOverlay").classList.add("open");
}
function closeConfirm() {
  APP.deletingSheetId = null;
  document.getElementById("confirmOverlay").classList.remove("open");
}

async function deleteSheet() {
  const id = APP.deletingSheetId;
  if (!id) return;
  try {
    await api(`/sheets/${id}`, { method: "DELETE" });
  } catch (e) {
    showToast(e.message);
    return;
  }
  closeConfirm();
  APP.sheets = APP.sheets.filter((s) => s.id !== id);
  if (APP.sheetId === id) {
    APP.sheetId = null;
    APP.rows = [];
    const next = activeSheets()[0];
    if (next) await selectSheet(next.id);
    else { history.replaceState(null, "", location.pathname); renderTabs(); }
  } else {
    renderTabs();
  }
  if (document.getElementById("archiveOverlay").classList.contains("open")) renderArchive();
}
