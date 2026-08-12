const APP = { me: {}, topics: [] };   // 앱 상태 한 곳 — 도메인 스크립트는 여기만 읽고 쓴다

const esc = s => (s == null ? "" : String(s))
  .replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));

const USER_COLORS = {
  "김호영": { bg: "#F5D9D4", fg: "#A8392B" }, "박성철": { bg: "#F7E0CC", fg: "#B0642A" },
  "안정민": { bg: "#F4E8C6", fg: "#94741C" }, "조성훈": { bg: "#E7ECCB", fg: "#6C782C" },
  "진소현": { bg: "#D5E7D1", fg: "#3B6B40" }, "김태주": { bg: "#CEE8E0", fg: "#2C6D62" },
  "김지안": { bg: "#D1E4EE", fg: "#2A6184" }, "김아랑": { bg: "#D8DCF0", fg: "#3A46A0" },
  "박화랑": { bg: "#E3D9F0", fg: "#68429F" }, "유병문": { bg: "#EED7EC", fg: "#883C84" },
  "유승인": { bg: "#F3D7E1", fg: "#A83964" }, "이한재": { bg: "#E6DCD0", fg: "#78593B" },
  "이준영": { bg: "#DBDFE3", fg: "#485663" }
};
function colorFor(name) {
  if (USER_COLORS[name]) return USER_COLORS[name];
  let h = 0;
  for (const ch of (name || "?")) h = (h * 31 + ch.charCodeAt(0)) % 360;
  return { bg: `hsl(${h},32%,87%)`, fg: `hsl(${h},42%,33%)` };
}

async function api(path, opts) {
  const r = await fetch(path, Object.assign({ credentials: "same-origin" }, opts || {}));
  if (r.status === 401) {
    // 개발 모드에서는 "/" 가 401 이어도 화면을 내주므로 이동시키면 무한 새로고침이 된다.
    if (APP.me.dev_login) throw new Error("상단 개발 모드 바에서 계정을 선택해 주세요");
    location.href = "/";
    throw new Error("unauthenticated");
  }
  if (!r.ok) {
    let msg = "요청이 실패했습니다";
    try { msg = (await r.json()).detail || msg; } catch (e) { }
    throw new Error(msg);
  }
  return r.status === 204 ? null : r.json();
}

const postJSON = (path, body) => api(path, {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body || {}),
});
let toastT;
function showToast(m) {
  const el = document.getElementById("toast");
  el.textContent = m;
  el.classList.add("show");
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove("show"), 2200);
}
function openSheet() { document.getElementById("overlay").classList.add("open"); }
function closeSheet() { document.getElementById("overlay").classList.remove("open"); }
function closeForm() { closeSheet(); }
function closeConfirm() { document.getElementById("confirmOverlay").classList.remove("open"); }
let dateResolver = null;
function askDate(title, hint, initial) {
  document.getElementById("dateTitle").textContent = title;
  document.getElementById("dateHint").textContent = hint;
  document.getElementById("dateInput").value = initial || "";
  document.getElementById("dateOverlay").classList.add("open");
  setTimeout(() => document.getElementById("dateInput").focus(), 50);
  return new Promise(r => { dateResolver = r; });
}
function closeDate(v) {
  document.getElementById("dateOverlay").classList.remove("open");
  if (dateResolver) { const r = dateResolver; dateResolver = null; r(v); }
}
const today = () => new Date().toISOString().slice(0, 10);
