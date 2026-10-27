// 처리 절차(런북) — '유지보수 방법' 탭의 "자주 있는 처리"와 이력↔절차 연결. 칸 정의는 SH.procFields / SH.procCats.
// site_id=0 은 공통 절차: 모든 사이트에서 보이고, "이 사이트용으로 복사"로 사이트 전용 절차를 만든다.

APP.procs = null;       // GET ?procedures=1 — 공통 + 사이트별 전체 (use_count·last_used 포함)
APP.procCat = "";       // 요청 유형 필터 ("" = 전체)

async function loadProcs(force) {
  if (APP.procs && !force) return;
  const j = await api("?procedures=1");
  APP.procs = j.procedures || [];
}

const procById = id => (APP.procs || []).find(p => p.id === Number(id)) || null;
const procCatLabel = k => SH.procCats[k] || k;

// 자주 쓴 절차가 위로 — 처리 횟수 → 직접 정한 순서
const procSort = list => list.slice().sort((a, b) => (b.use_count - a.use_count) || (a.sort_no - b.sort_no) || (a.id - b.id));

function procSection(s) {
  if (!APP.procs) {
    loadProcs().then(() => { if (APP.open.id === s.id && APP.open.tab === "method") refreshDrawer(); })
      .catch(e => showToast(e.message));
    return `<div class="d-sec"><div class="sh-synced">처리 절차를 불러오는 중…</div></div>`;
  }
  const mine = APP.procs.filter(p => p.site_id === s.id);
  const common = APP.procs.filter(p => p.site_id === 0);
  const cats = Object.keys(SH.procCats).filter(k => mine.concat(common).some(p => p.category === k));
  if (APP.procCat && !cats.includes(APP.procCat)) APP.procCat = "";
  const chips = cats.length > 1
    ? `<div class="sh-proc-cats">${[["", "전체"]].concat(cats.map(k => [k, procCatLabel(k)])).map(([k, label]) =>
        `<button type="button" class="chip${APP.procCat === k ? " on" : " ghost"}" onclick="setProcCat('${k}')">${esc(label)}</button>`).join("")}</div>`
    : "";
  const keep = p => !APP.procCat || p.category === APP.procCat;
  const list = (rows, common) => rows.length
    ? procSort(rows.filter(keep)).map(p => procRow(p, s, common)).join("") || `<li class="empty">이 유형의 절차가 없습니다.</li>`
    : `<li class="empty">${common ? "공통 절차가 아직 없습니다. 여러 사이트에 같이 쓰는 처리(예: 관리자 비밀번호 초기화)를 적어 두세요." : "이 사이트 전용 절차가 아직 없습니다."}</li>`;
  return `<div class="d-sec">
    <div class="sh-sec-head"><h4>자주 있는 처리 ${mine.length ? `<span class="muted">${mine.length}</span>` : ""}</h4>
      <span><button type="button" class="btn-mini ghost" onclick="openProcSuggest(${s.id}, ${s.id})" title="이 사이트 이력에서 반복되는 처리를 찾아 사이트 전용 절차로 등록하도록 제안합니다">제안 보기</button>
      <button type="button" class="btn-mini primary" onclick="openProcForm(0, ${s.id})">+ 이 사이트 절차</button></span></div>
    ${chips}
    <ul class="sh-rows">${list(mine, false)}</ul>
  </div>
  <div class="d-sec">
    <div class="sh-sec-head"><h4>공통 절차 ${common.length ? `<span class="muted">${common.length}</span>` : ""}</h4>
      <span><button type="button" class="btn-mini ghost" onclick="openProcSuggest(${s.id})" title="이력에서 자주 나오는 처리를 찾아 절차로 등록하도록 제안합니다">제안 보기</button>
      <button type="button" class="btn-mini" onclick="openProcForm(0, 0, ${s.id})">+ 공통 절차</button></span></div>
    <ul class="sh-rows">${list(common, true)}</ul>
  </div>`;
}

