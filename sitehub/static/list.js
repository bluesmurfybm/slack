// 목록 — 요약 네 칸 · 검색 · 필터 · 리스트/카드. 1행 = 사이트 1개.

async function load() {
  const j = await api("?all=1");
  APP.sites = j.sites || [];
  APP.staff = j.staff || [];
  APP.syncAge = j.meta ? j.meta.sync_age : null;
  applyMeta(j.meta);
  if (j.seeded) showToast(j.seeded.error ? "엑셀 자동 가져오기 실패: " + j.seeded.error
    : `호스팅 정보 엑셀에서 사이트 ${j.seeded.inserted}곳 · 접속정보 ${j.seeded.accounts} · 담당자 ${j.seeded.contacts} · 계약 ${j.seeded.contracts}을 가져왔습니다`);
  buildFilters();
  render();
}

function applyMeta(meta) {
  if (!meta) return;
  APP.unmatched = meta.unmatched || 0;
  APP.lastSync = meta.last_sync || null;
  if (meta.blumo) APP.blumo = meta.blumo;
  SH.canSync = !!meta.can_sync;
  if (typeof autoSyncSeen === "function") autoSyncSeen(meta.sync_age);
  const bs = $("btnSync"), bu = $("btnUnmatched");
  if (bs) {
    bs.hidden = !SH.canSync;
    bs.title = (APP.lastSync ? "마지막 동기화 " + APP.lastSync : "아직 동기화한 적 없음")
      + (SH.autoSyncMin ? ` · 화면을 열어 두면 ${SH.autoSyncMin}분마다 자동` : "");
  }
  if (bu) { bu.hidden = !APP.unmatched; bu.textContent = "미매칭 " + APP.unmatched; }
}

// 사이트 한 건을 바꿔 끼운다 — 쓰기 응답의 site 로 전체 재조회 없이 화면을 맞춘다
function replaceSite(site) {
  if (!site) return;
  const i = APP.sites.findIndex(s => s.id === site.id);
  if (i >= 0) APP.sites[i] = site; else APP.sites.push(site);
  buildFilters();
  render();
}
function removeSite(id) {
  APP.sites = APP.sites.filter(s => s.id !== Number(id));
  buildFilters();
  render();
}

/* ── 보기 ── 사용 중 / 보관함. 요약·필터·건수는 지금 보는 쪽만 센다. */
const inView = s => APP.view === "archive" ? !!s.archived : !s.archived;
const viewSites = () => APP.sites.filter(inView);

function setView(v) {
  const next = v === "archive" ? "archive" : "active";
  if (next === APP.view) return;
  APP.view = next;
  APP.filter.status = "";
  writeURL(false);
  buildFilters();
  render();
}

/* ── 필터 ── 호스팅·스택은 쉼표(·)로 나눈 값 하나하나로 고른다(access 의 VPN 필터와 같은 방식).
   'PHP 7.0, mariadb 10.0.x, CIboard' 는 PHP 7.0 / mariadb 10.0.x / CIboard 세 필터에 다 걸린다.
   대소문자·띄어쓰기(공백 전부)만 다른 값은 하나로 묶고, 이름은 가장 많이 쓴 표기로 보여 준다. 필터 값은 tagKey. */
function buildFilters() {
  const scope = viewSites();
  const fill = (id, key, label) => {
    const el = $(id);
    const cur = APP.filter[key];
    const vals = new Map();   // tagKey → { n: 사이트 수, names: Map(표기 → 횟수) }
    scope.forEach(s => {
      const seen = new Set();
      splitTags(s[key]).forEach(v => {
        const k = tagKey(v);
        if (seen.has(k)) return;   // 한 사이트에 같은 값이 두 번 있어도 한 번만 센다
        seen.add(k);
        const e = vals.get(k) || { n: 0, names: new Map() };
        e.n++;
        e.names.set(v, (e.names.get(v) || 0) + 1);
        vals.set(k, e);
      });
    });
    const label1 = e => [...e.names.entries()].sort((a, b) => b[1] - a[1])[0][0];
    const opts = [...vals.entries()].map(([k, e]) => [k, label1(e), e.n]).sort((a, b) => a[1].localeCompare(b[1], "ko", { numeric: true }));
    el.innerHTML = `<option value="">${label} 전체</option>` +
      opts.map(([k, name, n]) => `<option value="${esc(k)}"${k === cur ? " selected" : ""}>${esc(name)} (${n})</option>`).join("");
    if (cur && !vals.has(cur)) APP.filter[key] = "";
  };
  fill("fHosting", "hosting", "호스팅");
  fill("fStack", "stack", "스택");
}

