/* ---------- 다른 사람이 바꾼 것 따라가기 ----------
 * 30초마다 탭 목록과 지금 탭의 행을 다시 받아 바뀐 것만 반영한다.
 * - 표 안의 칸(메모 input·상태 select)에 포커스가 있으면 그리기를 미뤘다가 칸을 벗어날 때 그린다.
 * - 창이 숨겨지면 멈추고, 다시 보이면 바로 한 번 받는다.
 * - 내가 저장한 직후의 응답으로 이미 반영된 행은 같은 값이라 건드리지 않는다. */

const SYNC_EVERY_S = 30;
let syncTimer = null;      // 1초마다 돌며 초를 세고, 0이 되면 받는다
let syncLeft = SYNC_EVERY_S;
let syncBusy = false;
let syncPending = false;
let syncFreshT = null;
let syncAuto = false;      // 자동 갱신 체크박스. 기본은 끔, 켠 적이 있으면 브라우저에 기억한다

function loadSyncAuto() {
  try { syncAuto = localStorage.getItem("schooltodo.syncAuto") === "1"; } catch (e) { syncAuto = false; }
  document.getElementById("syncAuto").checked = syncAuto;
}
function setSyncAuto(on) {
  syncAuto = !!on;
  try { localStorage.setItem("schooltodo.syncAuto", syncAuto ? "1" : "0"); } catch (e) { }
  if (syncAuto) { syncNow(); startSync(); } else stopSync();
}

/* 표시: "27초 후 갱신" → 받는 동안 "갱신 중" → 바뀐 게 있으면 "N곳 갱신" 잠깐, 아니면 바로 다시 센다 */
function showSyncState(state, text) {
  const el = document.getElementById("syncInd");
  el.classList.toggle("busy", state === "busy");
  el.classList.toggle("paused", state === "paused");
  el.classList.toggle("fresh", state === "fresh");
  el.classList.toggle("manual", state === "manual");
  el.querySelector(".txt").textContent = text;
}
function showSyncCountdown() {
  if (syncBusy || syncFreshT) return;
  if (!syncAuto) { showSyncState("manual", "지금 갱신"); return; }
  showSyncState("", `${syncLeft}초 후 갱신`);
}

const ROW_KEYS = ["name", "code", "ver", "vpn", "status", "note", "updated_by", "updated_at"];
const sameRow = (a, b) => ROW_KEYS.every((k) => a[k] === b[k]);
const sameSheet = (a, b) => a.title === b.title && a.description === b.description && a.archived_at === b.archived_at
  && JSON.stringify(a.counts) === JSON.stringify(b.counts);

function editingGrid() {
  const el = document.activeElement;
  return !!el && document.getElementById("gridBody").contains(el);
}

async function syncNow(manual) {
  if (syncBusy || (document.hidden && !manual)) return;
  syncBusy = true;
  syncLeft = SYNC_EVERY_S;
  clearTimeout(syncFreshT); syncFreshT = null;
  showSyncState("busy", "갱신 중");
  const sheetId = APP.sheetId;
  let n = 0;
  try {
    const [list, detail] = await Promise.all([api("/sheets"), sheetId ? api(`/sheets/${sheetId}`) : null]);
    if (APP.sheetId !== sheetId) return;   // 받는 사이에 탭을 옮겼다
    n = applySync(list, detail);
  } catch (e) {
    // 잠깐의 네트워크 오류는 조용히 넘기고 다음 번에 다시 받는다 (로그인 풀림은 api() 가 처리)
    n = -1;
  } finally {
    syncBusy = false;
    if (n > 0 || n < 0 || manual) {
      showSyncState(n < 0 ? "paused" : "fresh", n < 0 ? "받지 못함" : n > 0 ? `${n}곳 갱신` : "바뀐 것 없음");
      syncFreshT = setTimeout(() => { syncFreshT = null; showSyncCountdown(); }, 2500);
    } else {
      showSyncCountdown();
    }
  }
}

/** @return number 바뀐 행 수(탭 목록만 바뀌었으면 0 이어도 다시 그린다) */
function applySync(list, detail) {
  let changed = false;
  let changedRows = 0;

  // 탭 목록 — 새 탭·보관·이름 변경·삭제
  const before = APP.sheets;
  const after = list.map((s) => {
    const old = before.find((x) => x.id === s.id);
    return old && sameSheet(old, s) ? old : s;
  });
  if (after.length !== before.length || after.some((s, i) => s !== before[i])) {
    APP.sheets = after;
    changed = true;
  }

  // 지금 탭이 다른 데서 지워졌다
  if (APP.sheetId && !APP.sheets.some((s) => s.id === APP.sheetId)) {
    showToast("보고 있던 할일이 지워졌습니다");
    APP.sheetId = null;
    APP.rows = [];
    const next = activeSheets()[0];
    if (next) selectSheet(next.id);
    else { history.replaceState(null, "", location.pathname); renderTabs(); }
    return 0;
  }

  // 지금 탭의 행
  if (detail) {
    const sheet = { ...detail };
    delete sheet.rows;
    const i = APP.sheets.findIndex((s) => s.id === sheet.id);
    if (i >= 0 && !sameSheet(APP.sheets[i], sheet)) { APP.sheets[i] = sheet; changed = true; }

    const byId = new Map(APP.rows.map((r) => [r.school_id, r]));
    const rows = detail.rows.map((r) => {
      const old = byId.get(r.school_id);
      if (old && sameRow(old, r)) return old;
      changedRows++;
      return r;
    });
    if (rows.length !== APP.rows.length || rows.some((r, k) => r !== APP.rows[k])) {
      APP.rows = rows;
      changed = true;
    }
  }

  if (!changed) return 0;
  if (editingGrid()) { syncPending = true; return changedRows; }
  if (APP.sheetId) renderSheet(); else renderTabs();
  return changedRows;
}

// 탭을 옮기면 그 탭의 행을 방금 받은 것이니 30초부터 다시 센다 (selectSheet 가 부른다)
function resetSyncCountdown() {
  syncLeft = SYNC_EVERY_S;
  clearTimeout(syncFreshT); syncFreshT = null;
  if (!syncBusy) showSyncCountdown();
}

function startSync() {
  stopSync();
  if (!syncAuto) { showSyncCountdown(); return; }
  syncLeft = SYNC_EVERY_S;
  showSyncCountdown();
  syncTimer = setInterval(() => {
    if (syncBusy) return;
    syncLeft--;
    if (syncLeft <= 0) syncNow();
    else showSyncCountdown();
  }, 1000);
}
function stopSync() {
  if (syncTimer) clearInterval(syncTimer);
  syncTimer = null;
  if (syncAuto) showSyncState("paused", "멈춤"); else showSyncCountdown();
}

// 칸을 벗어나면 미뤄 둔 그리기를 한다. 다른 칸으로 바로 옮겨 가는 경우는 다음 벗어남까지 또 미룬다.
document.getElementById("gridBody").addEventListener("focusout", () => {
  if (!syncPending) return;
  setTimeout(() => {
    if (editingGrid()) return;
    syncPending = false;
    renderSheet();
  }, 0);
});

document.addEventListener("visibilitychange", () => {
  if (document.hidden) stopSync();
  else if (syncAuto) { syncNow(); startSync(); }
});

loadSyncAuto();
startSync();
