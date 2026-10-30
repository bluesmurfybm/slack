/* ---------- 필터 ---------- */

function buildYearSelect() {
  const sel = document.getElementById("yearSel");
  sel.innerHTML = `<option value="all">전체 연도</option>`
    + APP.years.map((y) => `<option value="${y}">${y}년</option>`).join("");
  sel.value = APP.years.includes(APP.year) ? String(APP.year) : "all";
  if (sel.value === "all") APP.year = "all";
}
function onYear(v) {
  APP.year = v === "all" ? "all" : parseInt(v, 10);
  APP.editingId = null;
  render();
}

// 신청자 칩은 포털 계정 순서다. 신청 건이 있는 계정 없는 이름(퇴사자)은 뒤에 붙인다
function applicantNames() {
  const names = APP.members.map((m) => m.name.trim());
  for (const d of APP.data) {
    const n = String(d.applicant || "").trim();
    if (n && !names.includes(n)) names.push(n);
  }
  return names;
}
function buildApplicantFilter() {
  const inYear = (d) => APP.year === "all" || d.year === APP.year;
  document.getElementById("applicantFilter").innerHTML = applicantNames().map((n) => {
    const on = n === APP.filterApplicant;
    const cnt = APP.data.filter((d) => String(d.applicant || "").trim() === n && inYear(d)).length;
    const c = colorFor({ applicant: n });
    const style = on ? `background:${c};border-color:${c};color:#fff` : "";
    return `<button type="button" class="chip pick${on ? " on" : ""}" style="${style}" onclick="filterByApplicant('${esc(n)}')">
      <i class="dot" style="background:${esc(c)}"></i>${esc(n)} <span class="n">${cnt}</span></button>`;
  }).join("");
}
function filterByApplicant(n) {
  APP.filterApplicant = APP.filterApplicant === n ? "" : n;
  render();
}

/* ---------- 목록 ---------- */

function render() {
  buildApplicantFilter();
  const raw = document.getElementById("q").value.trim();
  const q = normTitle(raw);
  let rows = APP.data.slice();
  const titleEl = document.getElementById("ledgerTitle");
  if (q) {
    // 검색은 연도와 무관하게 전체에서 찾는다 — "그 책 언제 샀더라" 가 검색의 용도다
    rows = rows.filter((d) => normTitle(d.title).includes(q));
    titleEl.textContent = `"${raw}" 검색 결과`;
  } else {
    if (APP.year !== "all") rows = rows.filter((d) => d.year === APP.year);
    titleEl.textContent = APP.year === "all" ? "전체 신청 목록" : `${APP.year}년 신청 목록`;
  }
  if (APP.filterApplicant) rows = rows.filter((d) => String(d.applicant || "").trim() === APP.filterApplicant);
  rows.sort((a, b) => (b.date || "").localeCompare(a.date || "") || b.id - a.id);
  document.getElementById("ledgerCount").textContent = `${rows.length}건`;

  const totalEl = document.getElementById("applicantTotal");
  if (APP.filterApplicant) {
    const sum = rows.reduce((s, d) => s + (displayPrice(d) || 0), 0);
    document.getElementById("applicantTotalLabel").textContent = `${APP.filterApplicant}님 합계`;
    document.getElementById("applicantTotalSum").textContent = `${fmt(sum)}원`;
    totalEl.style.display = "flex";
  } else {
    totalEl.style.display = "none";
  }

  const list = document.getElementById("list");
  if (!rows.length) {
    list.innerHTML = `<li class="empty"><div class="big">신청 내역이 없어요</div><div>오른쪽 위 <b>신청</b> 단추로 도서를 신청해 보세요.</div></li>`;
    return;
  }
  list.innerHTML = rows.map((d) => (d.id === APP.editingId ? editRow(d) : viewRow(d))).join("");
}

function canEdit(d) {
  return APP.me.is_admin || (d.applicant_email && APP.me.email
    && d.applicant_email.toLowerCase() === APP.me.email.toLowerCase());
}

