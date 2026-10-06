function toggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById("hdrUserMenu").classList.toggle("open");
}
document.addEventListener("click", e => {
  const um = document.getElementById("hdrUserMenu");
  if (um && !um.contains(e.target)) um.classList.remove("open");
});

function setLayout(layout) {
  APP.view.layout = layout;
  try { localStorage.setItem(LAYOUT_KEY, layout); } catch (e) { }
  render();
}

function buildMyTeam() {
  const teams = APP.me.teams || [];
  const box = document.getElementById("myteamCheck");
  box.style.display = teams.length ? "" : "none";
  if (!teams.length) {
    document.getElementById("f-myteam").checked = false;
    return;
  }
  document.getElementById("myteamLabel").textContent =
    teams.length === 1 ? `우리 팀(${teams[0]})` : "우리 팀";
  box.title = `내 팀 — ${teams.join(" · ")}`;
}

let LIST_READY = false;

function renderListPage() {
  buildFilters();
  if (document.getElementById("f-team-in")) buildFormOptions();
  if (!LIST_READY) {
    applyListQuery();
    buildMyTeam();
  }
  if (APP.view.mode === "user") {
    renderStats();
    renderSchedule();
  }
  render();
  if (LIST_READY) {
    refreshDrawer();
  } else {
    LIST_READY = true;
    openDrawerFromQuery();
  }
}

const PAGE_RENDER = {
  list: renderListPage,
  articles: renderListPage,
  archive: renderListPage,
  rounds: () => renderRoundsPage(),
  fields: () => renderFieldsPage(),
  stats: () => renderStatsPage(),
  score: () => renderScorePage(),
};

function renderPage() {
  PAGE_RENDER[APP.view.tab]();
}

async function loadAll() {
  APP.me = await api("/magazineapi/whoami");
  if (typeof loadMembers === "function") await loadMembers(); // 관리자만 실제로 받아온다
  try {
    await reload();
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
  }
}

// 뒤로·앞으로 가기 — 상세를 닫거나 다시 열고, 필터도 그 시점 주소로 맞춘다
window.addEventListener("popstate", () => {
  if (PAGE_RENDER[APP.view.tab] !== renderListPage || !LIST_READY) return;
  applyListQuery();
  render();
  openDrawerFromQuery();
});

document.addEventListener("keydown", e => {
  if (e.key !== "Escape") return;
  closeDate(null);
  closeAsk(false);
  ["overlay", "confirmOverlay", "fieldOverlay", "roundOverlay", "issueOverlay"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove("open");
  });
  if (typeof closeDrawer === "function") closeDrawer();
});

loadAll();
