const natural = (a, b) => String(a).localeCompare(String(b), "ko", { numeric: true });

const SORTERS = {
  name: (a, b) => natural(a.name, b.name),
  ver: (a, b) => natural(a.ver, b.ver),
  vpn: (a, b) => natural(a.vpn, b.vpn),
  status: (a, b) => APP.boot.statuses.indexOf(a.status) - APP.boot.statuses.indexOf(b.status),
  note: (a, b) => natural(a.note, b.note),
  updated_at: (a, b) => natural(a.updated_at, b.updated_at),
};

const FILTER_COLS = ["ver", "vpn", "status"];

/* ---------- 거르기 · 정렬 ---------- */

function renderStatusFilter() {
  const s = currentSheet();
  const f = APP.filters.status;
  const chip = (value, label, n, on) => `<button type="button" class="chip pick${on ? " on" : ""}"
    onclick="filterByStatus('${esc(value)}')">${esc(label)} <span class="n">${n}</span></button>`;
  document.getElementById("statusFilter").innerHTML = chip("", "전체", APP.rows.length, !f)
    + APP.boot.statuses.map((st) => chip(st, st, s.counts[st], !!f && f.size === 1 && f.has(st))).join("");
}

function filterByStatus(status) {
  APP.filters.status = status ? new Set([status]) : null;
  renderStatusFilter();
  renderGrid();
}

/* 해당없음 숨기기 — 기본 켬, 브라우저에 기억. 상태 거르기에서 해당없음을 직접 고르면 그때는 보여 준다. */
function hideExcluded() {
  try { return localStorage.getItem("schooltodo.hideExcluded") !== "0"; } catch (e) { return true; }
}
function setHideExcluded(on) {
  try { localStorage.setItem("schooltodo.hideExcluded", on ? "1" : "0"); } catch (e) { }
  renderGrid();
}
function excludedHidden() {
  const f = APP.filters.status;
  return hideExcluded() && !(f && f.has(APP.boot.status_excluded));
}

function visibleRows() {
  const q = document.getElementById("q").value.trim().toLowerCase();
  const f = APP.filters;
  const hideEx = excludedHidden();
  return APP.rows.filter((r) => (!f.ver || f.ver.has(r.ver))
    && (!f.vpn || f.vpn.has(r.vpn))
    && (!f.status || f.status.has(r.status))
    && !(hideEx && r.status === APP.boot.status_excluded)
    && (!q || schoolLabel(r).toLowerCase().includes(q) || r.note.toLowerCase().includes(q)));
}

function sortBy(key, dir) {
  APP.sort = { key, dir: dir || (APP.sort.key === key ? -APP.sort.dir : 1) };
  renderGrid();
}

function sortedRows(rows) {
  const { key, dir } = APP.sort;
  return rows.slice().sort((a, b) => dir * SORTERS[key](a, b) || natural(a.name, b.name) || natural(a.ver, b.ver));
}

/* ---------- 표 ---------- */

function renderHeads() {
  document.querySelectorAll(".grid th[data-col]").forEach((th) => {
    const col = th.dataset.col;
    const on = col === APP.sort.key;
    th.classList.toggle("sorted", on);
    th.querySelector(".arrow").textContent = on ? (APP.sort.dir > 0 ? "▲" : "▼") : "";
    th.querySelector(".colbtn").classList.toggle("filtered", !!APP.filters[col]);
  });
}

function groupHead(ver, rows) {
  const excluded = rows.filter((r) => r.status === APP.boot.status_excluded).length;
  const done = rows.filter((r) => r.status === APP.boot.status_done).length;
  return `<tr class="group"><td colspan="7"><span class="grp-in">${esc(ver || "버전 없음")}
    <span class="n">${esc(APP.boot.status_done)} ${done} / 대상 ${rows.length - excluded}</span>
    <button type="button" class="grp-btn" onclick="openGroupMenu(event, ${JSON.stringify(ver)})"
      title="이 버전 대학 일괄 변경" aria-label="${esc(ver || "버전 없음")} 일괄 변경">▾</button></span></td></tr>`;
}

