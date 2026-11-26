// 대금청구서 — 내역서 탭의 '청구서' 하위 탭. bill_lib.php
// 번호 붙은 세 칸: 1 양식(모든 사이트 공통 고정 양식 대금청구서.xlsx) · 이 사이트 채우기 규칙 / 2 만들기(기간 → 수신·건명·품목·금액 확인·수정 → xlsx·pdf) / 3 만든 청구서.
// 금액은 계약 탭의 연간 금액을 VAT 포함 월 단가로 바꿔 '개월 수 × 월 단가'. 양식 금액이 세액포함이라 공급가액·부가세를 따로 쓰지 않는다.

const BILL = { siteId: null, data: null, from: "", to: "", draft: null, dirty: false, rulesOpen: false };

async function loadBill(s) {
  try {
    const j = await api("?bill=" + s.id);
    if (APP.open.id !== s.id || APP.open.tab !== "report" || REP.sub !== "bill") return;
    if (BILL.siteId !== s.id) Object.assign(BILL, { siteId: s.id, from: "", to: "", draft: null, dirty: false });
    BILL.data = j;
    if (!BILL.from) { const p = billDefaultPeriod(); BILL.from = p.from; BILL.to = p.to; }
    renderBill();
  } catch (e) {
    $("dBody").innerHTML = `<div class="sh-rep">${repSubTabs()}<div class="d-sec"><div class="sh-synced">${esc(e.message)}</div></div></div>`;
  }
}

function renderBill() {
  const s = siteById(BILL.siteId);
  if (!s || APP.open.id !== s.id || APP.open.tab !== "report" || REP.sub !== "bill" || !BILL.data) return;
  const body = $("dBody"), top = body.scrollTop;
  body.innerHTML = `<div class="sh-rep">${repSubTabs()}${billStepsNav()}${billTplSection(s)}${billMakeSection()}${billListSection()}</div>`;
  body.scrollTop = top;
}

function billStepsNav() {
  const n = (BILL.data.bills || []).length;
  const step = (i, label, state, extra) => `<button type="button" class="sh-rep-step ${state}" onclick="repGo(${i})"><span class="sh-rep-num">${state === "done" ? "✓" : i}</span><span>${label}${extra}</span></button>`;
  return `<nav class="sh-rep-steps" aria-label="청구서 단계">
    ${step(1, "양식 · 규칙", "done", "")}<span class="sh-rep-arrow" aria-hidden="true">›</span>
    ${step(2, "청구서 만들기", "now", "")}<span class="sh-rep-arrow" aria-hidden="true">›</span>
    ${step(3, "만든 청구서", n ? "done" : "", n ? ` <b>${n}</b>` : "")}
  </nav>`;
}

/* ── 금액 ── */
const billNum = v => { const n = Number(String(v == null ? "" : v).replace(/[^\d.\-]/g, "")); return isFinite(n) ? n : 0; };
const billWon = n => Math.round(n).toLocaleString("ko-KR");

// 일금 … 원정 의 한글 숫자 (bill_lib.php sitehub_won_hangul 과 같게 — 엑셀 [DBNum4])
function billHangul(n) {
  n = Math.round(n);
  if (!n) return "영";
  const neg = n < 0, dig = ["", "일", "이", "삼", "사", "오", "육", "칠", "팔", "구"], small = ["", "십", "백", "천"], big = ["", "만", "억", "조", "경"];
  let out = "", g = 0;
  n = Math.abs(n);
  while (n > 0) {
    const part = n % 10000;
    n = Math.floor(n / 10000);
    if (part) {
      let s = "";
      for (let i = 3; i >= 0; i--) { const d = Math.floor(part / 10 ** i) % 10; if (d) s += dig[d] + small[i]; }
      out = s + big[g] + out;
    }
    g++;
  }
  return (neg ? "마이너스" : "") + out;
}

function billTotal() {
  return ((BILL.draft && BILL.draft.items) || []).reduce((a, x) => a + billNum(x.qty) * billNum(x.price), 0);
}

/* ── 기간 ── */
// 기본 기간: 아직 청구서를 안 만든 최근 내역서 기간 → 마지막 청구서 다음 기간 → 직전 3개월
function billDefaultPeriod() {
  const lr = BILL.data.last_report, bills = BILL.data.bills || [];
  if (lr && !bills.some(b => b.period_from === lr.period_from && b.period_to === lr.period_to)) return { from: lr.period_from, to: lr.period_to };
  return billNextPeriod() || repPresetRange("last3");
}

