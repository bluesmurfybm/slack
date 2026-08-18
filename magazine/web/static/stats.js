const STATS_MONTHS = 6;

function monthKey(d) { return String(d || "").slice(0, 7); }

function recentMonths(n) {
  const now = new Date(), out = [];
  for (let i = n - 1; i >= 0; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    out.push({
      key: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`,
      label: `${d.getMonth() + 1}월`,
    });
  }
  return out;
}

function statsData() {
  const all = APP.topics;
  const done = all.filter(t => t.done_date);

  const months = recentMonths(STATS_MONTHS).map(m => ({
    ...m, v: done.filter(t => monthKey(t.done_date) === m.key).length,
  }));
  const cur = months[months.length - 1].v;
  const prev = months.length > 1 ? months[months.length - 2].v : 0;

  const waiting = all.filter(t => t.status === "미지정" && t.active && !t.archived).length;

  const spoke = new Set(done.map(t => t.presenter_email).filter(Boolean));
  const headcount = (typeof MEMBERS !== "undefined" ? MEMBERS.length : 0);
  const rate = headcount ? Math.round(spoke.size / headcount * 100) : 0;

  const likes = all.reduce((s, t) => s + emotionCount(t, "like"), 0);

  const teams = (APP.me.all_teams || []).map(team => {
    const rows = all.filter(t => t.team === team);
    const claimed = rows.filter(t => t.presenter_email).length;
    return { team, claimed, total: rows.length,
             pct: rows.length ? Math.round(claimed / rows.length * 100) : 0 };
  });

  const byPresenter = {};
  done.forEach(t => {
    if (t.presenter) byPresenter[t.presenter] = (byPresenter[t.presenter] || 0) + 1;
  });
  const rank = Object.entries(byPresenter).sort((a, b) => b[1] - a[1]).slice(0, 5);

  const fields = [...new Set(all.map(t => t.field).filter(Boolean))]
    .map(f => {
      const c = all.filter(t => t.field === f).length;
      return { f, c, pct: all.length ? Math.round(c / all.length * 100) : 0 };
    }).sort((a, b) => b.c - a.c);

  const reactions = EMOTIONS.filter(e => e.kind !== "like")
    .map(e => ({ ...e, v: all.reduce((s, t) => s + emotionCount(t, e.kind), 0) }))
    .sort((a, b) => b.v - a.v);

  return { all, done, months, cur, prev, waiting, spoke: spoke.size, headcount,
           rate, likes, teams, rank, fields, reactions };
}

function deltaHtml(cur, prev) {
  const d = cur - prev;
  if (!d) return '<i class="flat">전월과 같음</i>';
  return `<i class="${d > 0 ? "up" : "down"}">${d > 0 ? "▲" : "▼"} 전월 대비 ${d > 0 ? "+" : ""}${d}</i>`;
}

function barsHtml(months) {
  const max = Math.max(1, ...months.map(m => m.v));
  return `<div class="bars">${months.map(m => `
    <div class="b"><em>${m.v}</em>
      <i style="height:${Math.round(m.v / max * 100)}%"></i>
      <span>${m.label}</span></div>`).join("")}</div>`;
}

const hbar = (name, pct, value) => `<div class="hbar">
  <span class="nm">${esc(name)}</span>
  <span class="tr"><i style="width:${pct}%"></i></span>
  <span class="vl">${esc(value)}</span></div>`;

function statsHtml() {
  const d = statsData();
  const perTalk = d.done.length ? (d.likes / d.done.length).toFixed(1) : "0.0";
  return `
  <div class="stats">
    <div class="stat"><span>이번 달 발표</span><b>${d.cur}건</b>${deltaHtml(d.cur, d.prev)}</div>
    <div class="stat"><span>선점 대기</span><b>${d.waiting}건</b><i class="flat">노출 중인 미지정 주제</i></div>
    <div class="stat"><span>구성원 참여율</span><b>${d.rate}%</b><i class="flat">${d.spoke}/${d.headcount}명 발표 경험</i></div>
    <div class="stat"><span>누적 좋아요</span><b>${d.likes}</b><i class="flat">발표당 평균 ${perTalk}</i></div>
  </div>

  <div class="statgrid">
    <div class="box2">
      <h3>월별 발표 건수</h3>
      ${barsHtml(d.months)}
    </div>
    <div class="box2">
      <h3>팀별 선점률</h3>
      ${d.teams.length
        ? d.teams.map(t => hbar(t.team, t.pct, `${t.claimed}/${t.total}`)).join("")
        : '<p class="muted">팀 데이터가 없습니다.</p>'}
      <h3 style="margin-top:22px">발표 랭킹</h3>
      ${d.rank.length
        ? d.rank.map(([n, v], i) =>
            `<div class="rank"><span class="n">${i + 1}</span>
             <span class="nm">${esc(n)}</span><span class="v">${v}회</span></div>`).join("")
        : '<p class="muted">아직 발표 기록이 없습니다.</p>'}
    </div>
  </div>

  <div class="statgrid" style="margin-top:14px">
    <div class="box2">
      <h3>분야별 분포</h3>
      ${d.fields.length
        ? d.fields.map(f => hbar(f.f, f.pct, `${f.c}건`)).join("")
        : '<p class="muted">분야 데이터가 없습니다.</p>'}
    </div>
    <div class="box2">
      <h3>많이 받은 리액션</h3>
      ${d.reactions.map(r =>
        `<div class="rank"><span class="nm">${r.icon} ${esc(r.label)}</span>
         <span class="v">${r.v}</span></div>`).join("")}
    </div>
  </div>`;
}

function renderStatsPage() {
  document.getElementById("statsPage").innerHTML = statsHtml();
}
