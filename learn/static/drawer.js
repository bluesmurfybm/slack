let DETAIL = null;

const SETTLED = new Set(["", S.APPROVED, S.CLAIMED, S.CLAIM_APPROVED,
  S.CLAIM_REJECTED, S.REFUNDED, S.NO_REFUND]);

const canEdit = r => isMine(r) && (r.is_free || r.status === S.REQUESTED);
const canClaim = r => isMine(r) && !r.is_free && !isCompany(r) &&
  (r.status === S.APPROVED || r.status === S.CLAIM_REJECTED);
const canAttach = r => (isMine(r) || APP.me.is_admin) && SETTLED.has(r.status);
const canReview = r => isMine(r) && SETTLED.has(r.status);

async function openDrawer(rid) {
  try {
    DETAIL = await api(`/learningapi/requests/${rid}`);
  } catch (e) {
    showToast(e.message);
    return;
  }
  renderDrawer();
  document.getElementById("drawer").classList.add("open");
  document.getElementById("drawer").setAttribute("aria-hidden", "false");
  document.getElementById("scrim").classList.add("open");
}

function closeDrawer() {
  DETAIL = null;
  document.getElementById("drawer").classList.remove("open");
  document.getElementById("drawer").setAttribute("aria-hidden", "true");
  document.getElementById("scrim").classList.remove("open");
}

function reasonNote(r) {
  const pairs = [
    [r.reject_reason, "수강반려 사유"],
    [r.claim_reject_reason, "청구반려 사유"],
  ].filter(([v]) => v);
  return pairs.map(([v, label]) =>
    `<p class="note"><b>${label}</b><br>${esc(v)}</p>`).join("");
}

function certSection(r) {
  const rows = (r.certs || []).map(c => `
    <div class="drop filled">
      <span class="ic">📄</span>
      <div class="t">
        <b>${esc(c.name)}</b>
        <span>${esc(c.created_at)}</span>
      </div>
      <a class="btn-mini" href="${certDownloadURL(r.id, c.id)}"
         target="_blank" rel="noopener">열기</a>
      ${canAttach(r) ? `<button class="btn-mini danger"
         onclick="removeCert(${c.id})">삭제</button>` : ""}
    </div>`).join("");
  const adder = canAttach(r) ? `
    <div class="drop" style="margin-top:8px">
      <span class="ic">＋</span>
      <div class="t">
        <b>이수증 올리기</b>
        <span>이미지·pdf. 수료증이 없으면 진행률 화면을 캡쳐해 올립니다.</span>
      </div>
      <button class="btn-mini" onclick="document.getElementById('certFile').click()">선택</button>
    </div>
    <input type="file" id="certFile" style="display:none"
           accept=".png,.jpg,.jpeg,.gif,.webp,.bmp,.pdf" onchange="uploadCert(this)">` : "";
  return `<div class="d-sec"><h4>이수증</h4>${rows || ""}${adder}
    ${!rows && !adder ? '<p class="muted">등록된 이수증이 없습니다.</p>' : ""}</div>`;
}

function reviewSection(r) {
  if (!canReview(r) && r.rating == null && r.recommend == null) return "";
  const rw = canReview(r);
  return `<div class="d-sec"><h4>강의 평가</h4>
    <dl class="kv">
      <dt>강의평가</dt><dd>${starsHTML(r.rating, { editable: rw, field: "rating" })}</dd>
      <dt>추천도</dt><dd>${starsHTML(r.recommend, { editable: rw, field: "recommend" })}</dd>
    </dl>
    ${rw ? `<textarea id="reviewNote" class="date-field" rows="2" style="margin-top:10px"
       placeholder="한 줄 후기(선택)">${esc(r.review_note || "")}</textarea>
      <button class="btn-mini" onclick="saveNote()">후기 저장</button>`
      : (r.review_note ? `<p class="note">${esc(r.review_note)}</p>` : "")}
  </div>`;
}

function historySection(r) {
  if (!(r.history || []).length) return "";
  const items = r.history.map(h => `<li>
    <b>${esc(h.status)}</b>
    <span>${esc(h.created_at)} · ${esc(h.actor || h.actor_email)}</span>
    ${h.memo ? `<em>${esc(h.memo)}</em>` : ""}
  </li>`).join("");
  return `<div class="d-sec"><h4>이력</h4><ul class="tl">${items}</ul></div>`;
}

