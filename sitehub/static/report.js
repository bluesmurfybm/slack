// 내역서 탭 — 위에 하위 탭 두 개: 유지보수내역서(이 파일) · 청구서(static/bill.js). 고른 하위 탭은 이 브라우저에 기억한다.
// 유지보수내역서 — 번호 붙은 세 칸: 1 양식(.hwpx 올리기 · 채우기 규칙) · 2 만들기(기간 불러오기 → 행 고치기 → hwpx·pdf) · 3 만든 내역서. report_lib.php
// 행은 정기 점검 줄(요일마다, 달마다 마지막은 정기점검 문구) + 그 기간의 Slack 이력. 만들 때 보낸 행 그대로 양식에 채운다.

const REP = { siteId: null, data: null, rows: null, stats: null, holidays: [], from: "", to: "", reportDate: "", fileName: "", fields: null, dirty: false, err: "", rulesOpen: false,
  sub: (() => { try { return localStorage.getItem("sh_rep_sub") === "bill" ? "bill" : "report"; } catch (e) { return "report"; } })() };

// 하위 탭 — 유지보수내역서 | 청구서. 숫자는 불러온 쪽만(만든 개수)
function repSubTabs() {
  const id = APP.open.id;
  const n = { report: REP.data && REP.siteId === id ? (REP.data.reports || []).length : 0,
              bill: typeof BILL !== "undefined" && BILL.data && BILL.siteId === id ? (BILL.data.bills || []).length : 0 };
  const tab = (k, label) => `<button type="button" role="tab" id="repSub-${k}" aria-selected="${REP.sub === k}" class="${REP.sub === k ? "on" : ""}" onclick="repSub('${k}')">${label}${n[k] ? ` <span class="n">${n[k]}</span>` : ""}</button>`;
  return `<div class="sh-rep-tabs" role="tablist" aria-label="내역서 종류">${tab("report", "유지보수내역서")}${tab("bill", "청구서")}</div>`;
}

function repSub(k) {
  if (REP.sub === k) return;
  REP.sub = k;
  try { localStorage.setItem("sh_rep_sub", k); } catch (e) { /* 기억 못 해도 된다 */ }
  const s = siteById(APP.open.id);
  if (!s) return;
  $("dBody").scrollTop = 0;
  // 이미 불러온 같은 사이트 것은 바로 그리고, 뒤에서 새로 받아 다시 그린다
  if (k === "bill" && BILL.data && BILL.siteId === s.id) renderBill();
  else if (k === "report" && REP.data && REP.siteId === s.id) renderReport();
  else $("dBody").innerHTML = `<div class="sh-rep">${repSubTabs()}<div class="d-sec"><div class="sh-synced">불러오는 중…</div></div></div>`;
  loadReport(s);
}

async function loadReport(s) {
  if (REP.sub === "bill") return loadBill(s);
  try {
    const j = await api("?report=" + s.id);
    if (APP.open.id !== s.id || APP.open.tab !== "report" || REP.sub !== "report") return;
    if (REP.siteId !== s.id) Object.assign(REP, { siteId: s.id, rows: null, stats: null, reportDate: "", fileName: "", fields: null, dirty: false, err: "", from: "", to: "" });
    REP.data = j;
    if (!REP.from) { const p = repNextPeriod() || repPresetRange("last3"); REP.from = p.from; REP.to = p.to; }
    renderReport();
  } catch (e) {
    $("dBody").innerHTML = `<div class="sh-rep">${repSubTabs()}<div class="d-sec"><div class="sh-synced">${esc(e.message)}</div></div></div>`;
  }
}

function renderReport() {
  const s = siteById(REP.siteId);
  if (!s || APP.open.id !== s.id || APP.open.tab !== "report" || REP.sub !== "report" || !REP.data) return;
  const body = $("dBody"), top = body.scrollTop;
  body.innerHTML = `<div class="sh-rep">${repSubTabs()}${repStepsNav()}${repTplSection(s)}${repMakeSection(s)}${repListSection()}</div>`;
  body.scrollTop = top;
}

