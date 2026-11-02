// BLUMO 감시 — monitor.php(감시 현황 · 장애 이력 · 설정)와 사이트 상세의 '감시 설정' 탭이 같이 쓴다.
// 예전 blumo.pink 관리화면이 하던 일: 지금 점검, 자동 점검 멈춤, 알림 무음, 점검 기록(에이전트 응답·오류 화면), 장애 이력, 설정.

const MON = { rows: [], tab: "board", filter: "all", q: "", runPoll: null };
const MON_SEV = { ok: "정상", warn: "경고", down: "장애", none: "미점검", off: "멈춤" };
const MON_MUTES = [[30, "30분"], [60, "1시간"], [240, "4시간"], [1440, "24시간"], [10080, "7일"]];

/* 감시 주소 하나의 단계 — 장애는 연속 실패로 확정된 것만. 한 번 실패는 '경고(확인 중)' */
function monSev(r) {
  if (!Number(r.enabled)) return "off";
  const st = r.state;
  if (!st || !st.checked_at) return "none";
  if (st.status === "down") return "down";
  return st.class === "OK" ? "ok" : "warn";
}
const monSevTag = sev => `<span class="badge sh-sev sh-sev-${sev}">${MON_SEV[sev]}</span>`;
const monHost = u => String(u || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
const monN = n => Number(n).toLocaleString("ko-KR");

/* ── 공통 동작 (감시 현황 · 감시 설정 탭) ── */

async function monCheck(id, btn) {
  if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = "점검 중…"; }
  try {
    const j = await postJSON({ action: "monitor_check", id });
    monAfterChange(j.site);
    showToast(`${j.class === "OK" ? "정상" : j.class} · ${j.cause}${j.total_ms !== null ? " · " + monN(j.total_ms) + "ms" : ""}`);
    if ($("monDetail") && Number($("monDetail").dataset.id) === id) monOpenDetail(id);
  } catch (e) {
    showToast(e.message);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || "지금 점검"; }
  }
}

async function monEnable(id, on) {
  try {
    const j = await postJSON({ action: "monitor_enable", id, on: on ? 1 : 0 });
    monAfterChange(j.site);
    showToast(on ? "자동 점검을 다시 켭니다" : "이 주소의 자동 점검을 멈췄습니다 — 사이트 감시는 그대로입니다");
  } catch (e) { showToast(e.message); }
}

async function monMute(id, min) {
  try {
    const j = await postJSON({ action: "monitor_mute", id, min });
    monAfterChange(j.site);
    showToast(min ? `알림 무음 — ${MON_MUTES.find(x => x[0] === min)?.[1] || min + "분"} (점검·기록은 계속)` : "알림 무음을 풀었습니다");
  } catch (e) { showToast(e.message); }
}

// 쓰기 뒤 화면 맞추기 — 사이트 목록 화면이면 그 사이트를, 감시 현황이면 표를 다시
function monAfterChange(site) {
  document.querySelectorAll("details.sh-menu[open]").forEach(d => d.removeAttribute("open"));
  if (site && typeof replaceSite === "function") { replaceSite(site); if (typeof refreshDrawer === "function") refreshDrawer(); }
  if ($("monBody")) monLoad();
}

// ⋯ 메뉴 — 자동 점검 멈춤/재개, 알림 무음
function monMenu(r) {
  const muted = r.mute_until && new Date(String(r.mute_until).replace(" ", "T")) > new Date();
  return `<details class="sh-menu"><summary class="btn-mini ghost" title="자동 점검 · 알림 무음">⋯</summary><div class="sh-pop">
    ${Number(r.enabled) ? `<button type="button" onclick="monEnable(${r.id}, false)">이 주소 자동 점검 멈춤</button>` : `<button type="button" onclick="monEnable(${r.id}, true)">자동 점검 다시 켜기</button>`}
    <hr>
    ${muted ? `<button type="button" onclick="monMute(${r.id}, 0)">알림 무음 풀기 <small>~${esc(String(r.mute_until).slice(5, 16))}</small></button>`
            : MON_MUTES.map(([m, l]) => `<button type="button" onclick="monMute(${r.id}, ${m})">알림 무음 ${l}</button>`).join("")}
  </div></details>`;
}
document.addEventListener("click", e => {
  document.querySelectorAll("details.sh-menu[open]").forEach(d => { if (!d.contains(e.target)) d.removeAttribute("open"); });
});
document.addEventListener("toggle", e => {
  if (!(e.target instanceof HTMLDetailsElement) || !e.target.classList.contains("sh-menu") || !e.target.open) return;
  document.querySelectorAll("details.sh-menu[open]").forEach(d => { if (d !== e.target) d.removeAttribute("open"); });
}, true);

/* BLUMO 전체 상태 한 줄 — 일시정지 · 자동 점검 끊김 · 웹훅 없음 */
function monBlumoLine(b) {
  b = b || APP.blumo || {};
  const out = [];
  if (b.paused) out.push(`<span class="sh-mon-warn">전체 일시정지 중</span> — 자동 점검과 알림이 멈춰 있습니다`);
  if (!b.last_auto_run) out.push(`<span class="sh-mon-warn">자동 점검이 한 번도 돌지 않았습니다</span> — 작업 스케줄러 등록이 필요합니다`);
  else if ((b.auto_age || 0) > (b.stale_min || 20) * 60) out.push(`<span class="sh-mon-warn">자동 점검이 ${esc(agoText(b.last_auto_run))} 이후 멈췄습니다</span> — 작업 스케줄러나 PC 절전을 확인하세요`);
  else out.push(`마지막 자동 점검 ${esc(agoText(b.last_auto_run))}`);
  if (!b.webhook) out.push(`<span class="sh-mon-warn">알림 웹훅이 없습니다</span> — 장애가 나도 Slack 으로 알리지 않습니다`);
  return out.join(" · ");
}

