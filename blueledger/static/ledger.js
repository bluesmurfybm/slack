// BlueLedger 회계 장부 — 화면 도우미. 화면은 서버가 만들고, 여기서는 작은 동작만 붙인다.
(function () {
  // 포털 상단바 사용자 메뉴 열고 닫기 (출장여비 travel.js 와 같은 동작)
  const menu = document.getElementById('hdrUserMenu');
  const chip = document.getElementById('hdrChip');
  if (menu && chip) {
    chip.addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('open'); });
    document.addEventListener('click', (e) => { if (!menu.contains(e.target)) menu.classList.remove('open'); });
  }

  // 지우기 등 되돌릴 수 없는 일은 한 번 더 묻는다 (form 의 data-confirm).
  // 다른 곳에 있는 버튼(form="selDel")으로 보내도 submit 은 그 form 에서 일어난다.
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-confirm]');
    if (f && !confirm(f.dataset.confirm)) e.preventDefault();
  }, true);

  // 날짜·통장을 고르면 바로 조회
  document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // 모두 선택
  document.querySelectorAll('[data-pick-all]').forEach((all) => all.addEventListener('change', () => {
    document.querySelectorAll(all.dataset.pickAll).forEach((c) => { c.checked = all.checked; });
  }));
})();
