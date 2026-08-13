let DRAWER_ID = null;

function openDrawer(id) {
  const t = APP.topics.find(x => x.id === id);
  if (!t) return;
  DRAWER_ID = id;
  document.getElementById("drawer").innerHTML = drawerHtml(t);
  document.getElementById("drawer").classList.add("open");
  document.getElementById("scrim").classList.add("open");
}

function closeDrawer() {
  DRAWER_ID = null;
  document.getElementById("drawer").classList.remove("open");
  document.getElementById("scrim").classList.remove("open");
}

function refreshDrawer() {
  if (DRAWER_ID === null) return;
  if (APP.topics.some(t => t.id === DRAWER_ID)) openDrawer(DRAWER_ID);
  else closeDrawer();     // 삭제되거나 숨겨져서 목록에서 빠진 주제
}

function drawerHtml(t) {
  return `<header>
      <div class="d-head">
        <div class="d-tags">${teamTag(t)}${reqBadge(t)}${railHtml(t)}${stateTags(t)}</div>
        <h3>${esc(t.title)}</h3>
      </div>
      <button class="x" onclick="closeDrawer()" aria-label="닫기">×</button>
    </header>
    <div class="d-body">
      <dl class="kv">
        <dt>매거진</dt><dd>${esc(sourceOf(t) || "—")}${t.year ? ` · ${t.year}년` : ""}</dd>
        <dt>분야 / 키워드</dt><dd>${esc(t.field || "—")} · ${esc(t.keywords || "—")}</dd>
        <dt>발표자</dt><dd>${t.presenter ? esc(t.presenter)
      : (t.team ? `${esc(t.team)} 팀 배정` : '<span class="muted">아직 없음</span>')}</dd>
        <dt>${t.done_date ? "발표일" : "발표 예정일"}</dt>
        <dd>${esc(t.done_date || t.planned_date) || '<span class="muted">미정</span>'}</dd>
        ${t.note ? `<dt>비고</dt><dd>${esc(t.note)}</dd>` : ""}
      </dl>

      <section class="d-sec">
        <h4>발표 자료</h4>
        ${materialSlot(t)}
      </section>

      <section class="d-sec">
        <h4>연관 아티클</h4>
        ${relatedListHtml(t)}
      </section>
    </div>
    <footer>${drawerActions(t)}</footer>`;
}

function materialSlot(t) {
  const may = canManageMaterial(t);
  if (!t.material_kind) {
    return `<div class="drop">
      <span class="ic">📄</span>
      <span class="t"><b>아직 올린 자료가 없습니다</b>
        <span>스캔한 원본이나 발표용 자료 (파일 또는 링크)</span></span>
      ${may ? `<button class="btn-mini mat" onclick="openMaterial(${t.id})">자료 올리기</button>`
      : '<span class="chip ghost">미등록</span>'}
    </div>${may ? "" : '<p class="note">자료 등록은 발표자 본인이나 관리자만 할 수 있습니다.</p>'}`;
  }
  const name = t.material_name || (t.material_kind === "link" ? "링크" : "파일");
  return `<div class="drop filled">
    <span class="ic">${t.material_kind === "link" ? "🔗" : "📎"}</span>
    <span class="t"><b>${esc(name)}</b><span>등록 완료</span></span>
    <button class="btn-mini" onclick="openViewer(${t.id})">열기</button>
    ${may ? `<button class="btn-mini mat has" onclick="openMaterial(${t.id})">변경</button>` : ""}
  </div>`;
}

function relatedListHtml(t) {
  const found = relatedTo(t);
  if (!found.length) {
    return '<p class="note">분야·키워드가 겹치는 아티클이 아직 없습니다.</p>';
  }
  return found.map(r => `<div class="rel">
    <span class="score">연관 ${r.score}%</span>
    <span class="rel-main">
      <b>${esc(r.topic.title)}</b>
      <span class="muted">${esc(sourceOf(r.topic) || "—")}${r.topic.field ? ` · ${esc(r.topic.field)}` : ""}</span>
    </span>
    <button class="btn-mini" onclick="openDrawer(${r.topic.id})">보기</button>
  </div>`).join("");
}

function drawerActions(t) {
  const out = [];
  const mine = t.presenter_email === APP.me.email;
  if (t.status === "미지정") {
    out.push(`<button class="btn-submit grow" onclick="claim(${t.id})">내가 발표할게요</button>`);
  } else if (mine && t.status !== "발표완료") {
    out.push(`<button class="btn-ghost" onclick="schedule(${t.id})">예정일 변경</button>`);
    out.push(`<button class="btn-ghost" onclick="release(${t.id})">선점 취소</button>`);
  }
  if (APP.me.is_admin) {
    out.push(`<button class="btn-ghost" onclick="openAssign(${t.id})">발표자 지정</button>`);
    if (t.status === "발표예정") {
      out.push(`<button class="btn-ghost" onclick="complete(${t.id})">발표완료</button>`);
    }
    out.push(`<button class="btn-ghost" onclick="openEdit(${t.id})">수정</button>`);
  }
  out.push('<button class="btn-ghost" onclick="closeDrawer()">닫기</button>');
  return out.join("");
}