function siteText(s) {
  const parts = [s.name, s.customer, s.url_ops, s.url_admin, s.url_dev, s.hosting, s.stack, s.repo, s.slack_names,
    s.deploy, s.backup, s.caution, s.note];
  (s.contacts || []).forEach(c => parts.push(c.name, c.dept, c.phone, c.mobile, c.email, c.note));
  (s.accounts || []).forEach(a => parts.push(a.label, a.host, a.login_id, a.note));
  (s.contracts || []).forEach(c => parts.push(c.note, c.billing));
  return parts.filter(Boolean).join("\n").toLowerCase();
}

/* 정렬: 계약(유상) → 무상 → 건별 → 미계약. 같은 묶음 안에서는 만료 임박 → 계약중 → 만료, 그 다음 종료일이 가까운 순, 이름순. */
const STATUS_ORDER = { soon: 0, active: 1, expired: 2, none: 3 };
const KIND_TIER = { free: 1, case: 2, none: 3 };   // 그 밖(연간·월간)은 0
const kindTier = cs => KIND_TIER[cs.kind] ?? 0;
function sortSites(rows) {
  return rows.slice().sort((a, b) => {
    const ca = a.contract_status || {}, cb = b.contract_status || {};
    if (kindTier(ca) !== kindTier(cb)) return kindTier(ca) - kindTier(cb);
    if (STATUS_ORDER[ca.code] !== STATUS_ORDER[cb.code]) return (STATUS_ORDER[ca.code] ?? 9) - (STATUS_ORDER[cb.code] ?? 9);
    const da = ca.dday ?? 1e9, db = cb.dday ?? 1e9;
    if (da !== db) return ca.code === "expired" ? db - da : da - db;   // 만료는 최근에 끝난 것부터
    return a.name.localeCompare(b.name, "ko");
  });
}

function visibleSites() {
  const f = APP.filter;
  const q = f.q.trim().toLowerCase();
  return sortSites(viewSites()).filter(s => {
    if (f.expiry && !(s.expiry_alerts || []).length) return false;
    if (f.status && (s.contract_status || {}).code !== f.status) return false;
    if (f.hosting && !splitTags(s.hosting).some(v => tagKey(v) === f.hosting)) return false;
    if (f.stack && !splitTags(s.stack).some(v => tagKey(v) === f.stack)) return false;
    if (q && !siteText(s).includes(q)) return false;
    return true;
  });
}

/* ── 그리기 ── */
function render() {
  const archive = APP.view === "archive";
  renderStats();
  $("stats").hidden = archive || !SH.canStats;   // 요약은 지정 계정만, 보관함에서는 안 센다
  $("archiveNote").hidden = !archive;
  renderExpiryNote(archive);
  document.body.classList.toggle("sh-view-archive", archive);
  const rows = visibleSites();
  $("count").textContent = rows.length + " / " + viewSites().length + "곳";
  $("cntActive").textContent = APP.sites.filter(s => !s.archived).length;
  $("cntArchive").textContent = APP.sites.filter(s => s.archived).length;
  document.querySelectorAll("#viewseg button").forEach(b => b.classList.toggle("on", b.dataset.view === APP.view));
  $("list").innerHTML = APP.layout === "card" ? cardsHtml(rows) : listHtml(rows);
  document.querySelectorAll("#viewtoggle button").forEach(b => b.classList.toggle("on", b.dataset.layout === APP.layout));
}