// 세 단계를 번호 붙은 칸으로 나눈다 — 1 양식(사이트마다 한 번) · 2 만들기(기간마다) · 3 만든 내역서
function repStepsNav() {
  const t = REP.data.tpl, n = (REP.data.reports || []).length;
  const step = (i, label, state, extra) => `<button type="button" class="sh-rep-step ${state}" onclick="repGo(${i})"><span class="sh-rep-num">${state === "done" ? "✓" : i}</span><span>${label}${extra}</span></button>`;
  return `<nav class="sh-rep-steps" aria-label="내역서 단계">
    ${step(1, "양식", t ? "done" : "now", "")}<span class="sh-rep-arrow" aria-hidden="true">›</span>
    ${step(2, "내역서 만들기", t ? "now" : "", "")}<span class="sh-rep-arrow" aria-hidden="true">›</span>
    ${step(3, "만든 내역서", n ? "done" : "", n ? ` <b>${n}</b>` : "")}
  </nav>`;
}

function repGo(i) {
  const el = $("repStep" + i);
  if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
}

function repCard(i, title, desc, actions, body, state) {
  return `<section class="sh-rep-card ${state || ""}" id="repStep${i}">
    <header class="sh-rep-card-h"><span class="sh-rep-num">${state === "done" ? "✓" : i}</span>
      <div class="sh-rep-card-t"><h5>${title}</h5><p>${desc}</p></div>${actions ? `<div class="sh-rep-card-a">${actions}</div>` : ""}</header>
    <div class="sh-rep-card-b">${body}</div>
  </section>`;
}

/* ── 날짜 ── */
const repYmd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const repParse = v => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v || ""); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };

function repPresetRange(kind) {
  const n = new Date(), y = n.getFullYear(), m = n.getMonth();
  const r = { last3: [new Date(y, m - 3, 1), new Date(y, m, 0)], lastmonth: [new Date(y, m - 1, 1), new Date(y, m, 0)], thismonth: [new Date(y, m, 1), new Date(y, m + 1, 0)] }[kind];
  return { from: repYmd(r[0]), to: repYmd(r[1]) };
}

// 마지막 내역서 다음 기간 — 같은 달 수로. 내역서가 없으면 양식 제목의 기간 다음
function repNextPeriod() {
  const last = (REP.data && REP.data.reports || [])[0];
  const per = REP.data && REP.data.tpl && REP.data.tpl.analysis && REP.data.tpl.analysis.period;
  let a, b;
  if (last) { a = repParse(last.period_from); b = repParse(last.period_to); }
  else if (per) { a = repParse(per.from + "-01"); const e = repParse(per.to + "-01"); b = new Date(e.getFullYear(), e.getMonth() + 1, 0); }
  if (!a || !b) return null;
  const months = (b.getFullYear() - a.getFullYear()) * 12 + b.getMonth() - a.getMonth() + 1;
  const f = new Date(b.getFullYear(), b.getMonth() + 1, 1);
  return { from: repYmd(f), to: repYmd(new Date(f.getFullYear(), f.getMonth() + months, 0)) };
}

function repPreset(kind) {
  const p = kind === "next" ? repNextPeriod() : repPresetRange(kind);
  if (!p) return;
  $("repFrom").value = p.from;
  $("repTo").value = p.to;
}

// 양식의 날짜 모양(report_lib.php sitehub_report_fmt_date 와 같게) — 표 복사에 쓴다
function repFmtDate(v) {
  const d = repParse(v);
  if (!d) return v || "";
  const f = (REP.data.tpl && REP.data.tpl.settings.date_fmt) || REP.data.defaults.date_fmt;
  const z = n => (f.pad ? String(n).padStart(2, "0") : String(n));
  const tail = f.tail === "(요일)" ? `(${"일월화수목금토"[d.getDay()]})` : (f.tail || "");
  return `${d.getFullYear()}${f.sep1}${z(d.getMonth() + 1)}${f.sep2}${z(d.getDate())}${tail}`;
}

