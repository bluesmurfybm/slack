const FORM_IDS = ["f-title", "f-field-in", "f-keywords", "f-magazine-in",
  "f-volume", "f-page", "f-team-in", "f-planned", "f-note"];

let REQ = "recommended"; // 폼의 발표 구분 선택
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
  // 분야 select 에는 빈 옵션이 없다(시안과 동일). 첫 항목을 기본값으로 둔다.
  document.getElementById("f-field-in").selectedIndex = 0;
  document.getElementById("f-active").value = "1";
  document.getElementById("issueHint").style.display = "none";
  setReq("recommended");
  document.getElementById("sheet-kicker").textContent = "신규 등록";
  document.getElementById("sheet-title").textContent = "새 아티클 등록";
  openSheet();
}

function openEdit(id) {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  EDIT_ID = id;
  const s = (el, v) => document.getElementById(el).value = (v == null ? "" : v);
  s("f-title", t.title); s("f-field-in", t.field); s("f-keywords", t.keywords);
  s("f-magazine-in", t.magazine); s("f-volume", t.volume); s("f-page", t.page);
  s("f-team-in", t.team); s("f-planned", t.planned_date);
  s("f-note", t.note); s("f-active", t.active ? "1" : "0");
  document.getElementById("issueHint").style.display = "none";
  setReq(t.requirement);
  document.getElementById("sheet-kicker").textContent = "아티클 수정";
  document.getElementById("sheet-title").textContent = "아티클 정보 수정";
  openSheet();
}

// Volume 표기가 '279', '275. Oct-Nov', '20.2025' 로 섞여 있어 문자열 비교가 안 된다.
// 앞머리 숫자만 한 매거진 안에서 호 번호 구실을 한다.
function volumeHead(t) {
  const found = /^\s*(\d+(?:\.\d+)?)/.exec(t.volume || "");
  return found ? Number(found[1]) : -1;
}

// 최신 호 = Volume 번호 → 년도 → 등록 순.
// 등록 순(id)을 먼저 보면 안 된다 — xlsx 에서 넘어온 행의 id 는 등록 시점이 아니라
// 시트 행 순서라서, DI 가 279 가 아니라 2024년 275 호로 잡힌다.
// 년도를 먼저 보면 안 된다 — 년도는 비워 둘 수 있는 값이라, 년도 없이 등록한
// 새 호(280)가 년도 있는 옛 호(2025년 279)보다 뒤로 밀린다.
const byNewestIssue = (a, b) =>
  volumeHead(b) - volumeHead(a) || (b.year || 0) - (a.year || 0) || b.id - a.id;

function latestIssueOf(magazine) {
  const rows = APP.topics.filter(t => t.magazine === magazine);
  const numbered = rows.filter(t => t.volume);
  if (numbered.length) return numbered.sort(byNewestIssue)[0];
  return rows.filter(t => t.page).sort((a, b) => b.id - a.id)[0] || null;
}

function fillLatestIssue() {
  const hint = document.getElementById("issueHint");
  hint.style.display = "none";
  if (EDIT_ID) return; // 수정 중에는 이미 들어 있는 값을 덮지 않는다
  const magazine = document.getElementById("f-magazine-in").value.trim();
  const latest = magazine && latestIssueOf(magazine);
  if (!latest) return;

  document.getElementById("f-volume").value = latest.volume || "";
  document.getElementById("f-page").value = latest.page || "";
  hint.style.display = "";
  hint.textContent = `↺ ${magazine} 의 가장 최근 호로 채웠습니다 — `
    + `${sourceOf(latest)}. 이어지는 아티클이면 페이지만 고치세요.`;
}

function formValues() {
  const g = id => document.getElementById(id).value.trim();
  return {
    title: g("f-title"), field: g("f-field-in"), keywords: g("f-keywords"),
    magazine: g("f-magazine-in"), volume: g("f-volume"), page: g("f-page"),
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
    `"${t ? t.title : ""}" 아티클을 삭제할까요?`;
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
