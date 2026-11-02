// 상세 드로어 — 사이트 한 곳의 모든 것. 탭 여덟 개, 주소 ?site=<id>&tab=<key> 와 같이 움직인다.

const TABS = [["basic", "기본"], ["accounts", "접속 정보"], ["contacts", "담당자"], ["contracts", "계약"], ["costs", "비용"], ["method", "유지보수 방법"], ["monitor", "감시 설정"], ["history", "이력"], ["changes", "변경 이력"]];
const ICON_PENCIL = `<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>`;

function openDrawer(id, tab, push = true) {
  const s = siteById(id);
  if (!s) { showToast("사이트를 찾을 수 없습니다"); return; }
  const wasOpen = APP.open.id === s.id && $("drawer").classList.contains("open");
  // 보관된 사이트 링크로 들어오면 보관함 보기로, 복원된 사이트면 사용 중 보기로 맞춘다
  const view = s.archived ? "archive" : "active";
  if (view !== APP.view) { APP.view = view; APP.filter.status = ""; buildFilters(); render(); }
  APP.open.id = s.id;
  if (tab) APP.open.tab = tab;
  if (!TABS.some(t => t[0] === APP.open.tab)) APP.open.tab = "basic";
  writeURL(push && !wasOpen);
  const d = $("drawer");
  const scrollTop = wasOpen ? (d.querySelector(".d-body") || {}).scrollTop || 0 : 0;
  d.innerHTML = drawerHtml(s);
  d.classList.add("open");
  d.setAttribute("aria-hidden", "false");
  $("scrim").classList.add("open");
  renderTab(s);
  revealTab();
  if (scrollTop) d.querySelector(".d-body").scrollTop = scrollTop;
}

// 좁은 화면에서는 탭 줄이 옆으로 넘친다 — 고른 탭이 가려져 있으면 보이게 민다
function revealTab() {
  const nav = document.querySelector("#drawer .tabs"), on = nav && nav.querySelector("a.on");
  if (!on) return;
  if (on.offsetLeft + on.offsetWidth > nav.scrollLeft + nav.clientWidth || on.offsetLeft < nav.scrollLeft) nav.scrollLeft = on.offsetLeft - 22;
}

function hideDrawer() {
  $("drawer").classList.remove("open");
  $("drawer").setAttribute("aria-hidden", "true");
  $("scrim").classList.remove("open");
  APP.open.id = null;
}
function closeDrawer() { hideDrawer(); writeURL(false); }
function refreshDrawer() { if (APP.open.id) openDrawer(APP.open.id, APP.open.tab, false); }

function switchTab(tab) {
  APP.open.tab = tab;
  writeURL(false);
  const s = siteById(APP.open.id);
  if (!s) return;
  document.querySelectorAll("#drawer .tabs a").forEach(a => a.classList.toggle("on", a.dataset.tab === tab));
  revealTab();
  renderTab(s);
}

function drawerHtml(s) {
  return `
    <header>
      <div class="d-head">
        <div class="d-tags">${statusBadge(s)}${s.customer ? `<span class="chip ghost">${esc(s.customer)}</span>` : ""}${archiveBadge(s)}${Number(s.monitor) ? `<button type="button" class="chip sh-mchip" onclick="switchTab('monitor')">${monitorDot(s.monitor_status)}BLUMO</button>` : ""}</div>
        <h3>${esc(s.name)}</h3>
        ${s.archived ? `<p class="sh-arch-line">보관됨${s.archived_at ? " · " + esc(String(s.archived_at).slice(0, 16)) : ""}${s.archive_note ? " · " + esc(s.archive_note) : ""}</p>` : ""}
        ${lastEditLine(s)}
      </div>
      <button type="button" class="x" onclick="closeDrawer()" title="닫기">×</button>
    </header>
    <nav class="tabs">${TABS.map(([k, label]) => `<a data-tab="${k}" class="${k === APP.open.tab ? "on" : ""}" onclick="switchTab('${k}')">${label}${tabCount(s, k)}</a>`).join("")}</nav>
    <div class="d-body" id="dBody"></div>
    <footer>
      <button type="button" class="btn-ghost" onclick="openSiteForm(siteById(${s.id}))">사이트 수정</button>
      ${s.archived
        ? `<button type="button" class="btn-ghost" onclick="restoreSite(${s.id})">복원</button>
           <button type="button" class="btn-mini danger" onclick="deleteSite(${s.id})" title="되돌릴 수 없습니다 — 보관으로 충분하면 삭제하지 마세요">완전 삭제</button>`
        : `<button type="button" class="btn-ghost" onclick="archiveSite(${s.id})" title="사용하지 않거나 더 이상 필요 없는 사이트는 지우지 말고 보관함으로">보관</button>`}
      <span class="grow"></span>
      <button type="button" class="btn-ghost" onclick="closeDrawer()">닫기</button>
    </footer>`;
}

// 머리의 '마지막 수정' 한 줄 — 사이트·접속정보·담당자·계약 중 무엇을 고쳐도 사이트의 updated_at/updated_by 가 같이 오른다
function lastEditLine(s) {
  const st = stampOf(s);
  if (!st) return "";
  const at = String(s.updated_at || s.created_at || "");
  const label = st.imported ? `엑셀 가져옴 ${esc(at.slice(0, 10))}` : `${st.text.startsWith("등록") ? "등록" : "마지막 수정"} ${esc(at.slice(0, 16))}${st.who ? " · " + esc(st.who) : ""}`;
  return `<p class="sh-edit-line" title="${esc(st.title)}">${label} <span class="muted">(${esc(agoText(at))})</span>
    <button type="button" class="linkish" onclick="switchTab('changes')">변경 이력 보기</button></p>`;
}