/* 버전 묶음 머리줄 ▾ — 그 버전의 대학 전부(거르기·숨김과 무관)를 한 번에 바꾼다 (예: 3.2 전부 해당없음) */
function openGroupMenu(e, ver) {
  e.stopPropagation();
  closeHistory();
  APP.menuCol = null;
  APP.menuVer = ver;
  const rows = APP.rows.filter((r) => r.ver === ver);
  const menu = document.getElementById("colMenu");
  menu.innerHTML = `<div class="cm-title">${esc(ver || "버전 없음")} 대학 ${rows.length}곳 전부 일괄 변경</div><div class="cm-bulk">`
    + APP.boot.statuses.map((st, i) => `<button type="button" class="cm-st ${STATUS_CLASS[st]}" onclick="bulkStatusVer(${i})">${esc(st)}</button>`).join("")
    + `</div>`;
  const at = e.currentTarget.getBoundingClientRect();
  menu.classList.add("open");
  menu.style.top = at.bottom + 4 + "px";
  menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - menu.offsetWidth - 8)) + "px";
}

function bulkStatusVer(index) {
  const status = APP.boot.statuses[index];
  const ver = APP.menuVer;
  return bulkApply({ status }, `상태를 "${status}"(으)로 바꿉니다.`, APP.rows.filter((r) => r.ver === ver), `${ver || "버전 없음"} 대학 전부 — `);
}

/* ---------- 버전별 일괄 변경 창 ---------- */

function openVerBulk() {
  closeColMenu();
  const vers = [...new Set(APP.rows.map((r) => r.ver))].sort(natural);
  const countOf = (ver) => {
    const rows = APP.rows.filter((r) => r.ver === ver);
    const by = APP.boot.statuses.map((st) => `${st} ${rows.filter((r) => r.status === st).length}`).join(" · ");
    return { n: rows.length, by };
  };
  document.getElementById("vbList").innerHTML = vers.map((ver, i) => {
    const c = countOf(ver);
    return `<label class="vb-row"><input type="checkbox" value="${esc(ver)}" onchange="renderVerBulkCount()">
      <span class="vb-ver">${esc(ver || "버전 없음")}</span><span class="vb-n">${c.n}곳</span>
      <span class="vb-by">${esc(c.by)}</span></label>`;
  }).join("");
  const sel = document.getElementById("vbStatus");
  sel.innerHTML = APP.boot.statuses.map((st) => `<option value="${esc(st)}"${st === APP.boot.status_excluded ? " selected" : ""}>${esc(st)}</option>`).join("");
  renderVerBulkCount();
  document.getElementById("verBulkOverlay").classList.add("open");
}
function closeVerBulk() {
  document.getElementById("verBulkOverlay").classList.remove("open");
}
function verBulkPicked() {
  return [...document.querySelectorAll("#vbList input:checked")].map((el) => el.value);
}
function renderVerBulkCount() {
  const vers = new Set(verBulkPicked());
  const n = APP.rows.filter((r) => vers.has(r.ver)).length;
  document.getElementById("vbCount").textContent = vers.size ? `${vers.size}개 버전 · 대학 ${n}곳` : "버전을 고르세요";
  document.getElementById("vbApply").disabled = !vers.size;
}
async function applyVerBulk() {
  const vers = new Set(verBulkPicked());
  const status = document.getElementById("vbStatus").value;
  const rows = APP.rows.filter((r) => vers.has(r.ver));
  const label = [...vers].map((v) => v || "버전 없음").join(", ");
  closeVerBulk();
  return bulkApply({ status }, `상태를 "${status}"(으)로 바꿉니다.`, rows, `${label} 대학 전부 — `);
}

