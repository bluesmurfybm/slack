const REQUIREMENTS = [
  { key: "required", label: "필수" },
  { key: "recommended", label: "권장" },
  { key: "normal", label: "일반" },
];

// 화면의 진행 단계. 서버가 주는 status 는 3단계이고, '자료준비 완료'는
// 발표예정 + 자료 등록으로 화면에서 파생한다 (컬럼을 늘리지 않는다).
const STAGES = [
  { key: "open", label: "미지정" },
  { key: "planned", label: "발표예정" },
  { key: "ready", label: "자료준비 완료" },
  { key: "done", label: "발표완료" },
];

function stageOf(t) {
  if (t.status === "발표완료") return 3;
  if (t.status === "미지정") return 0;
  return t.material_kind ? 2 : 1;
}

function reqLabel(t) {
  const found = REQUIREMENTS.find(r => r.key === t.requirement);
  return found ? found.label : "권장";
}

function pool() {
  const v = APP.view;
  if (v.mode === "admin") {
    return APP.topics.filter(t => (v.tab === "archive" ? t.archived : !t.archived));
  }
  return APP.topics.filter(t => t.active && !t.archived);
}

function visible() {
  const v = id => document.getElementById(id).value;
  const q = v("q").trim().toLowerCase();
  const mine = document.getElementById("f-mine").checked;
  const myteam = document.getElementById("f-myteam").checked;
  const teams = APP.me.teams || [];
  return pool().filter(t =>
    (!v("f-field") || t.field === v("f-field")) &&
    (!v("f-magazine") || t.magazine === v("f-magazine")) &&
    (!v("f-team") || t.team === v("f-team")) &&
    (!myteam || teams.includes(t.team)) &&
    (!v("f-status") || STAGES[stageOf(t)].key === v("f-status")) &&
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
  fill("f-magazine", APP.me.all_magazines || [], "전체 매거진");
  fill("f-team", APP.me.all_teams || [], "전체 팀");

  const st = document.getElementById("f-status"), keep = st.value;
  st.innerHTML = '<option value="">전체 상태</option>' +
    STAGES.map(s => `<option value="${s.key}">${s.label}</option>`).join("");
  st.value = keep;
}

function buildFormOptions() {
  const union = (base, key) =>
    [...new Set([...base, ...APP.topics.map(t => t[key]).filter(Boolean)])];

  const fill = (id, vals, blank) => {
    document.getElementById(id).innerHTML =
      (blank ? `<option value="">${blank}</option>` : "") +
      vals.map(v => `<option>${esc(v)}</option>`).join("");
  };
  fill("f-team-in", APP.me.all_teams || [], "없음");
  // 서버의 분야 목록에 실데이터 값을 합친다 — 목록에서 지운 분야도 수정 폼에서 잃지 않는다.
  fill("f-field-in", union(APP.fields.map(f => f.name), "field"), null);
  fill("f-magazine-in", APP.me.all_magazines || [], "매거진 선택");
}

function myStats() {
  const mine = APP.topics.filter(t => t.presenter_email === APP.me.email);
  const done = mine.filter(t => t.status === "발표완료");
  return {
    done: done.length,
    upcoming: mine.length - done.length,
    likes: done.reduce((sum, t) => sum + emotionCount(t, "like"), 0),
    fields: new Set(done.map(t => t.field).filter(Boolean)).size,
  };
}

function renderStats() {
  const s = myStats();
  const put = (id, text) => document.getElementById(id).textContent = text;
  put("statDone", `${s.done}회`);
  put("statUpcoming", `${s.upcoming}건`);
  put("statLikes", `${s.likes}`);
  put("statFields", `${s.fields}개`);
}

const LEDGER_TITLE = {
  user: "발표 주제 목록",
  articles: "등록된 아티클",
  archive: "보관된 아티클",
};

function cardsMode() {
  return APP.view.mode !== "admin" && APP.view.layout === "card";
}

function render() {
  const v = APP.view;
  const admin = v.mode === "admin";
  const isCards = cardsMode();

  document.body.classList.toggle("admin-view", admin);
  document.getElementById("mystrip").style.display = admin ? "none" : "";
  document.getElementById("adminTabs").style.display = admin ? "" : "none";
  document.querySelector(".viewtoggle").style.display = admin ? "none" : "";
  document.getElementById("pageTitle").textContent = admin ? "DTI 운영 관리" : "DTI 발표 주제";
  document.getElementById("pageLede").textContent = admin
    ? "아티클 등록 · 노출 관리 · 발표자 지정 · 보관"
    : "배달된 매거진에서 우리 팀에 필요한 아티클을 골라 발표를 예약하세요.";
  document.getElementById("tabArticles").classList.toggle("on", v.tab === "articles");
  document.getElementById("tabArchive").classList.toggle("on", v.tab === "archive");
  document.getElementById("tabFields").classList.toggle("on", v.tab === "fields");
  document.getElementById("tabStats").classList.toggle("on", v.tab === "stats");
  document.getElementById("vList").classList.toggle("on", v.layout === "list");
  document.getElementById("vCard").classList.toggle("on", v.layout === "card");

  const isStats = admin && v.tab === "stats";
  const isFields = admin && v.tab === "fields";
  const isPanel = isStats || isFields;
  document.getElementById("statsPage").style.display = isStats ? "" : "none";
  document.getElementById("fieldsPage").style.display = isFields ? "" : "none";
  document.getElementById("toolbar").style.display = isPanel ? "none" : "";
  document.querySelector(".ledger-head").style.display = isPanel ? "none" : "";
  document.getElementById("list").style.display = isPanel ? "none" : "";
  if (isStats) { renderStatsPage(); return; }
  if (isFields) { renderFieldsPage(); return; }

  const rows = visible();
  const hidden = pool().filter(t => !t.active).length;
  document.getElementById("ledgerTitle").textContent =
    LEDGER_TITLE[admin ? v.tab : "user"];
  document.getElementById("ledgerCount").textContent =
    `${rows.length}건 / 전체 ${pool().length}건` + (admin && hidden ? ` · 비활성 ${hidden}건` : "");

  const list = document.getElementById("list");
  list.className = isCards ? "cards" : "list";
  list.innerHTML = rows.length
    ? rows.map(isCards ? cardHtml : rowHtml).join("")
    : `<li class="empty"><div class="big">해당하는 주제가 없어요</div>
       <div>${admin && v.tab === "archive"
      ? "발표가 끝난 아티클을 보관함으로 옮기면 여기에 모입니다."
      : "필터를 바꿔 보세요."}</div></li>`;
}

function railHtml(t) {
  const at = stageOf(t);
  const tone = at === 3 ? "done" : "on";
  let cells = "";
  for (let i = 0; i < STAGES.length; i++) {
    cells += `<i class="${i <= at ? tone : ""}"></i>`;
    if (i < STAGES.length - 1) cells += `<u class="${i < at ? tone : ""}"></u>`;
  }
  return `<span class="railwrap" title="예약 → 자료 등록 → 발표">
    <span class="rail">${cells}</span><b>${STAGES[at].label}</b></span>`;
}

function teamTag(t) {
  return t.team ? `<span class="badge team" data-t="${esc(t.team)}">${esc(t.team)}</span>` : "";
}

function reqBadge(t) {
  return `<span class="badge lv-${esc(t.requirement)}">${reqLabel(t)}</span>`;
}

function stateTags(t) {
  return (t.active ? "" : '<span class="badge off">비활성</span>')
    + (t.archived ? '<span class="badge off">보관</span>' : "");
}

function sourceOf(t) {
  return [t.magazine, t.volume && `Vol.${t.volume}`, t.page && `p.${t.page}`]
    .filter(Boolean).join(" ");
}

const dotDate = d => String(d).replace(/-/g, ".");

function presenterChip(t) {
  if (!t.presenter) return '<span class="chip ghost">발표자 미지정</span>';
  const c = colorFor(t.presenter);
  const when = t.done_date || t.planned_date;
  const sep = cardsMode() ? "" : "· ";
  return `<span class="who"><span class="dot" style="background:${c.bg};color:${c.fg}"
    >${esc(t.presenter.slice(0, 1))}</span>${esc(t.presenter)}</span>`
    + (when ? `<span class="when">${sep}${dotDate(esc(when))}</span>` : "");
}

function titleHtml(t) {
  return `<button class="linkish" onclick="openDrawer(${t.id})">${esc(t.title)}</button>`;
}

function tagsHtml(t) {
  return `${t.field ? `<span class="chip ghost">${esc(t.field)}</span>` : ""}
    ${t.keywords ? `<span class="chip">${esc(t.keywords)}</span>` : ""}`;
}

function sourceHtml(t) {
  return `<span class="muted">${esc(sourceOf(t) || "—")}</span>${materialChips(t)}`;
}

function metaHtml(t) {
  return `${tagsHtml(t)}${sourceHtml(t)}`;
}

function rowHtml(t) {
  return `<li class="row${t.active ? "" : " dim"}">
    <div class="row-lead">${teamTag(t)}${railHtml(t)}</div>
    <div class="row-main">
      <div class="row-title">${titleHtml(t)}${reqBadge(t)}${stateTags(t)}</div>
      <div class="row-sub">${metaHtml(t)}<span class="sep">·</span>${presenterChip(t)}</div>
    </div>
    <div class="row-act">${actionsHtml(t)}</div>
  </li>`;
}

function cardHtml(t) {
  return `<li class="card">
    <div class="card-top">${teamTag(t)}${reqBadge(t)}${stateTags(t)}
      <span class="card-rail">${railHtml(t)}</span></div>
    <div class="card-title">${titleHtml(t)}</div>
    <div class="row-sub card-tags">${tagsHtml(t)}</div>
    <div class="row-sub">${sourceHtml(t)}</div>
    <div class="card-foot">${presenterChip(t)}<span class="row-act">${actionsHtml(t)}</span></div>
  </li>`;
}

function actionsHtml(t) {
  if (APP.view.mode === "admin") return adminActionsHtml(t);
  const out = [];
  const mine = t.presenter_email === APP.me.email;

  if (t.status === "미지정") {
    out.push(`<button class="btn-mini primary" onclick="claim(${t.id})">내가 발표할게요</button>`);
  } else if (t.status === "발표완료") {
    out.push(likeButton(t));
  } else if (mine) {
    out.push(`<button class="btn-mini" onclick="schedule(${t.id})">예정일</button>`);
    out.push(`<button class="btn-mini" onclick="release(${t.id})">발표 예약 취소</button>`);
    // 자료는 상세에서 올린다 — 발표자가 거기로 갈 길이 있어야 한다
    out.push(`<button class="btn-mini ghost" onclick="openDrawer(${t.id})">상세</button>`);
  } else {
    out.push(`<button class="btn-mini ghost" onclick="openDrawer(${t.id})">상세</button>`);
  }
  return out.join("");
}

async function reload() {
  [APP.topics, APP.fields] = await Promise.all([
    api("/magazineapi/topics"), api("/magazineapi/fields")]);
  buildFilters();
  buildFormOptions();
  renderStats();
  render();
  refreshDrawer();
}
