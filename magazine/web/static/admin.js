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
