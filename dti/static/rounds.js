let ROUND_EDIT_ID = null;
let ISSUE_ROUND_ID = null;
let ISSUE_LIST = [];

const roundById = id => APP.rounds.find(r => r.id === id) || null;
const topicsOfRound = id => APP.topics.filter(t => t.round_id === id);
const roundName = r => `${r.no}회차`;
const roundLabel = r => r ? roundName(r) + (r.title ? ` · ${r.title}` : "") : "회차 없음";
const byRoundNoDesc = (a, b) => b.no - a.no;

function roundMatches(t, picked) {
  if (!picked) return true;
  if (picked === "none") return t.round_id == null;
  return t.round_id === Number(picked);
}

// 선택지는 지금 목록에 있는 회차만 — 구성원에게 숨긴 회차 이름이 드러나지 않는다
function buildRoundFilter() {
  const el = document.getElementById("f-round"), keep = el.value;
  const rows = pool();
  const ids = new Set(rows.map(t => t.round_id).filter(id => id != null));
  const rounds = APP.rounds.filter(r => ids.has(r.id)).sort(byRoundNoDesc);
  el.style.display = rounds.length ? "" : "none";
  el.innerHTML = '<option value="">전체 회차</option>'
    + rounds.map(r => `<option value="${r.id}">${esc(roundLabel(r))}</option>`).join("")
    + (rows.some(t => t.round_id == null) ? '<option value="none">회차 없음</option>' : "");
  el.value = [...el.options].some(o => o.value === keep) ? keep : "";
}

function buildRoundFormOptions() {
  document.getElementById("f-round-in").innerHTML = '<option value="">회차 없음</option>'
    + [...APP.rounds].sort(byRoundNoDesc)
      .map(r => `<option value="${r.id}">${esc(roundLabel(r))}</option>`).join("");
}

function roundSourceHtml(t) {
  const r = t.round_id == null ? null : roundById(t.round_id);
  return r ? `<span class="chip round">${esc(roundName(r))}</span>` : "";
}

/* ---------- 호 ---------- */

const issueName = (magazine, volume) =>
  `${magazine || "매거진 없음"} ${volume ? `Vol.${volume}` : "(Volume 없음)"}`;

function issuesOf(rows) {
  const out = new Map();
  rows.forEach(t => {
    const key = `${t.magazine}\u0000${t.volume}`;
    if (!out.has(key)) out.set(key, { magazine: t.magazine, volume: t.volume, rows: [] });
    out.get(key).rows.push(t);
  });
  return [...out.values()].sort((a, b) =>
    a.magazine.localeCompare(b.magazine) || volumeHead(b.rows[0]) - volumeHead(a.rows[0])
    || a.volume.localeCompare(b.volume));
}

function whereIssueIs(rows) {
  const byRound = {};
  rows.forEach(t => {
    const r = t.round_id == null ? null : roundById(t.round_id);
    const name = r ? roundName(r) : "회차 없음";
    byRound[name] = (byRound[name] || 0) + 1;
  });
  return Object.entries(byRound).map(([name, n]) => `${name} ${n}`).join(" · ");
}

/* ---------- 회차 탭 ---------- */

function roundCounts(rows) {
  return {
    total: rows.length,
    shown: rows.filter(t => t.active && !t.archived).length,
    hidden: rows.filter(t => !t.active && !t.archived).length,
    archived: rows.filter(t => t.archived).length,
    done: rows.filter(t => t.status === "발표완료").length,
  };
}

function roundCountsHtml(c) {
  return `아티클 ${c.total} · 노출 ${c.shown} · 숨김 ${c.hidden} · 보관 ${c.archived} · 발표완료 ${c.done}`;
}