/* ── 만료 안내 ── 사용 중 사이트의 호스팅·도메인·SSL 중 30일 안에 끝나거나 이미 끝난 것. 모든 계정에 보인다(운영 정보). */
function renderExpiryNote(archive) {
  const el = $("expiryNote");
  const items = archive ? [] : viewSites().flatMap(s => (s.expiry_alerts || []).map(a => Object.assign({ site: s }, a)));
  if (!items.length) { el.hidden = true; if (APP.filter.expiry) APP.filter.expiry = false; return; }
  items.sort((a, b) => a.dday - b.dday);
  const expired = items.filter(i => i.code === "expired").length;
  const short = { hosting: "호스팅", domain: "도메인", ssl: "SSL" };
  const byKind = {};
  items.forEach(i => { byKind[i.kind] = (byKind[i.kind] || 0) + 1; });
  const kinds = Object.entries(byKind).map(([k, n]) => `${short[k] || k} ${n}`).join(" · ");
  const top = items.slice(0, 4).map(i =>
    `<button type="button" class="linkish" onclick="openDrawer(${i.site.id}, 'accounts')">${esc(i.site.name)}</button> ${expiryBadge(i, true)}`).join("");
  el.innerHTML = `<div class="sh-exp-head"><b>만료 안내</b>
      <span>${items.length - expired ? `30일 안에 만료 <b>${items.length - expired}</b>건` : ""}${items.length - expired && expired ? " · " : ""}${expired ? `이미 만료 <b class="neg">${expired}</b>건` : ""}</span>
      <span class="muted">${esc(kinds)}</span>
      <button type="button" class="btn-mini${APP.filter.expiry ? "" : " ghost"}" onclick="toggleExpiryFilter()">${APP.filter.expiry ? "전체 보기" : "해당 사이트만 보기"}</button></div>
    <div class="sh-exp-items">${top}${items.length > 4 ? `<span class="muted">외 ${items.length - 4}건</span>` : ""}</div>`;
  el.hidden = false;
}
function toggleExpiryFilter() {
  APP.filter.expiry = !APP.filter.expiry;
  render();
}

function renderStats() {
  if (!SH.canStats) { $("stats").innerHTML = ""; return; }
  const scope = viewSites();
  const n = { active: 0, soon: 0, expired: 0, none: 0 };
  scope.forEach(s => { const c = (s.contract_status || {}).code; if (n[c] !== undefined) n[c]++; });
  const tiles = [["active", "계약중"], ["soon", "만료 임박 " + SH.soonDays + "일"], ["expired", "만료"], ["none", "미계약"]];
  $("stats").innerHTML = tiles.map(([code, label]) =>
    `<div class="stat sh-st-${code}${APP.filter.status === code ? " on" : ""}" onclick="toggleStatus('${code}')" title="누르면 이 상태만 봅니다">
       <span>${esc(label)}</span><b>${n[code]}</b></div>`).join("");
}
function toggleStatus(code) {
  APP.filter.status = APP.filter.status === code ? "" : code;
  render();
}

const urlLink = (u, label) => u
  ? `<a href="${esc(/^[a-z][a-z0-9+.-]*:\/\//i.test(u) ? u : "http://" + u)}" target="_blank" rel="noopener" title="${esc(u)}">${esc(label)}</a>`
  : "";

// 접속정보 복사 버튼 — 종류별 첫 건. 관리자는 ID·PW 둘 다, 나머지는 PW 하나.
function accountCopyButtons(s) {
  const kinds = SH.subs.accounts.fields.find(f => f.key === "kind").options;
  const short = { admin: "관리자", hosting: "호스팅", ftp: "FTP", ssh: "SSH", db: "DB", domain: "도메인", mail: "메일", etc: "기타" };
  const order = ["admin", "hosting", "ftp", "ssh", "db", "domain", "mail", "etc"];
  const seen = new Set();
  const out = [];
  const inRow = new Set(["admin", "hosting", "ftp", "db"]);   // 나머지(SSH·도메인·기타)는 드로어에서
  [...(s.accounts || [])].sort((a, b) => order.indexOf(a.kind) - order.indexOf(b.kind)).forEach(a => {
    if (!inRow.has(a.kind) || seen.has(a.kind)) return;
    seen.add(a.kind);
    const k = short[a.kind] || kinds[a.kind] || a.kind;
    if (a.kind === "admin") out.push(cpBtn(a.login_id, k + " ID", (a.label || k) + " 아이디 복사"));
    out.push(cpBtn(a.login_pw, k + " PW", (a.label || k) + " 비밀번호 복사"));
  });
  return out.join("");
}
function contactCopyButton(s) {
  const c = (s.contacts || [])[0];
  if (!c) return "";
  const v = c.mobile || c.phone;
  return v ? cpBtn(v, "📱 " + c.name, c.name + " 연락처 복사") : "";
}