/* ── 점검 기록 (시트) — 상태 · 가용률 · 응답시간 막대 · 장애 이력 · 점검 80회 ── */

async function monOpenDetail(id) {
  const first = !$("monDetail");
  if (first) openSheet(`<div class="sheet-head"><div><span class="k">BLUMO 점검 기록</span><h2 id="monDetailH">불러오는 중…</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body sh-mdetail" id="monDetail" data-id="${id}"><p class="fhint">불러오는 중…</p></div>`);
  let j;
  try { j = await api("?monitor_detail=" + id); }
  catch (e) { $("monDetail").innerHTML = `<p class="fhint">${esc(e.message)}</p>`; return; }
  const box = $("monDetail");
  if (!box) return;
  box.dataset.id = id;
  $("sheet").classList.add("sh-sheet-wide");
  const m = j.monitor, st = m.state || {};
  const sev = monSev(m);
  $("monDetailH").innerHTML = `${watchDot(m.watch)} ${esc(m.name)}`;
  const pct = a => a.pct === null ? "—" : a.pct + "%";
  box.innerHTML = `
    <div class="sh-mdetail-top">
      <a href="${esc(m.url)}" target="_blank" rel="noopener" class="sh-mdetail-url">${esc(m.url)} ↗</a>
      <span class="grow"></span>
      <button type="button" class="btn-mini primary" onclick="monCheck(${m.id}, this)">지금 점검</button>
      ${monMenu(m)}
      <a class="btn-mini ghost" href="index.php?site=${m.site_id}&tab=monitor">감시 설정</a>
    </div>
    <dl class="kv sh-mdetail-kv">
      <dt>현재 상태</dt><dd>${monSevTag(sev)} ${st.class ? `<b>${esc(st.class)}</b> ` : ""}${esc(st.cause || m.watch.text)}</dd>
      <dt>상태 지속</dt><dd>${st.since ? `${st.status === "down" ? "<b class=\"sh-neg\">장애</b>" : "정상"} ${esc(String(st.since).slice(5, 16))}부터 (${esc(agoText(st.since).replace(" 전", ""))})` : "—"}</dd>
      <dt>가용률</dt><dd>24시간 ${pct(j.day)} · 7일 ${pct(j.week)} · 평균 ${j.week.avg_ms === null ? "—" : monN(j.week.avg_ms) + "ms"}</dd>
      <dt>SSL</dt><dd>${st.cert_days !== null && st.cert_days !== undefined ? st.cert_days + "일 남음" : (/^https/.test(m.url) ? "—" : "http (SSL 없음)")}</dd>
      <dt>정상 판정 문구</dt><dd>${m.expect ? `<code>${esc(m.expect)}</code>` : `<span class="muted">없음 — 응답 크기로만 백지 판정</span>`}</dd>
      <dt>에이전트</dt><dd>${m.agent ? `연결 · <a href="api.php?agent=${m.id}">키 포함 blumo-agent.php 내려받기</a>` : `<span class="muted">없음 — 외부 증상까지만 판별 (감시 설정에서 주소·키를 넣으면 원인까지)</span>`}</dd>
      ${m.mute_until ? `<dt>알림 무음</dt><dd>${esc(String(m.mute_until).slice(5, 16))}까지 — 점검·기록은 계속</dd>` : ""}
    </dl>
    ${monBars(j.checks)}
    ${j.incidents.length ? `<h4 class="sh-sheet-h">장애 이력</h4><div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>시작</th><th>복구</th><th>지속</th><th>구분</th><th>원인</th></tr></thead><tbody>
      ${j.incidents.map(i => `<tr><td>${esc(String(i.started).slice(5, 16))}</td><td>${i.ended ? esc(String(i.ended).slice(5, 16)) : `<span class="badge sh-sev sh-sev-down">진행 중</span>`}</td>
        <td class="num">${esc(monDur(i.started, i.ended))}</td><td><span class="badge sh-sev sh-sev-down">${esc(i.class)}</span></td><td class="sh-mcause">${esc(i.cause)}</td></tr>`).join("")}
      </tbody></table></div>` : ""}
    <h4 class="sh-sheet-h">점검 기록 <span class="muted">최근 ${j.checks.length}회 · 행을 누르면 에이전트 응답 · 오류 당시 화면</span></h4>
    ${j.checks.length ? `<div class="sh-tablewrap"><table class="sh-table sh-mchecks"><thead><tr><th>시각</th><th>결과</th><th>원인</th><th class="num">HTTP</th><th class="num">DNS</th><th class="num">연결</th><th class="num">TLS</th><th class="num">TTFB</th><th class="num">전체</th></tr></thead><tbody>
      ${j.checks.map(c => {
        const more = c.health || c.snippet;
        return `<tr class="${more ? "is-more" : ""}"${more ? ` onclick="this.nextElementSibling.hidden=!this.nextElementSibling.hidden"` : ""}>
          <td>${esc(String(c.at).slice(5, 19))}</td><td>${monSevTag(c.class === "OK" ? "ok" : (["SLOW", "TLS_EXPIRING"].includes(c.class) ? "warn" : "down")).replace(/>[^<]+</, ">" + esc(c.class) + "<")}${more ? " ▾" : ""}</td>
          <td class="sh-mcause">${esc(c.cause)}</td><td class="num">${c.code ?? ""}</td><td class="num">${c.dns_ms ?? ""}</td><td class="num">${c.connect_ms ?? ""}</td>
          <td class="num">${c.tls_ms ?? ""}</td><td class="num">${c.ttfb_ms ?? ""}</td><td class="num">${c.total_ms === null ? "" : monN(c.total_ms) + "ms"}</td></tr>
          ${more ? `<tr hidden class="sh-mmore"><td colspan="9">
            ${c.health ? `<div class="fhint">에이전트 응답</div><pre>${esc(JSON.stringify(c.health, null, 2))}</pre>` : ""}
            ${c.snippet ? `<div class="fhint">오류 당시 화면</div><pre>${esc(c.snippet)}</pre>` : ""}</td></tr>` : ""}`;
      }).join("")}</tbody></table></div>` : `<p class="fhint">아직 점검 기록이 없습니다. ‘지금 점검’을 눌러 보세요.</p>`}`;
}

