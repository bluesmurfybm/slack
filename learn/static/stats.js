const STATS_MONTHS = 6;
let STATS_YEAR = ""; // "" = 전체 기간

/* ---------- 집계 ---------- */

// 보관은 목록에서 내리는 표시상의 처리일 뿐이라 통계에는 포함한다.
// 다만 "처리 대기"는 실제로 손대야 하는 건이므로 보관된 것을 뺀다.
function statsRows() {
  return STATS_YEAR
    ? APP.requests.filter(r => (r.created_at || "").startsWith(STATS_YEAR))
    : APP.requests;
}

function statsYears() {
  return [...new Set(APP.requests.map(r => (r.created_at || "").slice(0, 4)).filter(Boolean))]
    .sort().reverse();
}

// 기간을 고르면 그 해 열두 달, 전체면 최근 여섯 달 — 필터를 따라가지 않는 차트가 섞이면
// 같은 화면의 숫자끼리 어긋나 보인다
function chartMonths() {
  if (STATS_YEAR) {
    return Array.from({ length: 12 }, (_, i) => ({
      key: `${STATS_YEAR}-${String(i + 1).padStart(2, "0")}`,
      label: `${i + 1}월`,
    }));
  }
  const now = new Date(), out = [];
  for (let i = STATS_MONTHS - 1; i >= 0; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    out.push({
      key: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`,
      label: `${d.getMonth() + 1}월`,
    });
  }
  return out;
}

// 월별은 막대 아래 월 이름이 붙으므로 색은 순서를 눈에 띄게 하는 장식이다
const MONTH_BARS = ["#7fb0ef", "#79c8b4", "#ecc85e", "#f0b070", "#ad9cea", "#7fcb9b",
                    "#8fc7e8", "#eb96bb", "#c3d08a", "#9db8ea", "#eab98c", "#8fd0c0"];
const CHART = { level: "#79c8b4", large: "#ad9cea" };

const paid = r => !r.is_free && !isCompany(r);
const settledRefund = r => r.status === S.CLAIM_APPROVED || r.status === S.REFUNDED;

function countBy(rows, pick) {
  const map = new Map();
  for (const r of rows) {
    const k = pick(r);
    if (!k) continue;
    const cur = map.get(k) || { n: 0, won: 0 };
    cur.n += 1;
    cur.won += r.is_free ? 0 : (r.price || 0);
    map.set(k, cur);
  }
  return [...map.entries()].map(([k, v]) => ({ k, ...v })).sort((a, b) => b.n - a.n);
}

function statsData() {
  const rows = statsRows();
  const months = chartMonths().map(m => {
    const hit = APP.requests.filter(r => (r.created_at || "").startsWith(m.key));
    return { ...m, n: hit.length, won: hit.reduce((s, r) => s + (r.is_free ? 0 : r.price || 0), 0) };
  });
  const cur = months[months.length - 1].n;
  const prev = months.length > 1 ? months[months.length - 2].n : 0;

  const pending = rows.filter(r => !r.archived &&
    [S.REQUESTED, S.CLAIMED, S.CLAIM_APPROVED].includes(r.status));

  const money = {
    price: rows.filter(r => !r.is_free).reduce((s, r) => s + (r.price || 0), 0),
    fixed: rows.filter(settledRefund).reduce((s, r) => s + (r.refund_amount || 0), 0),
    done: rows.filter(r => r.status === S.REFUNDED).reduce((s, r) => s + (r.refund_amount || 0), 0),
    waiting: rows.filter(r => r.status === S.CLAIM_APPROVED)
      .reduce((s, r) => s + (r.refund_amount || 0), 0),
    self: rows.filter(settledRefund)
      .reduce((s, r) => s + Math.max(0, (r.price || 0) - (r.refund_amount || 0)), 0),
    company: rows.filter(r => isCompany(r) && !r.is_free)
      .reduce((s, r) => s + (r.price || 0), 0),
  };

  const byStatus = [...STATUSES, ""].map(st => ({
    st,
    n: rows.filter(r => (st === "" ? r.is_free : !r.is_free && r.status === st)).length,
  })).filter(x => x.n);

  const rated = rows.filter(r => r.rating != null);
  const avgRating = rated.length
    ? (rated.reduce((s, r) => s + r.rating, 0) / rated.length).toFixed(1) : null;

  const byApplicant = countBy(rows, r => r.applicant)
    .slice(0, 5);

  return {
    rows, months, cur, prev, pending, money, byStatus, rated, avgRating, byApplicant,
    bySite: countBy(rows, r => r.site),
    byLevel: countBy(rows.filter(r => r.level), r => r.level),
    byLarge: countBy(rows, r => r.category_large).slice(0, 6),
    paidCount: rows.filter(paid).length,
    freeCount: rows.filter(r => r.is_free).length,
    companyCount: rows.filter(r => isCompany(r) && !r.is_free).length,
  };
}

/* ---------- 조각 ---------- */

function deltaHTML(cur, prev) {
  const d = cur - prev;
  if (!d) return '<i class="flat">전월과 같음</i>';
  return `<i class="${d > 0 ? "up" : "down"}">${d > 0 ? "▲" : "▼"} 전월 대비 ${d > 0 ? "+" : ""}${d}건</i>`;
}

// 세로 막대 — 한 계열이라 범례가 없다. 값은 막대 위에 직접 붙인다.
function barsHTML(items) {
  const max = Math.max(1, ...items.map(m => m.n));
  return `<div class="chartbars">${items.map((m, i) => `
    <div class="b">
      <span class="v">${m.n}</span>
      <span class="tip" style="height:${Math.round(m.n / max * 100)}%">
        <i style="background:${MONTH_BARS[i % MONTH_BARS.length]}"></i>
        <em>${esc(m.key)} · ${m.n}건${m.won ? ` · ${won(m.won)}` : ""}</em>
      </span>
      <span class="x">${esc(m.label)}</span>
    </div>`).join("")}</div>`;
}

// 가로 막대 — 이름을 직접 붙이므로 색이 정체성을 지지 않는다
function hbarHTML(name, n, total, extra, color) {
  const pct = total ? Math.round(n / total * 100) : 0;
  // style 을 두 번 쓰면 뒤엣것이 통째로 무시된다 — 한 속성에 합쳐야 색이 먹는다
  const style = `width:${pct}%` + (color ? `;background:${color}` : "");
  return `<div class="hbar">
    <span class="nm" title="${esc(name)}">${esc(name)}</span>
    <span class="tip tr"><i style="${style}"></i>
      <em>${esc(name)} · ${n}건 · ${pct}%${extra ? ` · ${esc(extra)}` : ""}</em></span>
    <span class="vl">${n}건</span>
  </div>`;
}

const emptyBox = msg => `<p class="muted">${esc(msg)}</p>`;

function statsHTML() {
  const d = statsData();
  const years = statsYears();
  const total = d.rows.length;

  const filter = `<div class="addbar" style="margin-bottom:14px">
    <span class="muted">기간</span>
    <select id="stats-year" onchange="pickStatsYear()">
      <option value="">전체</option>
      ${years.map(y => `<option value="${y}"${STATS_YEAR === y ? " selected" : ""}>${y}년</option>`).join("")}
    </select>
    <span class="muted">${total}건 집계 · 보관된 건도 포함합니다</span>
  </div>`;

  const tiles = `<div class="stats">
    <div class="stat"><span>이번 달 신청</span><b>${d.cur}건</b>${deltaHTML(d.cur, d.prev)}</div>
    <div class="stat"><span>환급 완료</span><b>${won(d.money.done)}</b>
      <i class="flat">환급 대기 ${won(d.money.waiting)}</i></div>
    <div class="stat"><span>처리 대기</span><b>${d.pending.length}건</b>
      <i class="flat">관리자가 손대야 하는 건</i></div>
    <div class="stat"><span>평균 강의평가</span>
      <b>${d.avgRating ?? "—"}</b>
      <i class="flat">${d.rated.length}건 평가됨</i></div>
  </div>`;

  const moneyRows = [
    ["신청된 수강료", d.money.price, "무료 강의 제외"],
    ["환급 확정", d.money.fixed, "청구승인 이후"],
    ["환급 완료", d.money.done, "입금까지 끝난 금액"],
    ["환급 대기", d.money.waiting, "청구승인 후 미입금"],
    ["본인 부담", d.money.self, "부분환급 상한 초과분"],
    ["회사계정 결제", d.money.company, "환급 대상이 아님"],
  ];

  return `${filter}${tiles}
  <div class="statgrid">
    <div class="box2">
      <h3>월별 신청 건수
        <span class="muted">${STATS_YEAR ? `${STATS_YEAR}년` : `최근 ${STATS_MONTHS}개월`}</span></h3>
      ${barsHTML(d.months)}
    </div>
    <div class="box2">
      <h3>요청상태 분포</h3>
      ${d.byStatus.length
      ? d.byStatus.map(x => `<div class="hbar">
          <span class="nm">${x.st ? statusBadge({ status: x.st, is_free: 0 })
        : '<span class="badge st-free">무료</span>'}</span>
          <span class="tip tr"><i style="width:${Math.round(x.n / total * 100)}%;background:${STATUS_COLOR[x.st] || STATUS_COLOR[""]}"></i>
            <em>${esc(x.st || "무료")} · ${x.n}건 · ${Math.round(x.n / total * 100)}%</em></span>
          <span class="vl">${x.n}건</span></div>`).join("")
      : emptyBox("신청이 없습니다.")}
    </div>
  </div>

  <div class="statgrid" style="margin-top:14px">
    <div class="box2">
      <h3>교육 플랫폼별</h3>
      ${d.bySite.length
      ? d.bySite.map(x => hbarHTML(x.k, x.n, total, won(x.won), siteTheme(x.k).bar)).join("")
      : emptyBox("신청이 없습니다.")}
      <h3 style="margin-top:22px">학습수준별</h3>
      ${d.byLevel.length
      ? d.byLevel.map(x => hbarHTML(x.k, x.n, total, won(x.won), CHART.level)).join("")
      : emptyBox("학습수준을 적은 신청이 없습니다.")}
    </div>
    <div class="box2">
      <h3>많이 신청한 분류 <span class="muted">상위 6</span></h3>
      ${d.byLarge.length
      ? d.byLarge.map(x => hbarHTML(x.k, x.n, total, won(x.won), CHART.large)).join("")
      : emptyBox("분류가 적힌 신청이 없습니다.")}
    </div>
  </div>

  <div class="statgrid" style="margin-top:14px">
    <div class="box2">
      <h3>금액 요약</h3>
      <table class="mtable">
        ${moneyRows.map(([label, v, note]) => `<tr>
          <th>${esc(label)}</th>
          <td class="money">${won(v)}</td>
          <td class="muted">${esc(note)}</td></tr>`).join("")}
      </table>
      <p class="muted" style="margin-top:12px">
        유료·개인계정 ${d.paidCount}건 · 회사계정 ${d.companyCount}건 · 무료 ${d.freeCount}건
      </p>
    </div>
    <div class="box2">
      <h3>많이 신청한 사람 <span class="muted">상위 5</span></h3>
      ${d.byApplicant.length
      ? d.byApplicant.map((x, i) => `<div class="rank">
          <span class="n">${i + 1}</span>
          <span class="nm">${whoHTML(x.k)}</span>
          <span class="v">${x.n}건 · ${won(x.won)}</span></div>`).join("")
      : emptyBox("신청이 없습니다.")}
    </div>
  </div>`;
}

function pickStatsYear() {
  STATS_YEAR = document.getElementById("stats-year").value;
  renderAdmin();
}

function statsPanel() {
  return `<div class="statspage">${statsHTML()}</div>`;
}