/* ── 양식 ── */
function repTplSection(s) {
  const t = REP.data.tpl, others = REP.data.others || [];
  const input = `<input type="file" id="repFile" accept=".hwpx,.hwp" hidden onchange="repUpload(this.files[0]); this.value=''">`;
  const err = REP.err ? `<p class="sh-rep-err">${esc(REP.err)}</p>` : "";
  const copySel = others.length ? `<select class="sh-rep-copy" onchange="repCopyFrom(this)"><option value="">다른 사이트 양식 가져오기…</option>${others.map(o => `<option value="${o.site_id}" title="${esc(o.file_name)}">${esc(o.name)}</option>`).join("")}</select>` : "";
  const desc = "고객사에 내는 한글 양식과 채우는 규칙 — 사이트마다 한 번 올려 둡니다";
  if (!t) {
    return repCard(1, "내역서 양식", desc, "", `${input}
      <div class="sh-rep-drop" ondragover="event.preventDefault();this.classList.add('over')" ondragleave="this.classList.remove('over')" ondrop="repDrop(event, this)">
        <p><b>고객사에 내던 유지보수 내역서(한글)를 올리세요.</b> 표 모양 그대로 기간의 점검·작업 내역을 채워 만듭니다.</p>
        <p class="muted">예전 <b>.hwp</b> 는 한글에서 <b>파일 → 다른 이름으로 저장 → 파일 형식: 한글 표준 문서(*.hwpx)</b> 로 저장해 올려 주세요.
          표에 ‘수정일시 | 세부내용’ 같은 머리 행과 그 아래 견본 행이 한 줄 이상 있어야 합니다.</p>
        <div class="sh-rep-btns"><button type="button" class="btn-mini primary" onclick="$('repFile').click()">양식 올리기 (.hwpx)</button>${copySel}<span class="muted">또는 파일을 여기로 끌어 놓기</span></div>
        ${err}
      </div>`, "now");
  }
  const a = t.analysis || {}, f = a.fields || {};
  const kb = n => (n > 1048576 ? (n / 1048576).toFixed(1) + "MB" : Math.max(1, Math.round(n / 1024)) + "KB");
  const head = (a.head || []).join(" | ");
  const actions = `${input}
        <button type="button" class="btn-mini ghost" onclick="repDownload('?report_tpl_file=${s.id}', ${esc(JSON.stringify(t.file_name))})">받기</button>
        <button type="button" class="btn-mini ghost" onclick="$('repFile').click()">바꾸기</button>
        <button type="button" class="btn-mini ghost danger" onclick="repDeleteTpl()">지우기</button>`;
  return repCard(1, "내역서 양식", desc, actions, `
    <div class="sh-rep-file">
      <div class="sh-rep-fname"><span class="sh-rep-ico">HWPX</span><b>${esc(t.file_name)}</b>
        <span class="muted">${kb(t.file_size)} · ${esc(String(t.updated_at || "").slice(0, 16))}${t.updated_name ? " · " + esc(t.updated_name) : ""}</span></div>
      ${t.exists ? "" : `<p class="sh-rep-err">서버에 양식 파일이 없습니다 — 다시 올려 주세요.</p>`}
      <dl class="sh-rep-found">
        ${a.title ? `<dt>제목</dt><dd>${esc(a.title)} <span class="muted">— 기간만 바꿉니다</span></dd>` : `<dt>제목</dt><dd class="muted">기간(YYYY년 M월 ~ M월)이 든 제목을 못 찾아 그대로 둡니다</dd>`}
        <dt>내역 표</dt><dd>${esc(head)} · 내용 행 ${a.columns}칸${a.has_result ? "(결과 칸 있음)" : "(결과는 내용 끝에 붙임)"} · 견본 ${a.sample_rows}줄</dd>
        <dt>머리 칸</dt><dd>${[["일자", f.date], ["유지보수대상", f.target], ["점검자", f.inspector]].map(([k, v]) => v == null ? `<span class="muted">${k} 없음</span>` : `${k} <b>${esc(v || "(빈칸)")}</b>`).join(" · ")}</dd>
      </dl>
      ${err}
    </div>
    ${repRulesHtml(t)}
    ${copySel ? `<div class="sh-rep-other">${copySel}</div>` : ""}`, "done");
}

