let MAT_ID = null;
let MAT_MODE = "file";

const IMAGE_RE = /\.(png|jpe?g|gif|webp|bmp)$/i;
const PDF_RE = /\.pdf$/i;

/* 자료를 붙이거나 바꿀 수 있는 사람인가 (버튼 노출용) */
function canManageMaterial(t) {
  return APP.me.is_admin || (t.presenter_email && t.presenter_email === APP.me.email);
}

function materialChip(t) {
  if (!t.material_kind) return "";
  const name = t.material_name || (t.material_kind === "link" ? "링크" : "파일");
  const icon = t.material_kind === "link" ? "🔗" : "📎";
  return `<button class="chip material" onclick="openViewer(${t.id})"
            title="${esc(name)}">${icon} ${esc(name)}</button>`;
}

/* ---------- 등록 ---------- */
function openMaterial(id) {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  MAT_ID = id;
  document.getElementById("matCurrent").textContent = t.material_kind
    ? `현재: ${t.material_name || ""}`
    : "아직 올린 자료가 없습니다.";
  document.getElementById("mat-del").style.display = t.material_kind ? "" : "none";
  document.getElementById("mat-fileinput").value = "";
  document.getElementById("mat-url").value = t.material_kind === "link" ? t.material_url : "";
  document.getElementById("mat-name").value = t.material_kind === "link" ? (t.material_name || "") : "";
  setMatMode(t.material_kind === "link" ? "link" : "file");
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
      await postJSON(`/magazineapi/topics/${MAT_ID}/material/link`,
        { url, name: document.getElementById("mat-name").value.trim() });
    } else {
      const f = document.getElementById("mat-fileinput").files[0];
      if (!f) { showToast("파일을 골라 주세요"); return; }
      const fd = new FormData();
      fd.append("file", f);
      await api(`/magazineapi/topics/${MAT_ID}/material/file`, { method: "POST", body: fd });
    }
    showToast("자료를 올렸습니다");
    closeMaterial();
    await reload();
  } catch (e) { showToast(e.message); }
}

async function detachMaterial() {
  try {
    await api(`/magazineapi/topics/${MAT_ID}/material`, { method: "DELETE" });
    showToast("자료를 삭제했습니다");
    closeMaterial();
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 뷰어 ---------- */
function openViewer(id) {
  const t = APP.topics.find(x => x.id === id);
  if (!t || !t.material_kind) return;

  // 링크는 임베드를 막는 사이트가 많아 새 탭으로 연다.
  if (t.material_kind === "link") {
    window.open(t.material_url, "_blank", "noopener");
    return;
  }

  const name = t.material_name || "자료";
  const src = `/magazineapi/topics/${id}/material/download`;
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

/* 기본은 넓은 모달, 한 번 더 누르면 화면을 꽉 채운다.
   선택은 다음에 열 때도 유지된다. */
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
  document.getElementById("viewBody").innerHTML = "";   // iframe 정지
}