function footerHTML(r) {
  const b = [];
  if (canEdit(r)) {
    b.push(`<button class="btn-mini" onclick="editFromDrawer()">수정</button>`);
    b.push(`<button class="btn-mini danger" onclick="removeRequest()">삭제</button>`);
  }
  if (canClaim(r)) {
    b.push(`<button class="btn-submit" style="height:34px;padding:0 14px;font-size:13px"
      onclick="act('claim','수강료를 청구했습니다')">수강료 청구</button>`);
  }
  if (APP.me.is_admin) {
    if (r.status === S.REQUESTED) {
      b.push(`<button class="btn-submit" style="height:34px;padding:0 14px;font-size:13px"
        onclick="act('approve','승인했습니다')">수강승인</button>`);
      b.push(`<button class="btn-mini danger" onclick="reject('reject')">수강반려</button>`);
    }
    if (r.status === S.CLAIMED) {
      b.push(`<button class="btn-submit" style="height:34px;padding:0 14px;font-size:13px"
        onclick="act('claim-approve','청구를 승인했습니다')">청구승인</button>`);
      b.push(`<button class="btn-mini danger" onclick="reject('claim-reject')">청구반려</button>`);
    }
    if (r.status === S.CLAIM_APPROVED) {
      b.push(`<button class="btn-submit" style="height:34px;padding:0 14px;font-size:13px"
        onclick="act('refund','환급완료로 바꿨습니다')">환급완료</button>`);
    }
    b.push(`<span class="grow"></span>`);
    b.push(r.archived
      ? `<button class="btn-mini" onclick="act('unarchive','보관을 풀었습니다')">보관 해제</button>`
      : `<button class="btn-mini" onclick="act('archive','보관했습니다')">보관</button>`);
  }
  return b.join("");
}

/* ---------- 진행 상황 ---------- */

// 목록 행의 rail 을 상세에서 크게 펼친 것이다. 단계 정의는 railState 하나만 쓴다 —
// 목록의 점 다섯 개와 여기 스테퍼가 다른 말을 하는 일이 없어야 한다.
// 색도 rail 과 같다: 지나온 칸 초록, 지금 칸 파랑, 멈춘 칸 발간.
const STAGE_SHORT = {
  [S.REQUESTED]: "신청", [S.APPROVED]: "수강승인", [S.CLAIMED]: "청구",
  [S.CLAIM_APPROVED]: "청구승인", [S.REFUNDED]: "환급완료",
  [S.NO_REFUND]: "환급불필요", [S.REJECTED]: "수강반려", [S.CLAIM_REJECTED]: "청구반려",
};

// created_at 은 "YYYY-MM-DD HH:MM:SS" 라 날짜만 떼어 쓴다
const shortDay = v => {
  const d = (v || "").slice(0, 10).split("-");
  return d.length === 3 ? `${+d[1]}/${+d[2]}` : "";
};

// 종료일까지 며칠 남았는지. 오늘이 종료일이면 0, 지났으면 음수다
function daysLeft(r) {
  if (!r.end_date) return null;
  const end = new Date(`${r.end_date}T00:00:00`).getTime();
  const today = new Date(new Date().toDateString()).getTime();
  return Math.round((end - today) / 86400000);
}

function dueText(left) {
  if (left == null) return "";
  if (left > 0) return `${left}일 남음`;
  return left === 0 ? "오늘 종료" : `${-left}일 지남`;
}

const pctText = pct => (pct === 0 ? "시작 전" : pct === 100 ? "기간 종료" : `${pct}% 지남`);

function stageBar(r) {
  const { rail, at, stopped } = railState(r);
  if (at < 0) return "";

  const steps = rail.map((step, i) => {
    const halted = i === at && stopped;
    const cls = [
      i <= at ? "past" : "",
      i < at ? "done" : i === at ? (stopped ? "stop" : "on") : "",
    ].filter(Boolean).join(" ");
    // 칸 이름은 언제나 단계 이름이다. 멈췄더라도 그 단계까지 온 사실은 지우지 않는다 —
    // 실제 상태(반려)는 점 색과 아래 요약 줄이 말한다. 날짜만 반려 시각에서 가져온다.
    const when = shortDay(r[STATUS_AT[halted ? r.status : step]]);
    const tip = halted ? `${step} — ${r.status}` : step;
    return `<div class="dp-step${cls ? " " + cls : ""}">
      <i></i>
      <b title="${esc(tip)}">${esc(STAGE_SHORT[step] || step)}</b>
      ${when ? `<span>${esc(when)}</span>` : ""}
    </div>`;
  }).join("");

  return `<div class="dp-stage">${steps}</div>
    <p class="dp-at">${esc(rail.length ? `${at + 1} / ${rail.length} 단계` : "")}
      <b>${esc(stopped ? r.status : rail[at])}</b></p>`;
}

// 기간 막대는 "얼마나 지났는지"다 — 학습 진도가 아니라서 양쪽에 날짜를 붙여 말로 못 박는다
function periodBar(r) {
  const pct = periodPct(r);
  if (pct == null) return "";
  const left = daysLeft(r);
  const over = left != null && left < 0;
  return `<div class="dp-period">
    <div class="dp-prow">
      <span class="dp-plb">수강 기간</span>
      <span class="dp-pvl${over ? " over" : ""}">${esc(periodText(r))}${
        dueText(left) ? ` · ${esc(dueText(left))}` : ""}</span>
    </div>
    <div class="dp-pbar"><i style="width:${pct}%"></i></div>
    <div class="dp-pfoot">
      <span>${esc(shortDay(r.start_date))} 시작</span>
      <b>${esc(pctText(pct))}</b>
      <span>${esc(shortDay(r.end_date))} 종료</span>
    </div>
  </div>`;
}