function tabCount(s, k) {
  if (k === "monitor") return Number(s.monitor) ? " " + monitorDot(s.monitor_status) : "";
  const n = k === "history" ? s.history_count : (Array.isArray(s[k]) ? s[k].length : 0);
  return n ? ` <span class="muted" style="font-size:11px">${n}</span>` : "";
}

function renderTab(s) {
  const body = $("dBody");
  const tab = APP.open.tab;
  if (tab === "basic")    body.innerHTML = kvSection(s, "basic");
  else if (tab === "method") body.innerHTML = kvSection(s, "method") + procSection(s);
  else if (tab === "history") { body.innerHTML = `<div class="d-sec"><div class="sh-synced">불러오는 중…</div></div>`; loadHistory(s); }
  else if (tab === "changes") { body.innerHTML = `<div class="d-sec"><div class="sh-synced">불러오는 중…</div></div>`; loadChanges(s); }
  else if (tab === "monitor") { body.innerHTML = monitorSection(s); if (SH.canStats && !APP.blumoLink) loadBlumoLink().then(() => { if (APP.open.id === s.id && APP.open.tab === "monitor") refreshDrawer(); }); }
  else body.innerHTML = subSection(s, tab);
}

/* ── 기본 · 유지보수 방법: dl.kv, 칸을 누르면 그 자리에서 고친다 ── */
function kvSection(s, groupKey) {
  const g = SH.groups.find(x => x.key === groupKey);
  return `<div class="d-sec"><h4>${esc(g.title)}</h4><dl class="kv">${g.fields.map(f => `<dt>${esc(f.label)}</dt>${kvDd(s, f)}`).join("")}</dl></div>`;
}

function kvDd(s, f) {
  const v = s[f.key];
  const empty = v === null || v === undefined || v === "" || (f.type === "bool" && false);
  let html;
  if (f.type === "url" && v) html = `<a href="${esc(/^[a-z][a-z0-9+.-]*:\/\//i.test(v) ? v : "http://" + v)}" target="_blank" rel="noopener">${esc(v)}</a>`;
  else if (f.type === "bool") html = Number(v) ? "예" : "아니오";
  else if (f.type === "staff") html = esc(kvText(f, v));
  else html = esc(v == null ? "" : String(v));
  const copy = f.copy && v ? cpBtn(v, "복사") : "";
  const isEmpty = f.type !== "bool" && empty;
  return `<dd class="sh-edit${isEmpty ? " is-empty" : ""}" data-k="${esc(f.key)}" onclick="editCell(event, ${s.id}, '${f.key}')" title="눌러서 고치기">${isEmpty ? "" : html}${copy}<button type="button" class="btn-mini ghost" style="height:22px;padding:0 6px" title="고치기" onclick="editCell(event, ${s.id}, '${f.key}', true)">${ICON_PENCIL}</button></dd>`;
}

function kvText(f, v) {
  if (f.type === "bool") return Number(v) ? "예" : "아니오";
  if (f.type === "staff") return splitList(v).map(e => (APP.staff.find(p => p.email === e) || {}).name || e).join(", ");
  return v == null ? "" : String(v);
}

function editCell(e, siteId, key, force) {
  if (!force && e.target.closest("a, button, input, textarea, select, label")) return;   // 링크·복사 버튼은 그대로 동작
  e.stopPropagation();
  const dd = e.currentTarget && e.currentTarget.tagName === "DD" ? e.currentTarget : e.target.closest("dd");
  if (!dd || dd.classList.contains("editing")) return;
  const s = siteById(siteId);
  const f = SH.groups.flatMap(g => g.fields).find(x => x.key === key);
  if (!s || !f) return;
  dd.classList.add("editing");
  dd.classList.remove("is-empty");
  dd.removeAttribute("onclick");
  dd.innerHTML = fieldControl(f, s[key]) +
    `<div class="sh-inline-act"><button type="button" class="btn-mini primary" onclick="saveCell(${siteId}, '${key}', this)">저장</button>
     <button type="button" class="btn-mini ghost" onclick="refreshDrawer()">취소</button></div>`;
  const ctl = dd.querySelector("input, textarea, select");
  if (ctl) {
    ctl.focus();
    if (ctl.tagName === "INPUT" && ctl.type === "text") ctl.select();
    ctl.addEventListener("keydown", ev => {
      if (ev.key === "Escape") { ev.preventDefault(); refreshDrawer(); }
      if (ev.key === "Enter" && ctl.tagName !== "TEXTAREA") { ev.preventDefault(); saveCell(siteId, key, dd.querySelector(".btn-mini.primary")); }
    });
  }
}

async function saveCell(siteId, key, btn) {
  const dd = btn.closest("dd");
  const f = SH.groups.flatMap(g => g.fields).find(x => x.key === key);
  const vals = readFields(dd, [f], false);
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "save_field", site_id: siteId, key, value: vals[key] });
    replaceSite(j.site);
    refreshDrawer();
    showToast("저장했습니다");
  } catch (err) {
    btn.disabled = false;
    showToast(err.message);
  }
}

/* ── 접속 정보 · 담당자 · 계약: 행 목록 + 추가/수정/삭제 ── */
function subSection(s, table) {
  const def = SH.subs[table];
  const rows = s[table] || [];
  const render = { accounts: accountRow, contacts: contactRow, contracts: contractRow, costs: costRow }[table];
  return `<div class="d-sec">
    ${table === "costs" ? costSummary(rows) : ""}
    <div class="sh-sec-head"><h4>${esc(def.title)}${rows.length ? ` <span class="muted">${rows.length}</span>` : ""}</h4>
      <button type="button" class="btn-mini primary" onclick="openSubForm('${table}', ${s.id})">+ 추가</button></div>
    <ul class="sh-rows">${rows.length ? rows.map(r => render(s, r)).join("") : `<li class="empty">아직 등록된 ${esc(def.one)}이 없습니다. ‘+ 추가’로 넣으세요.</li>`}</ul>
  </div>`;
}