function billNextPeriod() {
  const last = (BILL.data.bills || [])[0];
  if (!last) return null;
  const a = repParse(last.period_from), b = repParse(last.period_to);
  if (!a || !b) return null;
  const months = (b.getFullYear() - a.getFullYear()) * 12 + b.getMonth() - a.getMonth() + 1;
  const f = new Date(b.getFullYear(), b.getMonth() + 1, 1);
  return { from: repYmd(f), to: repYmd(new Date(f.getFullYear(), f.getMonth() + months, 0)) };
}

function billPreset(kind) {
  const lr = BILL.data.last_report;
  const p = kind === "report" ? (lr ? { from: lr.period_from, to: lr.period_to } : null) : kind === "next" ? billNextPeriod() : repPresetRange(kind);
  if (!p) return;
  $("billFrom").value = p.from;
  $("billTo").value = p.to;
}

/* ── 1 양식 · 규칙 ── */
function billTplSection(s) {
  const t = BILL.data.tpl, sp = t.supplier || {};
  const kb = n => Math.max(1, Math.round(n / 1024)) + "KB";
  const actions = `<button type="button" class="btn-mini ghost" onclick="repDownload('?bill_tpl_file=1', '대금청구서.xlsx')">양식 받기</button>`;
  const fixed = [sp["상호"] && `공급자 <b>${esc(sp["상호"])}</b>${sp["사업자번호"] ? ` (${esc(sp["사업자번호"])})` : ""}`, sp["대표자"] && `대표자 ${esc(sp["대표자"])} · 도장`,
    (sp["은행명"] || sp["계좌번호"]) && `계좌 ${esc([sp["은행명"], sp["계좌번호"], sp["예금주"]].filter(Boolean).join(" "))}`, sp["지급기한"] && `지급기한 ${esc(sp["지급기한"])}`].filter(Boolean).join(" · ");
  return repCard(1, "청구서 양식", "모든 사이트가 같은 양식(블루소프트 대금청구서)을 씁니다 — 이 사이트에 맞는 값만 규칙으로 정해 둡니다", actions, `
    <div class="sh-rep-file">
      <div class="sh-rep-fname"><span class="sh-rep-ico xl">XLSX</span><b>${esc(t.file_name)}</b>
        <span class="muted">${kb(t.file_size)} · 공통 고정 양식</span></div>
      <dl class="sh-rep-found">
        <dt>채우는 칸</dt><dd>수신 · 참조 · 건명 · 담당자 · 품목 ${t.item_rows}줄(품명·규격·단위·수량·단가) · 청구 날짜 <span class="muted">— 금액·합계·한글 금액은 엑셀 수식이 계산</span></dd>
        <dt>그대로</dt><dd>${fixed || "공급자 정보"}</dd>
      </dl>
    </div>
    ${billRulesHtml(s)}`, "done");
}

