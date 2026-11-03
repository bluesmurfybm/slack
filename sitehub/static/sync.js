// 이력 동기화 · 미매칭 지정. sync.php 는 api.php 와 다른 파일이라 fetch 를 직접 쓴다.

let syncing = false;
async function runSync(full) {
  if (syncing || AUTO.running) return;
  syncing = true;
  const btn = $("btnSync");
  if (btn) { btn.disabled = true; btn.textContent = "동기화 중…"; }
  try {
    const r = await fetch("sync.php" + (full ? "?full=1" : ""), { credentials: "same-origin", cache: "no-store" });
    if (r.status === 401) { location.href = "../index.php?need_login=sitehub"; return; }
    const j = await r.json();
    if (j.schema) { openSchemaSheet(j); return; }
    if (!j.ok) throw new Error(j.error || "동기화에 실패했습니다");
    if (j.skipped === "locked") { showToast("다른 동기화가 진행 중입니다. 잠시 후 다시 시도하세요."); return; }
    applyMeta(j.meta);
    autoSyncSeen(0);
    showToast(`동기화 완료 — 훑음 ${j.scanned} · 반영 ${j.upserted} (매칭 ${j.matched} · 미매칭 ${j.unmatched})${j.archived ? " · 보관 " + j.archived : ""}`);
    await load();
    if (APP.open.id) openDrawer(APP.open.id, APP.open.tab, false);
  } catch (e) {
    showToast(e.message);
  } finally {
    syncing = false;
    if (btn) { btn.disabled = false; btn.textContent = "이력 동기화"; }
  }
}

/* list_cols.php 가 비어 있을 때 — 스키마를 보여 주고 붙여 넣을 PHP 조각을 만들어 준다 */
function openSchemaSheet(j) {
  // 이름으로 짐작한 매핑 — 업체명 열은 title 과 site 둘 다에 쓴다(이 리스트는 제목이 곧 사이트)
  const guess = { title: /제목|title|요청명|건명|업체명|사이트/i, site: /사이트|고객|업체|기관|site|customer/i, status: /처리상태|상태|진행|status/i,
    priority: /우선|priority/i, channel: /요청구분|구분|경로|channel/i, paid: /유상|무상|paid/i,
    req: /요청자|requester|등록자/i, asg: /담당|assignee|처리자/i, date: /요청일|등록일|접수|date/i, done: /완료일|done|종료일/i,
    remark: /비고|완료시간|remark/i, body: /내용|상세|본문|body|desc/i, attach: /첨부|파일|참고|attach|file/i };
  const typeOk = { title: ["text", "rich_text"], site: ["select", "text", "rich_text"], status: ["select"], priority: ["select"], channel: ["select"], paid: ["select"],
    req: ["user"], asg: ["user"], date: ["date"], done: ["date"], remark: ["text", "rich_text"], body: ["rich_text", "text"], attach: ["attachment"] };
  const shared = new Set(["site"]);   // title 과 같은 열을 써도 되는 역할
  const picked = {};
  Object.keys(guess).forEach(role => {
    const c = (j.schema || []).find(c => guess[role].test(c.name || "") && (typeOk[role] || []).includes(c.type) && (shared.has(role) || !Object.values(picked).includes(c.id)));
    if (c) picked[role] = c.id;
  });
  const php = "return [\n" + Object.keys(guess).map(role => `    '${role}'${" ".repeat(9 - role.length)}=> '${picked[role] || ""}',`).join("\n") + "\n];";
  openSheet(`
    <div class="sheet-head"><div><span class="k">이력 동기화</span><h2>리스트 컬럼을 먼저 연결하세요</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body">
      <p class="muted" style="margin:0">${esc(j.error || "")}</p>
      <ul class="sh-rows">${(j.schema || []).map(c => `<li><div><div class="t"><span class="chip">${esc(c.type)}</span>${esc(c.name)}</div>
        <div class="s"><span><b>ID</b> ${esc(c.id)} ${cpBtn(c.id, "복사")}</span>${c.key && c.key !== c.id ? `<span><b>key</b> ${esc(c.key)}</span>` : ""}${c.options && c.options.length ? `<span><b>옵션</b> ${esc(c.options.join(" / "))}</span>` : ""}</div></div></li>`).join("")}</ul>
      <div class="field"><label>sitehub/list_cols.php 에 붙여 넣을 내용 (이름으로 짐작한 값 — 확인 후 쓰세요)</label>
        <textarea rows="15" readonly style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px">${esc(php)}</textarea></div>
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">닫기</button>
      <button type="button" class="btn-submit" data-copy="${esc(php)}">코드 복사</button>
    </div>`);
}

