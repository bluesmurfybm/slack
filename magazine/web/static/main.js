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
  render();
}

function setTab(tab) {
  APP.view.tab = tab;
  render();
}

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

function buildDevBar() {
  const bar = document.getElementById("devbar");
  if (!APP.me.dev_login) { bar.style.display = "none"; return; }
  bar.style.display = "";
  bar.innerHTML = '<span class="muted">개발 모드 — 계정 전환:</span> ' +
    (APP.me.dev_accounts || []).map(a =>
      `<button class="btn-mini${a.email === APP.me.email ? " on" : ""}"
         onclick="devLogin('${esc(a.email)}')">${esc(a.name)} (${esc(a.role)})</button>`
    ).join("");
}

async function devLogin(email) {
  await postJSON("/magazineapi/devlogin", { email });
  await loadAll();
}
async function loadWhoami() {
  APP.me = await api("/magazineapi/whoami");
  document.body.classList.toggle("is-admin", !!APP.me.is_admin);
  if (!APP.me.is_admin) setMode("user"); // 관리자 화면은 계정 전환 시에도 남지 않는다
  const portalUrl = (APP.me.portal_url || "").replace(/\/+$/, "");
  document.getElementById("hdrBrand").href = portalUrl || "#";
  document.getElementById("dd-mypage").href = portalUrl ? `${portalUrl}/?view=profile` : "#";
  document.getElementById("dd-slack").href = APP.me.slack_url || "#";
  document.getElementById("dd-logout").href = portalUrl ? `${portalUrl}/logout.php` : "#";
  document.getElementById("hdrName").textContent = APP.me.name || "(로그인 필요)";
  buildMyTeam();
  const av = document.getElementById("hdrAvatar");
  av.textContent = (APP.me.name || "?").slice(0, 1);
  av.style.background = APP.me.color || colorFor(APP.me.name).fg;
}

async function loadAll() {
  await loadWhoami();
  buildDevBar();
  await loadMembers(); // 관리자만 실제로 받아온다
  try {
    await reload();
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
  }
}

document.addEventListener("keydown", e => {
  if (e.key !== "Escape") return;
  closeSheet();
  closeConfirm();
  closeDate(null);
  closeDrawer();
});

loadAll();