function billRulesHtml(s) {
  const c = BILL.data.cfg, st = c.settings, t = BILL.data.tpl;
  const opt = (v, cur, label) => `<option value="${v}"${String(v) === String(cur) ? " selected" : ""}>${esc(label)}</option>`;
  const recDef = s.customer && !String(s.name || "").includes(s.customer) ? `${s.customer} ${s.name}` : s.name;
  const repTpl = REP.data && REP.siteId === s.id && REP.data.tpl;
  const mgrDef = (repTpl && repTpl.settings.inspector) || (t.sample && t.sample.manager) || "";
  const tokens = (BILL.data.tokens || []).map(k => `<code>{${esc(k)}}</code>`).join(" ");
  const saved = c.saved ? `<span class="muted">— ${esc(String(c.updated_at || "").slice(0, 16))}${c.updated_name ? " · " + esc(c.updated_name) : ""} 저장</span>` : `<span class="muted">— 아직 기본값입니다. 고쳐서 저장할 수 있어요</span>`;
  return `<details class="sh-rep-rules" id="billRules"${BILL.rulesOpen ? " open" : ""} ontoggle="BILL.rulesOpen=this.open">
    <summary>이 사이트 채우기 규칙 ${saved}</summary>
    <div class="sh-rep-form">
      <p class="sh-rep-sub">받는 곳</p>
      <div class="two">
        <label class="field"><span>수신</span><input type="text" data-k="recipient" value="${esc(st.recipient)}" placeholder="${esc(recDef)}"></label>
        <label class="field"><span>참조</span><input type="text" data-k="ref" value="${esc(st.ref)}" placeholder="비우면 빈칸"></label>
      </div>
      <p class="sh-rep-sub">건명 · 품목</p>
      <label class="field"><span>건명 틀</span><input type="text" data-k="subject" value="${esc(st.subject)}" placeholder="${esc(BILL.data.defaults.subject)}"></label>
      <label class="field"><span>품명 틀</span><input type="text" data-k="item" value="${esc(st.item)}" placeholder="${esc(BILL.data.defaults.item)}"></label>
      <p class="sh-rep-hint">틀에 넣을 수 있는 값: ${tokens} <span class="muted">— 분기는 학년도 기준(1분기 시작 월부터 3개월씩)</span></p>
      <div class="three">
        <label class="field"><span>{기관}</span><input type="text" data-k="org" value="${esc(st.org)}" placeholder="${esc(s.name)}"></label>
        <label class="field"><span>1분기 시작 월</span><select data-k="q_start">${Array.from({ length: 12 }, (_, i) => opt(i + 1, st.q_start, `${i + 1}월${i + 1 === 3 ? " (학년도)" : i + 1 === 1 ? " (연도)" : ""}`)).join("")}</select></label>
        <label class="field"><span>단위 · 규격</span><span class="sh-bill-pair"><input type="text" data-k="unit" value="${esc(st.unit)}" placeholder="식"><input type="text" data-k="spec" value="${esc(st.spec)}" placeholder="규격"></span></label>
      </div>
      <p class="sh-rep-sub">금액 · 담당자</p>
      <div class="two">
        <label class="field"><span>월 단가 (VAT 포함) <small class="muted">— 비우면 계약 탭에서 계산</small></span><input type="text" inputmode="numeric" data-k="price" value="${Number(st.price) ? billWon(st.price) : ""}" placeholder="계약 연간 금액 ÷ 12" onblur="this.value=billNum(this.value)?billWon(billNum(this.value)):''"></label>
        <label class="field"><span>원 미만 끝전</span><select data-k="rounding">${opt("floor", st.rounding, "버림")}${opt("round", st.rounding, "반올림")}</select></label>
      </div>
      <label class="field"><span>담당자 <small class="muted">— 비우면 내역서 점검자</small></span><input type="text" data-k="manager" value="${esc(st.manager)}" placeholder="${esc(mgrDef)}"></label>
      <label class="sh-rep-chk"><input type="checkbox" data-k="day_blank"${Number(st.day_blank) ? " checked" : ""}><span>청구 날짜의 ‘일’은 비워 두기 <small class="muted">(양식처럼 ‘2026년 11월 일’ — 낼 때 손으로 적음)</small></span></label>
      <label class="field"><span>xlsx 글꼴 <small class="muted">— PDF 는 늘 Pretendard</small></span><select data-k="font">${opt("pretendard", st.font || "pretendard", "Pretendard 로 통일")}${opt("template", st.font, "양식 글꼴 그대로 (맑은 고딕)")}</select></label>
      <div class="sh-rep-btns"><button type="button" class="btn-mini primary" onclick="billSaveRules(this)">규칙 저장</button></div>
    </div>
  </details>`;
}

async function billSaveRules(btn) {
  const root = $("billRules"), v = k => root.querySelector(`[data-k="${k}"]`);
  const settings = {};
  ["recipient", "ref", "subject", "item", "org", "q_start", "unit", "spec", "price", "rounding", "manager", "font"].forEach(k => { settings[k] = v(k).value; });
  settings.price = billNum(settings.price);
  settings.day_blank = v("day_blank").checked ? 1 : 0;
  btn.disabled = true;
  try {
    BILL.data.cfg = (await postJSON({ action: "bill_settings", site_id: BILL.siteId, settings })).cfg;
    showToast(BILL.draft ? "규칙을 저장했습니다 — 불러오기를 다시 누르면 반영됩니다" : "규칙을 저장했습니다");
    renderBill();
  } catch (e) { showToast(e.message); } finally { btn.disabled = false; }
}

