function toggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById("hdrUserMenu").classList.toggle("open");
}
document.addEventListener("click", e => {
  const um = document.getElementById("hdrUserMenu");
  if (um && !um.contains(e.target)) um.classList.remove("open");
});
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

async function loadAll() {
  await loadWhoami();
  buildDevBar();
  await loadMembers();   // 관리자만 실제로 받아온다
  try {
    await reload();
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
  }
}

document.addEventListener("keydown", e => {
  if (e.key === "Escape") { closeSheet(); closeConfirm(); closeDate(null); }
});

loadAll();
