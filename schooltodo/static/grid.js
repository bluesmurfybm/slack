const natural = (a, b) => String(a).localeCompare(String(b), "ko", { numeric: true });

const SORTERS = {
  name: (a, b) => natural(a.name, b.name),
  ver: (a, b) => natural(a.ver, b.ver),
  status: (a, b) => APP.boot.statuses.indexOf(a.status) - APP.boot.statuses.indexOf(b.status),
  note: (a, b) => natural(a.note, b.note),
  updated_at: (a, b) => natural(a.updated_at, b.updated_at),
};

const FILTER_COLS = ["ver", "status"];

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

function visibleRows() {
  const q = document.getElementById("q").value.trim().toLowerCase();
  const f = APP.filters;
  return APP.rows.filter((r) => (!f.ver || f.ver.has(r.ver))
    && (!f.status || f.status.has(r.status))
    && (!q || r.name.toLowerCase().includes(q) || r.note.toLowerCase().includes(q)));
}

function sortBy(key, dir) {
  APP.sort = { key, dir: dir || (APP.sort.key === key ? -APP.sort.dir : 1) };
  renderGrid();
}

function toggleGroupByVer() {
  APP.groupByVer = !APP.groupByVer;
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
  document.getElementById("groupBtn").classList.toggle("on", APP.groupByVer);
}

function groupHead(ver, rows) {
  const excluded = rows.filter((r) => r.status === APP.boot.status_excluded).length;
  const done = rows.filter((r) => r.status === APP.boot.status_done).length;
  return `<tr class="group"><td colspan="6">${esc(ver || "버전 없음")}
    <span class="n">완료 ${done} / 대상 ${rows.length - excluded}</span></td></tr>`;
}

function renderGrid() {
  renderHeads();
  const rows = sortedRows(visibleRows());
  const body = document.getElementById("gridBody");
  if (!rows.length) {
    body.innerHTML = `<tr><td class="none" colspan="6">${APP.rows.length ? "조건에 맞는 대학이 없어요" : "등록된 대학이 없어요"}</td></tr>`;
    return;
  }
  if (!APP.groupByVer) {
    body.innerHTML = rowsHTML(rows, 0);
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

function rowsHTML(rows, offset) {
  return rows.map((r, i) => `<tr class="${STATUS_CLASS[r.status]}" data-school="${r.school_id}">
    <td class="c-no">${offset + i + 1}</td>
    <td class="c-name">${esc(r.name)}</td>
    <td class="c-ver">${esc(r.ver)}</td>
    <td class="c-status">
      <select onchange="saveCell(${r.school_id}, {status: this.value})">
        ${APP.boot.statuses.map((st) => `<option${st === r.status ? " selected" : ""}>${esc(st)}</option>`).join("")}
      </select>
    </td>
    <td class="c-note"><input type="text" value="${esc(r.note)}" maxlength="2000"
      onchange="saveCell(${r.school_id}, {note: this.value})" onkeydown="if(event.key==='Enter')this.blur()"></td>
    <td class="c-by">${r.updated_at ? `${esc(r.updated_by)} · ${esc(r.updated_at.slice(5, 16))}` : ""}</td>
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

async function bulkApply(patch, what) {
  const rows = visibleRows();
  if (!rows.length) { showToast("바꿀 대학이 없어요"); return; }
  if (!confirm(`지금 보이는 ${rows.length}곳의 ${what}\n계속할까요?`)) return;
  closeColMenu();
  if (await saveCells(rows.map((r) => ({ school_id: r.school_id, ...patch })))) {
    showToast(`${rows.length}곳을 바꿨습니다`);
  }
}

// 메뉴를 다시 그리면 눌린 요소가 문서에서 빠져 contains 로는 안쪽 클릭을 가릴 수 없다
document.getElementById("colMenu").addEventListener("click", (e) => e.stopPropagation());
document.addEventListener("click", () => { if (APP.menuCol) closeColMenu(); });

/* ---------- 내려받기 ---------- */

function downloadCsv() {
  const s = currentSheet();
  if (!s) return;
  const cell = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
  const lines = [["대학", "버전", "상태", "메모", "갱신한 사람", "갱신 시각"]]
    .concat(APP.rows.map((r) => [r.name, r.ver, r.status, r.note, r.updated_by, r.updated_at]))
    .map((cols) => cols.map(cell).join(","));
  // BOM 이 없으면 엑셀이 한글을 깨뜨린다
  const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = s.title.replace(/[\\/:*?"<>|]/g, "_") + ".csv";
  a.click();
  URL.revokeObjectURL(a.href);
}