const rowActs = (table, s, r) =>
  `<button type="button" class="btn-mini ghost" onclick="openSubForm('${table}', ${s.id}, ${r.id})">수정</button>
   <button type="button" class="btn-mini ghost danger" onclick="deleteSub('${table}', ${s.id}, ${r.id})">삭제</button>`;

function accountRow(s, a) {
  const kinds = SH.subs.accounts.fields.find(f => f.key === "kind").options;
  const pwId = `pw${a.id}`;
  return `<li>
    <div>
      <div class="t"><span class="chip">${esc(kinds[a.kind] || a.kind)}</span>${a.label ? esc(a.label) : ""}${a.expiry ? expiryBadge(Object.assign({ kind: a.kind, expires_on: a.expires_on }, a.expiry)) : ""}</div>
      <div class="s">
        ${a.host ? `<span><b>호스트</b> ${esc(a.host)}${a.port ? ":" + esc(a.port) : ""} ${cpBtn(a.host + (a.port ? ":" + a.port : ""), "복사")}</span>` : ""}
        ${a.login_id ? `<span><b>ID</b> ${esc(a.login_id)} ${cpBtn(a.login_id, "복사")}</span>` : ""}
        ${a.expires_on ? `<span><b>만료일</b> ${esc(a.expires_on)}</span>` : ""}
        ${a.login_pw ? `<span><b>PW</b> <span class="pw" id="${pwId}" data-pw="${esc(a.login_pw)}">••••••••</span>
          <button type="button" class="btn-mini ghost" style="height:22px;padding:0 6px" onclick="togglePw('${pwId}', this)" title="표시">${EYE_OFF}</button> ${cpBtn(a.login_pw, "복사")}</span>` : ""}
      </div>
      ${a.note ? `<div class="n">${esc(a.note)}</div>` : ""}
      <div class="sh-row-stamp">${stampHtml(a)}</div>
    </div>
    <div class="a">${rowActs("accounts", s, a)}</div>
  </li>`;
}

function togglePw(id, btn) {
  const el = $(id);
  const shown = el.textContent !== "••••••••";
  el.textContent = shown ? "••••••••" : el.dataset.pw.replace(/\s*\n+\s*/g, " ");
  btn.innerHTML = shown ? EYE_OFF : EYE_ON;
  btn.title = shown ? "표시" : "숨기기";
}

function contactRow(s, c) {
  const roles = SH.subs.contacts.fields.find(f => f.key === "role").options;
  return `<li>
    <div>
      <div class="t">${esc(c.name)}<span class="chip ghost">${esc(roles[c.role] || c.role)}</span>${c.dept ? `<span class="muted">${esc(c.dept)}</span>` : ""}</div>
      <div class="s">
        ${c.mobile ? `<span><b>휴대폰</b> ${esc(c.mobile)} ${cpBtn(c.mobile, "복사")}</span>` : ""}
        ${c.phone ? `<span><b>전화</b> ${esc(c.phone)} ${cpBtn(c.phone, "복사")}</span>` : ""}
        ${c.email ? `<span><b>이메일</b> <a href="mailto:${esc(c.email)}">${esc(c.email)}</a> ${cpBtn(c.email, "복사")}</span>` : ""}
      </div>
      ${c.note ? `<div class="n">${esc(c.note)}</div>` : ""}
      <div class="sh-row-stamp">${stampHtml(c)}</div>
    </div>
    <div class="a">${rowActs("contacts", s, c)}</div>
  </li>`;
}

function contractRow(s, c) {
  const kinds = SH.subs.contracts.fields.find(f => f.key === "kind").options;
  const latest = (s.contract_status || {}).contract_id === c.id;
  const t = new Date(), today = t.getFullYear() + "-" + String(t.getMonth() + 1).padStart(2, "0") + "-" + String(t.getDate()).padStart(2, "0");
  const upcoming = !latest && !!c.start_date && c.start_date > today;
  const period = (c.start_date || "?") + " ~ " + (c.end_date || "?");
  // 금액은 항상 1년 기준 — 납부 주기(월·분기)는 나눠 받는 방법일 뿐
  const amount = c.amount !== null && c.amount !== undefined ? `${fmtAmount(c.amount)} / 년${c.vat_included ? " (VAT 포함)" : " (VAT 별도)"}` : "";
  return `<li class="${latest ? "" : "dim"}">
    <div>
      <div class="t"><span class="chip">${esc(kinds[c.kind] || c.kind)}</span>${period}${latest ? statusBadge(s) : upcoming ? `<span class="badge sh-free">예정</span>` : `<span class="badge off">지난 계약</span>`}</div>
      <div class="s">
        ${amount ? `<span><b>연간 금액</b> ${esc(amount)}</span>` : ""}
        ${c.billing ? `<span><b>납부</b> ${esc(c.billing)}</span>` : ""}
        ${c.doc_url ? `<span><b>계약서</b> <a href="${esc(c.doc_url)}" target="_blank" rel="noopener">열기</a> ${cpBtn(c.doc_url, "복사")}</span>` : ""}
      </div>
      ${c.note ? `<div class="n">${esc(c.note)}</div>` : ""}
      <div class="sh-row-stamp">${stampHtml(c)}</div>
    </div>
    <div class="a">${rowActs("contracts", s, c)}</div>
  </li>`;
}

function costSummary(rows) {
  if (!rows.length) return "";
  const sum = list => list.reduce((a, r) => a + (Number(r.amount) || 0), 0);
  const year = String(new Date().getFullYear());
  const thisYear = rows.filter(r => String(r.paid_on || "").startsWith(year));
  return `<p class="sh-cost-sum"><span><b>누적</b> ${esc(fmtAmount(sum(rows)))}</span><span><b>${year}년</b> ${esc(fmtAmount(sum(thisYear)))}</span></p>`;
}

