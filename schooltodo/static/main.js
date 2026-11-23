async function loadSheets() {
  try {
    APP.sheets = await api("/sheets?all=1");   // 보관한 것까지 (보관함)
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
    return;
  }
  // 링크로 온 탭은 보관한 것이어도 연다. 아니면 보관하지 않은 첫 탭.
  const linked = parseInt(new URLSearchParams(location.search).get("sheet") || "", 10);
  const first = APP.sheets.find((s) => s.id === linked) || activeSheets()[0];
  if (first) await selectSheet(first.id);
  else renderTabs();
}

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") { closeSheetForm(); closeConfirm(); closeColMenu(); closeTabMenu(); closeArchive(); closeHistory(); closeVerBulk(); }
});

loadSheets();