function setProcCat(k) { APP.procCat = k; refreshDrawer(); }

function procRow(p, s, common) {
  const used = p.use_count ? `<span class="badge off" title="${p.last_used ? "마지막 처리 " + esc(String(p.last_used).slice(0, 10)) : ""}">처리 ${p.use_count}회</span>` : "";
  const dur = p.duration ? `<span class="muted">· ${esc(p.duration)}</span>` : "";
  const steps = p.steps ? `<div class="sh-hist-body">${esc(p.steps)}</div>` : "";
  const caution = p.caution ? `<div class="sh-hist-body sh-proc-caution"><b>주의</b> ${esc(p.caution)}</div>` : "";
  const link = p.link_url ? `<div class="sh-hist-body"><a href="${esc(/^[a-z][a-z0-9+.-]*:\/\//i.test(p.link_url) ? p.link_url : "http://" + p.link_url)}" target="_blank" rel="noopener">${esc(p.link_url)}</a></div>` : "";
  const copyBtn = p.steps ? `<button type="button" class="btn-mini ghost" onclick="copyProc(${p.id}, this)">절차 복사</button>` : "";
  const toSite = common ? `<button type="button" class="btn-mini ghost" onclick="copyProcToSite(${p.id}, ${s.id})" title="이 사이트에서만 고쳐 쓸 수 있게 사본을 만든다">이 사이트용 복사</button>` : "";
  return `<li>
    <div>
      <div class="t"><span class="chip">${esc(procCatLabel(p.category))}</span><button type="button" class="linkish sh-hist-head" onclick="this.closest('li').classList.toggle('open')">${esc(p.title)}</button>${used}${dur}</div>
      ${steps}${caution}${link}
    </div>
    <div class="a">${copyBtn}${toSite}
      <button type="button" class="btn-mini ghost" onclick="openProcForm(${p.id}, ${p.site_id}, ${s.id})">수정</button>
      <button type="button" class="btn-mini ghost danger" onclick="deleteProc(${p.id})">삭제</button></div>
  </li>`;
}

function copyProc(id, btn) {
  const p = procById(id);
  if (!p) return;
  copyText(p.title + "\n" + p.steps + (p.caution ? "\n\n[주의] " + p.caution : ""), btn);
}

/* ── 추가 / 수정 ── siteId 0 = 공통. backId 는 저장 뒤 돌아올 드로어 사이트 */
function openProcForm(id, siteId, backId, seed) {
  const p = id ? procById(id) : null;
  APP.procSeed = seed || null;   // 이력 제안에서 온 등록이면 {keyword, title, category, count}
  if (id && !p) { showToast("절차를 찾을 수 없습니다"); return; }
  const site = siteById(siteId || backId);
  const scope = siteId === 0 ? "공통 절차" : (site ? site.name : "");
  const seedNote = seed ? `<p class="fhint" style="margin:0 0 10px">이력 ${seed.count}건이 이 절차로 연결됩니다. 처리 절차를 적어 두면 다음부터 누구나 따라 할 수 있습니다.</p>` : "";
  const f = k => SH.procFields.find(x => x.key === k);
  const narrow = ["category", "duration"].map(f);
  openSheet(`
    <div class="sheet-head"><div><span class="k">${esc(scope)}</span><h2>처리 절차 ${p ? "수정" : "추가"}</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="procForm">
      ${seedNote}
      ${fieldHtml(f("title"), p ? p.title : (seed ? seed.title : ""))}
      <div class="two">${narrow.map(x => fieldHtml(x, p ? p[x.key] : (x.key === "category" ? (seed ? seed.category : "content") : ""))).join("")}</div>
      ${fieldHtml(f("link_url"), p ? p.link_url : "")}
      ${fieldHtml(f("steps"), p ? p.steps : "")}
      ${fieldHtml(f("caution"), p ? p.caution : "")}
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">취소</button>
      <button type="button" class="btn-submit" id="procSave" onclick="saveProcForm(${p ? p.id : 0}, ${siteId})">${p ? "저장" : "추가"}</button>
    </div>`);
  const st = $("procForm").querySelector('[data-k="steps"]');
  if (st) st.rows = 8;
}