function roundActionsHtml(r, rows) {
  const out = [`<button class="btn-mini" onclick="showRoundTopics(${r.id})"${rows.length ? "" : " disabled"}
    >아티클 보기</button>`,
    `<button class="btn-mini" onclick="openIssueModal(${r.id})">호 담기</button>`];
  if (rows.some(t => t.active)) out.push(`<button class="btn-mini" onclick="bulkRound(${r.id},'hide')">일괄 숨김</button>`);
  if (rows.some(t => !t.active)) out.push(`<button class="btn-mini" onclick="bulkRound(${r.id},'show')">일괄 노출</button>`);
  if (rows.some(t => !t.archived)) out.push(`<button class="btn-mini" onclick="bulkRound(${r.id},'archive')">일괄 보관</button>`);
  if (rows.some(t => t.archived)) out.push(`<button class="btn-mini" onclick="bulkRound(${r.id},'unarchive')">일괄 보관 해제</button>`);
  out.push(`<button class="btn-mini ghost" onclick="openRoundModal(${r.id})">수정</button>`);
  out.push(`<button class="btn-mini ghost danger" onclick="askDeleteRound(${r.id})">삭제</button>`);
  return out.join("");
}

function renderRoundsPage() {
  const rounds = [...APP.rounds].sort(byRoundNoDesc);
  const loose = APP.topics.filter(t => t.round_id == null);
  document.getElementById("roundsPage").innerHTML = `
    <div class="rounds-card">
      <div class="rounds-head">
        <h3>회차 관리</h3>
        <button class="btn-mini primary" onclick="openRoundModal()">+ 회차 추가</button>
      </div>
      <p class="muted">여러 매거진 호를 한 회차로 묶습니다.
        일괄 숨김·보관은 회차 안 아티클의 노출·보관 값을 한 번에 바꿉니다.</p>
      ${rounds.length ? `<ul class="rounds-list">${rounds.map(r => {
        const rows = topicsOfRound(r.id);
        const issues = issuesOf(rows).map(i => issueName(i.magazine, i.volume)).join(" · ");
        return `<li>
          <div class="rd-main">
            <div class="rd-name"><b>${esc(roundName(r))}</b>${r.title ? `<span>${esc(r.title)}</span>` : ""}</div>
            <div class="muted">${esc(issues || "담긴 호가 없습니다")}</div>
            <div class="rd-count">${roundCountsHtml(roundCounts(rows))}</div>
          </div>
          <div class="rd-act">${roundActionsHtml(r, rows)}</div>
        </li>`;
      }).join("")}</ul>` : '<p class="muted">아직 회차가 없습니다.</p>'}
      <div class="rounds-none">
        <span>회차 없음 <b>${loose.length}건</b></span>
        <button class="btn-mini ghost" onclick="showRoundTopics('none')"${loose.length ? "" : " disabled"}
          >아티클 보기</button>
      </div>
    </div>`;
}

function showRoundTopics(key) {
  const rows = key === "none" ? APP.topics.filter(t => t.round_id == null) : topicsOfRound(key);
  APP.view.tab = rows.length && rows.every(t => t.archived) ? "archive" : "articles";
  buildRoundFilter();
  document.getElementById("f-round").value = String(key);
  render();
}

/* ---------- 일괄 보관·숨김 ---------- */

const BULK = {
  hide: { patch: { active: false }, title: "일괄 숨김", verb: "구성원 화면에서 숨깁니다",
          done: n => `${n}건을 숨겼습니다` },
  show: { patch: { active: true }, title: "일괄 노출", verb: "구성원 화면에 노출합니다",
          done: n => `${n}건을 노출했습니다`, undo: true },
  archive: { patch: { archived: true }, title: "일괄 보관", verb: "보관함으로 옮깁니다",
             done: n => `${n}건을 보관함으로 옮겼습니다` },
  unarchive: { patch: { archived: false }, title: "일괄 보관 해제", verb: "보관에서 꺼냅니다",
               done: n => `${n}건의 보관을 해제했습니다`, undo: true },
};

