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
  // 장부 홈 달력: 이번 달부터 ‹ › 로 넘겨 보고, 점 찍힌 날을 누르면 오른쪽 일정 목록에서 강조
  document.querySelectorAll('[data-cal]').forEach((cal) => {
    const events = JSON.parse(cal.dataset.events || '{}');
    const today = cal.dataset.today;
    const grid = cal.querySelector('.cal-g');
    const title = cal.querySelector('.cal-t');
    const list = cal.closest('.box').querySelectorAll('.sched[data-date]');
    let y = +today.slice(0, 4), m = +today.slice(5, 7) - 1;
    const pad = (n) => String(n).padStart(2, '0');
    const pick = (d) => {
      list.forEach((s) => s.classList.toggle('hit', s.dataset.date === d));
      grid.querySelectorAll('button.cal-d').forEach((b) => b.classList.toggle('on', b.dataset.date === d));
      const first = [...list].find((s) => s.dataset.date === d);
      if (first) first.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    const draw = () => {
      title.textContent = `${y}년 ${m + 1}월`;
      const cells = ['일', '월', '화', '수', '목', '금', '토'].map((w, i) => `<div class="cal-w${i === 0 ? ' sun' : i === 6 ? ' sat' : ''}">${w}</div>`);
      const start = new Date(y, m, 1).getDay();
      const days = new Date(y, m + 1, 0).getDate();
      for (let i = 0; i < start; i++) cells.push('<div></div>');
      for (let d = 1; d <= days; d++) {
        const ds = `${y}-${pad(m + 1)}-${pad(d)}`;
        const wd = (start + d - 1) % 7;
        const cls = 'cal-d' + (wd === 0 ? ' sun' : wd === 6 ? ' sat' : '') + (ds === today ? ' today' : '');
        const ev = events[ds];
        cells.push(ev
          ? `<button type="button" class="${cls}" data-date="${ds}" title="${ev.join(', ').replace(/"/g, '&quot;')}" aria-label="${m + 1}월 ${d}일 일정: ${ev.join(', ').replace(/"/g, '&quot;')}"><span>${d}</span></button>`
          : `<div class="${cls}"${ds === today ? ' aria-current="date"' : ''}><span>${d}</span></div>`);
      }
      grid.innerHTML = cells.join('');
    };
    grid.addEventListener('click', (e) => { const b = e.target.closest('button.cal-d'); if (b) pick(b.dataset.date); });
    list.forEach((s) => s.addEventListener('click', () => {
      const d = s.dataset.date;
      y = +d.slice(0, 4); m = +d.slice(5, 7) - 1;
      draw(); pick(d);
    }));
    cal.querySelectorAll('[data-cal-move]').forEach((b) => b.addEventListener('click', () => {
      m += +b.dataset.calMove;
      if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
      draw();
    }));
    draw();
  });
})();