/* ── 2 만들기 ── */
function billMakeSection() {
  const lr = BILL.data.last_report;
  const presets = [["report", "내역서와 같은 기간", !!lr, lr ? `${lr.period_from} ~ ${lr.period_to}` : ""], ["next", "다음 기간", !!billNextPeriod(), ""], ["last3", "직전 3개월", true, ""], ["lastmonth", "지난달", true, ""]]
    .filter(p => p[2]).map(([k, l, , tip]) => `<button type="button" class="btn-mini ghost" onclick="billPreset('${k}')"${tip ? ` title="${esc(tip)}"` : ""}>${l}</button>`).join("");
  return repCard(2, "청구서 만들기", "기간을 고르고 불러와 수신·건명·품목·금액을 확인한 뒤 만들고, 만든 청구서 목록에서 xlsx · pdf 를 받습니다", "", `
    <div class="sh-rep-period">
      <input type="date" id="billFrom" value="${esc(BILL.from)}" aria-label="청구 기간 시작"><span>~</span><input type="date" id="billTo" value="${esc(BILL.to)}" aria-label="청구 기간 끝">
      <button type="button" class="btn-mini primary" onclick="billPreview(this)">불러오기</button>
    </div>
    <div class="sh-rep-presets">${presets}</div>
    ${BILL.draft ? billEditorHtml() : ""}`, "now");
}

async function billPreview(btn) {
  const from = $("billFrom").value, to = $("billTo").value;
  if (!from || !to || from > to) { showToast("기간을 확인하세요"); return; }
  if (BILL.dirty && !(await askConfirm("다시 불러오기", "고친 값이 사라지고 기간의 값을 새로 불러옵니다.", "불러오기"))) return;
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "bill_preview", site_id: BILL.siteId, from, to });
    Object.assign(BILL, { from, to, dirty: false, draft: {
      recipient: j.recipient, ref: j.ref, subject: j.subject, manager: j.manager, items: j.items.map(x => ({ ...x })),
      bill_date: j.bill_date, day_blank: j.day_blank, file_name: j.file_name, contract: j.contract, notes: j.notes || [], warn: j.warn || [], tokens: j.tokens } });
    renderBill();
  } catch (e) { showToast(e.message); } finally { btn.disabled = false; }
}

function billContractHtml(d) {
  const c = d.contract;
  const line = c ? `<b>계약</b>${c.kind !== "연간" ? ` ${esc(c.kind)} ·` : ""} ${c.amount != null ? `연간 ${billWon(c.amount)}원 (${c.vat_included ? "VAT 포함" : "VAT 별도"})` : "금액 없음"}${c.billing ? ` · ${esc(c.billing)} 납부` : ""}${c.start || c.end ? ` · ${esc(c.start || "?")} ~ ${esc(c.end || "?")}` : ""}`
    : d.tokens ? `<span class="muted">계약 탭에 계약이 없습니다</span>` : "";
  return `<div class="sh-bill-info">
    ${line ? `<p>${line}</p>` : ""}
    ${d.notes.map(n => `<p class="muted">${esc(n)}</p>`).join("")}
    ${d.warn.map(w => `<p class="sh-rep-warn-t">${esc(w)}</p>`).join("")}
  </div>`;
}

// 청구 날짜 글 — 양식처럼 'YYYY년  M월   일'
function billDateText(d) {
  const t = repParse(d.bill_date);
  if (!t) return "";
  return `${t.getFullYear()}년 ${t.getMonth() + 1}월 ${d.day_blank ? "   " : t.getDate()}일`;
}