function renderGrid() {
  renderHeads();
  const hideBox = document.getElementById("hideExcluded");
  hideBox.checked = hideExcluded();
  hideBox.disabled = !!(APP.filters.status && APP.filters.status.has(APP.boot.status_excluded));
  const rows = sortedRows(visibleRows());
  const body = document.getElementById("gridBody");
  if (!rows.length) {
    body.innerHTML = `<tr><td class="none" colspan="7">${APP.rows.length ? "조건에 맞는 대학이 없어요" : "등록된 대학이 없어요"}</td></tr>`;
    return;
  }
  const vers = [...new Set(rows.map((r) => r.ver))].sort(natural);
  let no = 0;
  body.innerHTML = vers.map((ver) => {
    const group = rows.filter((r) => r.ver === ver);
    const html = groupHead(ver, group) + rowsHTML(group, no);
    no += group.length;
    return html;
  }).join("");
}

function schoolLabel(r) {
  return r.code ? `${r.name}(${r.code})` : r.name;
}

function rowsHTML(rows, offset) {
  return rows.map((r, i) => `<tr class="${STATUS_CLASS[r.status]}" data-school="${r.school_id}">
    <td class="c-no">${offset + i + 1}</td>
    <td class="c-name">${esc(schoolLabel(r))}</td>
    <td class="c-ver">${esc(r.ver)}</td>
    <td class="c-vpn">${r.vpn ? `<span class="vpn" title="${esc(r.vpn)}">${esc(r.vpn)}</span>` : ""}</td>
    <td class="c-status">
      <select onchange="saveCell(${r.school_id}, {status: this.value})">
        ${APP.boot.statuses.map((st) => `<option${st === r.status ? " selected" : ""}>${esc(st)}</option>`).join("")}
      </select>
    </td>
    <td class="c-note"><input type="text" value="${esc(r.note)}" maxlength="2000"
      onchange="saveCell(${r.school_id}, {note: this.value})" onkeydown="if(event.key==='Enter')this.blur()"></td>
    <td class="c-by">${r.updated_at ? `<button type="button" class="by-btn" title="이력 보기"
      onclick="openHistory(event, ${r.school_id})">${esc(r.updated_by)} · ${esc(r.updated_at.slice(5, 16))}</button>` : ""}</td>
  </tr>`).join("");
}

/* ---------- 저장 ---------- */

function saveCell(schoolId, patch) {
  return saveCells([{ school_id: schoolId, ...patch }]);
}

async function saveCells(items) {
  const sheetId = APP.sheetId;
  let res;
  try {
    res = await sendJSON("PUT", `/sheets/${sheetId}/cells`, { items });
  } catch (e) {
    showToast(e.message);
    renderGrid();
    return false;
  }
  if (APP.sheetId !== sheetId) return false;
  for (const row of res.rows) {
    const i = APP.rows.findIndex((r) => r.school_id === row.school_id);
    if (i >= 0) APP.rows[i] = row;
  }
  const s = currentSheet();
  for (const st of APP.boot.statuses) s.counts[st] = APP.rows.filter((r) => r.status === st).length;
  renderSheet();
  return true;
}

/* ---------- 열 머리 메뉴 ---------- */

function columnValues(col) {
  if (col === "status") return APP.boot.statuses;
  return [...new Set(APP.rows.map((r) => r[col]))].sort(natural);
}

function openColMenu(e, col) {
  e.stopPropagation();
  closeHistory();
  APP.menuCol = col;
  renderColMenu();
  const menu = document.getElementById("colMenu");
  const at = e.currentTarget.getBoundingClientRect();
  menu.classList.add("open");
  menu.style.top = at.bottom + 4 + "px";
  menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - menu.offsetWidth - 8)) + "px";
}

function closeColMenu() {
  APP.menuCol = null;
  APP.menuVer = null;
  document.getElementById("colMenu").classList.remove("open");
}