function repRulesHtml(t) {
  const st = t.settings, wd = REP.data.weekdays || {};
  const opt = (v, cur, label) => `<option value="${v}"${String(v) === String(cur) ? " selected" : ""}>${esc(label)}</option>`;
  return `<details class="sh-rep-rules" id="repRules"${REP.rulesOpen ? " open" : ""} ontoggle="REP.rulesOpen=this.open">
    <summary>채우기 규칙 <span class="muted">— 양식의 견본 행에서 뽑았습니다. 고쳐서 저장할 수 있어요</span></summary>
    <div class="sh-rep-form">
      <p class="sh-rep-sub">정기 점검</p>
      <div class="two">
        <label class="field"><span>정기 점검 요일</span><select data-k="weekday">${Object.entries(wd).map(([k, v]) => opt(k, st.weekday, k === "0" ? "정기 점검 줄 넣지 않음" : `매주 ${v}요일`)).join("")}</select></label>
        <label class="field"><span>점검 결과</span><input type="text" data-k="mon_result" value="${esc(st.mon_result)}"></label>
      </div>
      <label class="field"><span>점검 문구</span><input type="text" data-k="mon_text" value="${esc(st.mon_text)}"></label>
      <label class="field"><span class="sh-rep-chk"><input type="checkbox" data-k="month_on"${Number(st.month_on) ? " checked" : ""}><span>달마다 마지막 점검일은 이 문구로 <small class="muted">(____ 는 만들 때 채울 빈칸)</small></span></span>
        <textarea data-k="month_text" rows="2">${esc(st.month_text)}</textarea></label>
      <label class="sh-rep-chk"><input type="checkbox" data-k="holiday_shift"${Number(st.holiday_shift) ? " checked" : ""}><span>점검일이 공휴일이면 그 전 평일로 <small class="muted">(목·금이 다 쉬면 수요일, 기간 첫 주라 앞이 없으면 다음 평일)</small></span></label>
      <label class="field"><span>추가 휴일 · 임시공휴일 <small class="muted">— 모든 사이트 공통. 한 줄에 하나, 빼려면 앞에 -</small></span>
        <textarea data-k="holiday_extra" rows="2" placeholder="2026-10-02 임시공휴일&#10;-2026-05-01">${esc(REP.data.holiday_extra || "")}</textarea></label>
      <p class="sh-rep-sub">Slack 이력</p>
      <div class="two">
        <label class="field"><span>Slack 이력</span><select data-k="hist_status">${opt("done", st.hist_status, "처리완료만")}${opt("open", st.hist_status, "취소·보류만 빼고 전부")}</select></label>
        <label class="field"><span>이력 날짜 기준</span><select data-k="hist_date">${opt("done", st.hist_date, "처리일 (없으면 요청일)")}${opt("req", st.hist_date, "요청일")}</select></label>
      </div>
      <label class="field"><span>이력 결과</span><input type="text" data-k="work_result" value="${esc(st.work_result)}" placeholder="${t.analysis.has_result ? "완료" : "비우면 붙이지 않음"}"></label>
      <label class="sh-rep-chk"><input type="checkbox" data-k="incidents"${Number(st.incidents) ? " checked" : ""}><span>BLUMO 장애가 있던 주는 점검 결과에 장애로 적기</span></label>
      <p class="sh-rep-sub">머리 칸 · 글꼴</p>
      <div class="two">
        <label class="field"><span>점검자</span><input type="text" data-k="inspector" value="${esc(st.inspector)}"></label>
        <label class="field"><span>유지보수대상</span><input type="text" data-k="target" value="${esc(st.target)}"></label>
      </div>
      <label class="field"><span>비고 (기본값)</span><textarea data-k="remark" rows="2">${esc(st.remark)}</textarea></label>
      <div class="two">
        <label class="field"><span>hwpx 글꼴 <small class="muted">— PDF 는 늘 Pretendard</small></span><select data-k="font">${opt("pretendard", st.font || "pretendard", "Pretendard 로 통일")}${opt("template", st.font, "양식 글꼴 그대로")}</select></label>
        <label class="field"><span>제목 글자 크기 <small class="muted">— 한 줄에 안 들어가면 그만큼 줄임</small></span><select data-k="title_grow">${[0, 2, 4, 6, 8].map(g => {
          const base = t.analysis.title_size;
          return opt(g, st.title_grow ?? 4, g ? `+${g}pt${base ? ` (${base + g}pt)` : ""}` : `양식 그대로${base ? ` (${base}pt)` : ""}`);
        }).join("")}</select></label>
      </div>
      <div class="sh-rep-btns"><button type="button" class="btn-mini primary" onclick="repSaveRules(this)">규칙 저장</button></div>
    </div>
  </details>`;
}

