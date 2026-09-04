function toggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById("hdrUserMenu").classList.toggle("open");
}
document.addEventListener("click", e => {
  const um = document.getElementById("hdrUserMenu");
  if (um && !um.contains(e.target)) um.classList.remove("open");
});

function setMode(mode) {
  APP.view.mode = mode;
  document.getElementById("modeUser").classList.toggle("on", mode === "user");
  document.getElementById("modeAdmin").classList.toggle("on", mode === "admin");
  const admin = mode === "admin" && APP.me.is_admin;
  document.getElementById("userView").style.display = admin ? "none" : "";
  document.getElementById("adminView").style.display = admin ? "" : "none";
  document.getElementById("btnNew").style.display = admin ? "none" : "";
  if (admin) renderAdmin(); else renderList();
}

async function loadWhoami() {
  APP.me = await api("/learningapi/whoami");
  document.body.classList.toggle("is-admin", !!APP.me.is_admin);
  const portalUrl = (APP.me.portal_url || "").replace(/\/+$/, "");
  document.getElementById("hdrBrand").href = portalUrl || "#";
  document.getElementById("dd-mypage").href = portalUrl ? `${portalUrl}/?view=profile` : "#";
  document.getElementById("dd-slack").href = APP.me.slack_url || "#";
  document.getElementById("dd-logout").href = portalUrl ? `${portalUrl}/logout.php` : "#";
  document.getElementById("hdrName").textContent = APP.me.name || "(로그인 필요)";
  const av = document.getElementById("hdrAvatar");
  av.textContent = (APP.me.name || "?").slice(0, 1);
  av.style.background = APP.me.color || colorFor(APP.me.name).fg;
}

async function reload({ keepDrawer = false } = {}) {
  const [requests, sites, categories, policy] = await Promise.all([
    api("/learningapi/requests"),
    api("/learningapi/sites"),
    api("/learningapi/categories"),
    api("/learningapi/policy"),
  ]);
  APP.requests = requests;
  APP.sites = sites;
  APP.categories = categories;
  APP.policy = policy;
  // 관리자 전용 엔드포인트라 일반 사용자는 부르지 않는다
  APP.admins = APP.me.is_admin ? await api("/learningapi/admins") : null;
  buildFilters();
  if (APP.view.mode === "admin" && APP.me.is_admin) renderAdmin();
  else renderList();
  if (!keepDrawer && DETAIL) closeDrawer();
}

async function loadAll() {
  await loadWhoami();
  if (!APP.me.is_admin) setMode("user");
  try {
    await reload();
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
  }
}

document.addEventListener("keydown", e => {
  if (e.key !== "Escape") return;
  // 분류 모달은 폼 위에 뜨므로 먼저 닫는다 — 한 번에 둘 다 닫으면 폼 입력이 날아간다
  const cats = document.getElementById("catsOverlay");
  if (cats.classList.contains("open")) { closeCats(); return; }
  closeSheet();
  closeReason(null);
  closeConfirm(false);
  closeDrawer();
});

loadAll().catch(e => {
  if (e.message !== "unauthenticated") showToast(e.message);
});