async function bulkRound(id, action) {
  const r = roundById(id), a = BULK[action];
  const text = `${roundName(r)} 아티클 ${topicsOfRound(id).length}건을 ${a.verb}.`
    + (a.undo ? " 따로 숨기거나 보관해 둔 아티클도 함께 풀립니다." : "");
  if (!await askConfirm(`${roundName(r)} ${a.title}`, text, a.title)) return;
  try {
    const res = await postJSON(`/magazineapi/rounds/${id}/topics`, a.patch);
    showToast(res.count ? a.done(res.count) : "바뀐 아티클이 없습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 등록·수정·삭제 ---------- */

function openRoundModal(id) {
  const r = id ? roundById(id) : null;
  ROUND_EDIT_ID = r ? r.id : null;
  const next = APP.rounds.reduce((max, x) => Math.max(max, x.no), 0) + 1;
  document.getElementById("roundTitle").textContent = r ? "회차 수정" : "회차 추가";
  document.getElementById("roundNo").value = r ? r.no : next;
  document.getElementById("roundName").value = r ? r.title : "";
  document.getElementById("roundOverlay").classList.add("open");
  setTimeout(() => document.getElementById("roundNo").focus(), 50);
}

function closeRoundModal() {
  document.getElementById("roundOverlay").classList.remove("open");
}

async function submitRound() {
  const body = {
    no: document.getElementById("roundNo").value.trim(),
    title: document.getElementById("roundName").value.trim(),
  };
  if (!body.no) { showToast("회차 번호를 입력해 주세요"); return; }
  try {
    if (ROUND_EDIT_ID) {
      await putJSON(`/magazineapi/rounds/${ROUND_EDIT_ID}`, body);
      showToast("회차를 고쳤습니다");
    } else {
      await postJSON("/magazineapi/rounds", body);
      showToast(`${body.no}회차를 추가했습니다`);
    }
    closeRoundModal();
    await reload();
  } catch (e) { showToast(e.message); }
}

async function askDeleteRound(id) {
  const r = roundById(id), n = topicsOfRound(id).length;
  const text = n ? `아티클 ${n}건은 회차 없음이 됩니다. 아티클은 지워지지 않습니다.` : "담긴 아티클이 없습니다.";
  if (!await askConfirm(`${roundName(r)} 삭제`, text, "삭제")) return;
  try {
    await api(`/magazineapi/rounds/${id}`, { method: "DELETE" });
    showToast(`${roundName(r)}를 삭제했습니다`);
    await reload();
  } catch (e) { showToast(e.message); }
}

/* ---------- 호 담기 ---------- */

function openIssueModal(id) {
  ISSUE_ROUND_ID = id;
  ISSUE_LIST = issuesOf(APP.topics);
  document.getElementById("issueTitle").textContent = `${roundName(roundById(id))}에 호 담기`;
  document.getElementById("issueList").innerHTML = ISSUE_LIST.length
    ? ISSUE_LIST.map((i, n) => `<button type="button" class="issue-pick" onclick="pickIssue(${n})">
        <span class="nm">${esc(issueName(i.magazine, i.volume))}</span>
        <span class="muted">${i.rows.length}건 · ${esc(whereIssueIs(i.rows))}</span>
      </button>`).join("")
    : '<p class="muted">등록된 아티클이 없습니다.</p>';
  document.getElementById("issueOverlay").classList.add("open");
}

function closeIssueModal() {
  document.getElementById("issueOverlay").classList.remove("open");
}

async function pickIssue(n) {
  const i = ISSUE_LIST[n];
  try {
    const res = await postJSON(`/magazineapi/rounds/${ISSUE_ROUND_ID}/issue`,
      { magazine: i.magazine, volume: i.volume });
    showToast(`${issueName(i.magazine, i.volume)} ${res.count}건을 담았습니다`
      + (res.moved ? ` (다른 회차에서 ${res.moved}건 옮김)` : ""));
    closeIssueModal();
    await reload();
  } catch (e) { showToast(e.message); }
}
