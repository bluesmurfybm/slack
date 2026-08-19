function adminActionsHtml(t) {
  if (APP.view.tab === "archive") {
    return `<button class="btn-mini" onclick="setArchived(${t.id},0)">보관 해제</button>
      <button class="btn-mini ghost" onclick="openDrawer(${t.id})">상세</button>`;
  }
  const out = [`<span class="toggle" title="구성원 화면 노출">
    <button type="button" class="switch${t.active ? " on" : ""}" aria-label="노출 전환"
      onclick="setActive(${t.id},${t.active ? 0 : 1})"></button>${t.active ? "노출" : "숨김"}</span>`];
  out.push(`<button class="btn-mini" onclick="openAssign(${t.id})">발표자 지정</button>`);
  if (t.status === "발표예정") {
    out.push(`<button class="btn-mini" onclick="complete(${t.id})">발표완료</button>`);
  }
  if (t.status === "발표완료") {
    out.push(`<button class="btn-mini" onclick="setArchived(${t.id},1)">보관함으로</button>`);
  }
  out.push(`<button class="btn-mini ghost" onclick="openEdit(${t.id})">수정</button>`);
  out.push(`<button class="btn-mini ghost danger" onclick="askDelete(${t.id})">삭제</button>`);
  return out.join("");
}

async function patchTopic(id, patch, message) {
  try {
    await putJSON(`/magazineapi/topics/${id}`, patch);
    showToast(message);
    await reload();
  } catch (e) { showToast(e.message); }
}

function setActive(id, active) {
  patchTopic(id, { active }, active
    ? "구성원 화면에 노출합니다"
    : "구성원 화면에서 숨겼습니다 (데이터는 유지)");
}

function setArchived(id, archived) {
  patchTopic(id, { archived }, archived ? "보관함으로 옮겼습니다" : "보관을 해제했습니다");
}

function renderFieldsPage() {
  const usedCount = name => APP.topics.filter(t => t.field === name).length;
  document.getElementById("fieldsPage").innerHTML = `
    <div class="fields-card">
      <h3>분야 관리</h3>
      <p class="muted">주제 등록 폼의 분야 선택지입니다.
        삭제해도 이미 등록된 주제의 분야는 바뀌지 않습니다.</p>
      <ul class="fields-list">
        ${APP.fields.map(f => `<li>
          <span class="name">${esc(f.name)}</span>
          <span class="muted">${usedCount(f.name) ? `${usedCount(f.name)}건 사용 중` : ""}</span>
          <button class="btn-mini ghost danger" onclick="deleteField(${f.id})">삭제</button>
        </li>`).join("")}
      </ul>
      <div class="fields-add">
        <input id="newFieldName" type="text" placeholder="새 분야 이름"
          onkeydown="if(event.key==='Enter')addField()">
        <button class="btn-mini primary" onclick="addField()">추가</button>
      </div>
    </div>`;
}

async function refreshFields() {
  APP.fields = await api("/magazineapi/fields");
  buildFormOptions();
  render();
}

async function addField() {
  const name = document.getElementById("newFieldName").value.trim();
  if (!name) { showToast("분야 이름을 입력해 주세요"); return; }
  try {
    await postJSON("/magazineapi/fields", { name });
    showToast(`"${name}" 분야를 추가했습니다`);
    await refreshFields();
  } catch (e) { showToast(e.message); }
}

async function deleteField(id) {
  const f = APP.fields.find(x => x.id === id);
  try {
    await api(`/magazineapi/fields/${id}`, { method: "DELETE" });
    showToast(`"${f ? f.name : ""}" 분야를 삭제했습니다`);
    await refreshFields();
  } catch (e) { showToast(e.message); }
}