// 행 끝의 보관/복원 버튼 — 보관함에서는 복원, 사용 중에서는 보관
const archiveActBtn = s => s.archived
  ? `<button type="button" class="btn-mini" onclick="restoreSite(${s.id})" title="사용 중 목록으로 되돌립니다">복원</button>`
  : `<button type="button" class="btn-mini ghost" onclick="archiveSite(${s.id})" title="보관함으로 옮깁니다 (데이터는 그대로)">보관</button>`;

// 목록의 '수정 n일 전' — 엑셀에서 들어온 뒤 손대지 않은 사이트는 표시하지 않는다(전부 같은 날짜라 소음)
function listStamp(s) {
  const st = stampOf(s);
  if (!st || st.imported) return "";
  return `<span class="sep">·</span><span class="sh-stamp" title="${esc(st.title)}">${st.text.startsWith("등록") ? "등록" : "수정"} ${esc(agoText(s.updated_at || s.created_at))}${st.who ? " · " + esc(st.who) : ""}</span>`;
}

function rowHtml(s) {
  return `<li class="row sh-row${s.archived ? " dim" : ""}" data-id="${s.id}">
    <div class="row-lead">
      ${statusBadge(s)}
      ${(s.expiry_alerts || []).length ? expiryBadge(s.expiry_alerts[0], true) : ""}
      ${s.hosting ? `<span class="chip ghost">${esc(splitTags(s.hosting)[0])}</span>` : ""}
      ${archiveBadge(s)}
    </div>
    <div class="row-main">
      <div class="row-title">
        ${monitorDot(s.monitor_status)}<button type="button" class="linkish" onclick="openDrawer(${s.id})">${esc(s.name)}</button>
        ${s.customer ? `<span class="muted">${esc(s.customer)}</span>` : ""}
      </div>
      <div class="row-sub">
        ${urlLink(s.url_ops, s.url_ops.replace(/^https?:\/\//, ""))}
        ${s.url_admin ? `<span class="sep">·</span>${urlLink(s.url_admin, "관리자")}` : ""}
        ${s.stack ? `<span class="sep">·</span><span class="chip">${esc(s.stack)}</span>` : ""}
        <span class="sep">·</span><span class="muted">이력 ${s.history_count}건</span>
        ${listStamp(s)}
      </div>
    </div>
    <div class="row-act">
      ${accountCopyButtons(s)}
      ${contactCopyButton(s)}
      ${archiveActBtn(s)}
      <button type="button" class="btn-mini ghost" onclick="openDrawer(${s.id})">상세</button>
    </div>
  </li>`;
}

function emptyHtml() {
  const total = viewSites().length;
  let big, sub;
  if (total) { big = "조건에 맞는 사이트가 없습니다"; sub = "검색어나 필터를 지워 보세요"; }
  else if (APP.view === "archive") { big = "보관함이 비어 있습니다"; sub = "사용하지 않거나 더 이상 필요 없는 사이트는 행의 ‘보관’으로 여기에 옮깁니다"; }
  else if (APP.sites.length) { big = "사용 중인 사이트가 없습니다"; sub = "모두 보관함에 있습니다 — 위의 ‘보관함’에서 복원할 수 있습니다"; }
  else { big = "아직 등록된 사이트가 없습니다"; sub = "오른쪽 위 ‘사이트 추가’로 첫 사이트를 넣으세요"; }
  return `<ul class="list"><li class="empty"><div class="big">${big}</div>${sub}</li></ul>`;
}

