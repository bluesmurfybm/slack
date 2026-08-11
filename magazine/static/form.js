/* 관리자 폼 도메인: 주제 등록·수정 시트와 삭제 확인. */

const FORM_IDS = ["f-title", "f-field-in", "f-keywords", "f-magazine-in",
  "f-volume", "f-page", "f-year", "f-team", "f-planned", "f-note"];

let REQ = "recommended";   // 폼의 필수/권장 선택
let EDIT_ID = null;
let PENDING_DELETE = null;

function setReq(v) {
  REQ = v;
  document.getElementById("req-recommended").classList.toggle("on", v === "recommended");
  document.getElementById("req-required").classList.toggle("on", v === "required");
}

function openForm() {
  EDIT_ID = null;
  FORM_IDS.forEach(id => document.getElementById(id).value = "");
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
  s("f-year", t.year); s("f-team", t.team); s("f-planned", t.planned_date);
  s("f-note", t.note);
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
    requirement: REQ, team: g("f-team"), planned_date: g("f-planned"), note: g("f-note"),
  };
}

async function submitForm() {
  const v = formValues();
  if (!v.title) { showToast("제목을 입력해 주세요"); return; }
  try {
    if (EDIT_ID) {
      await api(`/magazineapi/topics/${EDIT_ID}`, {
        method: "PUT", headers: { "Content-Type": "application/json" },
        body: JSON.stringify(v)
      });
      showToast("수정했습니다");
    } else {
      await postJSON("/magazineapi/topics", v);
      showToast("등록했습니다");
    }
    closeSheet();
    await reload();
  } catch (e) { showToast(e.message); }
}

/* 확인 다이얼로그는 askDelete 로 열고 doDelete 가 인자 없이 확정한다. */
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
