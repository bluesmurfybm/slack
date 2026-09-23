/* ============================================================================
 * slackai 🤖 AI 패널 — lists.php 가 ai/panel.php(마크업) 와 이 파일을 메인 인라인 스크립트 앞에 로드한다.
 *   전역: AI(객체), aiBadge(r), aiPanelShell(id), aiMd(md), aiDiffHtml(diff)
 *   서버: ai/ai_state.php(GET 상태 1회) · ai/ai_action.php(POST JSON {action, request_id, …}) · ai/ai_diff.php(전체 diff)
 *   lists.php 의 DATA/pal/openRecent/saveView 는 있을 때만 런타임에 참조한다(없어도 동작).
 *
 *  흐름: rowHtml() → aiPanelShell(id) 가 셸(캐시 있으면 완성 HTML)을 그리고, bindRows(box) → AI.bind(box) 가
 *       이벤트를 붙인다(캐시 없으면 AI.load). 잡이 queued/running 이면 5초 폴링(최대 120회), 그 외엔 status.php 의
 *       ai_changed_at 변화(AI.onStatus) 로만 갱신한다. 모든 버튼은 data-act 로 위임 처리(AI.onAct).
 * ========================================================================== */
(function () {
  "use strict";

  /* ---------------- 라벨/상수 ---------------- */
  const KIND = { ingest: "접수", triage: "분석", plan: "플랜", execute: "실행", commit: "커밋", review: "검토", learn: "학습", distill: "지식 정리", check_repo: "레포 점검", revert: "되돌리기", resync: "재동기화" };
  const JOB_ST = { queued: "대기", running: "진행 중", done: "완료", failed: "실패", cancelled: "취소" };
  const PLAN_ST = { draft: "검토 대기", approved: "승인됨", executing: "실행 중", executed: "실행 완료", committed: "커밋됨", reviewed: "검토 완료", rejected: "반려", superseded: "대체됨", failed: "실패" };
  const EXEC_ST = { queued: "대기", running: "실행 중", done: "완료", failed: "실패", cancelled: "취소", reverted: "되돌림" };
  const COMMIT_ST = { pending: "대기", committed: "커밋됨", pushed: "푸시됨", failed: "실패" };
  const LESSON_ST = { proposed: "제안", approved: "승인", rejected: "반려" };
  const PTYPE = { bug: "버그", feature: "기능", question: "질문", data: "데이터", ops: "운영", other: "기타" };
  const URG = { low: "낮음", normal: "보통", high: "높음", critical: "긴급" };
  const LKIND = { file_miss: "파일 누락", approach: "접근 방식", style: "코딩 규칙", test: "테스트", estimate: "예상 시간", tag_correction: "태그 교정", review_finding: "검토 지적", manual: "수동", other: "기타" };
  const SEV = { info: "정보", minor: "경미", major: "중요", critical: "치명" };
  // 상태 → 칩 색 (ok/warn/bad/info/muted)
  const ST_CLS = {
    draft: "warn", approved: "info", executing: "info", executed: "ok", committed: "ok", reviewed: "ok", rejected: "bad", superseded: "muted", failed: "bad",
    queued: "info", running: "info", done: "ok", cancelled: "muted", pending: "warn", pushed: "ok", reverted: "muted",
    pass: "ok", warn: "warn", fail: "bad", error: "muted", proposed: "warn", ok: "ok", unchecked: "muted",
  };
  const RETRYABLE = { triage: "triage", plan: "plan", review: "review", learn: "learn" };

  /* ---------------- 작은 헬퍼 ---------------- */
  const esc = (s) => (s ?? "").toString().replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const usd = (v) => (v == null || v === "" ? "—" : "$" + Number(v).toFixed(Number(v) > 0 && Number(v) < 0.01 ? 4 : 2));
  const dt = (s) => (s ? String(s).slice(0, 16).replace(/-/g, ".") : "—");          // "2026-09-22 10:31:00" → "2026.09.22 10:31"
  const ymd = (s) => (s ? String(s).slice(0, 10).replace(/-/g, ".") : "");
  const ago = (sec) => (sec == null ? "—" : sec < 60 ? sec + "초 전" : sec < 3600 ? Math.floor(sec / 60) + "분 전" : sec < 86400 ? Math.floor(sec / 3600) + "시간 전" : Math.floor(sec / 86400) + "일 전");
  const stars = (n) => { n = Math.max(0, Math.min(5, +n || 0)); return "★".repeat(n) + "☆".repeat(5 - n); };
  const tagStyle = (c) => { c = /^#[0-9a-f]{6}$/i.test(c || "") ? c : "#9aa0a6"; return `background:${c}22;color:${c};border-color:${c}66`; };
  const stChip = (st, label) => `<span class="ai-st ai-st-${ST_CLS[st] || "muted"}">${esc(label || st || "—")}</span>`;
  const btn = (act, label, o) => { o = o || {}; const dis = o.disabled ? ` disabled title="${esc(o.title || "")}"` : (o.title ? ` title="${esc(o.title)}"` : ""); const data = Object.entries(o.data || {}).map(([k, v]) => ` data-${k}="${esc(v)}"`).join(""); return `<button type="button" class="ai-btn${o.cls ? " " + o.cls : ""}" data-act="${act}"${data}${dis}>${label}</button>`; };
  const mi = (label, val) => `<div class="mi"><span class="ml">${esc(label)}</span><span class="mv">${val}</span></div>`;
  const det = (title, body, open) => `<details class="ai-det"${open ? " open" : ""}><summary>${title}</summary><div class="ai-det-b">${body}</div></details>`;
  // 플랜 모델: 자동 플랜은 Haiku(설정 plan_model_auto). 필요할 때만 사람이 Sonnet/Opus 로 따로 조회
  const PLAN_MODELS = [["haiku", "Haiku (기본 · 저렴)"], ["sonnet", "Sonnet (정밀)"], ["opus", "Opus (가장 깊게 · 고비용)"]];
  const modelSel = (u) => `<select class="ai-sel ai-plan-model" title="플랜 생성·재생성에 쓸 모델 (자동 플랜은 Haiku)">${PLAN_MODELS.map(([v, l]) => `<option value="${v}"${(u.model || "haiku") === v ? " selected" : ""}>🧠 ${l}</option>`).join("")}</select>`;
  const sec = (key, title, body, head) => `<section class="ai-sec ai-sec-${key}"><div class="ai-sec-h"><span>${title}</span>${head || ""}</div><div class="ai-sec-b">${body}</div></section>`;
  const md = (s) => `<div class="ai-md">${aiMd(s)}</div>`;
  const statusStyle = (st) => { const p = typeof window.pal === "function" ? window.pal(st) : null; return p ? `background:${p.bg};color:${p.fg}` : ""; };
  const listOf = (arr, f) => (Array.isArray(arr) && arr.length ? `<ul>${arr.map((x) => `<li>${f ? f(x) : aiMdInline(String(x))}</li>`).join("")}</ul>` : `<span class="ai-muted">—</span>`);
  const tip = (cond, text) => (cond ? text : "");

  /* ======================================================================
   * aiMd — 작은 안전 GFM 렌더러. HTML 을 먼저 이스케이프한 뒤 마크다운만 해석한다.
   *   지원: 펜스 코드, 제목(#~######), 목록(-,*,+,1.) 들여쓰기 중첩, 체크박스, 인용, 구분선, 표, 굵게/기울임/취소선/인라인 코드,
   *        링크 [t](url)(http/https/mailto/상대경로만) · 자동 링크. 단락 안 줄바꿈은 <br>.
   *   기존 lists.php 의 mrkdwn() 은 Slack 문법용이라 여기서 쓰지 않는다.
   * ==================================================================== */
  function aiMdInline(t) {
    let s = esc(t);
    const codes = [];
    s = s.replace(/`([^`]+)`/g, (m, c) => { codes.push(`<code>${c}</code>`); return `\u0000C${codes.length - 1}\u0000`; });
    s = s.replace(/\*\*([^*]+)\*\*/g, "<b>$1</b>").replace(/__([^_]+)__/g, "<b>$1</b>");
    s = s.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,;:!?]|$)/g, "$1<i>$2</i>");
    s = s.replace(/~~([^~]+)~~/g, "<s>$1</s>");
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, txt, url) => (/^(https?:\/\/|mailto:|\/|#|\.\.?\/)/i.test(url) ? `<a href="${url}" target="_blank" rel="noopener">${txt}</a>` : `${txt} <span class="ai-muted">(${url})</span>`));
    s = s.replace(/(^|[^"'>=\w])(https?:\/\/[^\s<]+?)(?=[.,;:)]*(?:\s|$|&lt;))/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
    s = s.replace(/\u0000C(\d+)\u0000/g, (m, n) => codes[+n]);
    return s;
  }
  function aiMd(input) {
    if (input == null || input === "") return "";
    let src = String(input).replace(/\r\n?/g, "\n");
    const blocks = [];
    src = src.replace(/```([^\n`]*)\n([\s\S]*?)```/g, (m, lang, code) => {
      blocks.push(`<pre class="ai-md-code"><code${lang.trim() ? ` data-lang="${esc(lang.trim())}"` : ""}>${esc(code.replace(/\n$/, ""))}</code></pre>`);
      return `\u0000B${blocks.length - 1}\u0000`;
    });
    const lines = src.split("\n");
    const out = [];
    let i = 0;
    const isList = (l) => /^\s*([-*+]|\d+[.)])\s+/.test(l);
    const isTableRow = (l) => /^\s*\|.*\|\s*$/.test(l);
    function list(baseIndent) {
      const first = lines[i].match(/^(\s*)([-*+]|\d+[.)])\s+/);
      const ordered = /\d/.test(first[2]);
      const items = [];
      while (i < lines.length) {
        const m = lines[i].match(/^(\s*)([-*+]|\d+[.)])\s+(.*)$/);
        if (!m) {
          if (items.length && /^\s+\S/.test(lines[i]) && lines[i].search(/\S/) > baseIndent) { items[items.length - 1].text += "<br>" + aiMdInline(lines[i].trim()); i++; continue; }
          break;
        }
        const ind = m[1].length;
        if (ind < baseIndent) break;
        if (ind > baseIndent) { if (items.length) items[items.length - 1].sub += list(ind); else items.push({ text: "", sub: list(ind) }); continue; }
        let text = m[3], cb = "";
        const t = text.match(/^\[([ xX])\]\s+(.*)$/);
        if (t) { cb = `<input type="checkbox" disabled${t[1] !== " " ? " checked" : ""}> `; text = t[2]; }
        items.push({ text: cb + aiMdInline(text), sub: "" });
        i++;
      }
      return `<${ordered ? "ol" : "ul"}>${items.map((it) => `<li>${it.text}${it.sub}</li>`).join("")}</${ordered ? "ol" : "ul"}>`;
    }
    while (i < lines.length) {
      const l = lines[i];
      let m;
      if (/^\s*$/.test(l)) { i++; continue; }
      if ((m = l.match(/^\s*\u0000B(\d+)\u0000\s*$/))) { out.push(blocks[+m[1]]); i++; continue; }
      if ((m = l.match(/^(#{1,6})\s+(.*)$/))) { const lv = Math.min(6, m[1].length + 2); out.push(`<h${lv}>${aiMdInline(m[2].replace(/\s+#+\s*$/, ""))}</h${lv}>`); i++; continue; }
      if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(l)) { out.push("<hr>"); i++; continue; }
      if (/^\s*>/.test(l)) { const q = []; while (i < lines.length && /^\s*>/.test(lines[i])) { q.push(lines[i].replace(/^\s*>\s?/, "")); i++; } out.push(`<blockquote>${aiMd(q.join("\n"))}</blockquote>`); continue; }
      if (isTableRow(l) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
        const rows = []; while (i < lines.length && isTableRow(lines[i])) { rows.push(lines[i]); i++; }
        const cells = (r) => r.trim().replace(/^\||\|$/g, "").split("|").map((c) => c.trim());
        const head = cells(rows[0]), body = rows.slice(2).map(cells);
        out.push(`<table><thead><tr>${head.map((c) => `<th>${aiMdInline(c)}</th>`).join("")}</tr></thead><tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${aiMdInline(c)}</td>`).join("")}</tr>`).join("")}</tbody></table>`);
        continue;
      }
      if ((m = l.match(/^(\s*)([-*+]|\d+[.)])\s+/))) { out.push(list(m[1].length)); continue; }
      const p = [];
      while (i < lines.length && !/^\s*$/.test(lines[i]) && !/^#{1,6}\s/.test(lines[i]) && !isList(lines[i]) && !/^\s*>/.test(lines[i]) && !/^\s*\u0000B/.test(lines[i]) && !isTableRow(lines[i])) { p.push(lines[i]); i++; }
      if (p.length) out.push(`<p>${p.map(aiMdInline).join("<br>")}</p>`);
    }
    return out.join("");
  }

  /* ======================================================================
   * aiDiffHtml — unified diff 를 파일별 <details> 로. `diff --git`(git) / `Index:`(svn) 헤더로 나눈다.
   *   줄마다 .dl + (.d-add/.d-del/.d-hunk/.d-meta). 복사 버튼은 pre.innerText 를 쓴다(블록 span → 줄바꿈 복원).
   * ==================================================================== */
  function aiDiffHtml(diff) {
    if (!diff) return '<div class="ai-muted">diff 없음</div>';
    const lines = String(diff).replace(/\r\n?/g, "\n").split("\n");
    if (lines.length && lines[lines.length - 1] === "") lines.pop();
    const files = [];
    let cur = null;
    const start = (name) => { cur = { name, lines: [], add: 0, del: 0 }; files.push(cur); };
    for (const l of lines) {
      let m;
      if ((m = l.match(/^diff --git a\/(.+?) b\/(.+)$/))) { start(m[2]); cur.lines.push(l); continue; }
      if ((m = l.match(/^Index: (.+)$/))) { start(m[1]); cur.lines.push(l); continue; }
      if (!cur) start("(header)");
      cur.lines.push(l);
      if (/^\+/.test(l) && !/^\+\+\+ /.test(l)) cur.add++;
      else if (/^-/.test(l) && !/^--- /.test(l)) cur.del++;
    }
    const cls = (l) => /^@@/.test(l) ? "d-hunk"
      : /^(\+\+\+ |--- |diff --git|Index: |={5,}|index [0-9a-f]|new file|deleted file|rename |similarity |Property changes|Added: |Deleted: |Modified: |## |\\ No newline)/.test(l) ? "d-meta"
      : /^\+/.test(l) ? "d-add" : /^-/.test(l) ? "d-del" : "";
    return `<div class="ai-diff">${files.map((f) => `<details class="ai-diff-file"${files.length <= 6 ? " open" : ""}><summary><span class="ai-diff-name" title="${esc(f.name)}">${esc(f.name)}</span><span class="ai-diff-stat"><span class="d-add">+${f.add}</span> <span class="d-del">−${f.del}</span></span><button type="button" class="ai-btn ai-btn-xs" data-act="diff_copy" title="이 파일 diff 복사">📋</button></summary><pre class="ai-diff-pre">${f.lines.map((l) => `<span class="dl ${cls(l)}">${esc(l)}</span>`).join("")}</pre></details>`).join("")}</div>`;
  }

  /* ======================================================================
   * 목록 행 배지 / 패널 셸
   * ==================================================================== */
  function aiBadge(r) {
    if (!r) return "";
    const out = [];
    if (r.ai_job_kind) out.push(`<span class="ai-b ai-b-job" title="AI ${esc(KIND[r.ai_job_kind] || r.ai_job_kind)} ${esc(JOB_ST[r.ai_job_status] || r.ai_job_status || "")}">◌</span>`);
    if (r.ai_summary) out.push(`<span class="ai-b ai-b-on" title="${esc(r.ai_summary)}${r.ai_difficulty ? ` · 난이도 ${stars(r.ai_difficulty)}` : ""}">●</span>`);
    else out.push('<span class="ai-b ai-b-off" title="AI 분석 전">○</span>');
    if (r.ai_plan_status) out.push(`<span class="ai-b ai-st ai-st-${ST_CLS[r.ai_plan_status] || "muted"}" title="플랜 v${r.ai_plan_version} ${esc(PLAN_ST[r.ai_plan_status] || r.ai_plan_status)}">📝 v${r.ai_plan_version} ${esc(PLAN_ST[r.ai_plan_status] || r.ai_plan_status)}</span>`);
    const tags = Array.isArray(r.ai_tags) ? r.ai_tags : [];
    tags.slice(0, 2).forEach((t) => out.push(`<span class="ai-b ai-tag" style="${tagStyle(t.color)}" title="${esc(t.name)}">${esc(String(t.name).split("/").pop())}</span>`));
    if (tags.length > 2) out.push(`<span class="ai-b ai-tag ai-tag-more" title="${esc(tags.slice(2).map((t) => t.name).join(", "))}">+${tags.length - 2}</span>`);
    return `<span class="ai-badges">${out.join("")}</span>`;
  }
  function aiPanelShell(id) {
    const s = AI.cache[id];
    return `<div class="ai-panel" id="ai-${esc(id)}" data-id="${esc(id)}">${s ? AI.html(s) : '<div class="ai-loading"><span class="ai-spin">◌</span> AI 상태 불러오는 중…</div>'}</div>`;
  }

  /* ======================================================================
   * AI 객체
   * ==================================================================== */
  const AI = {
    cache: {},            // request_id → ai_state 응답
    ui: {},               // request_id → 패널 UI 상태 {planVersion, tagsOpen, tagSel, simOpen, simAll, revertOpen, hint}
    _inflight: {},
    _lastChanged: null,
    _poll: { id: null, jobId: null, timer: null, n: 0 },
    _toastT: null,

    el(id) { return document.getElementById("ai-" + id); },
    u(id) { return this.ui[id] || (this.ui[id] = { simOpen: {} }); },

    /* ---- 로드/렌더 ---- */
    load(id, force) {
      if (!id) return Promise.resolve(null);
      if (this._inflight[id]) return this._inflight[id];
      if (!force && this.cache[id]) { this.render(id); return Promise.resolve(this.cache[id]); }
      const u = this.u(id);
      const url = "ai/ai_state.php?id=" + encodeURIComponent(id) + (u.planVersion ? "&plan_version=" + u.planVersion : "") + "&_=" + Date.now();
      const p = fetch(url, { cache: "no-store", headers: { "X-Requested-With": "fetch" } })
        .then((r) => r.json())
        .then((s) => {
          if (!s || s.ok === false) { const el = this.el(id); if (el) el.innerHTML = `<div class="ai-err">⚠️ ${esc((s && s.message) || "AI 상태를 불러오지 못했습니다")}</div>`; return null; }
          if (u.planVersion && !(s.plan && s.plan.version === u.planVersion)) u.planVersion = null;
          this.cache[id] = s;
          this.render(id);
          this.syncRow(id, s);
          this.schedulePoll(id, s);
          return s;
        })
        .catch((e) => { const el = this.el(id); if (el) el.innerHTML = `<div class="ai-err">⚠️ ${esc(e.message)}</div>`; return null; })
        .finally(() => { delete this._inflight[id]; });
      this._inflight[id] = p;
      return p;
    },
    render(id) {
      const el = this.el(id), s = this.cache[id];
      if (!el || !s) return;
      const y = el.querySelector(".ai-diff-pre") ? null : null;   // (스크롤 보존은 details 상태로 충분)
      el.innerHTML = this.html(s);
      this.bindPanel(el);
      void y;
    },
    /** lists.php bindRows(box) 끝에서 호출: 재렌더된 셸에 이벤트 바인딩(캐시 없으면 로드) */
    bind(box) {
      if (!box || !box.querySelectorAll) return;
      box.querySelectorAll(".ai-panel").forEach((el) => {
        const id = el.dataset.id;
        if (this.cache[id]) { if (!el.dataset.bound) this.bindPanel(el); }
        else this.load(id);
      });
    },
    /** 목록 행(DATA) 의 배지 필드를 최신 상태로 맞추고, 열린 행의 배지 DOM 도 바꿔 준다 */
    syncRow(id, s) {
      const D = window.DATA;
      const r = Array.isArray(D) ? D.find((x) => x.id === id) : null;
      if (!r) return;
      r.ai_summary = s.triage && !s.triage.stub ? s.triage.summary_short : null;
      r.ai_difficulty = s.triage ? s.triage.difficulty : null;
      r.ai_repo_id = s.triage ? s.triage.repo_id : null;
      const latest = s.plan && s.plan.versions && s.plan.versions[0];
      r.ai_plan_status = latest ? latest.status : null;
      r.ai_plan_version = latest ? latest.version : null;
      r.ai_job_kind = s.job ? s.job.kind : null;
      r.ai_job_status = s.job ? s.job.status : null;
      r.ai_tags = (s.tags && s.tags.assigned || []).map((t) => ({ id: t.id, name: t.name, color: t.color }));
      document.querySelectorAll(`.row[data-id="${(window.CSS && CSS.escape) ? CSS.escape(id) : id}"] .ai-badges`).forEach((b) => { b.outerHTML = aiBadge(r); });
    },

    /* ---- 폴링/상태 ---- */
    schedulePoll(id, s) {
      const p = this._poll;
      const open = s && s.job && (s.job.status === "queued" || s.job.status === "running");
      if (p.timer) { clearTimeout(p.timer); p.timer = null; }
      if (!open) { p.id = null; p.jobId = null; p.n = 0; return; }
      if (p.id !== id || p.jobId !== s.job.id) { p.id = id; p.jobId = s.job.id; p.n = 0; }
      if (p.n >= 120) return;                                   // 10분 넘으면 status.php(ai_changed_at) 에만 의존
      p.timer = setTimeout(() => { p.n++; if (this.el(id)) this.load(id, true); else { p.id = null; } }, 5000);
    },
    /** lists.php pollStatus(): ai_changed_at 이 늘면 열린 패널만 다시 읽는다 */
    onStatus(st) {
      if (!st) return;
      const v = +st.ai_changed_at || 0;
      if (this._lastChanged === null) { this._lastChanged = v; return; }
      if (v > this._lastChanged) { this._lastChanged = v; document.querySelectorAll(".ai-panel").forEach((el) => this.load(el.dataset.id, true)); }
    },

    /* ---- 서버 액션 ---- */
    async act(action, id, extra, opts) {
      opts = opts || {};
      let res, j;
      try {
        res = await fetch("ai/ai_action.php", { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "fetch" }, body: JSON.stringify(Object.assign({ action, request_id: id }, extra || {})) });
        j = await res.json();
      } catch (e) { this.toast("⚠️ 요청 실패: " + e.message, true); return null; }
      if (!j || j.ok === false) {
        this.toast("⚠️ " + ((j && j.message) || ("오류 " + res.status)) + (j && j.job_id ? ` (잡 #${j.job_id})` : ""), true);
        if (res.status === 409 || res.status === 422) this.load(id, true);   // 상태가 어긋났으면 최신으로
        return null;
      }
      if (!opts.quiet) this.toast(opts.msg || "✅ 처리했습니다.");
      await this.load(id, true);
      return j;
    },
    toast(msg, isErr) {
      let t = document.getElementById("aiToast");
      if (!t) { t = document.createElement("div"); t.id = "aiToast"; document.body.appendChild(t); }
      t.textContent = msg; t.className = isErr ? "err" : ""; t.hidden = false;
      clearTimeout(this._toastT); this._toastT = setTimeout(() => { t.hidden = true; }, isErr ? 7000 : 2500);
    },

    /* ---- 확인 모달: {title, rows:[[label, html]], files:[], body(html), warn, edit:{label,type,value,rows,min,max,step,hint,required}, checkbox, ok, okClass} → Promise<{value, checked}|null> ---- */
    confirm(o) {
      return new Promise((resolve) => {
        const ov = document.getElementById("aiConfirm");
        if (!ov) { resolve(window.confirm((o.title || "") + "\n\n진행할까요?") ? { value: o.edit ? o.edit.value : null, checked: true } : null); return; }
        const box = ov.querySelector(".ai-cf-body"), okBtn = ov.querySelector(".ai-cf-ok"), cancel = ov.querySelector(".ai-cf-cancel"), x = ov.querySelector(".ai-cf-x");
        ov.querySelector(".ai-cf-title").textContent = o.title || "확인";
        let html = "";
        if (o.rows && o.rows.length) html += `<table class="ai-cf-rows">${o.rows.map(([k, v]) => `<tr><th>${esc(k)}</th><td>${v}</td></tr>`).join("")}</table>`;
        if (o.files && o.files.length) html += `<div class="ai-cf-sec">수정 파일 ${o.files.length}개</div><ul class="ai-cf-files">${o.files.slice(0, 20).map((f) => `<li><code>${esc(f)}</code></li>`).join("")}${o.files.length > 20 ? `<li class="ai-muted">… 외 ${o.files.length - 20}개</li>` : ""}</ul>`;
        if (o.body) html += `<div class="ai-cf-sec">${o.body}</div>`;
        if (o.warn) html += `<div class="ai-cf-warn">⚠️ ${o.warn}</div>`;
        if (o.edit) {
          const e = o.edit;
          html += `<label class="ai-cf-edit"><span>${esc(e.label || "")}</span>${e.type === "textarea"
            ? `<textarea class="ai-cf-input" rows="${e.rows || 6}">${esc(e.value ?? "")}</textarea>`
            : `<input class="ai-cf-input" type="${e.type || "text"}" value="${esc(e.value ?? "")}"${e.min != null ? ` min="${e.min}"` : ""}${e.max != null ? ` max="${e.max}"` : ""}${e.step ? ` step="${e.step}"` : ""}>`}${e.hint ? `<small>${esc(e.hint)}</small>` : ""}</label>`;
        }
        if (o.checkbox) html += `<label class="ai-cf-check"><input type="checkbox" class="ai-cf-cb"> ${esc(o.checkbox)}</label>`;
        box.innerHTML = html;
        okBtn.textContent = o.ok || "확인";
        okBtn.className = "ai-cf-ok primary" + (o.okClass ? " " + o.okClass : "");
        const cb = box.querySelector(".ai-cf-cb"), input = box.querySelector(".ai-cf-input");
        const need = () => (cb && !cb.checked) || (input && o.edit.required && !(input.value || "").trim());
        okBtn.disabled = need();
        if (cb) cb.addEventListener("change", () => { okBtn.disabled = need(); });
        if (input) input.addEventListener("input", () => { okBtn.disabled = need(); });
        const done = (val) => { ov.hidden = true; document.removeEventListener("keydown", onKey, true); okBtn.onclick = cancel.onclick = x.onclick = ov.onclick = null; resolve(val); };
        const onKey = (e) => { if (e.key === "Escape") { e.stopImmediatePropagation(); e.preventDefault(); done(null); } };
        document.addEventListener("keydown", onKey, true);
        okBtn.onclick = () => { if (!okBtn.disabled) done({ value: input ? input.value : null, checked: cb ? cb.checked : true }); };
        cancel.onclick = x.onclick = () => done(null);
        ov.onclick = (e) => { if (e.target === ov) done(null); };
        ov.hidden = false;
        setTimeout(() => { (input || cb || okBtn).focus(); if (input && input.select && o.edit.type !== "textarea") input.select(); }, 30);
      });
    },

    /* ======================================================================
     * 렌더 — 섹션 (a)~(i)
     * ==================================================================== */
    html(s) {
      const id = s.request_id, u = this.u(id);
      const parts = [this.hHead(s), this.hJob(s)];
      if (!s.triage || s.triage.stub) parts.push(this.hStart(s));               // (a)
      else parts.push(this.hTriage(s, u));                                       // (b)
      parts.push(this.hRepo(s));                                                 // (c)
      if ((s.triage && !s.triage.stub) || s.plan) parts.push(this.hPlan(s, u));  // (d)
      if (s.execution) parts.push(this.hExec(s, u));                             // (e)
      if (s.commit) parts.push(this.hCommit(s));                                 // (f)
      if (s.review) parts.push(this.hReview(s));                                 // (g)
      if (s.lessons && s.lessons.length) parts.push(this.hLessons(s));           // (h)
      parts.push(this.hJobs(s));                                                 // (i)
      return parts.join("");
    },
    pickModel(id, u) { const sl = this.el(id) && this.el(id).querySelector(".ai-plan-model"); u.model = (sl && sl.value) || u.model || "haiku"; return u.model; },
    busy(s) { return !!(s.job && (s.job.status === "queued" || s.job.status === "running")); },
    busyTip(s) { return this.busy(s) ? `${KIND[s.job.kind] || s.job.kind} 작업이 ${JOB_ST[s.job.status]} 입니다` : ""; },

    hHead(s) {
      return `<div class="ai-head">🤖 AI <span class="ai-muted">${esc(s.request_id)}</span><span class="ai-sp"></span>
        <span class="ai-muted" title="이 문의에 쓴 LLM 비용 합계(잡 기준)">💵 ${usd(s.cost_total)}</span>
        <span class="ai-muted" title="${esc(s.me)}">${s.can_approve ? "🔑 승인자" : "👁 조회 전용"}${s.is_admin ? " · 관리자" : ""}</span>
        ${btn("refresh", "↻", { cls: "ai-btn-xs", title: "다시 읽기" })}</div>`;
    },
    hJob(s) {
      const j = s.job;
      if (j) {
        const kind = esc(KIND[j.kind] || j.kind);
        if (j.status === "queued") {
          return `<div class="ai-banner q">⏳ <b>${kind}</b> 대기 중 <small>#${j.id} · ${ago(j.queued_age)} 등록${j.attempts > 0 ? ` · 재시도 ${j.attempts}회` : ""}${j.progress ? ` · ${esc(j.progress)}` : ""}</small><span class="ai-sp"></span>${btn("cancel_job", "취소", { cls: "ai-btn-xs", data: { jid: j.id } })}</div>`;
        }
        const staleSec = (s.settings && s.settings.heartbeat_stale_sec) || 120;
        const stale = !!j.stale || (j.hb_age != null && j.hb_age > staleSec);
        return `<div class="ai-banner r${stale ? " stale" : ""}">${stale ? "⚠️" : '<span class="ai-spin">◌</span>'} <b>${kind}</b> ${stale ? `워커 응답 없음 <small>heartbeat ${ago(j.hb_age)} (기준 ${staleSec}초) — 워커 상태를 확인하세요</small>` : `진행 중${j.progress ? ` — ${esc(j.progress)}` : ""} <small>#${j.id} · heartbeat ${ago(j.hb_age)}${j.started_at ? ` · 시작 ${dt(j.started_at)}` : ""}</small>`}
          <span class="ai-sp"></span>${j.cancel_requested ? '<small>취소 요청됨…</small>' : btn("cancel_job", "취소", { cls: "ai-btn-xs", data: { jid: j.id } })}</div>`;
      }
      const last = s.jobs && s.jobs[0];
      if (last && last.status === "failed") {
        const rk = RETRYABLE[last.kind];
        return `<div class="ai-banner f">❌ <b>${esc(KIND[last.kind] || last.kind)}</b> 실패 <small>#${last.id} · ${dt(last.finished_at)}</small><span class="ai-sp"></span><span class="ai-muted" style="flex-basis:100%">${esc(String(last.error || "").slice(0, 400))}</span>${rk ? btn("retry_job", "↻ 재시도", { cls: "ai-btn-xs", data: { kind: rk } }) : ""}</div>`;
      }
      if (last && last.status === "cancelled") return `<div class="ai-banner c">⏹ ${esc(KIND[last.kind] || last.kind)} 취소됨 <small>#${last.id} · ${dt(last.finished_at)}</small></div>`;
      return "";
    },
    /* (a) 분석 전 */
    hStart(s) {
      const b = this.busy(s);
      return sec("start", "🤖 AI 분석", `<div class="ai-muted" style="margin-bottom:6px">요약 · 문제 유형/긴급도/난이도 · 태그 · 대상 레포 · 유사 과거 문의를 정리합니다.${s.triage && s.triage.stub ? " (레포만 지정된 상태)" : ""}</div>
        <div class="ai-btns">${btn("triage", "🤖 AI 분석 시작", { cls: "primary", disabled: b, title: this.busyTip(s) })}</div>`);
    },
    /* (b) 요약·태그·유사 */
    hTriage(s, u) {
      const t = s.triage;
      const meta = [
        t.problem_type ? mi("유형", esc(PTYPE[t.problem_type] || t.problem_type)) : "",
        t.urgency ? mi("긴급도", `<span class="ai-st ai-st-${t.urgency === "critical" || t.urgency === "high" ? "bad" : t.urgency === "low" ? "muted" : "info"}">${esc(URG[t.urgency] || t.urgency)}</span>`) : "",
        t.difficulty ? mi("난이도", `<span title="${t.difficulty}/5">${stars(t.difficulty)}</span>`) : "",
        t.affected_area ? mi("영역", esc(t.affected_area)) : "",
        mi("분석", `v${t.version} · ${dt(t.updated_at)}${t.model ? ` · ${esc(t.model)}` : ""}${t.cost_usd ? ` · ${usd(t.cost_usd)}` : ""}`),
      ].join("");
      const q = Array.isArray(t.questions) && t.questions.length ? `<div class="ai-warn">❓ 확인 필요<ul style="margin:4px 0 0;padding-left:18px">${t.questions.map((x) => `<li>${aiMdInline(String(typeof x === "string" ? x : (x.text || JSON.stringify(x))))}</li>`).join("")}</ul></div>` : "";
      const body = `<div class="ai-meta">${meta}</div>
        <div class="ai-sub ai-sub-summary">${t.summary_md ? md(t.summary_md) : `<p>${esc(t.summary_short || "")}</p>`}</div>${q}
        <div class="ai-sub"><div class="ai-sub-h">🏷️ 태그</div>${this.hTags(s, u)}</div>
        <div class="ai-sub"><div class="ai-sub-h">🔍 유사 과거 문의 <span class="ai-muted">LLM 상위 ${s.similar.filter((x) => x.rank > 0).length}건 · 후보 ${s.similar.filter((x) => x.rank === 0).length}건</span></div>${this.hSimilar(s, u)}</div>`;
      const head = `<span class="ai-sp"></span>${btn("retriage", "↻ 재분석", { cls: "ai-btn-xs", disabled: this.busy(s), title: this.busyTip(s) || "요약·태그·유사 사례를 다시 만듭니다 (사용자 태그는 유지)" })}`;
      return sec("triage", "📋 요약", body, head);
    },
    hTags(s, u) {
      const assigned = (s.tags && s.tags.assigned) || [], all = (s.tags && s.tags.all) || [];
      const srcIcon = (t) => (t.source === "user" ? "👤" : t.source === "ai" ? "🤖" : "⚙");
      const srcTip = (t) => (t.source === "user" ? "사용자 지정" + (t.set_by ? ` (${t.set_by})` : "") : t.source === "ai" ? "AI 제안" + (t.confidence != null ? ` ${Math.round(t.confidence * 100)}%` : "") : "규칙");
      let html = `<div class="ai-tags">🏷️ ${assigned.length ? assigned.map((t) => `<span class="ai-tag" style="${tagStyle(t.color)}" title="${esc(t.name)} · ${esc(srcTip(t))}">${esc(t.name)}<span class="ai-tag-src">${srcIcon(t)}</span></span>`).join("") : '<span class="ai-muted">태그 없음</span>'}
        ${u.tagsOpen ? "" : btn("tags_edit", "✏️ 수정", { cls: "ai-btn-xs", title: "태그를 고치면 source=user 로 남고 재분류 학습 신호가 됩니다" })}</div>`;
      const sug = s.triage && s.triage.suggested_new_tags;
      if (Array.isArray(sug) && sug.length) html += `<div class="ai-muted" style="margin-top:4px">💡 새 태그 제안: ${sug.map((x) => `<b>${esc(typeof x === "string" ? x : (x.name || x.slug || JSON.stringify(x)))}</b>${x && x.reason ? ` (${esc(x.reason)})` : ""}`).join(", ")} → <a href="tags/tags.php" target="_blank" rel="noopener">🏷️ 태그 관리</a></div>`;
      if (u.tagsOpen) {
        const sel = u.tagSel || (u.tagSel = new Set(assigned.map((t) => t.id)));
        const parents = all.filter((t) => !t.parent_id);
        const kids = (pid) => all.filter((t) => t.parent_id === pid);
        const chip = (t, isParent) => `<label class="ai-tp${sel.has(t.id) ? " on" : ""}${isParent ? " parent" : ""}" style="${sel.has(t.id) ? tagStyle(t.color) : ""}"><input type="checkbox" class="ai-tag-cb" value="${t.id}"${sel.has(t.id) ? " checked" : ""}>${esc(isParent || !String(t.name).includes("/") ? t.name : String(t.name).split("/").slice(1).join("/"))}</label>`;
        const orphans = all.filter((t) => t.parent_id && !parents.some((p) => p.id === t.parent_id));
        const list = parents.map((p) => `<div class="ai-tagpick-grp">${chip(p, true)}${kids(p.id).map((k) => chip(k, false)).join("")}</div>`).join("") + (orphans.length ? `<div class="ai-tagpick-grp">${orphans.map((k) => chip(k, false)).join("")}</div>` : "");
        const tpl = document.getElementById("aiTagPickerTpl");
        html += tpl ? tpl.innerHTML.replace("{{list}}", list) : `<div class="ai-tagpick"><div class="ai-tagpick-list">${list}</div><div class="ai-btns">${btn("tags_cancel", "취소")}${btn("save_tags", "💾 태그 저장", { cls: "primary" })}</div></div>`;
      }
      return html;
    },
    hSimilar(s, u) {
      const top = s.similar.filter((x) => x.rank > 0), cand = s.similar.filter((x) => x.rank === 0);
      if (!top.length && !cand.length) return '<div class="ai-muted" style="margin-top:4px">유사 문의 없음</div>';
      const row = (x) => {
        const score = x.score_llm != null ? x.score_llm : x.score_tfidf;
        const pct = Math.max(0, Math.min(100, Math.round((score || 0) * 100)));
        const open = !!(u.simOpen && u.simOpen[x.similar_id]);
        const inList = Array.isArray(window.DATA) && window.DATA.some((r) => r.id === x.similar_id);
        return `<div class="ai-sim${open ? " open" : ""}">
          <div class="ai-sim-h"><span class="ai-sim-rank${x.rank > 0 ? "" : " c"}">${x.rank > 0 ? x.rank : "·"}</span>
            <span class="ai-sim-bar" title="LLM ${x.score_llm != null ? x.score_llm.toFixed(2) : "—"} · TF-IDF ${x.score_tfidf != null ? x.score_tfidf.toFixed(2) : "—"}"><i style="width:${pct}%"></i></span>
            <span class="ai-sim-title" title="${esc(x.title)}">${esc(x.title)}</span>
            ${x.status ? `<span class="st" style="${statusStyle(x.status)}">${esc(x.status)}</span>` : ""}${x.archived ? '<span class="ai-chip">🗄️ 보관</span>' : ""}
            <span class="ai-muted">${x.done ? "완료 " + ymd(x.done) : ""}${x.asg && x.asg !== "—" ? " · " + esc(x.asg) : ""}${x.cmt_count ? ` · 💬${x.cmt_count}` : ""}</span>
            ${btn("sim_toggle", (open ? "▾" : "▸") + " 처리내용", { cls: "ai-btn-xs", data: { sid: x.similar_id } })}${btn("sim_open", "열기", { cls: "ai-btn-xs", data: { sid: x.similar_id, inlist: inList ? 1 : 0 }, title: inList ? "이 목록에서 열기" : "유사 이력 페이지에서 열기(보관/타 보드)" })}</div>
          ${open ? `<div class="ai-sim-b">${x.why_similar ? `<div class="ai-sim-why">🔗 ${aiMdInline(x.why_similar)}</div>` : ""}${x.resolution ? md(x.resolution) : '<span class="ai-muted">처리 내용 미확인</span>'}</div>` : ""}</div>`;
      };
      let html = top.map(row).join("");
      if (cand.length) {
        if (u.simAll) html += cand.map(row).join("") + `<div class="ai-btns">${btn("sim_less", "후보 접기", { cls: "ai-btn-xs" })}</div>`;
        else html += `<div class="ai-btns">${btn("sim_more", `TF-IDF 후보 ${cand.length}건 더 보기`, { cls: "ai-btn-xs" })}</div>`;
      }
      return html;
    },
    /* (c) 레포 */
    hRepo(s) {
      const r = s.repo || {}, res = r.resolved, all = r.all || [];
      const reasonTxt = { lms_url: "LMS URL → 학교 매핑", url_pattern: "URL 패턴 규칙", title_customer: "제목의 고객사명", llm: "LLM 추정", user: "사용자 지정" };
      let body = "";
      if (res) {
        body += `<div class="ai-repo">📁 <b>${esc(res.name)}</b> <span class="ai-chip">${esc(res.vcs)}</span> <code class="ai-code" title="워커 호스트의 작업 사본">${esc(res.local_path)}</code>
          ${stChip(res.check_status || "unchecked", "점검 " + (res.check_status || "unchecked"))}${res.head_revision ? `<span class="ai-muted">r${esc(res.head_revision)}</span>` : ""}${!res.active ? '<span class="ai-st ai-st-bad">비활성</span>' : ""}
          <span class="ai-muted">${esc(reasonTxt[r.reason] || r.reason || "")}${r.confidence != null ? ` ${Math.round(r.confidence * 100)}%` : ""}</span></div>`;
        if (res.check_status !== "ok") body += `<div class="ai-warn">레포 점검 상태가 <b>${esc(res.check_status || "unchecked")}</b> 입니다${res.check_message ? ` — ${esc(res.check_message)}` : ""}. <a href="repos/repos.php" target="_blank" rel="noopener">📁 레포 매핑</a>에서 [점검] 을 통과해야 실행을 승인할 수 있습니다.</div>`;
      } else {
        body += `<div class="ai-warn">⚠️ 대상 레포가 확정되지 않았습니다. 아래에서 선택해야 플랜을 만들 수 있습니다.</div>`;
      }
      const cands = (r.candidates || []).filter((c) => !res || c.id !== res.id);
      if (cands.length) body += `<div class="ai-btns"><span class="ai-muted">후보:</span>${cands.map((c) => btn("repo_cand", `${esc(c.name)}${c.score != null ? ` ${Math.round(c.score * 100)}%` : ""}`, { cls: "ai-btn-xs", data: { rid: c.id }, title: c.reason || "" })).join("")}</div>`;
      body += `<div class="ai-btns"><select class="ai-sel ai-repo-sel"${this.busy(s) ? " disabled" : ""}><option value="0">${res ? "(레포 해제)" : "(레포 선택)"}</option>${all.map((x) => `<option value="${x.id}"${res && res.id === x.id ? " selected" : ""}>${esc(x.name)} · ${esc(x.vcs)}${x.check_status && x.check_status !== "ok" ? ` (${esc(x.check_status)})` : ""}</option>`).join("")}</select>
        <a href="repos/repos.php" target="_blank" rel="noopener" class="ai-muted">📁 레포 매핑 관리</a>${all.length === 0 ? '<span class="ai-warn" style="margin:0">등록된 레포가 없습니다 — 먼저 레포를 등록하세요.</span>' : ""}</div>`;
      return sec("repo", "📁 대상 레포", body);
    },
    /* (d) 플랜 */
    hPlan(s, u) {
      const p = s.plan, res = s.repo && s.repo.resolved, b = this.busy(s);
      const triOk = s.triage && !s.triage.stub;
      const mkTip = !triOk ? "먼저 AI 분석이 필요합니다" : !res ? "대상 레포를 먼저 선택하세요" : this.busyTip(s);
      if (!p) {
        return sec("plan", "📝 플랜", `<div class="ai-muted" style="margin-bottom:6px">Claude Code 가 작업 사본을 <b>읽기 전용</b>으로 조사해 원인·접근·수정 파일·단계·리스크·테스트 계획을 만듭니다 (예산 상한 적용).</div>
          <div class="ai-btns"><input class="ai-inp ai-plan-hint" placeholder="힌트(선택): 원인 추정, 참고 파일, 제약 …" value="${esc(u.hint || "")}">${modelSel(u)}${btn("plan", "📝 플랜 생성", { cls: "primary", disabled: !!mkTip, title: mkTip })}</div>`);
      }
      const pj = p.plan_json || {};
      const versions = p.versions || [];
      const verSel = `<select class="ai-sel ai-plan-ver" title="플랜 버전">${versions.map((v) => `<option value="${v.version}"${v.version === p.version ? " selected" : ""}>v${v.version} · ${esc(PLAN_ST[v.status] || v.status)} · ${dt(v.created_at)}</option>`).join("")}</select>`;
      const meta = [
        mi("상태", stChip(p.status, PLAN_ST[p.status] || p.status)),
        p.est_minutes ? mi("예상", `${p.est_minutes}분`) : "",
        pj.confidence != null ? mi("확신", `${Math.round(pj.confidence * 100)}%`) : "",
        mi("비용", `${usd(p.cost_usd)}${p.budget_hit ? ' <span class="ai-st ai-st-warn" title="플랜 생성 예산 초과로 잘렸을 수 있음">예산 초과</span>' : ""}`),
        p.budget_usd != null ? mi("실행 예산", usd(p.budget_usd)) : "",
        p.model ? mi("모델", esc(p.model)) : "",
        p.base_revision ? mi("기준 리비전", esc(p.base_revision)) : "",
        p.num_turns ? mi("턴", p.num_turns) : "",
        p.claude_session_id ? mi("세션", `<code class="ai-code" title="${esc(p.claude_session_id)}">${esc(String(p.claude_session_id).slice(0, 8))}…</code>`) : "",
        p.created_by ? mi("작성", esc(p.created_by)) : "",
        p.approved_by ? mi("승인", `${esc(p.approved_by)} · ${dt(p.approved_at)}`) : "",
      ].join("");
      let body = `<div class="ai-meta">${meta}</div>`;
      if (p.title) body += `<div class="ai-title">${esc(p.title)}</div>`;
      if (pj.needs_human) body += `<div class="ai-warn">🙋 플랜이 <b>사람 확인 필요</b>로 표시되어 있습니다${Array.isArray(pj.questions) && pj.questions.length ? ` — ${pj.questions.map((x) => esc(x)).join(" / ")}` : ""}.</div>`;
      if (p.status === "rejected") body += `<div class="ai-warn">⛔ 반려 (${esc(p.rejected_by || "")} · ${dt(p.rejected_at)}): ${esc(p.reject_reason || "")}</div>`;
      if (p.user_hint) body += `<div class="ai-note">💬 힌트: ${esc(p.user_hint)}</div>`;
      if (pj.understanding_md) body += `<div class="ai-sub"><div class="ai-sub-h">🔎 원인 / 이해</div>${md(pj.understanding_md)}</div>`;
      if (pj.approach_md) body += `<div class="ai-sub"><div class="ai-sub-h">🧭 접근</div>${md(pj.approach_md)}</div>`;
      const files = Array.isArray(pj.files) ? pj.files : [];
      if (files.length) body += `<div class="ai-sub-h" style="margin-top:14px">📄 관련 파일</div><table class="ai-tbl ai-files"><thead><tr><th>파일 (${files.length})</th><th>작업</th><th>이유</th></tr></thead><tbody>${files.map((f) => `<tr><td>${esc(f.path)}</td><td class="ai-act-${esc(f.action || "modify")}">${esc(f.action || "modify")}</td><td>${aiMdInline(f.why || "")}</td></tr>`).join("")}</tbody></table>`;
      const steps = Array.isArray(pj.steps) ? pj.steps : [];
      body += `<div class="ai-cols">${steps.length ? `<div class="ai-col"><h5>단계 (${steps.length})</h5><ol class="ai-steps">${steps.map((st) => `<li>${aiMdInline(typeof st === "string" ? st : (st.text || ""))}${st && Array.isArray(st.files) && st.files.length ? ` <span class="ai-muted">(${st.files.map(esc).join(", ")})</span>` : ""}</li>`).join("")}</ol></div>` : ""}
        ${Array.isArray(pj.risks) && pj.risks.length ? `<div class="ai-col"><h5>리스크</h5>${listOf(pj.risks)}</div>` : ""}
        ${Array.isArray(pj.test_plan) && pj.test_plan.length ? `<div class="ai-col"><h5>테스트 계획</h5>${listOf(pj.test_plan)}</div>` : ""}</div>`;
      if (p.prompt_md) body += det("📝 플랜 프롬프트 (자동 생성 · Claude 에 보낸 그대로)", `<div class="ai-muted" style="margin-bottom:4px">문의·AI 요약·유사 사례·승인된 교훈·레포 지식으로 워커가 자동 조립했습니다. 바꾸고 싶으면 [🔁 플랜 재생성]에 힌트를 넣으세요.</div><pre class="ai-pre" style="max-height:420px">${esc(p.prompt_md)}</pre>`, false);
      if (p.plan_md) body += det("플랜 전문 (markdown)", md(p.plan_md), !pj.understanding_md && !files.length);
      if (p.metrics_json) { const m = p.metrics_json; body += det("📐 학습 지표 (플랜 예측 vs 실제)", `<div class="ai-meta">${m.jaccard != null ? mi("Jaccard", Number(m.jaccard).toFixed(2)) : ""}${m.precision != null ? mi("정밀도", Number(m.precision).toFixed(2)) : ""}${m.recall != null ? mi("재현율", Number(m.recall).toFixed(2)) : ""}${m.verdict ? mi("검토", esc(m.verdict)) : ""}${m.est_vs_actual ? mi("예상/실제", esc(typeof m.est_vs_actual === "string" ? m.est_vs_actual : JSON.stringify(m.est_vs_actual))) : ""}</div>${Array.isArray(m.missed) && m.missed.length ? `<div>누락: ${m.missed.map((x) => `<code class="ai-code">${esc(x)}</code>`).join(" ")}</div>` : ""}${Array.isArray(m.unexpected) && m.unexpected.length ? `<div>예상 외: ${m.unexpected.map((x) => `<code class="ai-code">${esc(x)}</code>`).join(" ")}</div>` : ""}`); }
      // 버튼
      const canRegen = !["approved", "executing"].includes(p.status) && !b && res;
      const draft = p.status === "draft";
      const approveTip = !s.can_approve ? "승인 권한이 없습니다 (config.php slackai.approvers / AI 설정)" : !res ? "대상 레포가 확정되지 않았습니다" : res.check_status !== "ok" ? `레포 점검이 ${res.check_status || "unchecked"} 입니다` : b ? this.busyTip(s) : "";
      let btns = modelSel(u);
      btns += btn("replan", "🔁 플랜 재생성", { disabled: !canRegen, title: !res ? "레포 선택 필요" : b ? this.busyTip(s) : ["approved", "executing"].includes(p.status) ? "실행 중인 플랜은 재생성할 수 없습니다" : "힌트를 넣어 새 버전을 만듭니다(현재 draft 는 대체됨)" });
      if (draft) {
        btns += btn("reject_plan", "⛔ 반려", { cls: "danger", disabled: b, title: b ? this.busyTip(s) : "사유를 남기고 반려합니다 (학습 신호)" });
        btns += btn("approve_execute", "✅ 승인하고 작업 진행", { cls: "primary", disabled: !!approveTip, title: approveTip || "확인 모달 후 워커가 작업 사본을 수정합니다" });
      }
      body += `<div class="ai-btns">${btns}</div>`;
      return sec("plan", `📝 플랜 ${verSel}`, body, `${!p.is_latest ? '<span class="ai-st ai-st-muted">이전 버전 보기</span>' : ""}`);
    },
    /* (e) 실행 */
    hExec(s, u) {
      const e = s.execution, res = s.repo && s.repo.resolved, b = this.busy(s);
      const meta = [
        mi("상태", stChip(e.status, EXEC_ST[e.status] || e.status)),
        e.resumed === 0 ? mi("세션", '<span class="ai-st ai-st-warn" title="플랜 세션 재개 실패 → 플랜 본문을 넣은 새 세션으로 실행">새 세션 폴백</span>') : "",
        e.started_at ? mi("시작", dt(e.started_at)) : "",
        e.finished_at ? mi("종료", dt(e.finished_at)) : "",
        mi("비용", `${usd(e.cost_usd)}${e.budget_hit ? ' <span class="ai-st ai-st-warn">예산 초과</span>' : ""}`),
        e.model ? mi("모델", esc(e.model)) : "",
        e.approved_by ? mi("승인", esc(e.approved_by)) : "",
        e.files_changed != null ? mi("변경", `파일 ${e.files_changed}개 · <span class="d-add">+${e.lines_added || 0}</span> <span class="d-del">−${e.lines_deleted || 0}</span>`) : "",
        e.lint.ok !== null ? mi("php -l", e.lint.ok ? '<span class="ai-st ai-st-ok">통과</span>' : '<span class="ai-st ai-st-bad">실패</span>') : "",
      ].join("");
      let body = `<div class="ai-meta">${meta}</div>`;
      if (e.lint.ok === false && e.lint.output) body += det("❌ php -l 출력", `<div class="ai-pre">${esc(e.lint.output)}</div>`, true);
      const stat = Array.isArray(e.diff_stat) ? e.diff_stat : [];
      if (stat.length) body += det(`파일별 통계 (${stat.length})`, `<table class="ai-tbl"><thead><tr><th>파일</th><th>상태</th><th>+</th><th>−</th></tr></thead><tbody>${stat.map((f) => `<tr><td class="mono">${esc(f.path)}</td><td>${esc(f.status || "M")}</td><td class="num d-add">${f.add ?? ""}</td><td class="num d-del">${f.del ?? ""}</td></tr>`).join("")}</tbody></table>`, false);
      if (e.result_md) body += det("🧾 Claude 완료 보고", md(e.result_md), true);
      if (e.diff) {
        body += `<div class="ai-diff-tools"><b style="font-size:12px">diff</b>${e.diff_truncated ? `<span class="ai-warn" style="margin:0;padding:2px 8px">앞 ${Math.round((e.diff.length || 0) / 1024)}KB 만 표시 (전체 ${Math.round(e.diff_len / 1024)}KB)</span>` : ""}<span class="ai-sp"></span>${btn("diff_copy_all", "📋 전체 복사", { cls: "ai-btn-xs" })}<a class="ai-btn" style="display:inline-flex;align-items:center;height:20px;padding:0 6px;font-size:11px;border:1px solid var(--line);border-radius:7px;text-decoration:none;color:var(--txt)" href="ai/ai_diff.php?execution_id=${e.id}" target="_blank" rel="noopener">↗ 전체 diff</a></div>${aiDiffHtml(e.diff)}`;
      } else if (e.status === "done") body += '<div class="ai-muted">diff 없음</div>';
      if (e.log) body += det("로그", `<div class="ai-pre">${esc(e.log)}</div>`);
      if (Array.isArray(e.preexisting_json) && e.preexisting_json.length) body += det(`실행 전 untracked 파일 ${e.preexisting_json.length}개 (커밋 대상에서 제외)`, `<div class="ai-pre">${esc(e.preexisting_json.join("\n"))}</div>`);
      // 버튼
      const c = s.commit, hasCommit = c && ["pending", "committed", "pushed"].includes(c.status);
      const commitTip = e.status !== "done" ? `실행이 ${EXEC_ST[e.status] || e.status} 상태입니다` : hasCommit ? `이미 커밋 #${c.id} (${COMMIT_ST[c.status]})` : !s.can_approve ? "승인 권한이 없습니다" : b ? this.busyTip(s) : "";
      let btns = btn("commit", "💾 커밋", { cls: "primary", disabled: !!commitTip, title: commitTip || "통계/lint 확인 → 커밋 메시지 편집 → 워커가 svn/git commit" });
      if (["done", "failed", "cancelled"].includes(e.status) && !hasCommit) btns += btn("revert_help", (u.revertOpen ? "▾" : "▸") + " 되돌리기 안내", { title: "작업 사본에서 직접 실행할 명령을 보여줍니다(자동 실행 없음)" });
      body += `<div class="ai-btns">${btns}</div>`;
      if (u.revertOpen) {
        const path = res ? res.local_path : "<작업 사본 경로>";
        const cmd = res && res.vcs === "git"
          ? `cd /d "${path}"\ngit status\ngit checkout -- .\ngit clean -fd          # 새로 만든(untracked) 파일 삭제 — 실행 전 untracked 목록은 위 '실행 전 untracked' 참고`
          : `cd /d "${path}"\nsvn status\nsvn revert -R .\nsvn status              # 남은 ? (새 파일) 은 직접 삭제`;
        body += `<div class="ai-note">아래 명령을 <b>워커 PC 의 작업 사본</b>에서 직접 실행하세요. 화면에서는 실행하지 않습니다.<div class="ai-pre" style="margin-top:6px">${esc(cmd)}</div></div>`;
      }
      return sec("exec", "⚙️ 실행", body);
    },
    /* (f) 커밋 */
    hCommit(s) {
      const c = s.commit, b = this.busy(s);
      const meta = [
        mi("상태", stChip(c.status, COMMIT_ST[c.status] || c.status)),
        c.revision ? mi("리비전", `<code class="ai-code">${esc(c.revision)}</code>`) : "",
        c.vcs ? mi("VCS", esc(c.vcs)) : "",
        c.branch ? mi("브랜치", esc(c.branch)) : "",
        c.committed_by ? mi("커밋", `${esc(c.committed_by)}${c.committed_at ? ` · ${dt(c.committed_at)}` : ""}`) : "",
      ].join("");
      let body = `<div class="ai-meta">${meta}</div><div class="ai-pre" style="max-height:160px">${esc(c.message)}</div>`;
      if (c.log) body += det("커밋 로그", `<div class="ai-pre">${esc(c.log)}</div>`, c.status === "failed");
      const done = ["committed", "pushed"].includes(c.status);
      let btns = btn("review", s.review ? "🧪 재검토 요청" : "🧪 검토 요청", { disabled: !done || b, title: !done ? `커밋이 ${COMMIT_ST[c.status] || c.status} 상태입니다` : b ? this.busyTip(s) : "Claude 가 아닌 다른 AI(codex→openai→gemini→claude 폴백)가 문의+플랜+diff 를 검토합니다" });
      if (!s.review) btns += btn("learn", "📚 학습 추출", { disabled: !done || b, title: !done ? "커밋 완료 후 가능" : b ? this.busyTip(s) : "플랜 예측 vs 실제 변경 · 검토 결과 · 사용자 교정으로 교훈을 제안합니다" });
      body += `<div class="ai-btns">${btns}</div>`;
      return sec("commit", "💾 커밋", body);
    },
    /* (g) 검토 */
    hReview(s) {
      const r = s.review, b = this.busy(s);
      const verdictLabel = { pass: "🟢 통과", warn: "🟡 경고", fail: "🔴 실패", error: "⚪ 오류" };
      const meta = [
        mi("판정", stChip(r.verdict, verdictLabel[r.verdict] || r.verdict || "—")),
        mi("검토자", `${esc(r.reviewer)}${r.model ? ` · ${esc(r.model)}` : ""}`),
        r.addresses_inquiry !== null ? mi("문의 해결", r.addresses_inquiry ? '<span class="ai-st ai-st-ok">예</span>' : '<span class="ai-st ai-st-bad">아니오</span>') : "",
        mi("비용", usd(r.cost_usd)),
        mi("시각", dt(r.created_at)),
      ].join("");
      let body = `<div class="ai-meta">${meta}</div>`;
      if (r.report_md) body += md(r.report_md);
      const f = Array.isArray(r.findings_json) ? r.findings_json : [];
      if (f.length) body += `<table class="ai-tbl"><thead><tr><th>심각도</th><th>위치</th><th>지적</th><th>제안</th></tr></thead><tbody>${f.map((x) => `<tr><td>${stChip(x.severity === "critical" || x.severity === "major" ? "fail" : x.severity === "minor" ? "warn" : "info", SEV[x.severity] || x.severity)}</td><td class="mono">${esc(x.file || "")}${x.line ? `:${x.line}` : ""}</td><td>${aiMdInline(x.text || "")}</td><td>${aiMdInline(x.suggestion || "")}</td></tr>`).join("")}</tbody></table>`;
      const done = s.commit && ["committed", "pushed"].includes(s.commit.status);
      body += `<div class="ai-btns">${btn("learn", "📚 학습 추출", { disabled: !done || b, title: !done ? "커밋 완료 후 가능" : b ? this.busyTip(s) : "플랜 예측 vs 실제 변경 · 검토 결과 · 사용자 교정으로 교훈을 제안합니다" })}</div>`;
      return sec("review", "🧪 검토", body);
    },
    /* (h) 학습 노트 */
    hLessons(s) {
      const rows = s.lessons.map((l) => {
        const scope = l.scope === "repo" ? `레포${l.repo_name ? ` · ${esc(l.repo_name)}` : ""}` : l.scope === "tag" ? `태그${l.tag_name ? ` · ${esc(l.tag_name)}` : ""}` : "전체";
        const acts = s.can_approve
          ? `${l.status !== "approved" ? btn("approve_lesson", "✅ 승인", { cls: "ai-btn-xs ok", data: { lid: l.id } }) : ""}${l.status !== "rejected" ? btn("reject_lesson", "⛔ 반려", { cls: "ai-btn-xs danger", data: { lid: l.id } }) : ""}`
          : '<span class="ai-muted" title="승인자만 가능">—</span>';
        return `<tr${l.status === "rejected" ? ' class="dim"' : ""}><td>${stChip(l.status, LESSON_ST[l.status] || l.status)}</td><td><span class="ai-chip">${esc(LKIND[l.kind] || l.kind)}</span><br><span class="ai-muted">${scope}</span></td>
          <td><b>${esc(l.title)}</b>${l.lesson_md ? md(l.lesson_md) : ""}${l.evidence_md ? det("근거", md(l.evidence_md)) : ""}</td>
          <td class="num" title="가중치 · 근거 ${l.evidence_count}건 · 사용 ${l.used_count}회">${Number(l.weight).toFixed(2)}</td><td>${acts}</td></tr>`;
      }).join("");
      return sec("lessons", `📚 학습 노트 <span class="ai-muted">승인한 교훈만 다음 플랜 프롬프트에 들어갑니다</span>`,
        `<table class="ai-tbl"><thead><tr><th>상태</th><th>종류/범위</th><th>교훈</th><th>가중치</th><th></th></tr></thead><tbody>${rows}</tbody></table>
         <div class="ai-btns"><a href="ai/lessons.php" target="_blank" rel="noopener" class="ai-muted">📚 학습 노트 전체</a></div>`);
    },
    /* (i) 잡/비용 + 이벤트 */
    hJobs(s) {
      const jobs = s.jobs || [];
      const dur = (j) => { if (!j.started_at) return ""; const a = new Date(j.started_at.replace(" ", "T")), z = j.finished_at ? new Date(j.finished_at.replace(" ", "T")) : null; if (!z) return ""; const sec = Math.round((z - a) / 1000); return sec < 60 ? `${sec}초` : `${Math.floor(sec / 60)}분 ${sec % 60}초`; };
      const rows = jobs.map((j) => `<tr${["failed", "cancelled"].includes(j.status) ? ' class="dim"' : ""}><td class="num">#${j.id}</td><td>${esc(KIND[j.kind] || j.kind)}</td><td>${stChip(j.status, JOB_ST[j.status] || j.status)}${j.attempts > 1 ? ` <span class="ai-muted">×${j.attempts}</span>` : ""}</td>
        <td>${j.status === "failed" && j.error ? `<span title="${esc(j.error)}">${esc(String(j.error).slice(0, 120))}</span>` : esc(j.progress || "")}</td>
        <td class="mono">${esc(j.model || "")}</td><td class="num">${usd(j.cost_usd)}</td><td class="num" title="입력/출력 토큰">${j.tokens_in != null ? `${j.tokens_in}/${j.tokens_out || 0}` : ""}</td>
        <td class="mono" title="${esc(j.requested_by || "")}">${esc(String(j.requested_by || "").split("@")[0])}</td><td>${dt(j.created_at)}</td><td>${dur(j)}</td>
        <td>${j.status === "queued" || (j.status === "running" && !j.cancel_requested) ? btn("cancel_job", "취소", { cls: "ai-btn-xs", data: { jid: j.id } }) : ""}</td></tr>`).join("");
      const ev = (s.events || []).map((e) => { const d = e.detail; let dtxt = ""; if (d && typeof d === "object") { if (e.action === "set_tags") dtxt = `${(d.before || []).map((t) => t.name).join(", ") || "∅"} → ${(d.after || []).map((t) => t.name).join(", ") || "∅"}`; else if (e.action === "set_repo") dtxt = `${d.before ?? "∅"} → ${d.after ?? "∅"}${d.repo ? ` (${d.repo})` : ""}`; else dtxt = Object.entries(d).filter(([k]) => !["before", "after"].includes(k)).map(([k, v]) => `${k}=${typeof v === "object" ? JSON.stringify(v) : v}`).join(" · "); } return `<tr><td>${dt(e.created_at)}</td><td class="mono">${esc(String(e.actor).split("@")[0])}</td><td><b>${esc(e.action)}</b>${e.ref_table ? ` <span class="ai-muted">${esc(e.ref_table)}${e.ref_id ? "#" + e.ref_id : ""}</span>` : ""}</td><td class="ai-muted" style="word-break:break-all">${esc(dtxt.slice(0, 300))}</td></tr>`; }).join("");
      return sec("jobs", `🧾 작업 이력 <span class="ai-muted">${jobs.length}건 · 비용 합계 ${usd(s.cost_total)}</span>`,
        (jobs.length ? `<table class="ai-tbl"><thead><tr><th>#</th><th>종류</th><th>상태</th><th>진행/오류</th><th>모델</th><th>비용</th><th>토큰</th><th>요청</th><th>등록</th><th>소요</th><th></th></tr></thead><tbody>${rows}</tbody></table>` : '<div class="ai-muted">아직 작업이 없습니다.</div>')
        + (ev ? det(`감사 로그 (최근 ${s.events.length}건)`, `<table class="ai-tbl"><tbody>${ev}</tbody></table>`) : "")
        + `<div class="ai-btns"><a href="ai/jobs.php?request_id=${encodeURIComponent(s.request_id)}" target="_blank" rel="noopener" class="ai-muted">🤖 AI 작업 로그 전체</a></div>`);
    },

    /* ======================================================================
     * 이벤트
     * ==================================================================== */
    bindPanel(el) {
      if (el.dataset.bound) return;
      el.dataset.bound = "1";
      el.addEventListener("click", (e) => {
        const b = e.target.closest("[data-act]");
        if (!b || !el.contains(b)) return;
        e.stopPropagation();
        e.preventDefault();
        if (b.disabled) return;
        this.onAct(b.dataset.act, el.dataset.id, b);
      });
      el.addEventListener("change", (e) => {
        const t = e.target, id = el.dataset.id;
        if (t.matches(".ai-repo-sel")) { e.stopPropagation(); this.act("set_repo", id, { repo_id: +t.value || 0 }, { msg: +t.value ? "📁 레포를 지정했습니다." : "📁 레포 지정을 해제했습니다." }); }
        else if (t.matches(".ai-plan-ver")) { e.stopPropagation(); this.u(id).planVersion = +t.value || null; this.load(id, true); }
        else if (t.matches(".ai-tag-cb")) { e.stopPropagation(); this.onTagToggle(el, t); }
      });
      el.addEventListener("input", (e) => { if (e.target.matches(".ai-plan-hint")) this.u(el.dataset.id).hint = e.target.value; });
      el.addEventListener("change", (e) => { if (e.target.matches(".ai-plan-model")) this.u(el.dataset.id).model = e.target.value; });
      el.addEventListener("keydown", (e) => { if (e.target.matches(".ai-plan-hint") && e.key === "Enter") { e.preventDefault(); e.stopPropagation(); const b = el.querySelector('[data-act="plan"]'); if (b && !b.disabled) this.onAct("plan", el.dataset.id, b); } });
    },
    onTagToggle(el, cb) {
      const id = el.dataset.id, u = this.u(id), s = this.cache[id];
      if (!u.tagSel) u.tagSel = new Set(((s && s.tags && s.tags.assigned) || []).map((t) => t.id));
      const tid = +cb.value;
      if (cb.checked) u.tagSel.add(tid); else u.tagSel.delete(tid);
      const lab = cb.closest("label");
      if (lab) { lab.classList.toggle("on", cb.checked); const t = ((s && s.tags && s.tags.all) || []).find((x) => x.id === tid); lab.style.cssText = cb.checked && t ? tagStyle(t.color) : ""; }
    },
    async onAct(act, id, b) {
      const s = this.cache[id], u = this.u(id);
      switch (act) {
        case "refresh": return this.load(id, true);
        case "triage": return this.act("triage", id, {}, { msg: "🤖 분석 잡을 등록했습니다." });
        case "retriage": return this.act("retriage", id, {}, { msg: "↻ 재분석 잡을 등록했습니다." });
        case "retry_job": { const k = b.dataset.kind; if (k === "review") return this.act("review", id, s && s.commit ? { commit_id: s.commit.id } : {}); if (k === "learn") return this.act("learn", id, s && s.plan ? { plan_id: s.plan.id } : {}); if (k === "plan") return this.act("plan", id, { hint: u.hint || "" }); return this.act("triage", id, { force: true }); }
        case "cancel_job": {
          const jid = +b.dataset.jid; const j = (s && s.jobs || []).find((x) => x.id === jid) || (s && s.job);
          const r = await this.confirm({ title: "⏹ 작업 취소", rows: [["작업", `#${jid} ${esc(KIND[j && j.kind] || (j && j.kind) || "")} · ${esc(JOB_ST[j && j.status] || "")}`]], warn: j && j.status === "running" ? "진행 중인 작업은 취소 요청만 남기고 워커가 다음 heartbeat 에서 중단합니다." : null, ok: "취소 실행" });
          if (!r) return; return this.act("cancel_job", id, { job_id: jid }, { msg: "⏹ 취소했습니다." });
        }
        case "tags_edit": u.tagsOpen = true; u.tagSel = null; return this.render(id);
        case "tags_cancel": u.tagsOpen = false; u.tagSel = null; return this.render(id);
        case "save_tags": {
          const ids = u.tagSel ? [...u.tagSel] : [...this.el(id).querySelectorAll(".ai-tag-cb:checked")].map((x) => +x.value);
          const r = await this.act("set_tags", id, { tag_ids: ids }, { msg: "🏷️ 태그를 저장했습니다." });
          if (r) { u.tagsOpen = false; u.tagSel = null; this.render(id); }
          return;
        }
        case "sim_toggle": { const sid = b.dataset.sid; u.simOpen = u.simOpen || {}; u.simOpen[sid] = !u.simOpen[sid]; return this.render(id); }
        case "sim_more": u.simAll = true; return this.render(id);
        case "sim_less": u.simAll = false; return this.render(id);
        case "sim_open": {
          const sid = b.dataset.sid;
          if (b.dataset.inlist === "1" && typeof window.openRecent === "function") return window.openRecent(sid);
          return window.open("similar/similar.php?id=" + encodeURIComponent(sid), "_blank", "noopener");
        }
        case "repo_cand": return this.act("set_repo", id, { repo_id: +b.dataset.rid }, { msg: "📁 레포를 지정했습니다." });
        case "plan": { const inp = this.el(id).querySelector(".ai-plan-hint"); const hint = (inp ? inp.value : u.hint || "").trim(); const model = this.pickModel(id, u); const r = await this.act("plan", id, { hint, model }, { msg: `📝 플랜 생성 잡을 등록했습니다 (${model}).` }); if (r) u.hint = ""; return; }
        case "replan": {
          const r = await this.confirm({ title: "🔁 플랜 재생성", rows: [["현재", s && s.plan ? `v${s.plan.version} · ${esc(PLAN_ST[s.plan.status] || s.plan.status)}` : "—"]], edit: { label: "힌트 (선택) — 왜 다시 만드는지, 참고할 파일/원인 추정", type: "textarea", value: u.hint || "", rows: 4 }, warn: s && s.plan && s.plan.status === "draft" ? "현재 draft 는 새 버전이 저장되면 '대체됨' 으로 바뀝니다." : null, ok: "🔁 재생성" });
          if (!r) return; const model = this.pickModel(id, u); return this.act("plan", id, { hint: (r.value || "").trim(), model }, { msg: `📝 플랜 재생성 잡을 등록했습니다 (${model}).` });
        }
        case "reject_plan": {
          if (!s || !s.plan) return;
          const r = await this.confirm({ title: "⛔ 플랜 반려", rows: [["플랜", `v${s.plan.version} · ${esc(s.plan.title || "")}`]], edit: { label: "반려 사유 (필수) — 학습 신호로 남습니다", type: "textarea", value: "", rows: 4, required: true }, ok: "⛔ 반려", okClass: "danger" });
          if (!r) return; return this.act("reject_plan", id, { plan_id: s.plan.id, reason: (r.value || "").trim() }, { msg: "⛔ 반려했습니다." });
        }
        case "approve_execute": return this.approveFlow(id);
        case "commit": return this.commitFlow(id);
        case "revert_help": u.revertOpen = !u.revertOpen; return this.render(id);
        case "review": return this.act("review", id, s && s.commit ? { commit_id: s.commit.id } : {}, { msg: "🧪 검토 잡을 등록했습니다." });
        case "learn": return this.act("learn", id, s && s.plan ? { plan_id: s.plan.id } : {}, { msg: "📚 학습 잡을 등록했습니다." });
        case "approve_lesson": return this.act("approve_lesson", id, { lesson_id: +b.dataset.lid }, { msg: "✅ 교훈을 승인했습니다. 다음 플랜부터 반영됩니다." });
        case "reject_lesson": {
          const r = await this.confirm({ title: "⛔ 교훈 반려", edit: { label: "사유 (선택)", type: "textarea", value: "", rows: 3 }, ok: "⛔ 반려", okClass: "danger" });
          if (!r) return; return this.act("reject_lesson", id, { lesson_id: +b.dataset.lid, reason: (r.value || "").trim() }, { msg: "⛔ 반려했습니다." });
        }
        case "diff_copy": { const pre = b.closest("details") && b.closest("details").querySelector(".ai-diff-pre"); if (pre) this.copy(pre.innerText, b); return; }
        case "diff_copy_all": { const pres = [...this.el(id).querySelectorAll(".ai-diff-pre")]; this.copy(pres.map((p) => p.innerText).join("\n"), b); return; }
        default: return;
      }
    },
    async copy(text, b) {
      try { await navigator.clipboard.writeText(text); }
      catch (_) { const ta = document.createElement("textarea"); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove(); }
      if (b) { const old = b.textContent; b.textContent = "✅"; setTimeout(() => { b.textContent = old; }, 1200); }
    },

    /* ---- 승인 모달(진행) ---- */
    async approveFlow(id) {
      const s = this.cache[id]; if (!s || !s.plan) return;
      const p = s.plan, repo = s.repo && s.repo.resolved, pj = p.plan_json || {};
      const files = (Array.isArray(pj.files) ? pj.files : []).filter((f) => f && f.action !== "inspect").map((f) => f.path + (f.action && f.action !== "modify" ? ` (${f.action})` : ""));
      const maxB = Number(s.settings && s.settings.budget_execute_usd) || 10;
      const def = Math.min(maxB, Number(p.budget_usd) > 0 ? Number(p.budget_usd) : maxB);
      const r = await this.confirm({
        title: "✅ 플랜 승인 → 작업 사본에 실제 수정을 진행합니다",
        rows: [
          ["문의", `<b>${esc(s.request.title)}</b> <span class="ai-muted">${esc(id)}</span>`],
          ["레포", repo ? `<b>${esc(repo.name)}</b> <span class="ai-chip">${esc(repo.vcs)}</span><br><code class="ai-code">${esc(repo.local_path)}</code>${repo.check_status !== "ok" ? ` <span class="ai-st ai-st-bad">점검 ${esc(repo.check_status || "unchecked")}</span>` : ""}` : '<span class="ai-st ai-st-bad">레포 미확정</span>'],
          ["플랜", `v${p.version} · ${esc(p.title || "(제목 없음)")}${p.est_minutes ? ` · 예상 ${p.est_minutes}분` : ""}${p.base_revision ? ` · 기준 r${esc(p.base_revision)}` : ""}`],
          ["승인자", esc(s.me)],
        ],
        files,
        warn: `Claude Code 가 위 작업 사본을 <b>직접 수정</b>합니다(커밋·푸시는 하지 않음). 실행 중에는 그 작업 사본을 건드리지 마세요.${pj.needs_human ? " 플랜이 <b>사람 확인 필요</b>로 표시되어 있습니다." : ""}${files.length === 0 ? " 플랜에 수정 파일 목록이 없습니다." : ""}`,
        edit: { label: `실행 예산 상한 (USD, 최대 $${maxB.toFixed(2)})`, type: "number", value: def.toFixed(2), min: 0.1, max: maxB, step: 0.5, required: true, hint: "Claude CLI --max-budget-usd 로 전달됩니다" },
        checkbox: "내용을 확인했습니다",
        ok: "✅ 승인하고 작업 진행",
      });
      if (!r) return;
      const budget = parseFloat(r.value);
      if (!(budget > 0) || budget > maxB + 1e-9) { this.toast(`⚠️ 예산은 0 초과 $${maxB.toFixed(2)} 이하여야 합니다.`, true); return; }
      return this.act("approve_execute", id, { confirm: true, plan_id: p.id, plan_version: p.version, budget_usd: budget }, { msg: "✅ 승인했습니다. 워커가 실행을 시작합니다." });
    },
    /* ---- 커밋 2단계 모달 ---- */
    async commitFlow(id) {
      const s = this.cache[id]; if (!s || !s.execution) return;
      const e = s.execution, p = s.plan, repo = s.repo && s.repo.resolved, stat = Array.isArray(e.diff_stat) ? e.diff_stat : [];
      const r1 = await this.confirm({
        title: "💾 커밋 1/2 — 변경 내용 확인",
        rows: [
          ["레포", repo ? `<b>${esc(repo.name)}</b> <span class="ai-chip">${esc(repo.vcs)}</span><br><code class="ai-code">${esc(repo.local_path)}</code>` : "—"],
          ["실행", `#${e.id} · ${dt(e.finished_at)}${e.resumed === 0 ? ' · <span class="ai-st ai-st-warn">새 세션 폴백</span>' : ""}${e.budget_hit ? ' · <span class="ai-st ai-st-warn">예산 초과</span>' : ""}`],
          ["변경", `파일 ${e.files_changed ?? stat.length}개 · <span class="d-add">+${e.lines_added ?? 0}</span> <span class="d-del">−${e.lines_deleted ?? 0}</span>`],
          ["php -l", e.lint.ok === null ? '<span class="ai-muted">해당 없음</span>' : e.lint.ok ? '<span class="ai-st ai-st-ok">통과</span>' : '<span class="ai-st ai-st-bad">실패</span>'],
        ],
        files: stat.map((f) => `${f.path}${f.status && f.status !== "M" ? ` (${f.status})` : ""}${f.add != null ? `  +${f.add} −${f.del || 0}` : ""}`),
        body: e.lint.ok === false ? `<div class="ai-pre">${esc(e.lint.output || "")}</div>` : "",
        warn: e.lint.ok === false ? "PHP 문법 검사에 실패한 파일이 있습니다. 그래도 커밋하려면 확인하세요." : null,
        checkbox: "변경 내용과 lint 결과를 확인했습니다",
        ok: "다음 →",
      });
      if (!r1) return;
      const pj = (p && p.plan_json) || {};
      const def = (pj.commit_message && String(pj.commit_message).trim()) || `[${id}] ${(p && p.title) || (s.request && s.request.title) || ""}`.trim();
      const r2 = await this.confirm({
        title: "💾 커밋 2/2 — 커밋 메시지",
        rows: [["형식", '<code class="ai-code">fix(area): 제목 [Rec…]</code> 권장 · 첫 줄 72자 이내 · 워커가 Rec id 를 보장합니다']],
        edit: { label: "커밋 메시지 (첫 줄 = 제목)", type: "textarea", value: def, rows: 7, required: true },
        warn: repo && repo.vcs === "git" ? "git commit 만 수행하고 push 는 하지 않습니다." : "svn commit 은 즉시 저장소에 반영됩니다(되돌리려면 새 리비전이 필요).",
        ok: "💾 커밋 실행",
      });
      if (!r2) return;
      return this.act("commit", id, { confirm: true, execution_id: e.id, message: r2.value }, { msg: "💾 커밋 잡을 등록했습니다." });
    },
  };

  window.AI = AI;
  window.aiBadge = aiBadge;
  window.aiPanelShell = aiPanelShell;
  window.aiMd = aiMd;
  window.aiDiffHtml = aiDiffHtml;
})();
