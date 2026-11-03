// SiteHub 통계 — api.php?stats 를 받아 CSS 막대로 그린다. 지정 계정만 이 화면에 들어온다(stats.php · API 둘 다 검사).
// 차트 규칙: 막대 ≤24px · 4px 둥근 끝(밑변은 각진) · 쌓인 조각 사이 2px 틈 · 값은 막대 끝 · 글자는 계열색을 안 입는다 ·
// 막대마다 마우스/포커스 툴팁 · 월별은 표로 보기.

const ST = { period: "12m", costPeriod: "12m", year: String(new Date().getFullYear()), data: null };
const PERIODS = ["12m", "ytd", "lastyear", "all"];

const fmtN = n => Number(n || 0).toLocaleString("ko-KR");
function fmtWon(n) {
  if (n === null || n === undefined) return "—";
  if (n >= 1e8) return (Math.round(n / 1e7) / 10).toLocaleString("ko-KR") + "억원";
  if (n >= 1e4) return Math.round(n / 1e4).toLocaleString("ko-KR") + "만원";
  return fmtN(n) + "원";
}
const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
const unitLabel = () => "/년";   // 계약 금액은 항상 1년 기준
const stripNo = s => String(s || "").replace(/^\d+_/, "");

/* ── 받아 오기 ── 다시 받는 동안 이전 그림을 흐리게 유지한다(깜빡임 없이) */
async function loadStats() {
  const root = $("statsRoot");
  root.classList.add("loading");
  try {
    const j = await api("?stats=1&period=" + encodeURIComponent(ST.period) + "&cost_period=" + encodeURIComponent(ST.costPeriod) + "&year=" + encodeURIComponent(ST.year));
    ST.data = j;
    renderAll();
    if (!ST.autoInit) {   // 통계 화면도 열어 두면 자동 동기화 — 바뀌면 숫자를 다시 받는다
      ST.autoInit = true;
      autoSyncInit(j.sync_age, async r => { await loadStats(); showToast(`Slack 이력 ${r.changed}건을 새로 반영했습니다`); });
    } else {
      autoSyncSeen(j.sync_age);
    }
  } catch (e) {
    showToast(e.message);
  } finally {
    root.classList.remove("loading");
  }
}

function setCostPeriod(p) {
  ST.costPeriod = PERIODS.includes(p) ? p : "12m";
  const u = new URL(location.href);
  if (ST.costPeriod === "12m") u.searchParams.delete("cost_period"); else u.searchParams.set("cost_period", ST.costPeriod);
  history.replaceState(null, "", u.pathname + u.search);
  document.querySelectorAll("#costPeriodSeg button").forEach(b => b.classList.toggle("on", b.dataset.period === ST.costPeriod));
  loadStats();
}

function setPeriod(p) {
  ST.period = PERIODS.includes(p) ? p : "12m";
  const u = new URL(location.href);
  if (ST.period === "12m") u.searchParams.delete("period"); else u.searchParams.set("period", ST.period);
  history.replaceState(null, "", u.pathname + u.search);
  document.querySelectorAll("#periodSeg button").forEach(b => b.classList.toggle("on", b.dataset.period === ST.period));
  loadStats();
}