function listHtml(rows) {
  if (!rows.length) return emptyHtml();
  return `<ul class="list">${rows.map(rowHtml).join("")}</ul>`;
}

function cardHtml(s) {
  const tags = [];
  if (s.url_ops) tags.push(urlLink(s.url_ops, "운영"));
  if (s.url_admin) tags.push(urlLink(s.url_admin, "관리자"));
  if (s.url_dev) tags.push(urlLink(s.url_dev, "개발"));
  return `<li class="card sh-card${s.archived ? " dim" : ""}" data-id="${s.id}">
    <div class="card-top">${statusBadge(s)}${(s.expiry_alerts || []).length ? expiryBadge(s.expiry_alerts[0], true) : ""}${s.hosting ? `<span class="chip ghost">${esc(splitTags(s.hosting)[0])}</span>` : ""}${s.stack ? `<span class="chip">${esc(s.stack)}</span>` : ""}${archiveBadge(s)}</div>
    <div class="card-title"><span class="sh-ct">${monitorDot(s.monitor_status)}<button type="button" class="linkish" onclick="openDrawer(${s.id})">${esc(s.name)}</button></span>${s.customer ? `<span class="muted">${esc(s.customer)}</span>` : ""}</div>
    <div class="card-tags">${tags.map(t => `<span class="chip ghost">${t}</span>`).join("")}<span class="muted">이력 ${s.history_count}건</span>${listStamp(s).replace('<span class="sep">·</span>', "")}</div>
    <div class="card-foot"><div class="row-act">${accountCopyButtons(s)}${contactCopyButton(s)}</div>
      <div class="row-act">${archiveActBtn(s)}<button type="button" class="btn-mini ghost" onclick="openDrawer(${s.id})">상세</button></div></div>
  </li>`;
}
function cardsHtml(rows) {
  if (!rows.length) return listHtml(rows);
  return `<ul class="cards">${rows.map(cardHtml).join("")}</ul>`;
}

function setLayout(v) {
  APP.layout = v === "card" ? "card" : "list";
  try { localStorage.setItem(LAYOUT_KEY, APP.layout); } catch (e) { }
  render();
}

/* ── 보관 / 복원 ── 삭제 대신 쓴다. 보관은 사유를 한 줄 받고, 복원은 묻기만 한다. */
function archiveSite(id) {
  const s = siteById(id);
  if (!s || s.archived) return;
  openSheet(`
    <div class="sheet-head"><div><span class="k">보관</span><h2>${esc(s.name)}</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="archForm">
      <p class="fhint">보관하면 사용 중 목록과 요약에서 빠지고 <b>보관함</b>에서만 보입니다. 접속정보·담당자·계약·Slack 이력은 그대로 남고 언제든 복원할 수 있습니다.</p>
      <label class="field"><span>보관 사유 <small>(선택)</small></span>
        <input id="archNote" type="text" maxlength="500" placeholder="예: 2026-09 계약 종료 · 사이트 폐쇄 · 타 업체 이관" value="${esc(s.archive_note)}"></label>
    </div>
    <div class="sheet-foot">
      <button type="button" class="btn-ghost" onclick="closeSheet()">취소</button>
      <button type="button" class="btn-submit" id="archSave" onclick="doArchive(${s.id}, 1)">보관함으로 옮기기</button>
    </div>`);
  $("archNote").addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); doArchive(s.id, 1); } });
}

async function restoreSite(id) {
  const s = siteById(id);
  if (!s || !s.archived) return;
  if (!(await askConfirm("복원", `‘${s.name}’ 을(를) 사용 중 목록으로 되돌립니다.`, "복원"))) return;
  doArchive(id, 0);
}