async function repUpload(file) {
  if (!file) return;
  REP.err = "";
  if (/\.hwp$/i.test(file.name)) {
    REP.err = ".hwp(예전 한글 파일)는 채울 수 없습니다. 한글에서 [파일 → 다른 이름으로 저장 → 파일 형식: 한글 표준 문서(*.hwpx)]로 저장해 그 파일을 올려 주세요.";
    renderReport();
    return;
  }
  if (REP.data.tpl && !(await askConfirm("양식 바꾸기", `‘${file.name}’ 으로 바꿉니다. 견본 행에서 채우기 규칙을 다시 뽑습니다(이력 상태·기준일·장애 반영은 그대로).`, "바꾸기"))) return;
  const fd = new FormData();
  fd.append("action", "report_tpl_upload");
  fd.append("site_id", REP.siteId);
  fd.append("file", file);
  showToast("양식을 읽는 중…");
  try {
    const j = await api("", { method: "POST", body: fd });
    REP.data.tpl = j.tpl;
    if (j.site) replaceSite(j.site);
    Object.assign(REP, { rows: null, from: "", to: "", dirty: false });   // 양식 제목의 기간 다음으로 다시 잡는다
    showToast("양식을 올렸습니다 — 표를 읽어 채우기 규칙을 만들었습니다");
    loadReport(siteById(REP.siteId));
  } catch (e) {
    REP.err = e.message;
    renderReport();
    showToast("양식을 올리지 못했습니다");
  }
}

function repDrop(ev, el) {
  ev.preventDefault();
  el.classList.remove("over");
  const f = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
  if (f) repUpload(f);
}

async function repCopyFrom(sel) {
  const from = Number(sel.value), o = (REP.data.others || []).find(x => x.site_id === from);
  sel.value = "";
  if (!o) return;
  if (!(await askConfirm("다른 사이트 양식 가져오기", `‘${o.name}’ 의 양식(${o.file_name})과 채우기 규칙을 이 사이트에 복사합니다. 유지보수대상은 이 사이트의 운영 URL 로 바꿉니다.`, "가져오기"))) return;
  try {
    await postJSON({ action: "report_tpl_copy", site_id: REP.siteId, from });
    Object.assign(REP, { rows: null, from: "", to: "", dirty: false });
    showToast("양식을 가져왔습니다");
    loadReport(siteById(REP.siteId));
  } catch (e) { showToast(e.message); }
}

async function repDeleteTpl() {
  if (!(await askConfirm("양식 지우기", "올린 양식과 채우기 규칙을 지웁니다. 만든 내역서 파일은 남습니다.", "지우기"))) return;
  try {
    await postJSON({ action: "report_tpl_delete", site_id: REP.siteId });
    REP.data.tpl = null;
    loadReport(siteById(REP.siteId));
  } catch (e) { showToast(e.message); }
}

async function repSaveRules(btn) {
  const root = $("repRules"), v = k => root.querySelector(`[data-k="${k}"]`);
  const settings = {};
  ["weekday", "mon_text", "mon_result", "month_text", "hist_status", "hist_date", "work_result", "inspector", "target", "remark", "font", "title_grow"].forEach(k => { settings[k] = v(k).value; });
  ["month_on", "incidents", "holiday_shift"].forEach(k => { settings[k] = v(k).checked ? 1 : 0; });
  const extra = v("holiday_extra").value;
  btn.disabled = true;
  try {
    if (extra !== (REP.data.holiday_extra || "")) REP.data.holiday_extra = (await postJSON({ action: "report_holidays", site_id: REP.siteId, text: extra })).text;
    const j = await postJSON({ action: "report_settings", site_id: REP.siteId, settings });
    REP.data.tpl = j.tpl;
    showToast(REP.rows ? "규칙을 저장했습니다 — 불러오기를 다시 누르면 반영됩니다" : "규칙을 저장했습니다");
    renderReport();
  } catch (e) { showToast(e.message); } finally { btn.disabled = false; }
}

/* ── 만들기 ── */
function repMakeSection(s) {
  const t = REP.data.tpl;
  const presets = [["next", "다음 기간", !!repNextPeriod()], ["last3", "직전 3개월", true], ["lastmonth", "지난달", true], ["thismonth", "이번 달", true]]
    .filter(p => p[2]).map(([k, l]) => `<button type="button" class="btn-mini ghost" onclick="repPreset('${k}')">${l}</button>`).join("");
  return repCard(2, "내역서 만들기", "기간을 고르고 불러와 행을 확인·수정한 뒤 hwpx · pdf 로 받습니다", "", `
    <div class="sh-rep-period">
      <input type="date" id="repFrom" value="${esc(REP.from)}"><span>~</span><input type="date" id="repTo" value="${esc(REP.to)}">
      <button type="button" class="btn-mini primary" onclick="repPreview(this)">불러오기</button>
    </div>
    <div class="sh-rep-presets">${presets}</div>
    ${t ? "" : `<p class="sh-synced">양식이 없어 기본 규칙(매주 금요일 모니터링)으로 불러옵니다. 양식을 올리면 그 모양의 한글 파일로 받을 수 있고, 지금은 표 복사만 됩니다.</p>`}
    ${REP.rows ? repEditorHtml(s) : ""}`, t ? "now" : "");
}

