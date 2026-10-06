// 구성원 화면 맨 위 일정판 — 왼쪽은 날짜별 발표 목록, 오른쪽은 달력. 보여 주기만 하고 아래 목록은 거르지 않는다
const SCHEDULE = { scope: "up", month: null, picked: null };
const WEEKDAYS = ["일", "월", "화", "수", "목", "금", "토"];

// today() 는 UTC 라 한국 시간 오전 9시 전에는 하루 밀린다. 일정판은 D-day 를 세므로 현지 날짜를 쓴다
function localISO(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
const scheduleToday = () => localISO(new Date());
const dateOf = s => new Date(`${s}T00:00:00`);

function scheduleItems() {
  return pool()
    .filter(t => t.presenter)
    .map(t => ({ t, date: t.done_date || t.planned_date, done: !!t.done_date, mine: t.presenter_email === APP.me.email }));
}

function byDate(items) {
  const m = new Map();
  items.forEach(it => {
    if (!m.has(it.date)) m.set(it.date, []);
    m.get(it.date).push(it);
  });
  return m;
}

function ddayHtml(date, now) {
  const n = Math.round((dateOf(date) - dateOf(now)) / 864e5);
  if (n < 0) return `<span class="sch-dday past">${-n}일 전</span>`;
  return `<span class="sch-dday${n <= 7 ? " soon" : ""}">${n === 0 ? "오늘" : `D-${n}`}</span>`;
}

function scheduleRowHtml(it) {
  const t = it.t;
  return `<button type="button" class="sch-row${it.mine ? " mine" : ""}" onclick="openDrawer(${t.id})">
    ${t.magazine ? `<span class="sch-mag">${esc(t.magazine)}</span>` : ""}
    <span class="tt" title="${esc(t.title)}">${esc(t.title)}</span>
    ${it.mine ? '<span class="sch-me">나</span>' : ""}
    ${it.done ? '<span class="sch-done">완료</span>' : ""}
    <span class="who">${esc(t.presenter)}</span>
  </button>`;
}

function renderScheduleList() {
  const now = scheduleToday();
  const items = scheduleItems();
  const dated = items.filter(it => it.date);
  const up = SCHEDULE.scope === "up";
  const rows = up
    ? dated.filter(it => it.date >= now).sort((a, b) => a.date.localeCompare(b.date))
    : dated.filter(it => it.date < now).sort((a, b) => b.date.localeCompare(a.date));
  const undated = items.length - dated.length;

  document.getElementById("schCount").textContent = `${rows.length}건`;
  let html = [...byDate(rows)].map(([date, its]) => `
    <div class="sch-day" data-date="${date}">
      <div class="sch-day-head">
        <span class="d">${dotDate(date.slice(5))}</span>
        <span class="w">${WEEKDAYS[dateOf(date).getDay()]}요일</span>
        ${ddayHtml(date, now)}
      </div>
      ${its.map(scheduleRowHtml).join("")}
    </div>`).join("");
  if (!rows.length) html = `<div class="sch-empty">${up ? "다가오는 발표가 없습니다." : "지난 발표가 없습니다."}</div>`;
  if (up && undated) {
    html += `<div class="sch-undated">날짜 미정 <b>${undated}건</b> — 예약은 됐지만 발표일을 아직 정하지 않았습니다.</div>`;
  }
  document.getElementById("schList").innerHTML = html;
}

function renderScheduleCalendar() {
  const now = scheduleToday();
  const first = SCHEDULE.month;
  const y = first.getFullYear(), mo = first.getMonth();
  document.getElementById("schMonth").textContent = `${y}. ${String(mo + 1).padStart(2, "0")}`;

  const dates = byDate(scheduleItems().filter(it => it.date));
  const lead = first.getDay();
  const weeks = Math.ceil((lead + new Date(y, mo + 1, 0).getDate()) / 7);
  let html = "";
  for (let i = 0; i < weeks * 7; i++) {
    const d = new Date(y, mo, 1 - lead + i);
    const iso = localISO(d);
    const its = dates.get(iso) || [];
    const cls = ["sch-cell"];
    if (d.getMonth() !== mo) cls.push("out");
    if (d.getDay() === 0) cls.push("sun");
    if (d.getDay() === 6) cls.push("sat");
    if (iso === now) cls.push("today");
    if (iso === SCHEDULE.picked) cls.push("picked");
    if (!its.length) {
      html += `<div class="${cls.join(" ")}"><span class="n">${d.getDate()}</span></div>`;
      continue;
    }
    cls.push("has");
    if (iso < now) cls.push("past");
    html += `<button type="button" class="${cls.join(" ")}" aria-label="${dotDate(iso)} 발표 ${its.length}건" onclick="pickScheduleDate('${iso}')">
      <span class="n">${d.getDate()}</span><span class="k">${its.length}건</span>
      ${its.some(it => it.mine) ? '<span class="mine-dot"></span>' : ""}
    </button>`;
  }
  document.getElementById("schCells").innerHTML = html;
}

function renderSchedule() {
  if (!SCHEDULE.month) {
    const now = new Date();
    SCHEDULE.month = new Date(now.getFullYear(), now.getMonth(), 1);
    document.getElementById("schDays").innerHTML = WEEKDAYS
      .map((w, i) => `<div class="sch-dow${i === 0 ? " sun" : i === 6 ? " sat" : ""}">${w}</div>`).join("");
  }
  renderScheduleList();
  renderScheduleCalendar();
}

function setScheduleScope(scope) {
  SCHEDULE.scope = scope;
  [["schUp", "up"], ["schPast", "past"]].forEach(([id, s]) => {
    const b = document.getElementById(id);
    b.classList.toggle("on", s === scope);
    b.setAttribute("aria-selected", String(s === scope));
  });
  renderScheduleList();
}

// 0 은 이번 달로 돌아온다
function moveScheduleMonth(step) {
  const m = step ? SCHEDULE.month : new Date();
  SCHEDULE.month = new Date(m.getFullYear(), m.getMonth() + step, 1);
  renderScheduleCalendar();
}

function pickScheduleDate(date) {
  SCHEDULE.picked = date;
  const scope = date < scheduleToday() ? "past" : "up";
  if (SCHEDULE.scope !== scope) setScheduleScope(scope);
  renderScheduleCalendar();

  const list = document.getElementById("schList");
  const day = list.querySelector(`.sch-day[data-date="${date}"]`);
  if (!day) return;
  const smooth = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  list.scrollTo({ top: day.offsetTop - 4, behavior: smooth ? "smooth" : "auto" });
  day.classList.remove("flash");
  void day.offsetWidth;
  day.classList.add("flash");
}
