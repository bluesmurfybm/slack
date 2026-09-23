/* slackai 관리 페이지 공용 JS (tags/repos/jobs/lessons/settings).
 *  - ADM.api(url, body?) : fetch JSON. POST 는 X-Requested-With: fetch + JSON 본문. 실패 시 Error(code/http/extra) throw.
 *  - 테마 토글(🌓)은 lists.php 와 동일(localStorage.ui_theme → html.dark/light). <head> 의 인라인 스크립트가 먼저 클래스를 붙인다.
 */
window.ADM = (function () {
  const $ = id => document.getElementById(id);
  const esc  = s => (s ?? "").toString().replace(/[&<>]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c]));
  const escA = s => esc(s).replace(/"/g, "&quot;");

  async function api(url, body) {
    const opt = body === undefined
      ? { cache: "no-store", headers: { "X-Requested-With": "fetch" } }
      : { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "fetch" }, body: JSON.stringify(body) };
    const res = await fetch(url, opt);
    let j = null;
    try { j = await res.json(); } catch (e) { j = null; }
    if (!j) {
      const err = new Error(res.status === 200 ? "응답 형식 오류" : `HTTP ${res.status}`);
      err.code = "http"; err.http = res.status; throw err;
    }
    if (!j.ok) {
      const err = new Error(j.message || j.error || "실패");
      err.code = j.error || "error"; err.http = res.status; err.extra = j; throw err;
    }
    return j;
  }

  let toastTimer = null;
  function toast(msg, bad) {
    let el = $("toast");
    if (!el) { el = document.createElement("div"); el.id = "toast"; el.className = "toast"; document.body.appendChild(el); }
    el.textContent = msg;
    el.classList.toggle("bad", !!bad);
    el.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove("show"), bad ? 4200 : 2200);
  }

  const pad = n => String(n).padStart(2, "0");
  /** 'YYYY-MM-DD HH:MM:SS' → 'MM-DD HH:MM' (오늘이면 HH:MM) */
  function fmtDt(s, full) {
    if (!s) return "";
    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return s;
    if (full) return `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}`;
    const now = new Date();
    const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    return (`${m[1]}-${m[2]}-${m[3]}` === today) ? `${m[4]}:${m[5]}` : `${m[2]}-${m[3]} ${m[4]}:${m[5]}`;
  }
  function fmtDur(sec) {
    if (sec === null || sec === undefined) return "";
    sec = Math.max(0, Math.round(sec));
    if (sec < 60) return sec + "s";
    if (sec < 3600) return Math.floor(sec / 60) + "m " + pad(sec % 60) + "s";
    return Math.floor(sec / 3600) + "h " + pad(Math.floor(sec % 3600 / 60)) + "m";
  }
  function fmtUsd(v, digits) {
    if (v === null || v === undefined || v === "") return "";
    const n = Number(v);
    if (!isFinite(n)) return "";
    return "$" + n.toFixed(digits === undefined ? (n >= 10 ? 2 : 3) : digits);
  }
  function fmtNum(v) { return (v === null || v === undefined) ? "" : Number(v).toLocaleString("ko-KR"); }

  /** 아주 작은 마크다운(굵게·코드·줄바꿈·불릿)만 — 노트 본문은 지시형 1~2문장이라 충분 */
  function md(src) {
    if (!src) return "";
    const blocks = String(src).replace(/\r/g, "").split(/```/);
    return blocks.map((b, i) => {
      if (i % 2 === 1) return `<pre>${esc(b.replace(/^\w*\n/, ""))}</pre>`;
      let h = esc(b);
      h = h.replace(/`([^`\n]+)`/g, "<code>$1</code>")
           .replace(/\*\*([^*\n]+)\*\*/g, "<b>$1</b>")
           .replace(/^(?:[-*]|\d+\.)\s+(.*)$/gm, "• $1");
      return h.replace(/\n/g, "<br>");
    }).join("");
  }

  function initTheme() {
    const b = $("themeBtn");
    if (!b) return;
    b.addEventListener("click", () => {
      const el = document.documentElement;
      const isDark = el.classList.contains("dark") || (!el.classList.contains("light") && matchMedia("(prefers-color-scheme: dark)").matches);
      el.classList.remove("dark", "light");
      el.classList.add(isDark ? "light" : "dark");
      localStorage.setItem("ui_theme", isDark ? "light" : "dark");
    });
  }
  document.addEventListener("DOMContentLoaded", initTheme);

  return { $, esc, escA, api, toast, fmtDt, fmtDur, fmtUsd, fmtNum, md };
})();