function billEditorHtml() {
  const d = BILL.draft, max = BILL.data.tpl.item_rows || 10;
  const total = billTotal();
  return `${billContractHtml(d)}
    <div class="sh-rep-form sh-rep-head">
      <div class="two">
        <label class="field"><span>수신</span><input type="text" value="${esc(d.recipient)}" oninput="billSet('recipient',this.value)"></label>
        <label class="field"><span>참조</span><input type="text" value="${esc(d.ref)}" oninput="billSet('ref',this.value)"></label>
      </div>
      <label class="field"><span>건명</span><input type="text" value="${esc(d.subject)}" oninput="billSet('subject',this.value)"></label>
    </div>
    <div class="sh-bill-items" role="table" aria-label="품목">
      <div class="sh-bill-ih" role="row"><span>품명</span><span>규격</span><span>단위</span><span>수량</span><span title="VAT 포함 금액">단가</span><span>금액</span><span></span></div>
      ${d.items.map((x, i) => `<div class="sh-bill-ir" role="row">
        <span><input type="text" value="${esc(x.name)}" title="${esc(x.name)}" aria-label="품명" placeholder="품명" oninput="billItem(${i},'name',this.value)"></span>
        <span><input type="text" value="${esc(x.spec)}" aria-label="규격" placeholder="규격" oninput="billItem(${i},'spec',this.value)"></span>
        <span><input type="text" value="${esc(x.unit)}" aria-label="단위" placeholder="단위" oninput="billItem(${i},'unit',this.value)"></span>
        <span><input type="text" inputmode="decimal" class="num" value="${esc(x.qty)}" aria-label="수량" placeholder="수량" oninput="billItem(${i},'qty',this.value)"></span>
        <span><input type="text" inputmode="numeric" class="num" value="${x.price === "" || x.price == null ? "" : billWon(billNum(x.price))}" aria-label="단가" placeholder="단가" oninput="billItem(${i},'price',this.value)" onblur="this.value=this.value.trim()===''?'':billWon(billNum(this.value))"></span>
        <span class="amt" id="billAmt${i}">${billNum(x.qty) * billNum(x.price) ? billWon(billNum(x.qty) * billNum(x.price)) : ""}</span>
        <span><button type="button" class="btn-mini ghost danger" title="이 품목 빼기" onclick="billDelItem(${i})"${d.items.length < 2 ? " disabled" : ""}>×</button></span>
      </div>`).join("")}
    </div>
    <div class="sh-rep-btns">${d.items.length < max ? `<button type="button" class="btn-mini" onclick="billAddItem()">+ 품목 추가</button>` : `<span class="muted">양식의 품목 줄(${max}줄)을 다 썼습니다</span>`}</div>
    <div class="sh-bill-total" id="billTotal">${billTotalHtml(total)}</div>
    <div class="sh-rep-form">
      <div class="two">
        <label class="field"><span>담당자</span><input type="text" value="${esc(d.manager)}" oninput="billSet('manager',this.value)"></label>
        <label class="field"><span>청구 날짜 <small class="muted" id="billDateText">${esc(billDateText(d))}</small></span>
          <span class="sh-bill-pair"><input type="date" value="${esc(d.bill_date)}" oninput="billSet('bill_date',this.value)">
          <label class="sh-rep-chk"><input type="checkbox"${d.day_blank ? " checked" : ""} onchange="billSet('day_blank',this.checked?1:0)"><span>일 비움</span></label></span></label>
      </div>
      <label class="field"><span>파일 이름</span><input type="text" value="${esc(d.file_name)}" oninput="billSet('file_name',this.value)"></label>
    </div>
    <div class="sh-rep-act">
      <button type="button" class="btn-submit" onclick="billBuild(this)">청구서 만들기 (xlsx · pdf)</button>
    </div>`;
}

function billTotalHtml(total) {
  return `<span>합계 <b>${billWon(total)}원</b> <span class="muted">VAT 포함</span></span><span class="words">일금 ${esc(billHangul(total))}원정</span>`;
}

function billSet(k, v) {
  BILL.draft[k] = v;
  BILL.dirty = true;
  if ((k === "bill_date" || k === "day_blank") && $("billDateText")) $("billDateText").textContent = billDateText(BILL.draft);
}

// 품목 칸 — 다시 그리지 않고 그 줄 금액과 합계만 고친다(입력 중인 칸의 커서를 지킨다)
function billItem(i, k, v) {
  const x = BILL.draft.items[i];
  if (!x) return;
  x[k] = k === "qty" || k === "price" ? (String(v).trim() === "" ? "" : billNum(v)) : v;
  BILL.dirty = true;
  const amt = billNum(x.qty) * billNum(x.price);
  if ($("billAmt" + i)) $("billAmt" + i).textContent = amt ? billWon(amt) : "";
  if ($("billTotal")) $("billTotal").innerHTML = billTotalHtml(billTotal());
}

function billAddItem() {
  BILL.draft.items.push({ name: "", spec: "", unit: (BILL.data.cfg.settings.unit || "식"), qty: 1, price: "" });
  BILL.dirty = true;
  renderBill();
}

function billDelItem(i) {
  BILL.draft.items.splice(i, 1);
  BILL.dirty = true;
  renderBill();
}

