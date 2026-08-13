const FORM_IDS = ["f-title", "f-field-in", "f-keywords", "f-magazine-in",
  "f-volume", "f-page", "f-year", "f-team-in", "f-planned", "f-note"];

let REQ = "recommended";   // 폼의 발표 구분 선택
let EDIT_ID = null;
let PENDING_DELETE = null;

function setReq(v) {
  REQ = v;
  REQUIREMENTS.forEach(r =>
    document.getElementById(`req-${r.key}`).classList.toggle("on", r.key === v));
}

function openForm() {
  EDIT_ID = null;
  FORM_IDS.forEach(id => document.getElementById(id).value = "");
  document.getElementById("f-active").value = "1";
  setReq("recommended");
  document.getElementById("sheet-kicker").textContent = "신규 등록";
  document.getElementById("sheet-title").textContent = "새 주제 등록";
  openSheet();
}

function openEdit(id) {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  EDIT_ID = id;
  const s = (el, v) => document.getElementById(el).value = (v == null ? "" : v);
  s("f-title", t.title); s("f-field-in", t.field); s("f-keywords", t.keywords);
  s("f-magazine-in", t.magazine); s("f-volume", t.volume); s("f-page", t.page);
  s("f-year", t.year); s("f-team-in", t.team); s("f-planned", t.planned_date);
  s("f-note", t.note); s("f-active", t.active ? "1" : "0");
  setReq(t.requirement);
  document.getElementById("sheet-kicker").textContent = "수정";
  document.getElementById("sheet-title").textContent = "주제 수정";
  openSheet();
}

function formValues() {
  const g = id => document.getElementById(id).value.trim();
  return {
    title: g("f-title"), field: g("f-field-in"), keywords: g("f-keywords"),
    magazine: g("f-magazine-in"), volume: g("f-volume"), page: g("f-page"),
    year: g("f-year") ? Number(g("f-year")) : null,
    requirement: REQ, team: g("f-team-in"), planned_date: g("f-planned"),
    note: g("f-note"), active: Number(g("f-active")),
  };
}

async function submitForm() {
  const v = formValues();
  if (!v.title) { showToast("제목을 입력해 주세요"); return; }
  try {
    if (EDIT_ID) {
      await putJSON(`/magazineapi/topics/${EDIT_ID}`, v);
      showToast("수정했습니다");
    } else {
      await postJSON("/magazineapi/topics", v);
      showToast("등록했습니다");
    }
    closeSheet();
    await reload();
  } catch (e) { showToast(e.message); }
}

function askDelete(id) {
  const t = APP.topics.find(x => x.id === id);
  PENDING_DELETE = id;
  document.getElementById("confirmText").textContent =
    `"${t ? t.title : ""}" 주제를 삭제할까요?`;
  document.getElementById("confirmOverlay").classList.add("open");
}

async function doDelete() {
  try {
    await api(`/magazineapi/topics/${PENDING_DELETE}`, { method: "DELETE" });
    showToast("삭제했습니다");
    closeConfirm();
    await reload();
  } catch (e) { showToast(e.message); }
}