/* 미매칭 이력 — 사이트를 골라 주면 바로 지정(site_locked=1)되어 재동기화에도 유지된다 */
async function openUnmatched() {
  let j;
  try { j = await api("?unmatched=1"); } catch (e) { showToast(e.message); return; }
  applyMeta(j.meta);
  const rows = j.rows || [];
  // 보관된 사이트는 고를 수 없게 뺀다 — 새 요청을 보관함 사이트에 붙일 일은 없다(필요하면 먼저 복원)
  const opts = `<option value="">사이트 선택…</option>` + APP.sites.filter(s => !s.archived).sort((a, b) => a.name.localeCompare(b.name, "ko"))
    .map(s => `<option value="${s.id}">${esc(s.name)}${s.customer ? " · " + esc(s.customer) : ""}</option>`).join("");
  // 연도 셀렉박스 — 행의 날짜(요청일시 우선, 없으면 date) 앞 4자리. 거르기는 행의 hidden 만 토글해 지정·삭제 흐름과 건수 계산을 건드리지 않는다
  const years = [...new Set(rows.map(unmatchedYear).filter(Boolean))].sort().reverse();
  const yearSel = years.length > 1 ? `<div class="sh-year-bar"><select id="unmatchedYear" onchange="filterUnmatchedYear(this.value)" title="연도별로 보기"><option value="">전체 연도</option>${years.map(y => `<option value="${y}">${y}년</option>`).join("")}</select><span class="muted" id="unmatchedYearNote"></span></div>` : "";
  openSheet(`
    <div class="sheet-head"><div><span class="k">이력</span><h2>미매칭 ${rows.length}건</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body">
      <p class="muted" style="margin:0">리스트의 업체명으로 사이트를 못 찾은 항목입니다. <b>자동 정리</b>는 비슷한 표기를 기존 사이트에 붙이고, 처음 보는 업체는 새 사이트로 등록합니다(미리보기 후 적용). 하나씩 고르면 그 항목만 바로 연결됩니다.</p>
      ${yearSel}
      <ul class="sh-rows sh-unmatched" id="unmatchedList">${rows.length ? rows.map(unmatchedRow.bind(null, opts)).join("") : `<li class="empty">미매칭 이력이 없습니다.</li>`}</ul>
    </div>
    <div class="sheet-foot"><button type="button" class="btn-ghost" onclick="closeSheet()">닫기</button>
      ${rows.length ? `<button type="button" class="btn-submit" id="btnAutoResolve" onclick="openAutoResolve()">자동 정리</button>` : ""}</div>`);
}