async function repPreview(btn) {
  const from = $("repFrom").value, to = $("repTo").value;
  if (!from || !to || from > to) { showToast("기간을 확인하세요"); return; }
  if (REP.dirty && !(await askConfirm("다시 불러오기", "고친 행이 사라지고 기간의 내역을 새로 불러옵니다.", "불러오기"))) return;
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "report_preview", site_id: REP.siteId, from, to });
    const s = siteById(REP.siteId), st = (REP.data.tpl && REP.data.tpl.settings) || REP.data.defaults;
    Object.assign(REP, {
      from, to, reportDate: to, rows: j.rows.map(r => ({ date: r.date, lines: r.lines, result: r.result, kind: r.kind, warn: r.warn, note: r.note || "" })),
      stats: j.stats, holidays: j.holidays || [], fileName: j.file_name, dirty: false,
      fields: { target: st.target || (s && s.url_ops) || "", inspector: st.inspector || "", remark: st.remark || "" },
    });
    renderReport();
  } catch (e) { showToast(e.message); } finally { btn.disabled = false; }
}

const repWarn = r => [r.warn, r.lines.some(l => l.includes("____")) ? "빈칸(____)을 채우세요" : ""].filter(Boolean).join(" · ");
const REP_KIND = { monitor: "점검", month: "월 정기점검", work: "이력", "monitor+work": "점검+이력", "month+work": "월 정기점검+이력" };

function repEditorHtml() {
  const t = REP.data.tpl, st = REP.stats || {}, f = REP.fields;
  const hasResult = !t || t.analysis.has_result;
  const sum = [st.monitor ? `정기 점검 ${st.monitor}` : "", st.month ? `월 정기점검 ${st.month}` : "", `Slack 이력 ${st.work || 0}건`,
    st.skipped ? `<span class="muted">${((t && t.settings.hist_status) || "done") === "done" ? "처리완료가 아니라" : "취소·보류라"} 뺀 ${st.skipped}건</span>` : "", st.incident ? `<b class="sh-rep-warn-t">장애 표시 ${st.incident}줄</b>` : "",
    st.shifted ? `공휴일로 옮긴 점검 ${st.shifted}` : ""].filter(Boolean).join(" · ");
  const hol = (REP.holidays || []).length ? `<details class="sh-rep-hol"><summary>기간 공휴일 ${REP.holidays.length}일</summary>${REP.holidays.map(h => `<span>${esc(h.date.slice(5))} ${esc(h.name)}</span>`).join("")}</details>` : "";
  const warnN = REP.rows.filter(repWarn).length;
  return `<div class="sh-rep-sum">${sum}${REP.dirty ? ` · <span class="muted">고친 내용 있음</span>` : ""}${hol}</div>
    <div class="sh-rep-form sh-rep-head">
      <div class="two">
        <label class="field"><span>일자</span><input type="date" value="${esc(REP.reportDate)}" oninput="REP.reportDate=this.value"></label>
        <label class="field"><span>점검자</span><input type="text" value="${esc(f.inspector)}" oninput="REP.fields.inspector=this.value"></label>
      </div>
      <label class="field"><span>유지보수대상</span><input type="text" value="${esc(f.target)}" oninput="REP.fields.target=this.value"></label>
    </div>
    <div class="sh-rep-grid" role="table">
      <div class="sh-rep-gh" role="row"><span>날짜</span><span>세부 내용 <small class="muted">한 줄 = 한 문단</small></span><span>${hasResult ? "결과" : "결과 <small class=\"muted\">첫 줄 끝에</small>"}</span><span></span></div>
      ${REP.rows.map((r, i) => `<div class="sh-rep-gr${repWarn(r) ? " warn" : ""} k-${esc((r.kind || "").replace("+", "-"))}" role="row" data-i="${i}">
        <span><input type="date" value="${esc(r.date)}" oninput="repSet(${i},'date',this.value)"><small class="sh-rep-kind">${esc(REP_KIND[r.kind] || "")}</small>${r.note ? `<small class="sh-rep-note" title="공휴일이라 옮겼습니다">${esc(r.note)}</small>` : ""}</span>
        <span><textarea rows="${Math.max(1, r.lines.length)}" oninput="repSet(${i},'lines',this.value);this.rows=Math.max(1,this.value.split('\\n').length)">${esc(r.lines.join("\n"))}</textarea><small class="sh-rep-warn-t">${esc(repWarn(r))}</small></span>
        <span><input type="text" value="${esc(r.result)}" oninput="repSet(${i},'result',this.value)"></span>
        <span><button type="button" class="btn-mini ghost danger" title="이 줄 빼기" onclick="repDel(${i})">×</button></span>
      </div>`).join("") || `<div class="sh-rep-gr empty">기간에 넣을 내역이 없습니다.</div>`}
    </div>
    <div class="sh-rep-btns"><button type="button" class="btn-mini" onclick="repAdd()">+ 줄 추가</button><span class="sh-rep-warn-t" id="repWarnN">${warnN ? `확인할 줄 ${warnN}개 — 노란 줄을 보고 고치세요` : ""}</span></div>
    <div class="sh-rep-form">
      <label class="field"><span>비고</span><textarea rows="2" oninput="REP.fields.remark=this.value">${esc(f.remark)}</textarea></label>
      <label class="field"><span>파일 이름</span><input type="text" value="${esc(REP.fileName)}" oninput="REP.fileName=this.value"></label>
    </div>
    <div class="sh-rep-act">
      <button type="button" class="btn-ghost" onclick="repCopy(this)" title="한글 표에 붙여 넣을 수 있게 날짜·내용·결과를 복사">표 복사</button>
      <button type="button" class="btn-submit" onclick="repBuild(this)"${t && t.exists ? "" : ` disabled title="양식을 올려야 만들 수 있습니다"`}>내역서 만들기 (hwpx · pdf)</button>
    </div>`;
}