function costRow(s, c) {
  const kinds = SH.subs.costs.fields.find(f => f.key === "kind").options;
  const amount = c.amount !== null && c.amount !== undefined ? fmtAmount(c.amount) + (c.vat_included ? " (VAT 포함)" : " (VAT 별도)") : "";
  return `<li>
    <div>
      <div class="t"><span class="chip">${esc(kinds[c.kind] || c.kind)}</span>${esc(c.item || "")}<span class="muted">${esc(c.paid_on || "결제일 없음")}</span></div>
      <div class="s">
        ${amount ? `<span><b>금액</b> ${esc(amount)}</span>` : ""}
        ${c.vendor ? `<span><b>결제처</b> ${esc(c.vendor)}</span>` : ""}
        ${c.method ? `<span><b>수단</b> ${esc(c.method)}</span>` : ""}
        ${c.period_note ? `<span><b>이용 기간</b> ${esc(c.period_note)}</span>` : ""}
        ${c.doc_url ? `<span><b>증빙</b> <a href="${esc(c.doc_url)}" target="_blank" rel="noopener">열기</a> ${cpBtn(c.doc_url, "복사")}</span>` : ""}
      </div>
      ${c.note ? `<div class="n">${esc(c.note)}</div>` : ""}
      <div class="sh-row-stamp">${stampHtml(c)}</div>
    </div>
    <div class="a">${rowActs("costs", s, c)}</div>
  </li>`;
}

/* ── 감시 설정 (BLUMO) ── 감시 대상 목록의 원본이 여기다. 켜면 monitor_run.php 가 5분마다 아래 주소를 점검하고
   장애가 확정되면 Slack #blumo 로 알린다. 지금 점검·자동 점검 멈춤·알림 무음·점검 기록도 여기서 (monitor.js 와 같이 쓴다). */
function monitorSection(s) {
  const on = !!Number(s.monitor);
  const ms = s.monitors || [];
  const urlOps = (s.url_ops || "").trim();
  const head = `<div class="sh-mon-head">
      <div class="sh-mon-title"><h4>BLUMO 감시</h4>${on ? monitorDot(s.monitor_status) : ""}</div>
      <label class="sh-switch" title="${on ? "끄면 다음 자동 점검부터 이 사이트를 뺍니다" : "켜면 BLUMO 가 이 사이트를 감시합니다"}">
        <input type="checkbox"${on ? " checked" : ""} onchange="toggleMonitor(${s.id}, this.checked, this)"><span class="sh-switch-ui"></span><b>${on ? "감시 중" : "꺼짐"}</b></label>
    </div>
    <p class="sh-mon-lede">${on
      ? "아래 주소를 자동 점검(5분마다)으로 열어 보고, 연속 실패로 장애가 확정되면 Slack #blumo 로 알립니다. 목록의 원은 초록=정상, 빨강=장애입니다."
      : (ms.length ? "감시를 끈 사이트입니다. 아래 주소는 남아 있어서 다시 켜면 그대로 감시합니다."
                   : `감시하지 않는 사이트입니다. 켜면 ${urlOps ? `운영 URL(<b>${esc(urlOps)}</b>)로 감시 주소를 만들고` : "감시 주소를 직접 넣어"} 감시를 시작합니다.`)}</p>`;
  const rows = ms.length ? `<ul class="sh-rows sh-mon-rows${on ? "" : " is-off"}">${ms.map(m => monitorRow(s, m, on)).join("")}</ul>`
    : (on ? `<ul class="sh-rows"><li class="empty">감시 주소가 없습니다. ‘+ 감시 주소’로 넣으세요.</li></ul>` : "");
  const list = (on || ms.length) ? `<div class="sh-sec-head" style="margin-top:14px"><h4>감시 주소 ${ms.length ? `<span class="muted">${ms.length}</span>` : ""}</h4>
      <button type="button" class="btn-mini primary" onclick="openSubForm('monitors', ${s.id})">+ 감시 주소</button></div>${rows}` : "";
  const sugg = SH.canStats ? seedSuggestions(s) : "";
  const foot = `<p class="sh-mon-foot">${monBlumoLine(APP.blumo)}<br>모든 사이트의 감시 현황·장애 이력은 <a href="monitor.php">BLUMO 감시</a> 화면에서 봅니다.</p>`;
  return `<div class="d-sec">${head}${sugg}${list}${foot}</div>`;
}