async function saveProcForm(id, siteId) {
  const body = readFields($("procForm"), SH.procFields, id > 0);
  const btn = $("procSave");
  btn.disabled = true;
  try {
    const seed = APP.procSeed;
    const j = await postJSON(seed
      ? Object.assign({ action: "proc_suggest_register", keyword: seed.keyword, scope_site: APP.procScope || 0, site_id: siteId }, body)
      : Object.assign({ action: "proc_save", id, site_id: siteId }, body));
    APP.procs = j.procedures;
    APP.procSeed = null;
    closeSheet();
    afterProcChange();
    showToast(seed ? `절차를 등록하고 이력 ${j.linked}건을 연결했습니다` : id > 0 ? "저장했습니다" : "절차를 추가했습니다");
  } catch (e) {
    showToast(e.message);
  } finally {
    btn.disabled = false;
  }
}

async function deleteProc(id) {
  const p = procById(id);
  if (!p) return;
  const extra = p.use_count ? ` 이력에 연결된 ${p.use_count}건의 처리 기록도 함께 사라집니다.` : "";
  if (!(await askConfirm("절차 삭제", `‘${p.title}’ 절차를 지웁니다.${extra} 되돌릴 수 없습니다.`, "삭제"))) return;
  try {
    const j = await postJSON({ action: "proc_delete", id });
    APP.procs = j.procedures;
    afterProcChange();
    showToast("삭제했습니다");
  } catch (e) {
    showToast(e.message);
  }
}

async function copyProcToSite(id, siteId) {
  try {
    const j = await postJSON({ action: "proc_copy", id, site_id: siteId });
    APP.procs = j.procedures;
    afterProcChange();
    showToast("이 사이트용 절차로 복사했습니다");
  } catch (e) {
    showToast(e.message);
  }
}

function afterProcChange() { if (APP.open.id) refreshDrawer(); }

/* ── 이력 ↔ 절차 연결 ── 이력 행의 '절차' 버튼 — 이 사이트의 절차와 공통 절차를 골라 잇고 끊는다 */
function histProcChips(h) {
  const ids = h.procs || [];
  if (!ids.length) return "";
  return ids.map(id => procById(id)).filter(Boolean)
    .map(p => `<span class="chip" title="처리 절차">${esc(p.title)}</span>`).join("");
}

function openProcLink(historyId, siteId) {
  const h = (APP.histRows || []).find(x => x.id === historyId);
  if (!h) return;
  const rows = procSort((APP.procs || []).filter(p => p.site_id === siteId || p.site_id === 0));
  const on = new Set(h.procs || []);
  openSheet(`
    <div class="sheet-head"><div><span class="k">이력 연결</span><h2>어떤 절차로 처리했나요?</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="procLink" data-h="${esc(historyId)}">
      <p class="fhint" style="margin:0 0 10px">${esc((h.body || h.title || "").slice(0, 120))}</p>
      ${rows.length ? `<ul class="sh-rows">${rows.map(p => `<li>
        <label class="check" style="height:auto"><input type="checkbox" value="${p.id}"${on.has(p.id) ? " checked" : ""} onchange="toggleProcUse(this)">
          <span><span class="chip">${esc(procCatLabel(p.category))}</span> ${esc(p.title)}${p.site_id === 0 ? ` <span class="muted">공통</span>` : ""}</span></label><span></span></li>`).join("")}</ul>`
        : `<p class="sh-synced">연결할 절차가 없습니다. '유지보수 방법' 탭에서 절차를 먼저 추가하세요.</p>`}
    </div>
    <div class="sheet-foot"><button type="button" class="btn-submit" onclick="closeSheet()">닫기</button></div>`);
}