function viewRow(d) {
  const rel = d.gubun === "직무관련";
  const admin = APP.me.is_admin;
  const status = d.done
    ? `<button type="button" class="badge st-done${admin ? " act" : ""}" ${admin ? `onclick="toggle(${d.id})" title="누르면 미처리로 되돌립니다"` : 'title="완료 처리는 관리자만 합니다"'}>✓ 완료</button>`
    : `<button type="button" class="badge st-wait${admin ? " act" : ""}" ${admin ? `onclick="toggle(${d.id})" title="누르면 처리완료로 표시합니다"` : 'title="완료 처리는 관리자만 합니다"'}>미처리</button>`;
  const actions = canEdit(d)
    ? `<button type="button" class="btn-mini" onclick="startEdit(${d.id})">수정</button>
       <button type="button" class="btn-mini danger" onclick="askDelete(${d.id})">삭제</button>`
    : "";
  const href = d.kyobo_url
    || `https://search.kyobobook.co.kr/search?keyword=${encodeURIComponent((d.title || "") + (d.author ? " " + d.author : ""))}`;
  const thumb = d.thumbnail
    ? `<img src="${esc(d.thumbnail)}" alt="" loading="lazy">`
    : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>`;
  return `<li class="row${d.done ? " done" : ""}">
    <div class="r-thumb">${thumb}</div>
    <div class="row-main">
      <div class="row-top">
        ${whoHTML(d)}
        <span class="badge gubun ${rel ? "rel" : "unrel"}">${esc(d.gubun || "미분류")}</span>
        <span class="when">${shortDate(d.date)}</span>
      </div>
      <a class="row-title" href="${esc(href)}" target="_blank" rel="noopener"
         title="${d.kyobo_url ? "교보문고 상품 페이지" : "교보문고에서 검색"}">${esc(d.title)}</a>
      <div class="row-sub muted">${esc(d.author) || "저자 미상"}<span class="sep">·</span>${esc(d.publisher) || "출판사 미상"}</div>
      ${d.note ? `<div class="note-line">${esc(d.note)}</div>` : ""}
    </div>
    <div class="row-rail">
      <div class="r-price">${fmt(displayPrice(d))}<span class="won">원</span></div>
      ${rel ? `<div class="price-sub">단가 50% 지원</div>` : ""}
      <div class="r-status">${status}</div>
      <div class="row-act">${actions}</div>
    </div>
  </li>`;
}

function editRow(d) {
  return `<li class="row editing">
    <div class="r-thumb">${d.thumbnail ? `<img src="${esc(d.thumbnail)}" alt="">` : ""}</div>
    <div class="row-main">
      <div class="row-top">${whoHTML(d)}<span class="when">${shortDate(d.date)}</span></div>
      <input id="ed-title" class="ef title" value="${esc(d.title)}" placeholder="도서명" oninput="onEditTitleInput()" autocomplete="off">
      <div class="booksearch" id="ed-booksearch"></div>
      <div class="egrid">
        <input id="ed-author" class="ef" value="${esc(d.author)}" placeholder="저자">
        <input id="ed-pub" class="ef" value="${esc(d.publisher)}" placeholder="출판사">
      </div>
      <div class="egrid">
        <select id="ed-gubun" class="ef">
          <option value="직무관련" ${d.gubun === "직무관련" ? "selected" : ""}>직무관련</option>
          <option value="직무무관" ${d.gubun === "직무무관" ? "selected" : ""}>직무무관</option>
        </select>
        <input id="ed-price" class="ef" type="text" inputmode="numeric" value="${d.price == null ? "" : d.price.toLocaleString("ko-KR")}" placeholder="단가(원)" oninput="formatPriceInput(this)">
      </div>
      <input id="ed-note" class="ef" value="${esc(d.note)}" placeholder="비고">
      <input id="ed-kyobo" class="ef" value="${esc(d.kyobo_url)}" placeholder="교보문고 링크 (비우면 자동으로 찾습니다)">
    </div>
    <div class="row-rail">
      <div class="row-act">
        <button type="button" class="btn-mini primary" onclick="saveEdit(${d.id})">저장</button>
        <button type="button" class="btn-mini" onclick="cancelEdit()">취소</button>
      </div>
    </div>
  </li>`;
}

/* ---------- 완료 토글 · 수정 · 삭제 ---------- */

async function toggle(id) {
  const d = APP.data.find((x) => x.id === id);
  if (!d) return;
  const nv = !d.done;
  d.done = nv;
  render();
  try {
    Object.assign(d, await sendJSON("PUT", `/bookapi/requests/${id}`, { done: nv }));
    render();
    showToast(nv ? `"${d.title}" 처리완료` : `"${d.title}" 미처리로 되돌림`);
  } catch (e) {
    d.done = !nv;
    render();
    showToast(e.message);
  }
}

function startEdit(id) {
  APP.editingId = id;
  const d = APP.data.find((x) => x.id === id);
  editPickedThumbnail = d ? (d.thumbnail || "") : "";
  render();
}
function cancelEdit() {
  APP.editingId = null;
  render();
}
async function saveEdit(id) {
  const d = APP.data.find((x) => x.id === id);
  if (!d) return;
  const t = document.getElementById("ed-title").value.trim();
  if (!t) { showToast("도서명을 입력해 주세요"); return; }
  const patch = {
    title: t,
    author: document.getElementById("ed-author").value.trim(),
    publisher: document.getElementById("ed-pub").value.trim(),
    gubun: document.getElementById("ed-gubun").value,
    price: parsePriceInput(document.getElementById("ed-price").value),
    note: document.getElementById("ed-note").value.trim(),
    thumbnail: editPickedThumbnail || null,
  };
  const k = document.getElementById("ed-kyobo").value.trim();
  if (k !== (d.kyobo_url || "")) patch.kyobo_url = k;
  try {
    const saved = await sendJSON("PUT", `/bookapi/requests/${id}`, patch);
    Object.assign(d, saved);
    APP.editingId = null;
    render();
    showToast("수정 완료");
  } catch (e) {
    showToast(e.message);
  }
}

function askDelete(id) {
  const d = APP.data.find((x) => x.id === id);
  if (!d) return;
  APP.pendingDelete = id;
  document.getElementById("confirmText").textContent = `"${d.title}" 신청을 삭제할까요? 되돌릴 수 없어요.`;
  document.getElementById("confirmOverlay").classList.add("open");
}
function closeConfirm() {
  APP.pendingDelete = null;
  document.getElementById("confirmOverlay").classList.remove("open");
}
async function doDelete() {
  const id = APP.pendingDelete;
  closeConfirm();
  if (id == null) return;
  const d = APP.data.find((x) => x.id === id);
  try {
    await api(`/bookapi/requests/${id}`, { method: "DELETE" });
    APP.data = APP.data.filter((x) => x.id !== id);
    if (APP.editingId === id) APP.editingId = null;
    render();
    showToast(d ? `"${d.title}" 삭제됨` : "삭제됨");
  } catch (e) {
    showToast(e.message);
  }
}
