let MEMBERS = [];
let ASSIGN_ID = null;

async function loadMembers() {
  if (!APP.me.is_admin || MEMBERS.length) return;
  try { MEMBERS = await api("/magazineapi/members"); } catch (e) { MEMBERS = []; }
}

function openAssign(id) {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  ASSIGN_ID = id;
  document.getElementById("assignSubject").textContent = t.title;
  const sel = document.getElementById("assignWho");
  sel.innerHTML = '<option value="">지정 안 함</option>' +
    MEMBERS.map(m => `<option value="${esc(m.email)}">${esc(m.name)}</option>`).join("");
  sel.value = t.presenter_email || "";
  document.getElementById("assignDate").value = t.planned_date || "";
  document.getElementById("assignOverlay").classList.add("open");
}

function closeAssign() {
  document.getElementById("assignOverlay").classList.remove("open");
}

async function submitAssign() {
  const email = document.getElementById("assignWho").value;
  const planned_date = document.getElementById("assignDate").value;
  try {
    await postJSON(`/magazineapi/topics/${ASSIGN_ID}/assign`, { email, planned_date });
    showToast(email ? "발표자를 지정했습니다" : "지정을 해제했습니다");
    closeAssign();
    await reload();
  } catch (e) { showToast(e.message); }
}