function monDur(a, b) {
  const s = Math.max(0, ((b ? new Date(String(b).replace(" ", "T")) : new Date()) - new Date(String(a).replace(" ", "T"))) / 1000);
  if (s < 60) return Math.round(s) + "초";
  if (s < 3600) return Math.floor(s / 60) + "분";
  if (s < 86400) return Math.floor(s / 3600) + "시간 " + Math.floor((s % 3600) / 60) + "분";
  return Math.floor(s / 86400) + "일 " + Math.floor((s % 86400) / 3600) + "시간";
}

/* 최근 응답시간 — 막대 하나 = 점검 한 번(왼쪽이 오래된 것). 정상은 파랑, 경고 노랑, 장애 빨강. 값·판정은 툴팁과 아래 표에 */
function monBars(checks) {
  const s = checks.slice(0, 60).reverse();
  if (!s.length) return "";
  const mx = Math.max(1, ...s.map(c => c.total_ms || 0));
  const sev = c => c.class === "OK" ? "ok" : (["SLOW", "TLS_EXPIRING"].includes(c.class) ? "warn" : "down");
  return `<div class="sh-mbars-wrap"><div class="sh-mbars-head"><b>최근 응답시간</b><span class="muted">점검 ${s.length}회 · 최대 ${monN(mx)}ms</span>
      <span class="sh-mbars-key"><i class="ok"></i>정상 <i class="warn"></i>경고 <i class="down"></i>장애</span></div>
    <div class="sh-mbars" role="img" aria-label="최근 응답시간 막대 — 값은 아래 점검 기록 표에 있습니다">
      ${s.map(c => `<span class="slot" tabindex="0" data-tip-v="${esc(c.total_ms === null ? "—" : monN(c.total_ms) + "ms")}" data-tip-l="${esc(String(c.at).slice(5, 16) + " · " + c.class)}">
        <i class="${sev(c)}" style="height:${Math.max(2, Math.round((c.total_ms || 0) / mx * 100))}%"></i></span>`).join("")}
    </div>
    <div class="sh-mbars-x"><span>${esc(String(s[0].at).slice(5, 16))}</span><span>${esc(String(s[s.length - 1].at).slice(5, 16))}</span></div></div>`;
}

/* 툴팁 — 값이 먼저(굵게), 이름이 뒤. 글자는 textContent 로만 */
(function () {
  let tip = null;
  const show = (el, x, y) => {
    if (!tip) { tip = document.createElement("div"); tip.className = "sh-tip"; tip.hidden = true; tip.setAttribute("role", "tooltip"); document.body.appendChild(tip); }
    tip.replaceChildren();
    const v = document.createElement("b"); v.textContent = el.dataset.tipV || "";
    const l = document.createElement("span"); l.textContent = el.dataset.tipL || "";
    tip.append(v, l);
    tip.hidden = false;
    const r = tip.getBoundingClientRect();
    tip.style.left = Math.min(window.innerWidth - r.width - 8, Math.max(8, x + 12)) + "px";
    tip.style.top = (y - r.height - 12 < 8 ? y + 16 : y - r.height - 12) + "px";
  };
  const hide = () => { if (tip) tip.hidden = true; };
  document.addEventListener("pointermove", e => { const el = e.target.closest && e.target.closest(".sh-mbars [data-tip-v]"); if (el) show(el, e.clientX, e.clientY); else hide(); });
  document.addEventListener("focusin", e => { const el = e.target.closest && e.target.closest(".sh-mbars [data-tip-v]"); if (!el) return hide(); const r = el.getBoundingClientRect(); show(el, r.left + r.width / 2, r.top); });
  document.addEventListener("focusout", hide);
  window.addEventListener("scroll", hide, { passive: true, capture: true });
})();

/* ── 예전 BLUMO 31곳 연결 (지정 계정) — 사이트 상세의 감시 설정 탭과 설정 화면에서 연다 ── */

