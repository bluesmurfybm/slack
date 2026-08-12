const STATUS_CLASS = { "미지정": "open", "발표예정": "planned", "발표완료": "done" };
const TEAMS = ["App", "LAB", "SQUARE"];

function visible() {
  const v = id => document.getElementById(id).value;
  const q = v("q").trim().toLowerCase();
  const mine = document.getElementById("f-mine").checked;
  return APP.topics.filter(t =>
    (!v("f-field") || t.field === v("f-field")) &&
    (!v("f-magazine") || t.magazine === v("f-magazine")) &&
    (!v("f-status") || t.status === v("f-status")) &&
    (!v("f-req") || t.requirement === v("f-req")) &&
    (!mine || t.presenter_email === APP.me.email) &&
    (!q || `${t.title} ${t.keywords || ""} ${t.presenter || ""} ${t.team || ""}`
      .toLowerCase().includes(q))
  );
}

function buildFilters() {
  const fill = (id, vals, allLabel) => {
    const el = document.getElementById(id), keep = el.value;
    el.innerHTML = `<option value="">${allLabel}</option>` +
      [...new Set(vals)].filter(Boolean).sort().map(x => `<option>${esc(x)}</option>`).join("");
    el.value = keep;
  };
  fill("f-field", APP.topics.map(t => t.field), "전체 분야");
  fill("f-magazine", APP.topics.map(t => t.magazine), "전체 매거진");
}

/* 분야·매거진은 기존 값 자동완성, 년도·팀은 드롭박스. 모두 현재 데이터에서 만든다. */
function buildFormOptions() {
  const fillDL = (id, vals) => {
    document.getElementById(id).innerHTML =
      [...new Set(vals)].filter(Boolean).sort()
        .map(v => `<option value="${esc(v)}"></option>`).join("");
  };
  fillDL("dl-field", APP.topics.map(t => t.field));
  fillDL("dl-magazine", APP.topics.map(t => t.magazine));

  const years = new Set(APP.topics.map(t => t.year).filter(Boolean));
  const now = new Date().getFullYear();
  for (let y = now - 1; y <= now + 1; y++) years.add(y);
  document.getElementById("f-year").innerHTML = '<option value="">선택</option>' +
    [...years].sort((a, b) => b - a).map(y => `<option value="${y}">${y}</option>`).join("");

  const teams = new Set(TEAMS);
  APP.topics.forEach(t => { if (t.team) teams.add(t.team); });
  document.getElementById("f-team").innerHTML = '<option value="">없음</option>' +
    [...teams].sort().map(t => `<option>${esc(t)}</option>`).join("");
}

function render() {
  const rows = visible();
  const list = document.getElementById("list");
  document.getElementById("ledgerCount").textContent =
    `${rows.length}건 / 전체 ${APP.topics.length}건`;
  list.innerHTML = rows.length
    ? rows.map(rowHtml).join("")
    : '<li class="empty"><div class="big">해당하는 주제가 없어요</div><div>필터를 바꿔 보세요.</div></li>';
}

function rowHtml(t) {
  const need = t.requirement === "required"
    ? '<span class="badge req">필수</span>'
    : '<span class="badge rec">권장</span>';
  const st = `<span class="badge st-${STATUS_CLASS[t.status]}">${esc(t.status)}</span>`;
  const who = t.presenter || t.team || "";
  const c = who ? colorFor(who) : null;
  const src = [t.magazine, t.volume && `Vol.${t.volume}`, t.page && `p.${t.page}`]
    .filter(Boolean).join(" ");
  const when = t.done_date || t.planned_date || "";
  // 발표자·날짜를 맨 왼쪽에 세로로 세운다
  return `<li class="row">
    <div class="row-side">
      ${who ? `<span class="who" style="background:${c.bg};color:${c.fg}">${esc(who)}</span>`
        : '<span class="who none">미지정</span>'}
      <span class="muted when">${esc(when || "—")}</span>
    </div>
    <div class="row-main">
      <div class="row-title">${esc(t.title)} ${need} ${st}</div>
      <div class="row-sub">
        ${t.field ? `<span class="chip">${esc(t.field)}</span>` : ""}
        ${t.keywords ? `<span class="chip ghost">${esc(t.keywords)}</span>` : ""}
        <span class="muted">${esc(src || "—")}</span>
        ${materialChip(t)}
      </div>
    </div>
    <div class="row-act">${actionsHtml(t)}</div>
  </li>`;
}

function actionsHtml(t) {
  const out = [];
  if (canManageMaterial(t)) {
    const has = !!t.material_kind;
    out.push(`<button class="btn-mini mat${has ? " has" : ""}"
      onclick="openMaterial(${t.id})">📎 ${has ? "자료 변경" : "자료 올리기"}</button>`);
  }
  if (t.status === "미지정") {
    out.push(`<button class="btn-mini primary" onclick="claim(${t.id})">내가 발표할게요</button>`);
  } else if (t.presenter_email === APP.me.email && t.status !== "발표완료") {
    out.push(`<button class="btn-mini" onclick="schedule(${t.id})">예정일</button>`);
    out.push(`<button class="btn-mini" onclick="release(${t.id})">취소</button>`);
  }
  if (APP.me.is_admin) {
    if (t.status === "발표예정") {
      out.push(`<button class="btn-mini" onclick="complete(${t.id})">발표완료</button>`);
    }
    out.push(`<button class="btn-mini ghost" onclick="openEdit(${t.id})">수정</button>`);
    out.push(`<button class="btn-mini ghost danger" onclick="askDelete(${t.id})">삭제</button>`);
  }
  return out.join("");
}

async function reload() {
  APP.topics = await api("/magazineapi/topics");
  buildFilters();
  buildFormOptions();
  render();
}