function monitorRow(s, m, on) {
  const st = m.state;
  const w = m.watch || { code: "unknown", text: "아직 점검 전" };
  let line;
  if (!on) line = `<span class="muted">사이트 감시 꺼짐</span>`;
  else if (!Number(m.enabled)) line = `<b>자동 점검 멈춤</b> <span class="muted">— ⋯ 에서 다시 켭니다</span>`;
  else if (!st) line = `<span class="muted">아직 점검 전 — ‘지금 점검’을 누르거나 다음 자동 점검을 기다리세요</span>`;
  else {
    const parts = [];
    if (st.status === "down") parts.push(`<b class="sh-neg">장애</b> ${esc(st.cause || st.class)}${st.since ? ` · ${esc(String(st.since).slice(5, 16))}부터` : ""}`);
    else if (w.code === "unknown") parts.push(`<b>확인 필요</b> <span class="muted">${esc(w.text)}</span>`);
    else if (st.class === "OK") parts.push(`<b class="sh-pos">정상</b>`);
    else parts.push(`<b class="sh-warn">${["SLOW", "TLS_EXPIRING"].includes(st.class) ? "경고" : "확인 중"}</b> <span class="badge sh-st-soon">${esc(st.class)}</span> ${esc(st.cause || "")}`);
    if (st.total_ms !== null) parts.push(`${Number(st.total_ms).toLocaleString("ko-KR")}ms`);
    if (st.cert_days !== null) parts.push(`SSL ${st.cert_days}일`);
    if (st.avail_24h !== null && st.avail_24h !== undefined) parts.push(`24시간 ${st.avail_24h}%`);
    if (st.checked_at) parts.push(`${esc(agoText(st.checked_at))} 점검`);
    line = parts.join(" · ");
  }
  if (m.mute_until) line += ` <span class="badge off" title="알림만 끔 — 점검은 계속">알림 무음 ~${esc(String(m.mute_until).slice(5, 16))}</span>`;
  const opts = [m.slow_ms ? `느림 ${Number(m.slow_ms).toLocaleString("ko-KR")}ms` : "", m.timeout ? `대기 ${m.timeout}초` : "", m.min_size ? `최소 ${Number(m.min_size).toLocaleString("ko-KR")}bytes` : ""].filter(Boolean);
  const shown = (m.url || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
  return `<li>
    <div>
      <div class="t">${on ? watchDot(w) : watchDot({ code: "paused", text: "감시 꺼짐" })}<a href="${esc(m.url)}" target="_blank" rel="noopener">${esc(shown)}</a>${m.label ? `<span class="chip ghost">${esc(m.label)}</span>` : ""}${m.health ? `<span class="chip" title="${esc(m.health)}">에이전트</span>` : ""}</div>
      <div class="s sh-mon-state"><span>${line}</span></div>
      <div class="s">
        <span><b>정상 판정 문구</b> ${m.expect ? esc(m.expect) : `<span class="muted">없음 — 응답 크기로만 판단</span>`}</span>
        ${opts.length ? `<span><b>기준</b> ${esc(opts.join(" · "))}</span>` : ""}
        <span><b>에이전트</b> ${m.health ? `<a href="${esc(m.health)}" target="_blank" rel="noopener">${esc(m.health.replace(/^https?:\/\//, ""))}</a> ${cpBtn(m.health, "복사", "에이전트 주소 복사")} ` : `<span class="muted">주소 없음</span> `}<button type="button" class="btn-mini ghost" onclick="downloadAgent(${s.id}, ${m.id})" title="키가 들어간 blumo-agent.php — 사이트 최상위에 올린다">blumo-agent.php 받기</button></span>
        ${m.health_key ? `<span><b>에이전트 키</b> ${cpBtn(m.health_key, "복사", "에이전트 키 복사")}</span>` : ""}
      </div>
      ${m.note ? `<div class="n">${esc(m.note)}</div>` : ""}
      <div class="sh-row-stamp">${stampHtml(m)}</div>
    </div>
    <div class="a">
      <button type="button" class="btn-mini" onclick="monCheck(${m.id}, this)">지금 점검</button>
      <button type="button" class="btn-mini ghost" onclick="monOpenDetail(${m.id})">기록</button>
      ${monMenu(m)}
      ${rowActs("monitors", s, m)}
    </div>
  </li>`;
}

async function toggleMonitor(siteId, on, el) {
  const s = siteById(siteId);
  if (!s) return;
  if (!on && !(await askConfirm("BLUMO 감시 끄기", `‘${s.name}’ 을 다음 자동 점검부터 뺍니다. 장애가 나도 알림이 오지 않습니다. 감시 주소와 점검 기록은 남겨 둡니다.`, "끄기"))) {
    el.checked = true; return;
  }
  el.disabled = true;
  try {
    const j = await postJSON({ action: "monitor_toggle", site_id: siteId, on: on ? 1 : 0 });
    applyMeta(j.meta);
    replaceSite(j.site);
    refreshDrawer();
    showToast(on ? (j.made ? "감시를 켰습니다 — 운영 URL 로 감시 주소를 만들었으니 ‘지금 점검’으로 확인해 보세요" : "감시를 켰습니다") : "감시를 껐습니다");
  } catch (e) {
    el.checked = !on; el.disabled = false;
    showToast(e.message);
  }
}

/* ── 이력 (Slack 리스트 동기화 결과) ── */
async function loadHistory(s) {
  try {
    const [j] = await Promise.all([api("?history=" + s.id), loadProcs().catch(() => { })]);
    if (APP.open.id !== s.id || APP.open.tab !== "history") return;
    applyMeta(j.meta);
    APP.histRows = j.rows || [];
    $("dBody").innerHTML = historySection(s, APP.histRows);
  } catch (e) {
    $("dBody").innerHTML = `<div class="d-sec"><div class="sh-synced">${esc(e.message)}</div></div>`;
  }
}

function historySection(s, all) {
  // Slack 하위 항목(parent_id)은 따로 줄을 만들지 않고 부모 요청 아래에 붙인다 — 리스트 화면에서도 부모 밑에 접혀 있다
  const ids = new Set(all.map(r => r.id));
  const kids = {};
  all.forEach(r => { if (r.parent_id && ids.has(r.parent_id)) (kids[r.parent_id] = kids[r.parent_id] || []).push(r); });
  const top = all.filter(r => !(r.parent_id && ids.has(r.parent_id)));
  // 연도 셀렉박스 — 목록 머리에 보이는 날짜(요청일시 우선, 없으면 date)의 앞 4자리. 없는 연도가 골라져 있으면 전체로 본다
  const yearOf = r => String(r.req_at || r.date || "").slice(0, 4);
  const years = [...new Set(top.map(yearOf).filter(y => /^\d{4}$/.test(y)))].sort().reverse();
  const year = years.includes(APP.histYear) ? APP.histYear : "";
  const rows = year ? top.filter(r => yearOf(r) === year) : top;
  const yearSel = years.length > 1 ? `<select class="sh-hist-year" onchange="setHistYear(this.value)" title="연도별로 보기"><option value="">전체 연도</option>${years.map(y => `<option value="${y}"${y === year ? " selected" : ""}>${y}년</option>`).join("")}</select>` : "";
  Object.values(kids).forEach(list => list.sort((a, b) => a.created - b.created));
  const synced = APP.lastSync ? `마지막 동기화 ${esc(APP.lastSync)}` : "아직 동기화한 적이 없습니다";
  const syncBtn = SH.canSync ? `<button type="button" class="btn-mini" onclick="runSync()">지금 동기화</button>` : "";
  const hint = !SH.listUrl && !SH.canSync ? `<span class="muted">config.php 의 sitehub_list_id · sitehub_list_url 과 config.local.php 의 sitehub_slack_token 을 채우면 Slack 리스트에서 가져옵니다.</span>` : "";
  const addBtn = SH.listUrl || SH.canSync ? `<button type="button" class="btn-mini primary" onclick="openHistForm(${s.id})" title="Slack 리스트에도 같이 등록됩니다 (개인 Slack 토큰)">+ 이력 추가</button>` : "";
  return `<div class="d-sec">
    <div class="sh-sec-head"><h4>이력 ${rows.length ? `<span class="muted">${rows.length}</span>` : ""}</h4><div class="sh-synced">${yearSel}${synced} ${syncBtn} ${addBtn}</div></div>
    ${hint ? `<p class="sh-synced" style="margin:0 0 10px">${hint}</p>` : ""}
    <ul class="sh-rows">${rows.length ? rows.map(h => historyRow(h, kids[h.id] || [])).join("") : `<li class="empty">이 사이트에 매칭된 이력이 없습니다.${s.slack_names ? "" : " 기본 탭의 ‘Slack 표기’에 리스트에서 쓰는 이름을 적으면 자동으로 매칭됩니다."}</li>`}</ul>
  </div>`;
}

// 이력 연도 선택 — 고른 값은 APP.histYear 에 두고 목록만 다시 그린다(서버 재요청 없음)
function setHistYear(y) {
  APP.histYear = y;
  const s = APP.open.id ? siteById(APP.open.id) : null;
  if (s && APP.open.tab === "history") $("dBody").innerHTML = historySection(s, APP.histRows || []);
}

// 처리상태 색 — 처리완료 posi · 취소/보류 회색 · 나머지(시작전/작업중/검토중) warn
function historyStatusBadge(st) {
  if (!st) return "";
  const cls = /완료/.test(st) ? "sh-st-active" : /취소|보류/.test(st) ? "off" : "sh-st-soon";
  return `<span class="badge ${cls}">${esc(st.replace(/^\d+_/, ""))}</span>`;
}

function historyRow(h, kids = []) {
  const link = SH.listUrl ? cpBtn(SH.listUrl + "?record_id=" + h.id, "링크 복사") : "";
  const site = APP.open.id ? siteById(APP.open.id) : null;
  const norm = v => (v || "").replace(/\s+/g, "").toLowerCase();
  // 이 리스트는 제목 열이 업체명이라 사이트 안에서는 같은 말 반복 — 제목 대신 작업내용을 머리로 쓴다
  const sameAsSite = SH.titleIsSite || (site && (norm(h.title) === norm(site.name) || splitList(site.slack_names).some(n => norm(n) === norm(h.title))));
  const head = sameAsSite && h.body ? h.body : (h.title || "(제목 없음)");
  const body = sameAsSite ? "" : h.body;
  // 한 줄 머리: [상태] [요청일] 작업내용 (우선순위가 '높음' 이면 끝에). 요청구분·무/유상은 이 목록에 안 보인다(통계에서 본다).
  const when = h.req_at ? String(h.req_at).slice(0, 16) : (h.date || "");   // 요청일시(시간까지) 가 있으면 그걸
  const date = when ? `<span class="sh-hist-date">${esc(when)}</span>` : "";
  const prio = h.priority === "높음" ? `<span class="badge lv-required">높음</span>` : "";
  const asg = h.asg && h.asg !== "—" ? `<span><b>담당</b> ${esc(h.asg)}</span>` : "";
  const done = h.remark ? `<span><b>완료</b> ${esc(h.remark)}</span>` : (h.done ? `<span><b>완료</b> ${esc(h.done)}</span>` : "");
  const files = (h.attachments || []).length ? `<span><b>첨부</b> ${h.attachments.length}건</span>` : "";
  const kidsBtn = kids.length ? `<button type="button" class="btn-mini ghost sh-kids-btn" onclick="this.closest('li').classList.toggle('open')">하위 작업 ${kids.length}</button>` : "";
  const sub = asg + done + files + histProcChips(h) + kidsBtn;
  return `<li data-id="${esc(h.id)}">
    <div>
      <div class="t">${historyStatusBadge(h.status)}${date}<button type="button" class="linkish sh-hist-head" onclick="this.closest('li').classList.toggle('open')" title="${esc(h.title || "")}">${esc(head)}</button>${prio}</div>
      ${sub ? `<div class="s">${sub}</div>` : ""}
      ${body ? `<div class="sh-hist-body">${esc(body)}</div>` : ""}
      ${kids.length ? `<ul class="sh-hist-kids">${kids.map(k => `<li>${esc(k.title || k.site_text || "(내용 없음)")}${k.remark ? `<span class="muted"> · ${esc(k.remark)}</span>` : ""}</li>`).join("")}</ul>` : ""}
    </div>
    <div class="a">${APP.open.id ? `<button type="button" class="btn-mini ghost" onclick="openProcLink('${esc(h.id)}', ${APP.open.id})" title="이 요청을 처리한 절차를 연결">절차</button>` : ""}${link}${APP.open.id && !h.parent_id && (SH.listUrl || SH.canSync) ? `<button type="button" class="btn-mini ghost" onclick="openHistForm(${APP.open.id}, '${esc(h.id)}')">수정</button><button type="button" class="btn-mini ghost danger" onclick="deleteHist('${esc(h.id)}')">삭제</button>` : ""}</div>
  </li>`;
}

/* ── 이력 추가 · 수정 · 삭제 — Slack 리스트에 먼저 쓰고 DB 에 반영한다(개인 Slack 토큰, history_write.php) ── */
const HIST_NOW = () => { const d = new Date(), z = n => String(n).padStart(2, "0"); return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())} ${z(d.getHours())}:${z(d.getMinutes())}`; };

async function openHistForm(siteId, id) {
  const site = siteById(siteId);
  const row = id ? (APP.histRows || []).find(r => r.id === id) : null;
  if (!site || (id && !row)) { showToast("이력을 찾을 수 없습니다"); return; }
  try {
    if (!APP.histOpts) APP.histOpts = (await api("?history_options=1")).options;
  } catch (e) { showToast(e.message); return; }
  const sel = (key, label) => ({ key, label, type: "select", options: Object.assign({ "": "—" }, ...(APP.histOpts[key] || []).map(v => ({ [v]: v }))) });
  const f = [
    { key: "req_at", label: "요청일시", type: "text", hint: "YYYY-MM-DD HH:MM — 비우면 지금" },
    sel("channel", "요청구분"),
    { key: "body", label: "작업내용", type: "area", required: 1 },
    sel("status", "처리상태"), sel("paid", "무/유상"), sel("priority", "우선순위"),
    { key: "remark", label: "완료시간 · 비고", type: "text" },
  ];
  const v = row ? { req_at: row.req_at ? String(row.req_at).slice(0, 16) : (row.date || ""), channel: row.channel, body: row.body, status: row.status, paid: row.paid, priority: row.priority, remark: row.remark }
    : { req_at: HIST_NOW(), channel: "", body: "", status: (APP.histOpts.status || []).find(x => /시작전/.test(x)) || "", paid: "", priority: "", remark: "" };
  window.__histFields = f;
  openSheet(`
    <div class="sheet-head"><div><span class="k">${esc(site.name)}</span><h2>이력 ${row ? "수정" : "추가"}</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="histForm">
      <p class="fhint">저장하면 Slack 리스트 ‘BlueLab-유지보수처리목록’ 에도 같이 ${row ? "반영" : "등록"}됩니다.</p>
      ${fieldHtml(f[0], v.req_at)}<div class="two">${fieldHtml(f[1], v.channel)}${fieldHtml(f[3], v.status)}</div>
      ${fieldHtml(f[2], v.body)}<div class="two">${fieldHtml(f[4], v.paid)}${fieldHtml(f[5], v.priority)}</div>${fieldHtml(f[6], v.remark)}
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">취소</button>
      <button type="button" class="btn-submit" id="histSave" onclick="saveHistForm(${siteId}, '${row ? esc(row.id) : ""}')">${row ? "저장" : "추가"}</button>
    </div>`);
}

function applyHistResult(j) {
  APP.histRows = j.rows || [];
  applyMeta(j.meta);
  if (j.site) replaceSite(j.site);
  const s = APP.open.id ? siteById(APP.open.id) : null;
  if (s && APP.open.tab === "history") $("dBody").innerHTML = historySection(s, APP.histRows);
}

async function saveHistForm(siteId, id) {
  const body = readFields($("histForm"), window.__histFields, !!id);
  const btn = $("histSave");
  btn.disabled = true;
  try {
    const j = await postJSON(Object.assign({ action: id ? "history_update" : "history_add", site_id: siteId, id }, body));
    closeSheet();
    applyHistResult(j);
    showToast(id ? "수정했습니다 (Slack 반영)" : "이력을 추가했습니다 (Slack 등록)");
  } catch (e) {
    showToast(e.message);
  } finally {
    btn.disabled = false;
  }
}

async function deleteHist(id) {
  const row = (APP.histRows || []).find(r => r.id === id);
  const label = row ? String(row.body || row.title || "").replace(/\s+/g, " ").slice(0, 40) : "";
  if (!(await askConfirm("이력 삭제", (label ? "‘" + label + "’ 이력을 " : "이 이력을 ") + "Slack 리스트에서도 지웁니다(하위 작업 포함). 되돌릴 수 없습니다.", "삭제"))) return;
  try {
    applyHistResult(await postJSON({ action: "history_delete", id }));
    showToast("삭제했습니다 (Slack 반영)");
  } catch (e) {
    showToast(e.message);
  }
}

/* ── 변경 이력 — api.php?changes=<id> (audit_lib.php). 한 번 저장에 칸이 여럿 바뀌면 한 묶음으로 보인다. ── */
const CHG_ACTION = { create: "추가", update: "수정", delete: "삭제", archive: "보관", restore: "복원", import: "엑셀 가져오기" };
const CHG_TARGET = { site: "사이트", accounts: "접속 정보", contacts: "담당자", contracts: "계약", costs: "비용", monitors: "감시 주소", procedures: "처리 절차" };

async function loadChanges(s) {
  try {
    const j = await api("?changes=" + s.id);
    if (APP.open.id !== s.id || APP.open.tab !== "changes") return;
    $("dBody").innerHTML = changesSection(s, j.rows || []);
  } catch (e) {
    $("dBody").innerHTML = `<div class="d-sec"><div class="sh-synced">${esc(e.message)}</div></div>`;
  }
}

function changesSection(s, rows) {
  // 같은 시각·사람·대상·행·동작이면 한 번의 저장 — 묶는다 (rows 는 최신순)
  const groups = [];
  rows.forEach(r => {
    const g = groups[groups.length - 1];
    const key = [r.at, r.user_email, r.action, r.target, r.row_id].join("|");
    if (g && g.key === key) g.items.push(r);
    else groups.push({ key, head: r, items: [r] });
  });
  const days = [];
  groups.forEach(g => {
    const day = String(g.head.at).slice(0, 10);
    const d = days[days.length - 1];
    if (d && d.day === day) d.groups.push(g); else days.push({ day, groups: [g] });
  });
  const st = stampOf(s);
  const origin = s.updated_by === "import:xlsx" || /^\[엑셀\]/.test(s.note || "") ? "엑셀 가져오기" : "등록";
  const foot = `<p class="sh-chg-foot">${esc(origin)} ${esc(String(s.created_at || "").slice(0, 16))}${st && st.imported ? " · 그 뒤로 고친 적 없음" : ""}<br>
    변경 이력은 이 기능을 넣은 뒤부터 쌓입니다. 비밀번호는 값을 남기지 않고 바뀌었다는 것만 적습니다.</p>`;
  return `<div class="d-sec">
    <div class="sh-sec-head"><h4>변경 이력 ${groups.length ? `<span class="muted">${groups.length}</span>` : ""}</h4></div>
    ${days.length ? days.map(d => `<div class="sh-chg-day">${esc(d.day)} <span class="muted">${esc(dayAgo(d.day))}</span></div>
      <ul class="sh-rows sh-chg">${d.groups.map(changeGroupHtml).join("")}</ul>`).join("")
      : `<ul class="sh-rows"><li class="empty">아직 기록된 변경이 없습니다.</li></ul>`}
    ${foot}
  </div>`;
}

function dayAgo(day) {
  const now = new Date(), t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate()), t1 = new Date(day + "T00:00:00");
  const n = Math.round((t0 - t1) / 86400000);
  return n <= 0 ? "오늘" : n === 1 ? "어제" : n + "일 전";
}

function changeGroupHtml(g) {
  const h = g.head;
  const who = h.user_name || whoName(h.user_email) || h.user_email;
  const target = CHG_TARGET[h.target] || h.target;
  const action = CHG_ACTION[h.action] || h.action;
  const verb = h.target === "site" && h.action === "create" ? "등록" : action;
  const label = h.target === "site" ? "" : (h.row_label ? `<span class="sh-chg-row">${esc(h.row_label)}</span>` : "");
  const fields = g.items.filter(r => r.field);
  const cls = { create: "sh-st-active", delete: "sh-st-expired", archive: "off", restore: "sh-st-active", import: "off" }[h.action] || "sh-st-soon";
  return `<li>
    <div>
      <div class="t"><span class="sh-chg-time" title="${esc(h.at)}">${esc(String(h.at).slice(11, 16))}</span>
        <span class="badge ${cls}">${esc(h.target === "site" ? verb : target + " " + verb)}</span>${label}
        <span class="sh-chg-who">${esc(who)}</span></div>
      ${fields.length ? `<ul class="sh-chg-fields">${fields.map(changeFieldHtml).join("")}</ul>` : ""}
      ${h.note ? `<div class="n">${esc(h.note)}</div>` : ""}
    </div>
  </li>`;
}

function changeFieldHtml(r) {
  const val = (v, cls) => {
    if (v === null || v === undefined || v === "") return `<span class="sh-chg-empty">(비어 있음)</span>`;
    const t = String(v);
    if (t.length > 120 || /\n/.test(t)) {
      return `<details class="sh-chg-long ${cls}"><summary>${esc(t.replace(/\s+/g, " ").slice(0, 60))}… <span class="muted">(${t.length}자)</span></summary><div>${nl2br(t)}</div></details>`;
    }
    return `<span class="${cls}">${esc(t)}</span>`;
  };
  if (r.new_value === "(변경됨)" && r.old_value === null) {
    return `<li><b>${esc(r.field_label || r.field)}</b> <span class="sh-chg-secret">변경됨 (값은 남기지 않음)</span></li>`;
  }
  return `<li><b>${esc(r.field_label || r.field)}</b> ${val(r.old_value, "sh-chg-old")} <span class="sh-chg-arrow">→</span> ${val(r.new_value, "sh-chg-new")}</li>`;
}

/* ── 사이트 삭제 — 하위 행도 같이 지워지므로 두 번 묻는다 ── */
async function deleteSite(id) {
  const s = siteById(id);
  if (!s) return;
  const n = (s.accounts || []).length + (s.contacts || []).length + (s.contracts || []).length;
  if (!(await askConfirm("사이트 삭제", `‘${s.name}’ 과 접속정보·담당자·계약 ${n}건을 지웁니다. Slack 이력은 남고 사이트 연결만 풀립니다.`, "계속"))) return;
  if (!(await askConfirm("정말 삭제할까요?", "되돌릴 수 없습니다. 비밀번호 등 접속정보도 함께 사라집니다.", "삭제"))) return;
  try {
    const j = await postJSON({ action: "delete", site_id: id });
    applyMeta(j.meta);
    closeDrawer();
    removeSite(id);
    showToast("삭제했습니다");
  } catch (e) {
    showToast(e.message);
  }
}

// 에이전트 파일은 키가 있어야 만든다 — 없으면 수정 창을 열어 '키 생성'부터 하게 한다
function downloadAgent(siteId, monId) {
  const site = siteById(siteId);
  const m = site && (site.monitors || []).find(x => x.id === Number(monId));
  if (!m) return;
  if (m.health_key) { location.href = "api.php?agent=" + m.id; return; }
  showToast("에이전트 키가 없습니다. 키 생성 후 저장하면 받을 수 있습니다");
  openSubForm("monitors", siteId, monId);
}