async function loadBlumoLink() {
  try { APP.blumoLink = await api("?blumo=1"); if (APP.blumoLink.meta) APP.blumo = APP.blumoLink.meta; }
  catch (e) { APP.blumoLink = { seed: [], error: e.message }; }
  return APP.blumoLink;
}

function seedSuggestions(s) {
  const seed = (APP.blumoLink && APP.blumoLink.seed || []).filter(x => !x.linked && x.site_id === s.id);
  if (!seed.length) return "";
  return `<div class="sh-mon-seed"><b>예전 BLUMO 에 있던 감시 대상</b> — 이 사이트로 보입니다. 연결하면 예전 점검 기록을 옮겨 올 수 있습니다.
    <ul>${seed.map(x => `<li><span>${esc(x.name)} · <span class="muted">${esc(x.url)}</span></span>
      <button type="button" class="btn-mini primary" onclick="importSeeds([{blumo_id:${x.blumo_id}, site_id:${s.id}}], this)">이 사이트에 연결</button></li>`).join("")}</ul></div>`;
}

async function importSeeds(items, btn) {
  if (!items.length) { showToast("연결할 대상을 고르세요"); return; }
  if (btn) btn.disabled = true;
  try {
    const j = await postJSON({ action: "blumo_import", items });
    if (typeof replaceSite === "function") (j.sites || []).forEach(replaceSite);
    if (APP.blumoLink) APP.blumoLink.seed = j.seed || APP.blumoLink.seed;
    showToast(`감시 주소 ${j.made}곳을 만들었습니다`);
    if ($("blumoLinkForm")) renderBlumoLink();
    if (typeof refreshDrawer === "function") refreshDrawer();
    if ($("monBody")) monLoad();
  } catch (e) {
    showToast(e.message);
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function openBlumoLink() {
  openSheet(`<div class="sheet-head"><div><span class="k">BLUMO</span><h2>예전 BLUMO 감시 대상 연결</h2></div>
      <button type="button" class="btn-mini ghost" onclick="closeSheet()">✕</button></div>
    <div class="sheet-body" id="blumoLinkForm"><p class="fhint">불러오는 중…</p></div>
    <div class="sheet-foot"><button type="button" class="btn-ghost" onclick="closeSheet()">닫기</button>
      <button type="button" class="btn-submit" id="seedApply" onclick="applySeedForm()" hidden>선택한 대상 연결</button></div>`);
  await loadBlumoLink();
  renderBlumoLink();
}

function renderBlumoLink() {
  const L = APP.blumoLink || {};
  const box = $("blumoLinkForm");
  if (!box) return;
  if (L.error) { box.innerHTML = `<p class="fhint">${esc(L.error)}</p>`; return; }
  const all = (APP.sites && APP.sites.length ? APP.sites : (L.sites || [])).filter(x => !x.archived).slice().sort((a, c) => a.name.localeCompare(c.name, "ko"));
  const opt = sel => `<option value="">— 연결 안 함 —</option>` + all.map(x => `<option value="${x.id}"${x.id === sel ? " selected" : ""}>${esc(x.name)}${x.customer && x.customer !== x.name ? " · " + esc(x.customer) : ""}</option>`).join("");
  const seed = (L.seed || []).slice().sort((a, c) => (a.linked - c.linked) || (a.blumo_id - c.blumo_id));
  const open = seed.filter(x => !x.linked);
  const how = { url: "주소로 찾음", name: "이름으로 찾음", "": "못 찾음" };
  const nameOf = id => ((all.find(x => x.id === id)) || {}).name || "";
  box.innerHTML = `
    <p class="fhint">SiteHub 로 옮기기 전 BLUMO(blumo.pink)에 있던 ${seed.length}곳입니다. 연결하면 그 사이트에 감시 주소(정상 판정 문구·메모 포함)가 생기고 감시가 켜집니다.
      주소·이름으로 사이트를 미리 골라 두었으니 확인하고 ‘선택한 대상 연결’을 누르세요. 예전 점검 기록·웹훅 설정은 감시 › 설정 › ‘예전 BLUMO 가져오기’에서 옮깁니다.</p>
    <p class="fhint"><b>${seed.length - open.length} / ${seed.length}</b> 연결됨</p>
    <ul class="sh-rows sh-seed">${seed.map(x => `<li class="${x.linked ? "dim" : ""}">
      <div><label class="sh-seed-pick">${x.linked ? `<span class="badge sh-st-active">연결됨</span>` : `<input type="checkbox" data-bid="${x.blumo_id}"${x.site_id ? " checked" : ""}>`}
        <b>${esc(x.name)}</b></label><span class="sh-seed-url">${esc(monHost(x.url))}</span>${x.linked ? "" : ` <span class="badge ${x.site_id ? "off" : "sh-st-soon"}">${esc(how[x.how] || "")}</span>`}</div>
      <div class="a">${x.linked ? esc(nameOf(x.site_id)) : `<select data-bid="${x.blumo_id}" onchange="this.closest('li').querySelector('input[type=checkbox]').checked = !!this.value">${opt(x.site_id)}</select>`}</div>
    </li>`).join("")}</ul>`;
  const ap = $("seedApply");
  if (ap) ap.hidden = !open.length;
}

function applySeedForm() {
  const items = [...document.querySelectorAll("#blumoLinkForm input[type=checkbox][data-bid]:checked")].map(c => {
    const sel = document.querySelector(`#blumoLinkForm select[data-bid="${c.dataset.bid}"]`);
    return { blumo_id: Number(c.dataset.bid), site_id: Number(sel && sel.value) };
  }).filter(x => x.site_id);
  importSeeds(items, $("seedApply"));
}

/* ── monitor.php — 감시 현황 · 장애 이력 · 설정 ── */

async function monLoad() {
  if (MON.tab !== "board") return;
  let j;
  try { j = await api("?monitor_board=1"); }
  catch (e) { $("monBody").innerHTML = `<ul class="list"><li class="empty"><div class="big">불러오지 못했습니다</div>${esc(e.message)}</li></ul>`; return; }
  MON.rows = j.rows || [];
  APP.blumo = j.blumo;
  monBanner();
  monRender();
  if (j.blumo && j.blumo.running) monWatchRun();
}

function monBanner() {
  const b = APP.blumo || {};
  const parts = [];
  if (b.running) parts.push(`<div class="sh-mon-banner run"><b>점검 진행 중</b> <span>${Number(b.running.done || 0)} / ${Number(b.running.total || 0) || "…"}${b.running.current ? " · " + esc(b.running.current) : ""}</span><span class="muted">끝나면 자동으로 새로 그립니다</span></div>`);
  const line = monBlumoLine(b);
  parts.push(`<div class="sh-mon-banner${/sh-mon-warn/.test(line) ? " warn" : ""}">${line}${SH.canStats && (b.paused || !b.last_auto_run || !b.webhook) ? ` <button type="button" class="btn-mini" onclick="monTab('settings')">설정</button>` : ""}</div>`);
  $("monBanner").innerHTML = parts.join("");
  const ra = $("runAll");
  if (ra) { ra.disabled = !!b.running; ra.textContent = b.running ? "점검 중…" : "전체 지금 점검"; }
}

function monRender() {
  const rows = MON.rows;
  const n = { all: rows.length, ok: 0, warn: 0, down: 0, off: 0 };
  rows.forEach(r => { const s = monSev(r); if (s === "none" || s === "off") n.off++; else n[s]++; });
  const tiles = [["all", "전체"], ["ok", "정상"], ["warn", "경고 · 확인 중"], ["down", "장애"], ["off", "멈춤 · 미점검"]];
  $("monKpi").innerHTML = MON.tab === "board" ? tiles.map(([k, l]) =>
    `<div class="stat sh-mkpi sh-mkpi-${k}${MON.filter === k && k !== "all" ? " on" : ""}" onclick="monFilter('${k}')" title="누르면 이 상태만 봅니다"><span>${esc(l)}</span><b>${n[k]}</b></div>`).join("") : "";
  const q = MON.q.trim().toLowerCase();
  const list = rows.filter(r => {
    const s = monSev(r);
    if (MON.filter !== "all" && !(MON.filter === "off" ? (s === "off" || s === "none") : s === MON.filter)) return false;
    return !q || [r.site_name, r.customer, r.label, r.url, r.state && r.state.cause, r.state && r.state.class].join(" ").toLowerCase().includes(q);
  });
  const rank = { down: 0, warn: 1, none: 2, ok: 3, off: 4 };
  list.sort((a, b) => rank[monSev(a)] - rank[monSev(b)]);   // 장애·경고가 위로 (같은 단계는 원래 순서 유지)
  if (!rows.length) {
    $("monBody").innerHTML = `<ul class="list"><li class="empty"><div class="big">감시 중인 사이트가 없습니다</div>
      사이트 상세의 <b>감시 설정</b> 탭에서 감시를 켜세요.${SH.canStats ? ` 예전 BLUMO 에 있던 31곳은 <button type="button" class="linkish" style="color:var(--cos45)" onclick="openBlumoLink()">한 번에 연결</button>할 수 있습니다.` : ""}</li></ul>`;
    return;
  }
  $("monBody").innerHTML = list.length ? `<ul class="list sh-mlist">${list.map(monRow).join("")}</ul>`
    : `<ul class="list"><li class="empty"><div class="big">조건에 맞는 감시 주소가 없습니다</div>검색어나 상태 필터를 지워 보세요</li></ul>`;
}

function monRow(r) {
  const sev = monSev(r), st = r.state || {};
  const muted = r.mute_until ? `<span class="badge off" title="알림만 끔 — 점검은 계속">무음 ~${esc(String(r.mute_until).slice(11, 16))}</span>` : "";
  const sub = [];
  if (st.cause && sev !== "ok") sub.push(`<span class="sh-mcause">${esc(st.cause)}</span>`);
  if (st.total_ms !== null && st.total_ms !== undefined) sub.push(`${monN(st.total_ms)}ms`);
  if (st.avail_24h !== null && st.avail_24h !== undefined) sub.push(`24h ${st.avail_24h}%`);
  if (st.cert_days !== null && st.cert_days !== undefined) sub.push(st.cert_days <= 14 ? `<span class="sh-mon-warn">SSL ${st.cert_days}일</span>` : `SSL ${st.cert_days}일`);
  if (st.checked_at) sub.push(`<span class="muted">${esc(agoText(st.checked_at))} 점검</span>`);
  return `<li class="row sh-mrow${sev === "off" ? " dim" : ""}" data-id="${r.id}">
    <div class="row-lead">${monSevTag(sev)}${st.class && st.class !== "OK" ? `<span class="sh-mclass">${esc(st.class)}</span>` : ""}${muted}</div>
    <div class="row-main">
      <div class="row-title">${watchDot(r.watch)}<a class="linkish" href="index.php?site=${r.site_id}&tab=monitor" title="사이트 상세 — 감시 설정">${esc(r.site_name)}</a>
        ${r.label ? `<span class="chip ghost">${esc(r.label)}</span>` : ""}${r.agent ? `<span class="chip" title="에이전트 연결 — DB·디스크·세션·에러로그까지 원인 판별">에이전트</span>` : ""}</div>
      <div class="row-sub"><a href="${esc(r.url)}" target="_blank" rel="noopener" class="muted">${esc(monHost(r.url))}</a>${sub.length ? `<span class="sep">·</span>` + sub.join(`<span class="sep">·</span>`) : ""}</div>
    </div>
    <div class="row-act">
      <button type="button" class="btn-mini" onclick="monCheck(${r.id}, this)">점검</button>
      <button type="button" class="btn-mini ghost" onclick="monOpenDetail(${r.id})">기록</button>
      ${monMenu(r)}
    </div>
  </li>`;
}

function monFilter(k) { MON.filter = MON.filter === k || k === "all" ? "all" : k; monRender(); }

async function monRunAll() {
  if (!(await askConfirm("전체 지금 점검", `감시 주소 ${MON.rows.filter(r => Number(r.enabled)).length}곳을 지금 점검합니다. 장애가 확정되면 알림도 갑니다. 일시정지 중이어도 돕니다.`, "점검"))) return;
  try {
    const j = await postJSON({ action: "monitor_run_all" });
    APP.blumo = Object.assign({}, APP.blumo, { running: j.running || { done: 0, total: 0 } });
    monBanner();
    monWatchRun();
  } catch (e) { showToast(e.message); }
}

// 전체 점검 진행 — 2초마다 잠금 파일을 본다. 끝나면 표를 새로
function monWatchRun() {
  if (MON.runPoll) return;
  MON.runPoll = setInterval(async () => {
    let j;
    try { j = await api("?monitor_status=1"); } catch (e) { return; }
    APP.blumo = j.blumo;
    if (!j.running) {
      clearInterval(MON.runPoll); MON.runPoll = null;
      await monLoad();
      showToast("전체 점검을 마쳤습니다");
      return;
    }
    monBanner();
  }, 2000);
}

function monTab(t) {
  if (t === "settings" && !SH.canStats) t = "board";
  MON.tab = t;
  document.querySelectorAll("#monTabs button").forEach(b => b.classList.toggle("on", b.dataset.tab === t));
  const u = new URL(location.href);
  if (t === "board") u.searchParams.delete("tab"); else u.searchParams.set("tab", t);
  history.replaceState(null, "", u);
  document.querySelector(".sh-monpage .search").hidden = t !== "board";
  $("monKpi").hidden = t !== "board";
  if (t === "board") { $("monBody").innerHTML = `<ul class="list"><li class="empty">불러오는 중…</li></ul>`; monLoad(); }
  else if (t === "incidents") monIncidents();
  else monSettings();
}

async function monIncidents() {
  $("monBody").innerHTML = `<ul class="list"><li class="empty">불러오는 중…</li></ul>`;
  let j;
  try { j = await api("?monitor_incidents=1"); } catch (e) { $("monBody").innerHTML = `<ul class="list"><li class="empty">${esc(e.message)}</li></ul>`; return; }
  const rows = j.rows || [];
  $("monBody").innerHTML = rows.length ? `<div class="sh-panel"><div class="sh-panel-head"><h3>장애 이력</h3><p>최근 ${rows.length}건 · 연속 실패로 확정된 장애만</p></div>
    <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>시작</th><th>복구</th><th class="num">지속</th><th>사이트</th><th>구분</th><th>원인</th></tr></thead><tbody>
    ${rows.map(i => `<tr><td>${esc(String(i.started).slice(0, 16))}</td><td>${i.ended ? esc(String(i.ended).slice(5, 16)) : `<span class="badge sh-sev sh-sev-down">진행 중</span>`}</td>
      <td class="num">${esc(monDur(i.started, i.ended))}</td>
      <td><button type="button" class="linkish" onclick="monOpenDetail(${i.monitor_id})">${esc(i.site_name || "(지운 사이트)")}${i.label ? " · " + esc(i.label) : ""}</button></td>
      <td><span class="badge sh-sev sh-sev-down">${esc(i.class)}</span></td><td class="sh-mcause">${esc(i.cause)}</td></tr>`).join("")}
    </tbody></table></div></div>` : `<ul class="list"><li class="empty"><div class="big">기록된 장애가 없습니다</div>연속 실패로 확정된 장애만 여기에 남습니다</li></ul>`;
}

/* 설정 (지정 계정) — 알림 · 판정 기준 · 자동 점검 · 일시정지 · 환경 진단 · 예전 BLUMO 가져오기 */
async function monSettings() {
  $("monBody").innerHTML = `<ul class="list"><li class="empty">불러오는 중…</li></ul>`;
  let j;
  try { j = await api("?monitor_settings=1"); } catch (e) { $("monBody").innerHTML = `<ul class="list"><li class="empty">${esc(e.message)}</li></ul>`; return; }
  const c = j.cfg, R = j.ranges, b = j.meta, L = j.legacy;
  APP.blumo = b;
  monBanner();
  const num = (k, label, hint) => `<div class="field"><label>${esc(label)}</label><input type="number" data-k="${k}" min="${R[k][0]}" max="${R[k][1]}" value="${esc(c[k])}">${hint ? `<span class="fhint">${esc(hint)}</span>` : ""}</div>`;
  $("monBody").innerHTML = `<div class="sh-sgrid sh-mset">
    <div class="sh-panel" id="monSetForm">
      <div class="sh-panel-head"><h3>알림</h3><p>상태가 바뀔 때만 보냅니다 — 장애 확정 · 장애 지속(재알림) · 복구</p></div>
      <div class="field"><label>웹훅 주소</label><div class="sh-copyrow"><div class="pwwrap"><input type="password" data-k="webhook_url" value="${esc(c.webhook_url)}" autocomplete="off" placeholder="https://hooks.slack.com/services/…">
        <button type="button" class="eye" title="표시" onclick="toggleEye(this)">${EYE_OFF}</button></div></div>
        <span class="fhint">이 주소를 아는 사람은 누구나 채널(#blumo)에 글을 쓸 수 있습니다. 화면 공유할 때 주의하세요.</span></div>
      <div class="two">
        <div class="field"><label>형식</label><select data-k="webhook_format">${[["slack", "슬랙"], ["jandi", "잔디"], ["raw", "JSON(자체 수신)"]].map(([k, l]) => `<option value="${k}"${c.webhook_format === k ? " selected" : ""}>${l}</option>`).join("")}</select></div>
        ${num("confirm_failures", "장애 확정 (연속 실패 횟수)", "깜빡임 무시 — 이만큼 연속 실패해야 장애")}
      </div>
      <div class="two">${num("repeat_alert_min", "장애 지속 시 재알림 (분)")}
        <div class="field"><label>경고 알림</label><label class="check" style="height:40px;width:max-content"><input type="checkbox" data-k="alert_on_warn"${Number(c.alert_on_warn) ? " checked" : ""}> 느림 · SSL 만료 임박도 알림</label></div></div>
      <div class="sh-panel-head" style="margin-top:8px"><h3>판정 기준</h3><p>감시 주소마다 따로 정하지 않으면 이 값</p></div>
      <div class="two">${num("slow_ms", "느림 기준 (ms)")}${num("timeout", "페이지 대기 한도 (초)")}</div>
      <div class="two">${num("health_timeout", "에이전트 대기 한도 (초)")}${num("cert_warn_days", "SSL 만료 경고 (일 전)")}</div>
      <div class="two">${num("retention_days", "점검 기록 보관 (일)", "지난 기록은 하루 한 번 지웁니다. 장애 이력은 남습니다")}</div>
      <div><button type="button" class="btn-submit" onclick="monSaveSettings(this)">설정 저장</button></div>
    </div>
    <div class="sh-mset-side">
      <div class="sh-panel"><div class="sh-panel-head"><h3>자동 점검</h3><p>작업 스케줄러가 5분마다 monitor_run.php 를 부릅니다</p></div>
        <p class="sh-mset-p">${b.last_auto_run ? `마지막 자동 점검 <b>${esc(b.last_auto_run.slice(5, 16))}</b> (${esc(agoText(b.last_auto_run))})` : `<span class="sh-mon-warn">자동 점검이 한 번도 돌지 않았습니다.</span>`}
          ${b.last_run ? `<br>마지막 전체 점검 ${esc(b.last_run.slice(5, 16))} · ${b.last_run_by === "manual" ? "화면에서" : "자동"}` : ""}</p>
        <p class="fhint">등록: iworks 폴더의 <code>sitehub\\tools\\monitor-task.bat</code> 를 더블클릭 (관리자 권한 필요 없음, 콘솔 창이 뜨지 않는 php-win.exe 로 5분마다).
          직접 돌리려면 <code>${esc(j.run_cmd)}</code></p>
        <p class="fhint">점검용 PHP ${j.php_cli ? `<code>${esc(j.php_cli)}</code>` : `<span class="sh-mon-warn">못 찾음</span> — config.php 에 <code>'sitehub_php_cli' => 'C:\\\\wamp64\\\\bin\\\\php\\\\php8.x\\\\php.exe'</code>`} · CA 묶음 ${j.ca ? "있음" : `<span class="sh-mon-warn">없음 — https 점검이 전부 실패합니다</span>`}</p>
      </div>
      <div class="sh-panel"><div class="sh-panel-head"><h3>전체 일시정지</h3><p>서버 작업·이전 공사처럼 알림이 쏟아질 때. 화면에서 누르는 점검은 계속 됩니다</p></div>
        <button type="button" class="${b.paused ? "btn-submit" : "btn-ghost"}" onclick="monPause(${b.paused ? 0 : 1})">${b.paused ? "자동 점검 재개" : "전체 일시정지"}</button></div>
      <div class="sh-panel"><div class="sh-panel-head"><h3>웹훅 테스트 · 환경 진단</h3><p>저장된 웹훅으로 장애·복구 샘플 2건 / PHP 확장 · CA · 연결 상태</p></div>
        <button type="button" class="btn-ghost" onclick="monTestWebhook(this)">테스트 알림 보내기</button>
        <button type="button" class="btn-ghost" onclick="monDoctor(this)">환경 진단</button>
        <pre class="sh-mpre" id="monDoctorOut" hidden></pre></div>
      <div class="sh-panel"><div class="sh-panel-head"><h3>예전 BLUMO 가져오기</h3><p>SiteHub 로 옮기기 전 BLUMO(blumo.pink, blumo.sqlite)</p></div>
        <p class="sh-mset-p"><b>1.</b> 감시 대상 31곳을 사이트에 연결 <button type="button" class="btn-mini" onclick="openBlumoLink()">대상 연결</button></p>
        <p class="sh-mset-p"><b>2.</b> 웹훅·판정 기준·일시정지와 점검 기록·장애 이력 옮기기 ${L.imported_at ? `<span class="muted">(${esc(L.imported_at.slice(5, 16))}에 가져옴)</span>` : ""}</p>
        <div class="field"><label>blumo.sqlite 위치</label><input type="text" id="monLegacyPath" value="${esc(L.path)}"></div>
        <p class="fhint">${!L.sqlite ? `<span class="sh-mon-warn">이 PHP 에 pdo_sqlite 확장이 없어 읽을 수 없습니다</span> — 웹훅 주소는 왼쪽에 직접 넣으세요.` : (L.exists ? "파일이 있습니다." : `<span class="sh-mon-warn">이 위치에 파일이 없습니다.</span>`)}
          기록은 1번에서 연결한 대상만 옮깁니다. 여러 번 눌러도 같은 기록은 두 번 들어가지 않습니다.</p>
        <label class="check"><input type="checkbox" id="monLegacySet" checked> 설정</label> <label class="check"><input type="checkbox" id="monLegacyHist" checked> 점검 기록 · 장애 이력</label>
        <div style="margin-top:8px"><button type="button" class="btn-ghost" onclick="monLegacy(this)"${L.sqlite ? "" : " disabled"}>가져오기</button></div>
        <pre class="sh-mpre" id="monLegacyOut" hidden></pre></div>
    </div>
  </div>`;
}

async function monSaveSettings(btn) {
  const body = { action: "monitor_settings_save" };
  document.querySelectorAll("#monSetForm [data-k]").forEach(el => { body[el.dataset.k] = el.type === "checkbox" ? (el.checked ? 1 : 0) : el.value; });
  btn.disabled = true;
  try { const j = await postJSON(body); APP.blumo = j.meta; monBanner(); showToast("설정을 저장했습니다 — 다음 점검부터 적용됩니다"); }
  catch (e) { showToast(e.message); }
  finally { btn.disabled = false; }
}
async function monPause(on) {
  if (on && !(await askConfirm("전체 일시정지", "모든 자동 점검과 알림을 멈춥니다. 화면에서 누르는 점검은 계속 됩니다.", "일시정지"))) return;
  try { const j = await postJSON({ action: "monitor_pause", on }); APP.blumo = j.meta; showToast(on ? "전체 일시정지했습니다" : "자동 점검을 재개했습니다"); monSettings(); }
  catch (e) { showToast(e.message); }
}
async function monTestWebhook(btn) {
  btn.disabled = true;
  try { await postJSON({ action: "monitor_test_webhook" }); showToast("웹훅 발송 성공 — 채널에 장애·복구 2건이 왔는지 확인하세요"); }
  catch (e) { showToast(e.message); }
  finally { btn.disabled = false; }
}
async function monDoctor(btn) {
  btn.disabled = true; btn.textContent = "진단 중…";
  try { const j = await postJSON({ action: "monitor_doctor" }); const o = $("monDoctorOut"); o.textContent = j.lines.join("\n"); o.hidden = false; }
  catch (e) { showToast(e.message); }
  finally { btn.disabled = false; btn.textContent = "환경 진단"; }
}
async function monLegacy(btn) {
  btn.disabled = true;
  try {
    const j = await postJSON({ action: "monitor_legacy_import", path: $("monLegacyPath").value, settings: $("monLegacySet").checked ? 1 : 0, history: $("monLegacyHist").checked ? 1 : 0 });
    const r = j.result, o = $("monLegacyOut");
    o.textContent = `설정 ${r.settings.length ? r.settings.join(", ") : "없음"}\n점검 기록 ${r.checks}건 · 장애 이력 ${r.incidents}건 · 현재 상태 ${r.states}곳`
      + (r.unmapped.length ? `\n연결 안 된 예전 대상(기록을 안 옮김): ${r.unmapped.join(", ")}` : "");
    o.hidden = false;
    APP.blumo = j.meta;
    showToast("예전 BLUMO 에서 가져왔습니다");
  } catch (e) { showToast(e.message); }
  finally { btn.disabled = false; }
}

/* monitor.php 첫 화면 */
if ($("monBody")) {
  const t = new URLSearchParams(location.search).get("tab");
  $("monQ").addEventListener("input", e => { MON.q = e.target.value; monRender(); });
  monTab(["incidents", "settings"].includes(t) ? t : "board");
  setInterval(() => { if (!document.hidden && MON.tab === "board" && !MON.runPoll && !document.querySelector("details.sh-menu[open]") && !$("overlay").classList.contains("open")) monLoad(); }, 60000);
}