function repSet(i, k, v) {
  const r = REP.rows[i];
  if (!r) return;
  r[k] = k === "lines" ? v.split("\n") : v;
  REP.dirty = true;
  if (k !== "lines") return;
  // 빈칸(____)을 채우면 노란 표시를 바로 거둔다 — 다시 그리면 입력 중인 칸의 커서가 날아가서 그 줄만 고친다
  const el = document.querySelector(`.sh-rep-gr[data-i="${i}"]`), w = repWarn(r), n = REP.rows.filter(repWarn).length;
  if (el) { el.classList.toggle("warn", !!w); el.querySelector(".sh-rep-warn-t").textContent = w; }
  if ($("repWarnN")) $("repWarnN").textContent = n ? `확인할 줄 ${n}개 — 노란 줄을 보고 고치세요` : "";
}
function repDel(i) { REP.rows.splice(i, 1); REP.dirty = true; renderReport(); }
function repAdd() {
  const last = REP.rows[REP.rows.length - 1];
  REP.rows.push({ date: last ? last.date : REP.from, lines: ["- "], result: (REP.data.tpl && REP.data.tpl.settings.work_result) || "", kind: "work", warn: "" });
  REP.dirty = true;
  renderReport();
}

function repCleanRows() {
  return REP.rows.map(r => ({ date: r.date, lines: r.lines.map(l => l.replace(/\s+$/, "")).filter(l => l.trim() !== ""), result: (r.result || "").trim() }))
    .filter(r => r.date || r.lines.length).sort((a, b) => (a.date || "").localeCompare(b.date || ""));
}

async function repCopy(btn) {
  const rows = repCleanRows();
  const html = `<table border="1">${rows.map(r => `<tr><td>${esc(repFmtDate(r.date))}</td><td>${r.lines.map(esc).join("<br>")}</td><td>${esc(r.result)}</td></tr>`).join("")}</table>`;
  const tsv = rows.map(r => [repFmtDate(r.date), `"${r.lines.join("\n").replace(/"/g, '""')}"`, r.result].join("\t")).join("\n");
  if (navigator.clipboard && window.isSecureContext && window.ClipboardItem) {
    try {
      await navigator.clipboard.write([new ClipboardItem({ "text/html": new Blob([html], { type: "text/html" }), "text/plain": new Blob([tsv], { type: "text/plain" }) })]);
      showToast("표를 복사했습니다 — 한글 표에 붙여 넣으세요");
      return;
    } catch (e) { /* 아래 글자 복사로 */ }
  }
  copyText(tsv, btn);
}