function renderColMenu() {
  const col = APP.menuCol;
  if (!col) return;
  const n = visibleRows().length;
  let html = `<button type="button" class="cm-item" onclick="sortBy('${col}', 1); closeColMenu()">▲ 오름차순 정렬</button>
    <button type="button" class="cm-item" onclick="sortBy('${col}', -1); closeColMenu()">▼ 내림차순 정렬</button>`;

  if (FILTER_COLS.includes(col)) {
    const f = APP.filters[col];
    html += `<div class="cm-sep"></div><div class="cm-title">거르기</div>
      <label class="cm-check"><input type="checkbox" ${f ? "" : "checked"} onchange="setColFilterAll(this.checked)"> (모두 선택)</label>`
      + columnValues(col).map((v, i) => `<label class="cm-check">
        <input type="checkbox" ${!f || f.has(v) ? "checked" : ""} onchange="setColFilterValue(${i}, this.checked)">
        ${esc(v || "(빈 값)")} <span class="n">${APP.rows.filter((r) => r[col] === v).length}</span></label>`).join("");
  }
  if (col === "status") {
    html += `<div class="cm-sep"></div><div class="cm-title">지금 보이는 ${n}곳 일괄 변경</div><div class="cm-bulk">`
      + APP.boot.statuses.map((st, i) => `<button type="button" class="cm-st ${STATUS_CLASS[st]}" onclick="bulkStatus(${i})">${esc(st)}</button>`).join("")
      + `</div>`;
  }
  if (col === "note") {
    html += `<div class="cm-sep"></div><div class="cm-title">지금 보이는 ${n}곳 일괄 입력</div>
      <div class="cm-bulk"><input type="text" id="bulkNote" maxlength="2000" placeholder="메모 (비우면 지움)"
        onkeydown="if(event.key==='Enter')bulkNote()">
      <button type="button" class="cm-apply" onclick="bulkNote()">채우기</button></div>`;
  }
  document.getElementById("colMenu").innerHTML = html;
}

function setColFilterAll(checked) {
  APP.filters[APP.menuCol] = checked ? null : new Set();
  afterColFilter();
}

function setColFilterValue(index, checked) {
  const col = APP.menuCol;
  const values = columnValues(col);
  const picked = new Set(APP.filters[col] || values);
  if (checked) picked.add(values[index]); else picked.delete(values[index]);
  APP.filters[col] = picked.size === values.length ? null : picked;
  afterColFilter();
}

function afterColFilter() {
  renderStatusFilter();
  renderGrid();
  renderColMenu();
}

function bulkStatus(index) {
  const status = APP.boot.statuses[index];
  return bulkApply({ status }, `상태를 "${status}"(으)로 바꿉니다.`);
}

function bulkNote() {
  const note = document.getElementById("bulkNote").value.trim();
  return bulkApply({ note }, note ? `메모를 "${note}"(으)로 채웁니다.` : "메모를 지웁니다.");
}

async function bulkApply(patch, what, rows, scope) {
  rows = rows || visibleRows();
  if (!rows.length) { showToast("바꿀 대학이 없어요"); return; }
  if (!confirm(`${scope || "지금 보이는 "}${rows.length}곳의 ${what}\n계속할까요?`)) return;
  closeColMenu();
  if (await saveCells(rows.map((r) => ({ school_id: r.school_id, ...patch })))) {
    showToast(`${rows.length}곳을 바꿨습니다`);
  }
}

// 메뉴를 다시 그리면 눌린 요소가 문서에서 빠져 contains 로는 안쪽 클릭을 가릴 수 없다
document.getElementById("colMenu").addEventListener("click", (e) => e.stopPropagation());
document.addEventListener("click", () => { if (APP.menuCol || APP.menuVer != null) closeColMenu(); });

/* ---------- 링크 복사 ---------- */