function renderAll() {
  const d = ST.data;
  const c = d.contracts, h = d.history;
  $("contractAsOf").textContent = `${d.today} 기준 · 보관함 ${fmtN(c.kpi.archived)}곳 제외`;
  $("histSource").textContent = "Slack BlueLab-유지보수처리목록 · " + (d.last_sync ? "마지막 동기화 " + d.last_sync : "아직 동기화 안 함");
  $("expWindow").textContent = `종료일 기준 지난 ${c.window.past}일 ~ 앞으로 ${c.window.next}일`;

  const k = c.kpi;
  const yearSel = $("contractYear");
  yearSel.innerHTML = k.years.map(y => `<option value="${y}"${y === k.year ? " selected" : ""}>${y}년</option>`).join("");
  const amountNote = [k.annual_sites ? `${fmtN(k.annual_sites)}곳 합계` : "", k.once_excluded ? `1회 금액 ${k.once_excluded}건 제외` : "",
    k.no_amount ? `금액 없음 ${k.no_amount}건` : ""].filter(Boolean).join(" · ");
  $("kpiContract").innerHTML = [
    tile("사용 중 사이트", fmtN(k.sites), `보관 ${fmtN(k.archived)}곳 별도`),
    tile("유상 계약중", fmtN(k.paid), k.paid_soon ? `만료 임박 ${k.paid_soon}곳 포함` : "만료 임박 없음", k.paid_soon ? "warn" : ""),
    tile("무상", fmtN(k.free), k.free_soon ? `만료 임박 ${k.free_soon}곳 포함` : ""),
    tile("만료", fmtN(k.expired), "갱신 안 된 계약", k.expired ? "down" : ""),
    tile("미계약", fmtN(k.none), ""),
    tile(`${k.year}년 계약액`, fmtWon(k.annual_total), amountNote || "금액이 적힌 유상 계약 없음"),
  ].join("");

  $("chTop").innerHTML = c.top.length
    ? hbars(c.top.map(r => ({ label: r.name, n: r.value, tip: `${fmtWon(r.value)} / 년`, sub: `${fmtN(r.amount)}원/년${r.vat ? " · VAT 포함" : ""}`,
      href: `index.php?site=${r.site_id}&tab=contracts` })), fmtWon)
    : empty("금액이 적힌 유상 계약이 없습니다");
  $("chHosting").innerHTML = c.hosting.length ? hbars(c.hosting.map(r => ({ label: r.label, n: r.n, tip: `${fmtN(r.n)}곳` })), n => fmtN(n) + "곳") : empty("사이트가 없습니다");
  $("tblExpiring").innerHTML = expiringTable(c.expiring);

  const hk = h.kpi;
  $("kpiHistory").innerHTML = [
    tile(`요청 · ${h.period.label}`, fmtN(hk.total), h.period.from ? `${h.period.from} ~ ${h.period.to}` : "동기화된 전체"),
    tile("처리완료", fmtN(hk.done), hk.total ? `${pct(hk.done, hk.total)}%` : ""),
    tile("유상 요청", fmtN(hk.paid), hk.total ? `${pct(hk.paid, hk.total)}% · 무상 ${fmtN(hk.free)}` : ""),
    tile("지금 진행 중", fmtN(hk.open), "기간과 무관 · 시작전·작업중·검토중", hk.open ? "warn" : ""),
    tile("미매칭", fmtN(hk.unmatched), hk.unmatched ? "목록 화면의 ‘미매칭’ 버튼에서 사이트 지정" : "모두 사이트에 연결됨", hk.unmatched ? "down" : ""),
  ].join("");

  const noHist = !hk.total;
  $("chMonths").innerHTML = noHist ? empty(d.last_sync ? "이 기간에 요청이 없습니다" : "아직 Slack 이력을 가져오지 않았습니다 — 목록 화면의 ‘이력 동기화’를 먼저 하세요") : monthsChart(h.months, h.period.clipped);
  const order = ["1_시작전", "2_작업중", "4_검토중", "처리완료", "보류중", "취소"];
  const status = h.status.slice().sort((a, b) => {
    const ia = order.indexOf(a.label), ib = order.indexOf(b.label);
    return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib) || b.n - a.n;
  });
  $("chStatus").innerHTML = noHist ? empty("—") : hbars(status.map(r => ({ label: stripNo(r.label), n: r.n, tip: `${fmtN(r.n)}건 · ${pct(r.n, hk.total)}%` })), n => fmtN(n) + "건");
  $("chChannel").innerHTML = noHist ? empty("—") : hbars(h.channel.map(r => ({ label: r.label, n: r.n, tip: `${fmtN(r.n)}건 · ${pct(r.n, hk.total)}%` })), n => fmtN(n) + "건");
  $("chSites").innerHTML = h.sites.length
    ? hbars(h.sites.map(r => ({ label: r.label + (r.archived ? " (보관)" : ""), n: r.n, tip: `${fmtN(r.n)}건`, href: `index.php?site=${r.site_id}&tab=history` })), n => fmtN(n) + "건")
    : empty(noHist ? "—" : "사이트에 연결된 이력이 없습니다");
  renderCosts(d.costs);
}