async function toggleProcUse(cb) {
  const hid = $("procLink").dataset.h;
  cb.disabled = true;
  try {
    const j = await postJSON({ action: cb.checked ? "proc_use" : "proc_unuse", procedure_id: Number(cb.value), history_id: hid });
    APP.procs = j.procedures;
    const h = (APP.histRows || []).find(x => x.id === hid);
    if (h) h.procs = j.procs;
    if (APP.open.tab === "history") { const s = siteById(APP.open.id); if (s) $("dBody").innerHTML = historySection(s, APP.histRows); }
  } catch (e) {
    cb.checked = !cb.checked;
    showToast(e.message);
  } finally {
    cb.disabled = false;
  }
}

/* ── 이력에서 뽑은 등록 제안 ── 규칙은 proc_suggest.php. 등록하면 그 묶음의 이력이 전부 절차에 연결된다 */
async function openProcSuggest(backId, scopeSite) {
  APP.procScope = scopeSite || 0;   // 0 = 전체 사이트(공통 제안), 아니면 그 사이트 이력만(전용 제안)
  openSheet(`<div class="sheet-head"><div><span class="k">이력 분석</span><h2>절차로 등록할 만한 처리</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body"><div class="sh-synced">이력을 살펴보는 중…</div></div>`);
  try {
    const j = await api("?proc_suggest=1" + (scopeSite ? "&site=" + scopeSite : ""));
    APP.procSuggest = j.suggestions || [];
    renderProcSuggest(backId);
  } catch (e) {
    closeSheet();
    showToast(e.message);
  }
}

function renderProcSuggest(backId) {
  const rows = APP.procSuggest || [];
  const siteName = id => (siteById(id) || {}).name || "";
  openSheet(`<div class="sheet-head"><div><span class="k">이력 분석</span><h2>절차로 등록할 만한 처리</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body">
      <p class="fhint" style="margin:0 0 10px">${APP.procScope ? `<b>${esc(siteName(APP.procScope))}</b> 이력에서 같은 말이 2건 이상 반복된 요청입니다. 등록하면 이 사이트 전용 절차가 됩니다.` : "전체 사이트 이력에서 같은 말이 3건 이상 반복된 요청입니다."} 아직 절차에 연결되지 않은 이력만 셉니다.</p>
      ${rows.length ? `<ul class="sh-rows">${rows.map((g, i) => `<li>
        <div>
          <div class="t"><span class="chip">${esc(procCatLabel(g.category))}</span>${esc(g.title)}
            <span class="badge off">${g.count}건 · ${g.site_count}곳</span>${g.site_id ? `<span class="muted">${esc(siteName(g.site_id))} 전용</span>` : `<span class="muted">공통</span>`}</div>
          <div class="n">${g.examples.map(x => "· " + esc(x)).join("<br>")}</div>
        </div>
        <div class="a">
          <button type="button" class="btn-mini primary" onclick="registerSuggest(${i}, ${backId})">등록</button>
          <button type="button" class="btn-mini ghost" onclick="ignoreSuggest(${i}, ${backId})" title="이 키워드는 다시 제안하지 않습니다">무시</button>
        </div></li>`).join("")}</ul>`
        : `<p class="sh-synced">지금 제안할 만한 반복 처리가 없습니다.</p>`}
    </div>
    <div class="sheet-foot"><button type="button" class="btn-submit" onclick="closeSheet()">닫기</button></div>`);
}

function registerSuggest(i, backId) {
  const g = (APP.procSuggest || [])[i];
  if (!g) return;
  openProcForm(0, g.site_id, backId, { keyword: g.keyword, title: g.title, category: g.category, count: g.count });
}

async function ignoreSuggest(i, backId) {
  const g = (APP.procSuggest || [])[i];
  if (!g) return;
  try {
    await postJSON({ action: "proc_suggest_ignore", keyword: g.keyword });
    APP.procSuggest.splice(i, 1);
    renderProcSuggest(backId);
  } catch (e) {
    showToast(e.message);
  }
}