// 무료 강의는 승인·청구 절차가 없어 단계도 기간 막대도 뜻이 없다 — 안내 한 줄만 남는다
function progressSection(r) {
  const stage = r.is_free ? "" : stageBar(r);
  const period = r.is_free ? "" : periodBar(r);
  if (!stage && !period) return "";
  const next = nextStepText(r);
  return `<div class="d-sec dp">
    <h4>진행 상황</h4>
    ${stage}
    ${next ? `<p class="note next">${esc(next)}</p>` : ""}
    ${period}
  </div>`;
}

function renderDrawer() {
  const r = DETAIL;
  if (!r) return;
  // rail 은 아래 "진행 상황" 스테퍼가 크게 대신한다 — 머리에서는 배지만 낸다
  document.getElementById("dTags").innerHTML =
    siteBadge(r.site) + statusBadge(r, true);
  document.getElementById("dTitle").innerHTML = r.url
    ? `<a href="${esc(r.url)}" target="_blank" rel="noopener">${esc(r.title)}</a>`
    : esc(r.title);

  const cat = [r.category_large, r.category_medium].filter(Boolean).join(" > ");
  const prog = progressSection(r);
  const next = nextStepText(r);
  document.getElementById("dBody").innerHTML = `
    ${prog || (next ? `<p class="note next">${esc(next)}</p>` : "")}
    ${reasonNote(r)}
    <dl class="kv">
      <dt>신청자</dt><dd>${whoHTML(r.applicant)}</dd>
      <dt>분류</dt><dd>${esc(cat || "—")}</dd>
      <dt>학습수준</dt><dd>${esc(r.level || "—")}</dd>
      <dt>계정</dt><dd>${esc(r.account_type)}</dd>
      <dt>수강료</dt><dd class="money">${esc(moneyText(r))}</dd>
      <dt>강의시간</dt><dd>${esc(durationText(r.duration_min) || "—")}</dd>
      <dt>강의기간</dt><dd>${esc(periodText(r) || "—")}</dd>
      <dt>신청일</dt><dd>${esc(r.created_at)}</dd>
    </dl>
    ${certSection(r)}
    ${reviewSection(r)}
    ${historySection(r)}`;
  document.getElementById("dFoot").innerHTML = footerHTML(r);
  bindStars();
}

/* ---------- 동작 ---------- */

async function refreshDetail() {
  DETAIL = await api(`/learningapi/requests/${DETAIL.id}`);
  renderDrawer();
  await reload({ keepDrawer: true });
}

async function act(action, done) {
  try {
    await postJSON(`/learningapi/requests/${DETAIL.id}/${action}`);
    showToast(done);
    await refreshDetail();
  } catch (e) {
    showToast(e.message);
  }
}

async function reject(action) {
  const label = action === "reject" ? "수강반려" : "청구반려";
  const reason = await askReason(label, "사유를 입력하면 신청자에게 그대로 보입니다.");
  if (!reason) return;
  try {
    await postJSON(`/learningapi/requests/${DETAIL.id}/${action}`, { reason });
    showToast(`${label} 처리했습니다`);
    await refreshDetail();
  } catch (e) {
    showToast(e.message);
  }
}

function editFromDrawer() {
  const r = DETAIL;
  closeDrawer();
  openForm(r);
}

async function removeRequest() {
  if (!await askConfirm("신청 삭제", "이수증까지 함께 지웁니다. 되돌릴 수 없습니다.", "삭제")) return;
  try {
    await api(`/learningapi/requests/${DETAIL.id}`, { method: "DELETE" });
    closeDrawer();
    showToast("삭제했습니다");
    await reload();
  } catch (e) {
    showToast(e.message);
  }
}

async function uploadCert(input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append("file", file);
  try {
    await api(`/learningapi/requests/${DETAIL.id}/certs`, { method: "POST", body: fd });
    showToast("이수증을 올렸습니다");
    await refreshDetail();
  } catch (e) {
    showToast(e.message);
  }
  input.value = "";
}

async function removeCert(cid) {
  if (!await askConfirm("이수증 삭제", "올린 파일을 지웁니다.", "삭제")) return;
  try {
    await api(`/learningapi/requests/${DETAIL.id}/certs/${cid}`, { method: "DELETE" });
    await refreshDetail();
  } catch (e) {
    showToast(e.message);
  }
}

function bindStars() {
  document.querySelectorAll("#dBody .stars.rw").forEach(box => {
    box.querySelectorAll("i").forEach(hit => {
      hit.onclick = () => saveScore(box.dataset.field, Number(hit.dataset.v));
    });
  });
}

async function saveScore(field, value) {
  try {
    await postJSON(`/learningapi/requests/${DETAIL.id}/review`, { [field]: value });
    await refreshDetail();
  } catch (e) {
    showToast(e.message);
  }
}

async function saveNote() {
  const note = document.getElementById("reviewNote").value;
  try {
    await postJSON(`/learningapi/requests/${DETAIL.id}/review`, { review_note: note });
    showToast("후기를 저장했습니다");
    await refreshDetail();
  } catch (e) {
    showToast(e.message);
  }
}
