let CAT_PICK = false;

function catTree(pickable) {
  const tree = new Map();
  for (const c of APP.categories) {
    if (pickable && !c.active) continue;
    if (!tree.has(c.site)) tree.set(c.site, new Map());
    const larges = tree.get(c.site);
    if (!c.medium) {
      larges.set(c.large, larges.get(c.large) || { row: null, mediums: [] });
      larges.get(c.large).row = c;
    } else {
      larges.set(c.large, larges.get(c.large) || { row: null, mediums: [] });
      larges.get(c.large).mediums.push(c);
    }
  }
  return tree;
}

function chipHTML(site, large, c) {
  const cls = ["catchip", c.recommended ? "rec" : "", c.active ? "" : "off"]
    .filter(Boolean).join(" ");
  const label = esc(c.medium) + (c.recommended ? " ★" : "");
  if (!CAT_PICK) return `<span class="${cls}">${label}</span>`;
  return `<button type="button" class="${cls}"
    onclick="pickCategory(${c.id})">${label}</button>`;
}

function cardHTML(site, large, node) {
  const off = node.row && !node.row.active;
  const chips = node.mediums.map(c => chipHTML(site, large, c)).join("");
  const star = node.row && node.row.recommended ? " ★" : "";
  return `<div class="catcard${off ? " off" : ""}" style="border-left-color:${siteTheme(site).fg}">
    <div class="t"><b>${esc(large)}${star}</b><span>${node.mediums.length}</span></div>
    ${chips ? `<div class="catchips">${chips}</div>`
      : '<p class="catnone">중분류가 없습니다</p>'}
  </div>`;
}

function openCategoryBrowser(pick) {
  CAT_PICK = !!pick;
  const tree = catTree(CAT_PICK);
  document.getElementById("catsTitle").textContent =
    CAT_PICK ? "분류를 골라 주세요" : "플랫폼별 카테고리";

  const body = [...tree.entries()].map(([site, larges]) => {
    const mediums = [...larges.values()].reduce((n, v) => n + v.mediums.length, 0);
    const cards = [...larges.entries()].map(([large, node]) => cardHTML(site, large, node));
    return `<section class="catsite">
      <div class="head">
        <b style="color:${siteTheme(site).fg}">${esc(site)}</b>
        <span class="chip ghost">대분류 ${larges.size} · 중분류 ${mediums}</span>
      </div>
      <div class="catgrid">${cards.join("")}</div>
    </section>`;
  }).join("");

  document.getElementById("catsBody").innerHTML = body ||
    '<p class="muted">등록된 분류가 없습니다.</p>';
  document.getElementById("catsOverlay").classList.add("open");
}

function closeCats() {
  document.getElementById("catsOverlay").classList.remove("open");
}

// 폼에서 열었을 때만 고를 수 있다. 화면에 없는 사이트가 골리면 폼이 조용히 어긋나므로
// 사이트 셀렉트에 실제로 있는지 확인하고 넣는다.
function pickCategory(cid) {
  const c = APP.categories.find(x => x.id === cid);
  if (!c) return;
  const site = document.getElementById("i-site");
  if (![...site.options].some(o => o.value === c.site)) {
    showToast(`${c.site} 은(는) 지금 신청할 수 없는 플랫폼입니다`);
    return;
  }
  site.value = c.site;
  onSiteChange({ large: c.large, medium: c.medium });
  closeCats();
}
