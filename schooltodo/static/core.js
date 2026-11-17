const APP = {
  boot: SCHOOLTODO_BOOT,
  sheets: [],
  sheetId: null,
  rows: [],
  filterStatus: "",
  editingSheetId: null,
};

async function api(path, opts) {
  const [p, qs] = String(path).split("?");
  const url = "api.php?p=" + encodeURIComponent(p) + (qs ? "&" + qs : "");
  const r = await fetch(url, Object.assign({ credentials: "same-origin" }, opts || {}));
  if (r.status === 401) {
    location.href = "../index.php?need_login=schooltodo";
    throw new Error("unauthenticated");
  }
  if (!r.ok) {
    let msg = "요청이 실패했습니다";
    try { msg = (await r.json()).detail || msg; } catch (e) { }
    throw new Error(msg);
  }
  return r.json();
}

const sendJSON = (method, path, body) => api(path, {
  method,
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body),
});

const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

const STATUS_CLASS = { "미조치": "st-todo", "진행중": "st-doing", "완료": "st-done", "해당없음": "st-excluded" };

function currentSheet() {
  return APP.sheets.find((s) => s.id === APP.sheetId) || null;
}

// 대상은 해당없음을 뺀 대학이다
function progressOf(counts) {
  const target = Object.values(counts).reduce((a, b) => a + b, 0) - counts[APP.boot.status_excluded];
  return { done: counts[APP.boot.status_done], target };
}

let toastT;
function showToast(m) {
  const el = document.getElementById("toast");
  el.textContent = m;
  el.classList.add("show");
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove("show"), 2200);
}

function toggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById("hdrUserMenu").classList.toggle("open");
}
document.addEventListener("click", (e) => {
  const um = document.getElementById("hdrUserMenu");
  if (um && !um.contains(e.target)) um.classList.remove("open");
});
