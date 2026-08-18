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

const slotOf = (t, slot, part) => t[`${slot}_${part}`];
const slotName = (t, slot) =>
  slotOf(t, slot, "name") || (slotOf(t, slot, "kind") === "link" ? "링크" : "파일");

function canManageMaterial(t) {
  const mine = t.presenter_email && t.presenter_email === APP.me.email;
  return mine || (APP.me.is_admin && APP.view.mode === "admin");
}

function materialChips(t) {
  return Object.keys(SLOTS).filter(s => slotOf(t, s, "kind")).map(s => {
    const icon = slotOf(t, s, "kind") === "link" ? "🔗" : SLOTS[s].icon;
    return `<button class="chip material" onclick="openViewer(${t.id},'${s}')"
      title="${SLOTS[s].label} — ${esc(slotName(t, s))}">${icon} ${esc(slotName(t, s))}</button>`;
  }).join("");
}
function openMaterial(id, slot = "material") {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  MAT_ID = id;
  MAT_SLOT = slot;
  const kind = slotOf(t, slot, "kind");
  document.getElementById("matTitle").textContent = SLOTS[slot].label;
  document.getElementById("matCurrent").textContent = kind
    ? `현재: ${slotName(t, slot)}`
    : `아직 올린 자료가 없습니다. (${SLOTS[slot].hint})`;
  document.getElementById("mat-del").style.display = kind ? "" : "none";
  document.getElementById("mat-fileinput").value = "";
  setPickedFile(null);
  const isLink = kind === "link";
  document.getElementById("mat-url").value = isLink ? slotOf(t, slot, "url") : "";
  document.getElementById("mat-name").value = isLink ? (slotOf(t, slot, "name") || "") : "";
  setMatMode(isLink ? "link" : "file");
  document.getElementById("matOverlay").classList.add("open");
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
  try {
    if (MAT_MODE === "link") {
      const url = document.getElementById("mat-url").value.trim();
      if (!url) { showToast("주소를 넣어 주세요"); return; }
      await postJSON(`/magazineapi/topics/${MAT_ID}/${MAT_SLOT}/link`,
        { url, name: document.getElementById("mat-name").value.trim() });
    } else {
      if (!PICKED_FILE) { showToast("파일을 골라 주세요"); return; }
      const fd = new FormData();
      fd.append("file", PICKED_FILE);
      await api(`/magazineapi/topics/${MAT_ID}/${MAT_SLOT}/file`, { method: "POST", body: fd });
    }
    showToast("자료를 올렸습니다");
    closeMaterial();
    await reload();
  } catch (e) { showToast(e.message); }
}

async function detachMaterial() {
  try {
    await api(`/magazineapi/topics/${MAT_ID}/${MAT_SLOT}`, { method: "DELETE" });
    showToast("자료를 삭제했습니다");
    closeMaterial();
    await reload();
  } catch (e) { showToast(e.message); }
}
function openViewer(id, slot = "material") {
  const t = APP.topics.find(x => x.id === id);
  if (!t || !slotOf(t, slot, "kind")) return;

  // 링크는 임베드를 막는 사이트가 많아 새 탭으로 연다.
  if (slotOf(t, slot, "kind") === "link") {
    window.open(slotOf(t, slot, "url"), "_blank", "noopener");
    return;
  }

  const name = slotName(t, slot);
  const src = `/magazineapi/topics/${id}/${slot}/download`;
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


let PICKED_FILE = null;

function setPickedFile(f) {
  PICKED_FILE = f || null;
  document.getElementById("mat-filename").textContent =
    PICKED_FILE ? PICKED_FILE.name : "형식 제한 없음";
  document.getElementById("mat-drop").classList.toggle("has", !!PICKED_FILE);
}

function initDropzone() {
  const dz = document.getElementById("mat-drop");
  const input = document.getElementById("mat-fileinput");
  input.addEventListener("change", () => setPickedFile(input.files[0]));

  ["dragenter", "dragover"].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault();
    dz.classList.add("over");
  }));
  ["dragleave", "dragend"].forEach(ev =>
    dz.addEventListener(ev, () => dz.classList.remove("over")));

  dz.addEventListener("drop", e => {
    e.preventDefault();
    dz.classList.remove("over");
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) { setMatMode("file"); setPickedFile(f); }
  });

  ["dragover", "drop"].forEach(ev => window.addEventListener(ev, e => {
    if (!e.target.closest || !e.target.closest("#mat-drop")) e.preventDefault();
  }));
}

initDropzone();
