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
  focusLinked();
}

// 알림이 연 신청(?id=N) — 그 해로 옮겨 보여 준다
function focusLinked() {
  const id = parseInt(new URLSearchParams(location.search).get("id") || "", 10);
  const d = id && APP.data.find((x) => x.id === id);
  if (!d) return;
  if (APP.year !== "all" && d.year !== APP.year) {
    APP.year = d.year || "all";
    document.getElementById("yearSel").value = String(APP.year);
    render();
  }
  const li = document.querySelector(`#list li.row[data-id="${id}"]`);
  if (!li) return;
  li.classList.add("linked");
  li.scrollIntoView({ block: "center" });
}

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") { closeForm(); closeConfirm(); }
});

loadData();
