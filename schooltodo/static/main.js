async function loadSheets() {
  try {
    APP.sheets = await api("/sheets");
  } catch (e) {
    if (e.message !== "unauthenticated") showToast(e.message);
    return;
  }
  const linked = parseInt(new URLSearchParams(location.search).get("sheet") || "", 10);
  const first = APP.sheets.find((s) => s.id === linked) || APP.sheets[0];
  if (first) await selectSheet(first.id);
  else renderTabs();
}

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") { closeSheetForm(); closeConfirm(); closeColMenu(); }
});

loadSheets();