/* ── 지출 비용 ── 금액은 입력한 그대로(VAT 포함·별도가 섞임) */
function renderCosts(cs) {
  const k = cs.kpi;
  const none = !k.n;
  $("kpiCost").innerHTML = [
    tile(`지출 · ${cs.period.label}`, fmtWon(k.amount), cs.period.from ? `${cs.period.from} ~ ${cs.period.to}` : "등록된 전체"),
    tile("결제 건수", fmtN(k.n), ""),
    tile("업체(결제처)", fmtN(k.vendors), ""),
    tile("월 평균", fmtWon(cs.months.length ? Math.round(k.amount / cs.months.length) : 0), `${cs.months.length}개월 기준`),
    tile("결제일 없음", fmtN(k.undated), k.undated ? "기간 통계에서 빠짐 — 비용 탭에서 결제일 입력" : "", k.undated ? "down" : ""),
  ].join("");
  $("chCostMonths").innerHTML = none ? empty("이 기간에 등록된 비용이 없습니다 — 사이트 상세의 ‘비용’ 탭에서 등록하세요") : costMonthsChart(cs.months, cs.period.clipped);
  $("tblCostYears").innerHTML = cs.years.length
    ? `<div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>연도</th><th class="num">건수</th><th class="num">금액</th></tr></thead><tbody>${
        cs.years.map(y => `<tr><td>${esc(y.year)}년</td><td class="num">${fmtN(y.n)}</td><td class="num">${fmtN(y.amount)}원</td></tr>`).join("")
      }</tbody></table></div>`
    : empty("—");
  const vend = cs.vendors.length > 10 ? cs.vendors.slice(0, 9).concat([{ label: "그 외", amount: cs.vendors.slice(9).reduce((a, r) => a + r.amount, 0), n: cs.vendors.slice(9).reduce((a, r) => a + r.n, 0) }]) : cs.vendors;
  $("chCostVendors").innerHTML = none ? empty("—") : hbars(vend.map(r => ({ label: r.label, n: r.amount, tip: `${fmtN(r.amount)}원 · ${fmtN(r.n)}건` })), fmtWon);
  const vy = cs.vendor_years;
  $("tblCostVendorYears").innerHTML = vy.rows.length
    ? `<div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>업체</th>${vy.years.map(y => `<th class="num">${esc(y)}</th>`).join("")}<th class="num">합계</th></tr></thead><tbody>${
        vy.rows.map(r => `<tr><td>${esc(r.label)}</td>${vy.years.map(y => `<td class="num">${r.by[y] ? fmtN(r.by[y]) : "—"}</td>`).join("")}<td class="num"><b>${fmtN(r.total)}</b></td></tr>`).join("")
      }<tr><td><b>합계</b></td>${cs.years.map(y => `<td class="num"><b>${fmtN(y.amount)}</b></td>`).join("")}<td class="num"><b>${fmtN(cs.years.reduce((a, y) => a + y.amount, 0))}</b></td></tr></tbody></table></div>`
    : empty("—");
  $("chCostKinds").innerHTML = none ? empty("—") : hbars(cs.kinds.map(r => ({ label: r.label, n: r.amount, tip: `${fmtN(r.amount)}원 · ${fmtN(r.n)}건` })), fmtWon);
  $("chCostSites").innerHTML = cs.sites.length
    ? hbars(cs.sites.map(r => ({ label: r.label, n: r.amount, tip: `${fmtN(r.amount)}원 · ${fmtN(r.n)}건`, href: `index.php?site=${r.site_id}&tab=costs` })), fmtWon)
    : empty("—");
}

// 월별 세로 막대(금액 한 계열) — 최댓값·마지막 달만 머리에 금액
function costMonthsChart(months, clipped) {
  const max = Math.max(1, ...months.map(m => m.amount));
  const step = niceStep(max);
  const top = Math.ceil(max / step) * step;
  const ticks = [];
  for (let v = 0; v <= top; v += step) ticks.push(v);
  const maxIdx = months.reduce((bi, m, i) => (m.amount > months[bi].amount ? i : bi), 0);
  const lastIdx = months.length - 1;
  const grid = ticks.map(v => `<div class="gl" style="bottom:${(v / top) * 100}%"><span>${fmtWon(v)}</span></div>`).join("");
  const cols = months.map((m, i) => {
    const [y, mo] = m.ym.split("-");
    const cap = m.amount && (i === maxIdx || i === lastIdx) ? `<em class="cap">${fmtWon(m.amount)}</em>` : "";
    const dense = months.length > 18;
    const showX = !dense || i === 0 || ["01", "04", "07", "10"].includes(mo);
    const xl = showX ? `${Number(mo)}월${i === 0 || mo === "01" ? `<small>${y}</small>` : ""}` : "";
    return `<div class="slot" tabindex="0" data-tip-v="${fmtN(m.amount)}원" data-tip-l="${esc(`${y}년 ${Number(mo)}월 · ${m.n}건`)}">
      <div class="colwrap"><div class="col" style="height:${(m.amount / top) * 100}%">${cap}${m.amount ? `<i class="s1" style="flex:1"></i>` : ""}</div></div>
      <div class="xl">${xl}</div></div>`;
  }).join("");
  const table = `<details class="sh-tableview"><summary>표로 보기</summary><table class="sh-table"><thead><tr><th>월</th><th class="num">건수</th><th class="num">금액</th></tr></thead><tbody>${
    months.map(m => `<tr><td>${m.ym}</td><td class="num">${fmtN(m.n)}</td><td class="num">${fmtN(m.amount)}원</td></tr>`).join("")
  }</tbody></table></details>`;
  return `<div class="sh-cols" style="--n:${months.length}"><div class="grid">${grid}</div><div class="plot">${cols}</div></div>
    ${clipped ? `<p class="sh-note">전체 기간이 길어 최근 36개월만 그렸습니다.</p>` : ""}${table}`;
}

