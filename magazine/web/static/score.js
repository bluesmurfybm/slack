const SCORE_KINDS = [
  { kind: "done", label: "발표" }, { kind: "required", label: "필수" },
  { kind: "material", label: "자료" }, { kind: "reaction", label: "반응" },
];
const SCORE_RULE = "발표 완료 10 · 필수 주제 +5 · 자료 등록 +3 · 반응 1 (하루 최대 3)";
let SCORE_YEAR = String(new Date().getFullYear());

function scoreYears() {
  const years = new Set([String(new Date().getFullYear())]);
  APP.topics.forEach(t => { if (t.done_date) years.add(String(t.done_date).slice(0, 4)); });
  return [...years].sort().reverse();
}

function setScoreYear(y) {
  SCORE_YEAR = y;
  renderScorePage();
}

function scoreRowHtml(r, i) {
  const parts = SCORE_KINDS.filter(k => r.breakdown[k.kind])
    .map(k => `${k.label} ${r.breakdown[k.kind]}`).join(" · ");
  return `<div class="rank"><span class="n">${i + 1}</span>
    <span class="nm">${esc(r.name)}</span>
    <span class="v">${esc(parts || "—")}</span>
    <span class="v" style="width:52px;text-align:right"><b>${r.total}점</b></span></div>`;
}

async function renderScorePage() {
  const box = document.getElementById("scorePage");
  const range = SCORE_YEAR ? `?start=${SCORE_YEAR}-01-01&end=${SCORE_YEAR}-12-31` : "";
  let rows;
  try {
    rows = await api(`/magazineapi/score${range}`);
  } catch (e) {
    box.innerHTML = `<p class="muted">${esc(e.message)}</p>`;
    return;
  }
  const options = [["", "전체"], ...scoreYears().map(y => [y, `${y}년`])]
    .map(([v, l]) => `<option value="${v}"${v === SCORE_YEAR ? " selected" : ""}>${l}</option>`)
    .join("");
  box.innerHTML = `
  <div class="box2">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <h3 style="margin:0;flex:1">멤버 점수</h3>
      <select onchange="setScoreYear(this.value)">${options}</select>
    </div>
    <p class="muted" style="margin:0 0 12px">${SCORE_RULE}</p>
    ${rows.map(scoreRowHtml).join("")}
  </div>`;
}
