// 첫 화면 — 명단과 목록을 같이 받고 그린다
async function loadData() {
  try {
    const [members, data] = await Promise.all([api("/bookapi/members"), api("/bookapi/requests")]);
    APP.members = members;
    APP.data = data;
  } catch (e) {
    if (e.message === "unauthenticated") return;   // 이미 포털로 가는 중
    showToast(e.message);
    return;
  }
  APP.years = [...new Set(APP.data.map((d) => d.year).filter(Boolean))].sort((a, b) => b - a);
  buildYearSelect();
  render();
}

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") { closeForm(); closeConfirm(); }
});

loadData();