async function billBuild(btn) {
  const d = BILL.draft;
  const items = d.items.filter(x => String(x.name).trim() !== "" || billNum(x.qty) || billNum(x.price));
  if (!items.length) { showToast("품목을 한 줄 이상 넣으세요"); return; }
  if (!billTotal() && !(await askConfirm("금액 0원", "합계가 0원입니다. 이대로 만들까요?", "만들기"))) return;
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "bill_build", site_id: BILL.siteId, from: BILL.from, to: BILL.to, recipient: d.recipient, ref: d.ref, subject: d.subject,
      manager: d.manager, items, bill_date: d.bill_date, day_blank: d.day_blank, file_name: d.file_name });
    BILL.data.bills = j.bills;
    BILL.dirty = false;
    renderBill();
    showToast(j.pdf_error ? `xlsx 는 만들었지만 PDF 는 실패했습니다 — ${j.pdf_error}` : `만들었습니다 — 합계 ${billWon(j.total)}원. 아래 목록의 XLSX · PDF 버튼으로 받으세요`);
  } catch (e) { showToast(e.message); } finally { btn.disabled = false; }
}

/* ── 3 만든 청구서 ── */
function billListSection() {
  const list = BILL.data.bills || [];
  return repCard(3, `만든 청구서 ${list.length ? `<span class="muted">${list.length}</span>` : ""}`, "만든 xlsx · pdf 를 다시 받거나, 불러와 고쳐서 새로 만듭니다", "", `
    <ul class="sh-rows">${list.length ? list.map(b => `<li>
      <div><div class="t">${esc(b.period_from)} ~ ${esc(b.period_to)} <span class="sh-bill-sum">${billWon(b.total)}원</span></div>
        <div class="s"><span>${esc(b.file_name)}</span><span>${esc(String(b.created_at).slice(0, 16))} · ${esc(b.created_name || b.created_by)}</span></div></div>
      <div class="a"><button type="button" class="btn-mini" onclick="repDownload('?bill_file=${b.id}', ${esc(JSON.stringify(b.file_name))})" title="엑셀 파일">XLSX</button>
        <button type="button" class="btn-mini" onclick="repDownload('?bill_file=${b.id}&fmt=pdf', ${esc(JSON.stringify(b.file_name.replace(/\.xlsx$/i, "") + ".pdf"))})" title="PDF">PDF</button>
        <button type="button" class="btn-mini ghost" onclick="billReopen(${b.id})" title="그때의 값을 불러와 고쳐서 다시 만들기">불러오기</button>
        <button type="button" class="btn-mini ghost danger" onclick="billDelete(${b.id})">삭제</button></div>
    </li>`).join("") : `<li class="empty">아직 만든 청구서가 없습니다.</li>`}</ul>`, list.length ? "done" : "");
}

async function billReopen(id) {
  if (BILL.dirty && !(await askConfirm("불러오기", "고친 값이 사라지고 그 청구서의 값을 불러옵니다.", "불러오기"))) return;
  try {
    const b = (await api("?bill_detail=" + id)).bill, x = b.data || {};
    Object.assign(BILL, { from: b.from, to: b.to, dirty: false, draft: {
      recipient: x.recipient || "", ref: x.ref || "", subject: x.subject || "", manager: x.manager || "", items: (x.items || []).map(i => ({ ...i })),
      bill_date: x.bill_date || b.to, day_blank: x.day_blank ? 1 : 0, file_name: b.file_name, contract: null, notes: ["만든 청구서에서 불러온 값입니다 — 계약 금액을 다시 보려면 불러오기를 누르세요."], warn: [], tokens: null } });
    if (!BILL.draft.items.length) BILL.draft.items.push({ name: "", spec: "", unit: "식", qty: 1, price: "" });
    renderBill();
    repGo(2);
    showToast("불러왔습니다 — 고쳐서 만들면 새 청구서로 저장됩니다");
  } catch (e) { showToast(e.message); }
}

async function billDelete(id) {
  if (!(await askConfirm("청구서 삭제", "만든 청구서 기록과 파일(xlsx · pdf)을 지웁니다. 되돌릴 수 없습니다.", "삭제"))) return;
  try {
    BILL.data.bills = (await postJSON({ action: "bill_delete", id })).bills;
    renderBill();
  } catch (e) { showToast(e.message); }
}