/* ── 조각들 ── */
function tile(label, value, sub, tone) {
  return `<div class="stat"><span>${esc(label)}</span><b>${esc(value)}</b>${sub ? `<i class="${tone || "flat"}">${esc(sub)}</i>` : ""}</div>`;
}
const empty = msg => `<div class="sh-empty">${esc(msg)}</div>`;

// 가로 막대 — 한 계열(파랑)이라 범례 없이 제목이 무엇인지 말한다. 값은 막대 끝, 누르면 이동(href 가 있으면).
function hbars(rows, fmt) {
  const max = Math.max(1, ...rows.map(r => r.n));
  return `<ul class="sh-hbars">${rows.map(r => {
    const w = Math.max(1.5, (r.n / max) * 100);
    const inner = `<span class="l" title="${esc(r.label)}">${esc(r.label)}</span>
      <span class="track"><span class="bar" style="width:calc(${w.toFixed(2)}% * .78)"></span><span class="v">${esc(fmt(r.n))}</span></span>`;
    const tip = `data-tip-v="${esc(r.tip || fmt(r.n))}" data-tip-l="${esc(r.label + (r.sub ? " · " + r.sub : ""))}"`;
    return r.href
      ? `<li><a href="${esc(r.href)}" ${tip}>${inner}</a></li>`
      : `<li tabindex="0" ${tip}>${inner}</li>`;
  }).join("")}</ul>`;
}

