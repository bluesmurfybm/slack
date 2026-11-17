function renderStatusFilter() {
  const s = currentSheet();
  const total = APP.rows.length;
  const chip = (value, label, n) => `<button type="button" class="chip pick${APP.filterStatus === value ? " on" : ""}"
    onclick="filterByStatus('${esc(value)}')">${esc(label)} <span class="n">${n}</span></button>`;
  document.getElementById("statusFilter").innerHTML =
    chip("", "전체", total) + APP.boot.statuses.map((st) => chip(st, st, s.counts[st])).join("");
}

function filterByStatus(status) {
  APP.filterStatus = status;
  renderStatusFilter();
  renderGrid();
}

function visibleRows() {
  const q = document.getElementById("q").value.trim().toLowerCase();
  return APP.rows.filter((r) => (!APP.filterStatus || r.status === APP.filterStatus)
    && (!q || r.name.toLowerCase().includes(q) || r.note.toLowerCase().includes(q)));
}

function renderGrid() {
  const rows = visibleRows();
  const body = document.getElementById("gridBody");
  if (!rows.length) {
    body.innerHTML = `<tr><td class="none" colspan="6">${APP.rows.length ? "조건에 맞는 대학이 없어요" : "등록된 대학이 없어요"}</td></tr>`;
    return;
  }
  body.innerHTML = rows.map((r, i) => `<tr class="${STATUS_CLASS[r.status]}" data-school="${r.school_id}">
    <td class="c-no">${i + 1}</td>
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

async function saveCell(schoolId, patch) {
  const sheetId = APP.sheetId;
  let res;
  try {
    res = await sendJSON("PUT", `/sheets/${sheetId}/cells`, { items: [{ school_id: schoolId, ...patch }] });
  } catch (e) {
    showToast(e.message);
    renderGrid();
    return;
  }
  if (APP.sheetId !== sheetId) return;
  for (const row of res.rows) {
    const i = APP.rows.findIndex((r) => r.school_id === row.school_id);
    if (i >= 0) APP.rows[i] = row;
  }
  const s = currentSheet();
  for (const st of APP.boot.statuses) s.counts[st] = APP.rows.filter((r) => r.status === st).length;
  renderSheet();
}

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
