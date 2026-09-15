async function loadRelated(id) {
  const box = document.getElementById("relatedBox");
  if (!box) return;
  try {
    const items = await api(`/magazineapi/topics/${id}/related`);
    if (DRAWER_ID !== id) return; // 응답이 오기 전에 다른 아티클을 열었다
    box.innerHTML = relatedItemsHtml(items);
  } catch (e) {
    box.innerHTML = '<p class="note">연관 아티클을 불러오지 못했습니다.</p>';
  }
}

function relatedItemsHtml(items) {
  if (!items.length) {
    return '<p class="note">분야·키워드가 겹치는 아티클이 아직 없습니다.</p>';
  }
  return items.map(r => `<div class="rel">
    <span class="score">연관 ${r.score}%</span>
    <span class="rel-main">
      <b>${esc(r.title)}</b>
      <span class="muted">${esc(sourceOf(r) || "—")}${r.field ? ` · ${esc(r.field)}` : ""}</span>
    </span>
    <button class="btn-mini" onclick="openDrawer(${r.id})">보기</button>
  </div>`).join("");
}