/* ── 미매칭 자동 정리 ── 계획을 받아 세 묶음(기존 사이트 연결 · 새 사이트 등록 · 건너뜀)으로 보여 주고, 체크를 뺀 표기는 두고 적용 */
async function openAutoResolve() {
  const btn = $("btnAutoResolve");
  if (btn) btn.disabled = true;
  let plan;
  try { plan = (await postJSON({ action: "auto_resolve" })).plan; }
  catch (e) { showToast(e.message); if (btn) btn.disabled = false; return; }
  const c = plan.counts;
  const chk = name => `<input type="checkbox" class="arChk" data-name="${esc(name)}" checked>`;
  const variants = v => v.length > 1 ? `<div class="s">${v.map(x => `<span>${esc(x.name)} <b>${x.n}</b></span>`).join("")}</div>` : "";
  const linkRows = plan.link.map(l => `<li><label class="ar-row">${chk(l.name)}<div>
      <div class="t">${esc(l.name)} <span class="muted">${l.n}건</span></div>
      <div class="s"><span>→ <b>${esc(l.site_name)}</b></span></div></div></label></li>`).join("");
  const createRows = plan.create.map(g => `<li><label class="ar-row">${chk(g.name)}<div>
      <div class="t">${esc(g.name)} <span class="muted">${g.n}건</span></div>${variants(g.variants)}</div></label></li>`).join("");
  const skipRows = plan.skip.map(k => `<li><div><div class="t sh-ar-skip">${esc(k.name || "(빈 칸)")} <span class="muted">${k.n}건</span></div>
      <div class="s"><span>${esc(k.reason)}</span></div></div></li>`).join("");
  openSheet(`
    <div class="sheet-head"><div><span class="k">미매칭 ${plan.total}건</span><h2>자동 정리 미리보기</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body sh-ar">
      <p class="muted" style="margin:0">체크를 빼면 그 표기는 미매칭으로 남습니다. 연결·등록한 표기는 그 사이트의 ‘Slack 표기’에 더해져 다음 동기화부터 자동으로 붙습니다.</p>
      <h4>기존 사이트에 연결 <span class="muted">${plan.link.length}가지 표기 · ${c.link}건</span></h4>
      <ul class="sh-rows">${linkRows || `<li class="empty">없음</li>`}</ul>
      <h4>새 사이트로 등록 <span class="muted">${plan.create.length}곳 · ${c.create}건</span></h4>
      <p class="fhint" style="margin:0 0 6px">이름은 가장 많이 쓴 표기입니다. URL·접속정보·계약은 비어 있으니 등록 뒤 채우거나 이름을 고치세요.</p>
      <ul class="sh-rows">${createRows || `<li class="empty">없음</li>`}</ul>
      <h4>건너뜀 <span class="muted">${c.skip}건 — 업체명 칸에 작업 내용이 적힌 행</span></h4>
      <ul class="sh-rows">${skipRows || `<li class="empty">없음</li>`}</ul>
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="openUnmatched()">← 미매칭 목록</button>
      <button type="button" class="btn-submit" id="btnArApply" onclick="applyAutoResolve()" ${c.link + c.create ? "" : "disabled"}>적용</button>
    </div>`);
}

async function applyAutoResolve() {
  const exclude = [...document.querySelectorAll(".arChk")].filter(x => !x.checked).map(x => x.dataset.name);
  const btn = $("btnArApply");
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "auto_resolve", apply: 1, exclude });
    const r = j.result;
    closeSheet();
    await load();   // 새 사이트·Slack 표기·이력 건수를 다시 받는다
    applyMeta(j.meta);
    showToast(`이력 ${r.assigned}건 연결 — 기존 사이트 표기 ${r.linked_names}개 · 새 사이트 ${r.created_sites}곳`);
  } catch (e) {
    showToast(e.message);
    btn.disabled = false;
  }
}

function unmatchedYear(h) {
  const y = String(h.req_at || h.date || "").slice(0, 4);
  return /^\d{4}$/.test(y) ? y : "";
}

// 고른 연도가 아닌 행은 숨기고, 몇 건이 보이는지 셀렉박스 옆에 적는다
function filterUnmatchedYear(y) {
  const items = document.querySelectorAll("#unmatchedList li[data-id]");
  let shown = 0;
  items.forEach(li => { const on = !y || li.dataset.year === y; li.hidden = !on; if (on) shown++; });
  const note = $("unmatchedYearNote");
  if (note) note.textContent = y ? `${y}년 ${shown}건` : "";
}

function unmatchedRow(opts, h) {
  return `<li data-id="${esc(h.id)}" data-year="${unmatchedYear(h)}">
    <div>
      <div class="t">${typeof historyStatusBadge === "function" ? historyStatusBadge(h.status) : ""}${h.req_at || h.date ? `<span class="sh-hist-date">${esc(h.req_at ? String(h.req_at).slice(0, 16) : h.date)}</span>` : ""}${esc(h.title || "(제목 없음)")}</div>
      <div class="s">${h.site_text && h.site_text !== h.title ? `<span><b>표기</b> ${esc(h.site_text)}</span>` : ""}${h.body ? `<span class="muted">${esc(h.body.length > 60 ? h.body.slice(0, 60) + "…" : h.body)}</span>` : ""}</div>
    </div>
    <div><select onchange="assignHistory('${esc(h.id)}', this.value, this)">${opts}</select></div>
  </li>`;
}

