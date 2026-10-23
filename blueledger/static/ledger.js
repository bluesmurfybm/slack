// BlueLedger 회계 장부 — 화면 도우미. 화면은 서버가 만들고, 여기서는 작은 동작만 붙인다.
(function () {
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
  // 출력(자금일보·출납장·세무사 전달용)은 팝업 창에서 미리보기 → 인쇄·엑셀로 저장.
  // 팝업이 막힌 브라우저면 지금 창에서 연다.
  const popup = (url) => {
    const w = window.open(url, 'lg_print', 'width=1180,height=860,scrollbars=yes,resizable=yes');
    if (w) w.focus(); else location.href = url;
  };
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-popup]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    popup(a.href);
  });
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-popup]');
    if (!f || e.defaultPrevented) return;
    e.preventDefault();
    popup(f.action + '?' + new URLSearchParams(new FormData(f)));
  });

  // 미리보기 창: 시트 고르기 · 인쇄 · 닫기
  document.querySelectorAll('[data-sheet-tab]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.sheetTab;
    document.querySelectorAll('[data-sheet]').forEach((s) => { s.hidden = k !== 'all' && s.dataset.sheet !== k; });
    document.querySelectorAll('[data-sheet-tab]').forEach((t) => { t.classList.toggle('on', t === b); t.setAttribute('aria-pressed', t === b ? 'true' : 'false'); });
  }));
  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => {
    window.close();
    if (!window.closed) history.length > 1 ? history.back() : (location.href = 'ledger.php');
  }));
})();
