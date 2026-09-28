const STATS_MONTHS = 6;
const STATS_ROUNDS = 6;
let STATS_ROUND = queryOf("round");

function setStatsRound(v) {
  STATS_ROUND = v;
  writeQuery({ round: v }, false);
  renderStatsPage();
}

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

const doneOf = rows => rows.filter(t => t.done_date);

// 고른 회차에서 끝나는 최근 회차들. 회차 하나 안에서는 월별 막대가 의미가 없다
function recentRoundBars(round) {
  const asc = [...APP.rounds].sort((a, b) => a.no - b.no);
  const end = asc.findIndex(r => r.id === round.id);
  return asc.slice(Math.max(0, end - STATS_ROUNDS + 1), end + 1).map(r => ({
    key: r.id, label: `${r.no}회`, v: doneOf(topicsOfRound(r.id)).length,
  }));
}

function statsData() {
  const round = STATS_ROUND ? roundById(Number(STATS_ROUND)) : null;
  const all = round ? topicsOfRound(round.id) : APP.topics;
  const done = doneOf(all);

  const bars = round ? recentRoundBars(round) : recentMonths(STATS_MONTHS).map(m => ({
    ...m, v: done.filter(t => monthKey(t.done_date) === m.key).length,
  }));
  const cur = bars[bars.length - 1].v;
  const prev = bars.length > 1 ? bars[bars.length - 2].v : 0;

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

  return { round, all, done, bars, cur, prev, waiting, spoke: spoke.size, headcount,
           rate, likes, teams, rank, fields, reactions };
}

function deltaHtml(cur, prev, before, same) {
  const d = cur - prev;
  if (!d) return `<i class="flat">${same}</i>`;
  return `<i class="${d > 0 ? "up" : "down"}">${d > 0 ? "▲" : "▼"} ${before} 대비 ${d > 0 ? "+" : ""}${d}</i>`;
}

function barsHtml(bars) {
  const max = Math.max(1, ...bars.map(m => m.v));
  return `<div class="bars">${bars.map(m => `
    <div class="b"><em>${m.v}</em>
      <i style="height:${Math.round(m.v / max * 100)}%"></i>
      <span>${m.label}</span></div>`).join("")}</div>`;
}

const hbar = (name, pct, value) => `<div class="hbar">
  <span class="nm">${esc(name)}</span>
  <span class="tr"><i style="width:${pct}%"></i></span>
  <span class="vl">${esc(value)}</span></div>`;

function roundCompareRow(name, rows) {
  const done = doneOf(rows);
  const spoke = new Set(done.map(t => t.presenter_email).filter(Boolean)).size;
  const likes = rows.reduce((s, t) => s + emotionCount(t, "like"), 0);
  return `<tr><th>${esc(name)}</th><td>${rows.length}</td><td>${done.length}</td>
    <td>${spoke}</td><td>${likes}</td></tr>`;
}

function roundCompareHtml() {
  const rounds = [...APP.rounds].sort(byRoundNoDesc);
  if (!rounds.length) return "";
  const loose = APP.topics.filter(t => t.round_id == null);
  return `<div class="box2" style="margin-top:14px">
    <h3>회차 비교</h3>
    <table class="rtable">
      <thead><tr><th>회차</th><th>아티클</th><th>발표 완료</th><th>발표한 사람</th><th>좋아요</th></tr></thead>
      <tbody>${rounds.map(r => roundCompareRow(roundLabel(r), topicsOfRound(r.id))).join("")}
        ${loose.length ? roundCompareRow("회차 없음", loose) : ""}</tbody>
    </table>
  </div>`;
}

function statsRoundPicker(round) {
  if (!APP.rounds.length) return "";
  const picked = round ? String(round.id) : "";
  const options = [["", "전체"], ...[...APP.rounds].sort(byRoundNoDesc).map(r => [String(r.id), roundLabel(r)])]
    .map(([v, l]) => `<option value="${v}"${v === picked ? " selected" : ""}>${esc(l)}</option>`).join("");
  return `<div class="stats-head"><select onchange="setStatsRound(this.value)">${options}</select></div>`;
}

function statsHtml() {
  const d = statsData();
  const perTalk = d.done.length ? (d.likes / d.done.length).toFixed(1) : "0.0";
  return `${statsRoundPicker(d.round)}
  <div class="stats">
    ${d.round
      ? `<div class="stat"><span>이 회차 발표</span><b>${d.cur}건</b>${deltaHtml(d.cur, d.prev, "이전 회차", "이전 회차와 같음")}</div>`
      : `<div class="stat"><span>이번 달 발표</span><b>${d.cur}건</b>${deltaHtml(d.cur, d.prev, "전월", "전월과 같음")}</div>`}
    <div class="stat"><span>예약 대기</span><b>${d.waiting}건</b><i class="flat">노출 중인 미지정 아티클</i></div>
    <div class="stat"><span>구성원 참여율</span><b>${d.rate}%</b><i class="flat">${d.spoke}/${d.headcount}명 발표 경험</i></div>
    <div class="stat"><span>누적 좋아요</span><b>${d.likes}</b><i class="flat">발표당 평균 ${perTalk}</i></div>
  </div>

  <div class="statgrid">
    <div class="box2">
      <h3>${d.round ? "회차별 발표 건수" : "월별 발표 건수"}</h3>
      ${barsHtml(d.bars)}
    </div>
    <div class="box2">
      <h3>팀별 예약률</h3>
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
  </div>
  ${roundCompareHtml()}`;
}

function renderStatsPage() {
  document.getElementById("statsPage").innerHTML = statsHtml();
}