async function repBuild(btn) {
  const rows = repCleanRows();
  if (!rows.length && !(await askConfirm("빈 내역서", "넣을 줄이 없습니다. 빈 행 하나로 만들까요?", "만들기"))) return;
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "report_build", site_id: REP.siteId, from: REP.from, to: REP.to, report_date: REP.reportDate,
      target: REP.fields.target, inspector: REP.fields.inspector, remark: REP.fields.remark, file_name: REP.fileName, rows });
    REP.data.reports = j.reports;
    REP.dirty = false;
    renderReport();
    showToast(j.pdf_error ? `hwpx 는 만들었지만 PDF 는 실패했습니다 — ${j.pdf_error}` : `만들었습니다 — ${j.rows}줄 (hwpx · pdf)`);
    await repDownload("?report_file=" + j.id, j.file_name);
    if (!j.pdf_error) setTimeout(() => repDownload(`?report_file=${j.id}&fmt=pdf`, j.pdf_name), 700);   // 브라우저가 '여러 파일 받기' 허용을 물을 수 있다
  } catch (e) { showToast(e.message); } finally { btn.disabled = false; }
}

// 파일 받기 — 실패하면 API 가 JSON 으로 답하므로 fetch 로 받아 가른다
async function repDownload(qs, name) {
  try {
    const r = await fetch("api.php" + qs, { credentials: "same-origin", cache: "no-store" });
    const ct = r.headers.get("Content-Type") || "";
    if (!r.ok || ct.includes("json")) {
      let m = "내려받지 못했습니다";
      try { m = (await r.json()).error || m; } catch (e) { /* 그대로 */ }
      throw new Error(m);
    }
    const url = URL.createObjectURL(await r.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1500);
  } catch (e) { showToast(e.message); }
}

/* ── 만든 내역서 ── */
function repListSection() {
  const list = REP.data.reports || [];
  return repCard(3, `만든 내역서 ${list.length ? `<span class="muted">${list.length}</span>` : ""}`, "만든 hwpx · pdf 를 다시 받거나, 불러와 고쳐서 새로 만듭니다", "", `
    <ul class="sh-rows">${list.length ? list.map(r => `<li>
      <div><div class="t">${esc(r.period_from)} ~ ${esc(r.period_to)} <span class="muted" style="font-weight:600">${r.row_count}줄</span></div>
        <div class="s"><span>${esc(r.file_name)}</span><span>${esc(String(r.created_at).slice(0, 16))} · ${esc(r.created_name || r.created_by)}</span></div></div>
      <div class="a"><button type="button" class="btn-mini" onclick="repDownload('?report_file=${r.id}', ${esc(JSON.stringify(r.file_name))})" title="한글 파일">HWPX</button>
        <button type="button" class="btn-mini" onclick="repDownload('?report_file=${r.id}&fmt=pdf', ${esc(JSON.stringify(r.file_name.replace(/\.hwpx$/i, "") + ".pdf"))})" title="PDF">PDF</button>
        <button type="button" class="btn-mini ghost" onclick="repReopen(${r.id})" title="그때의 행을 불러와 고쳐서 다시 만들기">불러오기</button>
        <button type="button" class="btn-mini ghost danger" onclick="repDelete(${r.id})">삭제</button></div>
    </li>`).join("") : `<li class="empty">아직 만든 내역서가 없습니다.</li>`}</ul>`, (REP.data.reports || []).length ? "done" : "");
}

async function repReopen(id) {
  if (REP.dirty && !(await askConfirm("불러오기", "고친 행이 사라지고 그 내역서의 행을 불러옵니다.", "불러오기"))) return;
  try {
    const r = (await api("?report_detail=" + id)).report;
    Object.assign(REP, { from: r.from, to: r.to, reportDate: r.report_date || r.to, fileName: r.file_name, dirty: false, stats: null, holidays: [],
      rows: r.rows.map(x => ({ date: x.date, lines: x.lines, result: x.result, kind: "", warn: "" })),
      fields: { target: r.fields.target || "", inspector: r.fields.inspector || "", remark: r.fields.remark || "" } });
    renderReport();
    showToast("불러왔습니다 — 고쳐서 만들면 새 내역서로 저장됩니다");
  } catch (e) { showToast(e.message); }
}

async function repDelete(id) {
  if (!(await askConfirm("내역서 삭제", "만든 내역서 기록과 파일을 지웁니다. 되돌릴 수 없습니다.", "삭제"))) return;
  try {
    REP.data.reports = (await postJSON({ action: "report_delete", id })).reports;
    renderReport();
  } catch (e) { showToast(e.message); }
}