// 월별 세로 막대 — 무상(1번 파랑) · 유상(2번 주황) · 미기재(회색) 쌓기. 범례 + 최댓값·마지막 달만 머리에 숫자.
function monthsChart(months, clipped) {
  const max = Math.max(1, ...months.map(m => m.n));
  const step = niceStep(max);
  const top = Math.ceil(max / step) * step;
  const ticks = [];
  for (let v = 0; v <= top; v += step) ticks.push(v);
  const hasOther = months.some(m => m.other > 0);
  const maxIdx = months.reduce((bi, m, i) => (m.n > months[bi].n ? i : bi), 0);
  const lastIdx = months.length - 1;
  const legend = `<div class="sh-legend"><span><i class="sw s1"></i>무상</span><span><i class="sw s2"></i>유상</span>${hasOther ? `<span><i class="sw s0"></i>미기재</span>` : ""}</div>`;
  const grid = ticks.map(v => `<div class="gl" style="bottom:${(v / top) * 100}%"><span>${fmtN(v)}</span></div>`).join("");
  const cols = months.map((m, i) => {
    const [y, mo] = m.ym.split("-");
    const segs = [["s1", m.free, "무상"], ["s2", m.paid, "유상"], ["s0", m.other, "미기재"]].filter(s => s[1] > 0);
    const h = (m.n / top) * 100;
    const cap = m.n && (i === maxIdx || i === lastIdx) ? `<em class="cap">${fmtN(m.n)}</em>` : "";
    const dense = months.length > 18;   // 19개월 이상이면 분기 첫 달만 이름표
    const showX = !dense || i === 0 || ["01", "04", "07", "10"].includes(mo);
    const xl = showX ? `${Number(mo)}월${i === 0 || mo === "01" ? `<small>${y}</small>` : ""}` : "";
    const tipL = `${y}년 ${Number(mo)}월` + (m.n ? " · " + segs.map(s => `${s[2]} ${s[1]}`).join(" · ") : "");
    return `<div class="slot" tabindex="0" data-tip-v="${fmtN(m.n)}건" data-tip-l="${esc(tipL)}">
      <div class="colwrap"><div class="col" style="height:${h}%">${cap}${segs.slice().reverse().map(s => `<i class="${s[0]}" style="flex:${s[1]}"></i>`).join("")}</div></div>
      <div class="xl">${xl}</div></div>`;
  }).join("");
  const table = `<details class="sh-tableview"><summary>표로 보기</summary><table class="sh-table"><thead><tr><th>월</th><th>합계</th><th>무상</th><th>유상</th>${hasOther ? "<th>미기재</th>" : ""}</tr></thead><tbody>${
    months.map(m => `<tr><td>${m.ym}</td><td>${fmtN(m.n)}</td><td>${fmtN(m.free)}</td><td>${fmtN(m.paid)}</td>${hasOther ? `<td>${fmtN(m.other)}</td>` : ""}</tr>`).join("")
  }</tbody></table></details>`;
  return `${legend}<div class="sh-cols" style="--n:${months.length}"><div class="grid">${grid}</div><div class="plot">${cols}</div></div>
    ${clipped ? `<p class="sh-note">전체 기간이 길어 최근 36개월만 그렸습니다.</p>` : ""}${table}`;
}
function niceStep(max) {
  const raw = max / 4;
  const p = Math.pow(10, Math.floor(Math.log10(raw)));
  const m = raw / p;
  return Math.max(1, (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p);
}

function expiringTable(rows) {
  if (!rows.length) return empty("이 구간에 끝나는 계약·호스팅·도메인·SSL 이 없습니다");
  const badge = r => {
    const d = r.dday < 0 ? "D+" + -r.dday : r.dday === 0 ? "D-day" : "D-" + r.dday;
    const cls = r.code === "expired" ? "sh-st-expired" : r.code === "soon" ? "sh-st-soon" : "sh-st-active";
    const word = r.code === "expired" ? "만료" : r.code === "soon" ? "임박" : "예정";
    return `<span class="badge ${cls}">${word} ${d}</span>`;
  };
  return `<div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>사이트</th><th>구분</th><th>만료일</th><th>남은 기간</th><th class="num">금액</th></tr></thead><tbody>${
    rows.map(r => `<tr>
      <td><a href="index.php?site=${r.site_id}&tab=${r.what === "account" ? "accounts" : "contracts"}">${esc(r.name)}</a>${r.customer && r.customer !== r.name ? `<span class="muted"> ${esc(r.customer)}</span>` : ""}</td>
      <td>${esc(r.kind)}</td><td>${esc(r.end_date)}</td><td>${badge(r)}</td>
      <td class="num">${r.amount === null ? "—" : fmtN(r.amount) + "원" + unitLabel(r.unit)}</td></tr>`).join("")
  }</tbody></table></div>`;
}

/* ── 툴바 · 툴팁 ── 값이 먼저(굵게), 이름이 뒤. 글자는 textContent 로만 넣는다. */
const tipEl = $("shTip");
function showTip(el, x, y) {
  tipEl.replaceChildren();
  const v = document.createElement("b"); v.textContent = el.dataset.tipV || "";
  const l = document.createElement("span"); l.textContent = el.dataset.tipL || "";
  tipEl.append(v, l);
  tipEl.hidden = false;
  const r = tipEl.getBoundingClientRect();
  const left = Math.min(window.innerWidth - r.width - 8, Math.max(8, x + 12));
  const top = y - r.height - 12 < 8 ? y + 16 : y - r.height - 12;
  tipEl.style.left = left + "px";
  tipEl.style.top = top + "px";
}
function hideTip() { tipEl.hidden = true; }
document.addEventListener("pointermove", e => {
  const el = e.target.closest("[data-tip-v]");
  if (el) showTip(el, e.clientX, e.clientY); else hideTip();
});
document.addEventListener("focusin", e => {
  const el = e.target.closest("[data-tip-v]");
  if (!el) return hideTip();
  const r = el.getBoundingClientRect();
  showTip(el, r.left + r.width / 2, r.top);
});
document.addEventListener("focusout", hideTip);
window.addEventListener("scroll", hideTip, { passive: true });

$("contractYear").addEventListener("change", e => { ST.year = e.target.value; loadStats(); });
document.querySelectorAll("#periodSeg button").forEach(b => b.addEventListener("click", () => setPeriod(b.dataset.period)));
document.querySelectorAll("#costPeriodSeg button").forEach(b => b.addEventListener("click", () => setCostPeriod(b.dataset.period)));
{
  const q = new URLSearchParams(location.search).get("cost_period");
  if (PERIODS.includes(q)) ST.costPeriod = q;
  document.querySelectorAll("#costPeriodSeg button").forEach(b => b.classList.toggle("on", b.dataset.period === ST.costPeriod));
}
setPeriod(new URLSearchParams(location.search).get("period") || "12m");
