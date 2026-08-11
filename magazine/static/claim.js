/* 선점 도메인: 주제 선점, 예정일 지정, 취소, 발표완료 처리. */

async function claim(id) {
  const d = await askDate("발표 예정일", "비워 두면 선점 후 '예정일' 버튼으로 정할 수 있어요.", "");
  if (d === null) return;
  try {
    await postJSON(`/magazineapi/topics/${id}/claim`, { planned_date: d });
    showToast("선점했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

/* 선점 시 날짜를 비웠거나, 정해둔 날짜를 바꿀 때 */
async function schedule(id) {
  const t = APP.topics.find(x => x.id === id);
  const d = await askDate("발표 예정일", "비워 두면 미정으로 돌아갑니다.", t ? t.planned_date : "");
  if (d === null) return;
  try {
    await postJSON(`/magazineapi/topics/${id}/schedule`, { planned_date: d });
    showToast(d ? "예정일을 정했습니다" : "예정일을 지웠습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function release(id) {
  try {
    await postJSON(`/magazineapi/topics/${id}/release`);
    showToast("선점을 취소했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function complete(id) {
  const d = await askDate("발표일", "비워 두면 오늘 날짜로 기록됩니다.", today());
  if (d === null) return;
  try {
    await postJSON(`/magazineapi/topics/${id}/complete`, { done_date: d });
    showToast("발표완료 처리했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}
