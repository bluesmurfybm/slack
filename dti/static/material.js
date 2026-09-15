let MAT_ID = null;
let MAT_SLOT = "material";
let MAT_MODE = "file";

// 슬롯 이름은 서버의 컬럼 접두어이자 API 경로다 (features/material/router.py)
const SLOTS = {
  scan: { label: "스캔한 아티클 원본", icon: "📄", hint: "PDF · 이미지" },
  material: { label: "발표용 자료", icon: "📊", hint: "PPTX · PDF · 키노트" },
};

const IMAGE_RE = /\.(png|jpe?g|gif|webp|bmp)$/i;
const PDF_RE = /\.pdf$/i;

// 한 칸에 여러 건이 들어간다. material_* 는 첫 자료에서 파생된 값이라 정렬·배지에만 쓴다
const slotList = (t, slot) => t[`${slot}s`] || [];
const matName = (m) => m.name || (m.kind === "link" ? "링크" : "파일");

function canManageMaterial(t) {
  const mine = t.presenter_email && t.presenter_email === APP.me.email;
  return mine || (APP.me.is_admin && APP.view.mode === "admin");
}

function materialChips(t) {
  return Object.keys(SLOTS).flatMap(s => slotList(t, s).map(m => {
    const icon = m.kind === "link" ? "🔗" : SLOTS[s].icon;
    return `<button class="chip material" onclick="openViewer(${t.id},'${s}',${m.id})"
      title="${SLOTS[s].label} — ${esc(matName(m))}">${icon} ${esc(matName(m))}</button>`;
  })).join("");
}

function matListHTML(t, slot) {
  const list = slotList(t, slot);
  if (!list.length) return `<p class="muted">아직 올린 자료가 없습니다. (${SLOTS[slot].hint})</p>`;

  return `<ul class="mat-list">` + list.map(m => `
    <li>
      <button class="mat-open" onclick="openViewer(${t.id},'${slot}',${m.id})"
        title="${esc(matName(m))}">${m.kind === "link" ? "🔗" : SLOTS[slot].icon} ${esc(matName(m))}</button>
      <button class="mat-del" onclick="detachMaterial(${m.id})" title="삭제">✕</button>
    </li>`).join("") + `</ul>`;
}

function renderMatList() {
  const t = APP.topics.find(x => x.id === MAT_ID);
  document.getElementById("matList").innerHTML = t ? matListHTML(t, MAT_SLOT) : "";
}
function openMaterial(id, slot = "material") {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  MAT_ID = id;
  MAT_SLOT = slot;
  document.getElementById("matTitle").textContent = SLOTS[slot].label;
  renderMatList();
  clearMatForm();
  setMatMode("file");
  document.getElementById("matOverlay").classList.add("open");
}

function clearMatForm() {
  document.getElementById("mat-fileinput").value = "";
  document.getElementById("mat-url").value = "";
  document.getElementById("mat-name").value = "";
  setPickedFiles(null);
}

function closeMaterial() {
  document.getElementById("matOverlay").classList.remove("open");
}

function setMatMode(m) {
  MAT_MODE = m;
  document.getElementById("mat-file").classList.toggle("on", m === "file");
  document.getElementById("mat-link").classList.toggle("on", m === "link");
  document.getElementById("mat-file-box").style.display = m === "file" ? "" : "none";
  document.getElementById("mat-link-box").style.display = m === "link" ? "" : "none";
}

async function submitMaterial() {
  const added = MAT_MODE === "link" ? await submitLink() : await submitFiles();
  if (!added) return;

  // 모달은 닫지 않는다 — 여러 건을 잇따라 올리는 게 흔하다
  await reload();
  renderMatList();
  clearMatForm();
}

async function submitLink() {
  const url = document.getElementById("mat-url").value.trim();
  if (!url) { showToast("주소를 넣어 주세요"); return 0; }
  try {
    await postJSON(`/magazineapi/topics/${MAT_ID}/${MAT_SLOT}/link`,
      { url, name: document.getElementById("mat-name").value.trim() });
    showToast("자료를 올렸습니다");
    return 1;
  } catch (e) { showToast(e.message); return 0; }
}