async function doArchive(id, on) {
  const btn = $("archSave");
  if (btn) btn.disabled = true;
  try {
    const j = await postJSON({ action: "archive", site_id: id, archived: on, note: on ? ($("archNote")?.value || "") : "" });
    closeSheet();
    replaceSite(j.site);
    if (APP.open.id === id && typeof refreshDrawer === "function") refreshDrawer();
    showToast(on ? "보관함으로 옮겼습니다 — 위의 ‘보관함’에서 볼 수 있습니다" : "복원했습니다 — 사용 중 목록에 있습니다");
  } catch (e) {
    showToast(e.message);
    if (btn) btn.disabled = false;
  }
}

/* ── 이벤트 ── */
let qT;
$("q").addEventListener("input", e => {
  $("qClear").hidden = !e.target.value;
  clearTimeout(qT); qT = setTimeout(() => { APP.filter.q = e.target.value; render(); }, 120);
});
$("qClear").addEventListener("click", () => {
  clearTimeout(qT);
  $("q").value = "";
  $("qClear").hidden = true;
  APP.filter.q = "";
  render();
  $("q").focus();
});
$("q").addEventListener("keydown", e => { if (e.key === "Escape" && $("q").value) $("qClear").click(); });
$("fHosting").addEventListener("change", e => { APP.filter.hosting = e.target.value; render(); });
$("fStack").addEventListener("change", e => { APP.filter.stack = e.target.value; render(); });
window.addEventListener("popstate", () => {
  const was = APP.view;
  readURL();
  if (was !== APP.view) { APP.filter.status = ""; buildFilters(); render(); }
  if (APP.open.id) openDrawer(APP.open.id, APP.open.tab, false); else if (typeof hideDrawer === "function") hideDrawer();
});

// 자동 동기화로 Slack 쪽이 바뀌었으면 — 목록·건수·미매칭을 다시 받는다. 드로어는 칸을 고치는 중이 아니고 이력 탭일 때만 새로 그린다.
async function onAutoSynced(j) {
  await load();
  const editing = document.querySelector("#drawer .sh-edit input, #drawer .sh-edit textarea, #drawer .sh-edit select");
  if (APP.open.id && APP.open.tab === "history" && !editing && typeof refreshDrawer === "function") refreshDrawer();
  showToast(`Slack 이력 ${j.changed}건을 새로 반영했습니다` + (j.inserted ? ` (새 요청 ${j.inserted})` : ""));
}

/* BLUMO 원 — 감시 대상이 있으면 1분마다 상태만 다시 받는다(BLUMO 는 5분마다 보고). 바뀐 게 있을 때만 다시 그린다. */
async function refreshMonitors() {
  if (document.hidden || !APP.sites.some(s => Number(s.monitor))) return;
  let j;
  try { j = await api("?monitors=1"); } catch (e) { return; }
  APP.blumo = j.blumo || APP.blumo;
  let changed = false;
  APP.sites.forEach(s => {
    const n = (j.sites || {})[s.id];
    if (!n) return;
    if (JSON.stringify(n.monitor_status) !== JSON.stringify(s.monitor_status)) changed = true;
    s.monitor_status = n.monitor_status;
    (s.monitors || []).forEach(m => {
      const w = n.monitors[m.id];
      if (!w) return;
      if (JSON.stringify(w.state) !== JSON.stringify(m.state) || w.enabled !== m.enabled || w.mute_until !== m.mute_until) changed = true;
      m.watch = w.watch; m.state = w.state; m.enabled = w.enabled; m.mute_until = w.mute_until;
    });
  });
  if (!changed) return;
  render();
  const editing = document.querySelector("#drawer .sh-edit input, #drawer .sh-edit textarea, #drawer .sh-edit select");
  if (APP.open.id && !editing && typeof refreshDrawer === "function" && !$("overlay").classList.contains("open")) refreshDrawer();
}

readURL();
load().then(() => {
  if (APP.open.id) openDrawer(APP.open.id, APP.open.tab, false);
  autoSyncInit(APP.syncAge, onAutoSynced);
  setInterval(refreshMonitors, 60000);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) refreshMonitors(); });
}).catch(e => {
  $("list").innerHTML = `<ul class="list"><li class="empty"><div class="big">불러오지 못했습니다</div>${esc(e.message)}</li></ul>`;
});