async function assignHistory(id, siteId, sel) {
  if (!siteId) return;
  sel.disabled = true;
  try {
    const j = await postJSON({ action: "history_assign", id, site_id: Number(siteId) });
    applyMeta(j.meta);
    replaceSite(j.site);
    const li = sel.closest("li");
    li.remove();
    const list = $("unmatchedList");
    if (list && !list.children.length) list.innerHTML = `<li class="empty">미매칭 이력이 없습니다.</li>`;
    const h2 = document.querySelector("#sheet .sheet-head h2");
    if (h2) h2.textContent = "미매칭 " + (list ? list.querySelectorAll("li[data-id]").length : 0) + "건";
    const ys = $("unmatchedYear");
    if (ys) filterUnmatchedYear(ys.value);
    showToast("연결했습니다");
    if (APP.open.id === Number(siteId)) refreshDrawer();
  } catch (e) {
    sel.disabled = false;
    showToast(e.message);
  }
}

/* ── 엑셀 가져오기 (호스팅 정보_v.xlsx) — import_xlsx.php. 시트 하나가 사이트 하나. */
function openImport() {
  openSheet(`
    <div class="sheet-head"><div><span class="k">가져오기</span><h2>호스팅 정보 엑셀</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body">
      <p class="muted" style="margin:0">‘!!전체목록’ 시트와 사이트별 시트(G15-우진교통, C06-매경 …)를 읽어 사이트·접속정보·담당자·계약을 넣습니다.
        자유 서술 칸은 가능한 만큼 아이디/비밀번호로 가르고, 못 가른 글은 메모에 원문 그대로 남깁니다. 비밀번호는 암호화해 저장합니다.</p>
      ${SH.rootXlsx ? `<label class="check" style="width:max-content"><input type="checkbox" id="impRoot" checked onchange="$('impFileWrap').style.display=this.checked?'none':''"> 레포 루트의 ‘${esc(SH.rootXlsx)}’ 사용 (업로드 없이)</label>` : ""}
      <div class="field" id="impFileWrap" ${SH.rootXlsx ? 'style="display:none"' : ""}><label>xlsx 파일</label><input type="file" id="impFile" accept=".xlsx"></div>
      <label class="check" style="width:max-content"><input type="checkbox" id="impForce"> 같은 이름의 사이트가 있으면 덮어쓰기 (그 사이트의 접속정보·담당자·계약을 지우고 다시 넣습니다)</label>
      <p class="muted" style="margin:0">덮어쓰기를 켜지 않으면 이미 있는 사이트는 건너뜁니다.</p>
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">취소</button>
      <button type="button" class="btn-submit" id="impGo" onclick="runImport()">가져오기</button>
    </div>`);
}

async function runImport() {
  const useRoot = $("impRoot") && $("impRoot").checked;
  const f = $("impFile").files[0];
  if (!useRoot && !f) { showToast("xlsx 파일을 고르세요"); return; }
  const btn = $("impGo");
  btn.disabled = true; btn.textContent = "가져오는 중…";
  try {
    const fd = new FormData();
    if (useRoot) fd.append("use_root", "1"); else fd.append("xlsx", f);
    if ($("impForce").checked) fd.append("force", "1");
    const r = await fetch("import_xlsx.php", { method: "POST", body: fd, credentials: "same-origin" });
    if (r.status === 401) { location.href = "../index.php?need_login=sitehub"; return; }
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || "가져오지 못했습니다");
    closeSheet();
    showToast(`시트 ${j.sheets} · 신규 ${j.inserted}${j.archived ? ` (보관 ${j.archived})` : ""} · 교체 ${j.replaced} · 건너뜀 ${j.skipped.length} — 접속정보 ${j.accounts} · 담당자 ${j.contacts} · 계약 ${j.contracts}`);
    await load();
    if (APP.open.id) refreshDrawer();
  } catch (e) {
    showToast(e.message);
    btn.disabled = false; btn.textContent = "가져오기";
  }
}