/** 하나씩 차례로 보낸다 — 한 건이 실패해도 나머지는 올라간다 */
async function submitFiles() {
  if (!PICKED_FILES.length) { showToast("파일을 골라 주세요"); return 0; }

  const failed = [];
  let done = 0;
  for (const file of PICKED_FILES) {
    const fd = new FormData();
    fd.append("file", file);
    try {
      await api(`/magazineapi/topics/${MAT_ID}/${MAT_SLOT}/file`, { method: "POST", body: fd });
      done++;
    } catch (e) { failed.push(`${file.name}: ${e.message}`); }
  }

  if (failed.length) showToast(failed.join(" / "));
  else showToast(done === 1 ? "자료를 올렸습니다" : `${done}개를 올렸습니다`);
  return done;
}

async function detachMaterial(mid) {
  try {
    await api(`/magazineapi/topics/${MAT_ID}/${MAT_SLOT}/${mid}`, { method: "DELETE" });
    showToast("자료를 삭제했습니다");
    await reload();
    renderMatList();
  } catch (e) { showToast(e.message); }
}
function openViewer(id, slot = "material", mid = null) {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  const list = slotList(t, slot);
  const m = mid === null ? list[0] : list.find(x => x.id === mid);
  if (!m) return;

  // 링크는 임베드를 막는 사이트가 많아 새 탭으로 연다.
  if (m.kind === "link") {
    window.open(m.url, "_blank", "noopener");
    return;
  }

  const name = matName(m);
  const src = dtiApiURL(`/magazineapi/topics/${id}/${slot}/${m.id}/download`);
  const body = document.getElementById("viewBody");
  document.getElementById("viewName").textContent = name;
  document.getElementById("viewDownload").href = src;
  document.getElementById("viewNewTab").href = src;

  if (IMAGE_RE.test(name)) {
    body.innerHTML = `<img src="${src}" alt="${esc(name)}">`;
  } else if (PDF_RE.test(name)) {
    body.innerHTML = `<iframe src="${src}" title="${esc(name)}"></iframe>`;
  } else {
    body.innerHTML = `<div class="viewer-none">
        <div class="big">여기서는 미리 볼 수 없는 형식이에요</div>
        <div class="muted">${esc(name)}</div>
      </div>`;
  }
  applyViewerSize();
  document.getElementById("viewOverlay").classList.add("open");
}

function toggleViewerSize() {
  const v = document.querySelector("#viewOverlay .viewer");
  const full = v.classList.toggle("full");
  document.getElementById("viewExpand").textContent = full ? "작게 보기" : "크게 보기";
  try { localStorage.setItem("dti-viewer-full", full ? "1" : "0"); } catch (e) { }
}

function applyViewerSize() {
  let full = false;
  try { full = localStorage.getItem("dti-viewer-full") === "1"; } catch (e) { }
  const v = document.querySelector("#viewOverlay .viewer");
  v.classList.toggle("full", full);
  document.getElementById("viewExpand").textContent = full ? "작게 보기" : "크게 보기";
}

function closeViewer() {
  document.getElementById("viewOverlay").classList.remove("open");
  document.getElementById("viewBody").innerHTML = ""; // iframe 정지
}


let PICKED_FILES = [];

function setPickedFiles(files) {
  PICKED_FILES = files ? Array.from(files) : [];
  const names = PICKED_FILES.map(f => f.name);
  document.getElementById("mat-filename").textContent =
    names.length === 0 ? "형식 제한 없음"
      : names.length === 1 ? names[0]
        : `${names.length}개 — ${names.join(", ")}`;
  document.getElementById("mat-drop").classList.toggle("has", names.length > 0);
}

function initDropzone() {
  const dz = document.getElementById("mat-drop");
  const input = document.getElementById("mat-fileinput");
  input.addEventListener("change", () => setPickedFiles(input.files));

  ["dragenter", "dragover"].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault();
    dz.classList.add("over");
  }));
  ["dragleave", "dragend"].forEach(ev =>
    dz.addEventListener(ev, () => dz.classList.remove("over")));

  dz.addEventListener("drop", e => {
    e.preventDefault();
    dz.classList.remove("over");
    const files = e.dataTransfer && e.dataTransfer.files;
    if (files && files.length) { setMatMode("file"); setPickedFiles(files); }
  });

  ["dragover", "drop"].forEach(ev => window.addEventListener(ev, e => {
    if (!e.target.closest || !e.target.closest("#mat-drop")) e.preventDefault();
  }));
}

initDropzone();