// PWA(설치형)로 열면 주소창이 없어 탭의 주소를 가져갈 길이 없다
async function copySheetLink(id) {
  id = id || APP.sheetId;
  if (!id) return;
  const url = new URL(location.pathname + "?sheet=" + id, location.href).href;
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(url);
    } else {
      // http 로 띄운 개발 PC 에서는 clipboard API 가 막혀 있다
      const ta = document.createElement("textarea");
      ta.value = url;
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      ta.remove();
    }
    showToast("링크를 복사했습니다");
  } catch (e) {
    prompt("복사가 막혀 있어요. 직접 복사해 주세요:", url);
  }
}

/* ---------- 이력 ---------- */

async function openHistory(e, schoolId) {
  e.stopPropagation();
  closeColMenu();
  closeTabMenu();
  const sheetId = APP.sheetId;
  const at = e.currentTarget.getBoundingClientRect();
  const menu = document.getElementById("histMenu");
  APP.historySchool = schoolId;
  menu.innerHTML = `<div class="hist-empty">불러오는 중…</div>`;
  menu.classList.add("open");
  placeHistory(menu, at);

  let res;
  try {
    res = await api(`/sheets/${sheetId}/cells/${schoolId}/history`);
  } catch (err) {
    closeHistory();
    if (err.message !== "unauthenticated") showToast(err.message);
    return;
  }
  if (APP.sheetId !== sheetId || APP.historySchool !== schoolId) return;
  const row = APP.rows.find((r) => r.school_id === schoolId);
  if (!row) { closeHistory(); return; }
  menu.innerHTML = historyHTML(row, res.history);
  placeHistory(menu, at);
}

function placeHistory(menu, at) {
  const below = at.bottom + 4;
  menu.style.top = (below + menu.offsetHeight > window.innerHeight - 8
    ? Math.max(8, at.top - menu.offsetHeight - 4) : below) + "px";
  menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - menu.offsetWidth - 8)) + "px";
}

function closeHistory() {
  APP.historySchool = null;
  document.getElementById("histMenu").classList.remove("open");
}

function historyHTML(row, history) {
  const item = (h, now) => `<div class="hist-item${now ? " now" : ""}">
    <div class="hist-head"><span class="hist-st ${STATUS_CLASS[h.status] || ""}">${esc(h.status)}</span>
      <span>${h.updated_at ? `${esc(h.updated_by)} · ${esc(h.updated_at.slice(0, 16))}` : "기록 없음"}</span>
      ${now ? `<span class="hist-now">현재</span>` : ""}</div>
    <div class="hist-note${h.note ? "" : " blank"}">${h.note ? esc(h.note) : "메모 없음"}</div></div>`;
  return `<div class="cm-title">${esc(schoolLabel(row))} · ${esc(row.ver)} 이력</div>`
    + item(row, true)
    + (history.length ? history.map((h) => item(h, false)).join("") : `<div class="hist-empty">이전 값이 없어요</div>`)
    + `<div class="hist-foot">바뀌기 전 값을 최근 ${APP.boot.history_max}건까지 보관합니다</div>`;
}

document.getElementById("histMenu").addEventListener("click", (e) => e.stopPropagation());
document.addEventListener("click", () => { if (APP.historySchool !== null) closeHistory(); });

/* ---------- 내려받기 ---------- */

function downloadCsv() {
  const s = currentSheet();
  if (!s) return;
  const cell = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
  const lines = [["대학", "버전", "VPN", "상태", "메모", "갱신한 사람", "갱신 시각"]]
    .concat(APP.rows.map((r) => [schoolLabel(r), r.ver, r.vpn, r.status, r.note, r.updated_by, r.updated_at]))
    .map((cols) => cols.map(cell).join(","));
  // BOM 이 없으면 엑셀이 한글을 깨뜨린다
  const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = s.title.replace(/[\\/:*?"<>|]/g, "_") + ".csv";
  a.click();
  URL.revokeObjectURL(a.href);
}
