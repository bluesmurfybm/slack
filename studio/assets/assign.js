/* =====================================================================
   BlueStudio client

   의존 라이브러리 없음. iworks 페이지 안에 얹히므로 전역을 오염시키지
   않도록 IIFE 안에 전부 가둔다(BlueCart assets/app.js 와 같은 방식).

   상단바 드롭다운과 로그아웃은 inc/layout.php 가 직접 들고 있다.
   여기서 다시 붙이지 말 것 — 리스너가 두 번 걸린다.
   ===================================================================== */
(function () {
  'use strict';

  var app = document.getElementById('ba-app');
  if (!app) return;

  var CSRF     = app.dataset.csrf;
  var ME_ID    = app.dataset.meId;
  var IS_ADMIN = app.dataset.isAdmin === '1';
  var NAV      = app.dataset.nav;

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // 화면 여러 곳에서 같은 말을 써야 한다. project_form.php 의 $parseLabel 과 맞춘다.
  var PARSE_LABEL = {
    pending: '분석 대기', ok: '분석 완료', fail: '분석 실패', skip: '분석 안 함'
  };

  var STATUS_ORDER = ['', 'draft', 'scoping', 'allocating', 'confirmed', 'running', 'hold', 'done'];
  var STATUS_LABEL = {
    '': '전체', draft: '작성 중', scoping: '범위 정리', allocating: '배정 중',
    confirmed: '배정 확정', running: '진행 중', done: '완료', hold: '보류'
  };

  // ---- 통신 ---------------------------------------------------------
  //
  // 응답 형식은 inc/helpers.php 가 정한 것을 따른다.
  //   성공  {"ok":true,  "data":{...}}
  //   실패  {"ok":false, "error":{"code":"...","message":"..."}}

  function api(url, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: {} };
    if (init.method === 'POST') {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = CSRF;
      init.body = JSON.stringify(opts.body || {});
    }
    return fetch(url, init).then(function (res) {
      return res.json().catch(function () {
        throw new Error('서버 응답을 읽지 못했습니다.');
      }).then(function (json) {
        if (!res.ok || !json.ok) {
          var err = (json && json.error) || {};
          var e = new Error(err.message || '처리에 실패했습니다.');
          e.code = err.code || 'UNKNOWN';
          throw e;
        }
        return json.data;
      });
    });
  }

  // multipart 업로드. Content-Type 을 브라우저가 정해야 해서 api() 와 다르다.
  function upload(url, formData) {
    formData.append('_csrf', CSRF);
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF },
      body: formData
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('업로드 응답을 읽지 못했습니다. 파일 크기를 확인해 보세요.');
      }).then(function (json) {
        if (!res.ok || !json.ok) {
          throw new Error(((json && json.error) || {}).message || '업로드에 실패했습니다.');
        }
        return json.data;
      });
    });
  }

  function qs(params) {
    var out = [];
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v === null || v === undefined || v === '') return;
      if (Array.isArray(v)) {
        v.forEach(function (x) { out.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(x)); });
      } else {
        out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    });
    return out.join('&');
  }

  // ---- 토스트 -------------------------------------------------------
  var toastTimer;
  function toast(msg, bad) {
    var el = $('#ba-toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'ba-toast' + (bad ? ' ba-toast--bad' : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3600);
  }

  function showError(sel, msg) {
    var el = $(sel);
    if (!el) { toast(msg, true); return; }
    el.textContent = msg;
    el.hidden = false;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }
  function clearError(sel) {
    var el = $(sel);
    if (el) el.hidden = true;
  }

  // =====================================================================
  // 프로젝트 등록 / 수정
  // =====================================================================
  function initProjectForm() {
    var form = $('#ba-form');
    if (!form) return;

    var pid = parseInt(form.dataset.projectId, 10) || 0;

    // ---- 기간 역전 검사 ---------------------------------------------
    //
    // 서버(ProjectRepo::assertPeriods)와 **같은 규칙**이어야 한다.
    // 한쪽만 고치면 화면은 통과시키는데 저장이 안 되는 상태가 된다.
    var P = {
      devS:   $('#ba-in-dev-start'),
      devE:   $('#ba-in-dev-end'),
      testS:  $('#ba-in-test-start'),
      testE:  $('#ba-in-test-end'),
      deploy: $('#ba-in-deploy')
    };

    function checkPeriods() {
      var v = {};
      Object.keys(P).forEach(function (k) { v[k] = P[k] ? P[k].value : ''; });

      var bad = [], msg = '';

      if (v.devS && v.devE && v.devS > v.devE) {
        bad.push(P.devS, P.devE);
        msg = '개발 기간이 뒤집혔습니다. 시작일이 종료일보다 늦습니다.';
      } else if (v.testS && v.testE && v.testS > v.testE) {
        bad.push(P.testS, P.testE);
        msg = '테스트 기간이 뒤집혔습니다. 시작일이 종료일보다 늦습니다.';
      } else if (v.devS && v.testS && v.testS < v.devS) {
        bad.push(P.testS);
        msg = '테스트 시작일이 개발 시작일보다 빠릅니다.';
      } else if (v.devS && v.deploy && v.deploy < v.devS) {
        bad.push(P.deploy);
        msg = '배포일이 개발 시작일보다 빠릅니다.';
      } else if (v.testS && v.deploy && v.deploy < v.testS) {
        bad.push(P.deploy);
        msg = '배포일이 테스트 시작일보다 빠릅니다.';
      }

      Object.keys(P).forEach(function (k) {
        if (P[k]) P[k].classList.toggle('is-bad', bad.indexOf(P[k]) >= 0);
      });

      var warn = $('#ba-period-warn');
      if (warn) {
        warn.textContent = msg;
        warn.hidden = msg === '';
      }
      return msg === '';
    }

    Object.keys(P).forEach(function (k) {
      if (P[k]) P[k].addEventListener('change', checkPeriods);
    });
    checkPeriods();

    // ---- 저장 --------------------------------------------------------
    function collect() {
      return {
        name:        ($('#ba-in-name')    || {}).value || '',
        client:      ($('#ba-in-client')  || {}).value || '',
        track:       ($('#ba-in-track')   || {}).value || '',
        summary:     ($('#ba-in-summary') || {}).value || '',
        dev_start:   P.devS   ? P.devS.value   : '',
        dev_end:     P.devE   ? P.devE.value   : '',
        test_start:  P.testS  ? P.testS.value  : '',
        test_end:    P.testE  ? P.testE.value  : '',
        deploy_date: P.deploy ? P.deploy.value : '',
        notes:       ($('#ba-in-notes') || {}).value || '',
        extra:       ($('#ba-in-extra') || {}).value || ''
      };
    }

    var saveBtn = $('#ba-save');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        clearError('#ba-form-error');

        var body = collect();
        if (!body.name.trim()) {
          showError('#ba-form-error', '프로젝트명을 입력하세요.');
          return;
        }
        if (!checkPeriods()) {
          showError('#ba-form-error', $('#ba-period-warn').textContent);
          return;
        }

        var statusSel = $('#ba-in-status');
        if (statusSel) body.status = statusSel.value;

        saveBtn.disabled = true;

        var url = 'api/project.php?act=' + (pid ? 'update' : 'create');
        if (pid) body.id = pid;

        api(url, { method: 'POST', body: body }).then(function (data) {
          if (!pid) {
            // 새로 만들었으면 수정 화면으로 옮겨 간다 — 출처 등록에 project_id 가 필요하다.
            location.href = 'project_form.php?id=' + data.id + '&created=1';
            return;
          }
          toast(data.message || '저장했습니다.');
          saveBtn.disabled = false;
        }).catch(function (e) {
          showError('#ba-form-error', e.message);
          saveBtn.disabled = false;
        });
      });
    }

    // 방금 만든 경우 안내
    if (/[?&]created=1/.test(location.search)) {
      toast('프로젝트를 등록했습니다. 이어서 개발 범위 문서를 올리세요.');
    }

    // ---- 삭제 (소프트) ------------------------------------------------
    var delBtn = $('#ba-delete');
    if (delBtn) {
      delBtn.addEventListener('click', function () {
        var code = form.dataset.code || '';
        // 실수 삭제를 막으려고 서버가 코드 재입력을 요구한다. 화면에서도 같은 것을 묻는다.
        var typed = window.prompt(
          '이 프로젝트를 삭제합니다.\n' +
          '태스크·배정·진행 기록은 지워지지 않고 목록에서만 감춰집니다.\n\n' +
          '확인을 위해 프로젝트 코드를 그대로 입력하세요: ' + code
        );
        if (typed === null) return;
        if (typed.trim() !== code) {
          toast('코드가 일치하지 않습니다.', true);
          return;
        }
        var reason = window.prompt('삭제 사유 (선택)') || '';

        api('api/project.php?act=delete', {
          method: 'POST',
          body: { id: pid, confirm: typed.trim(), reason: reason }
        }).then(function () {
          location.href = 'project_list.php';
        }).catch(function (e) {
          showError('#ba-form-error', e.message);
        });
      });
    }

    // ---- 출처 등록 ---------------------------------------------------
    if (pid) initSourcePanel(pid);
  }

  // ---- 출처 패널 ------------------------------------------------------
  function initSourcePanel(pid) {
    // 종류 탭
    $$('.ba-source-tabs [data-src-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var kind = btn.dataset.srcTab;
        $$('.ba-source-tabs [data-src-tab]').forEach(function (b) {
          b.setAttribute('aria-selected', String(b === btn));
        });
        $$('[data-src-pane]').forEach(function (p) {
          p.hidden = p.dataset.srcPane !== kind;
        });
      });
    });

    function renderSources(rows) {
      var box = $('#ba-srclist');
      if (!box) return;
      if (!rows || !rows.length) {
        box.innerHTML = '<p class="ba-empty-inline">아직 등록된 문서가 없습니다.</p>';
        return;
      }
      box.innerHTML = rows.map(function (s) {
        var meta = s.size_label ? esc(s.size_label)
                 : (s.parsed_len ? Number(s.parsed_len).toLocaleString('ko-KR') + '자' : '');
        var title = s.url
          ? '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.title) + '</a>'
          : esc(s.title);
        return '<div class="ba-src" data-id="' + s.id + '">' +
               '<span class="ba-src__kind">' + esc(s.kind_label) + '</span>' +
               '<span class="ba-src__title">' + title + '</span>' +
               '<span class="ba-src__meta">' + meta + '</span>' +
               '<span class="ba-src__parse ba-src__parse--' + esc(s.parse_status) + '">' +
                 esc(PARSE_LABEL[s.parse_status] || s.parse_status) +
               '</span>' +
               '<button type="button" class="ba-btn ba-btn--sm ba-btn--danger" data-src-del="' + s.id + '">삭제</button>' +
               '</div>';
      }).join('');
    }

    function afterChange(data) {
      renderSources(data.sources);
      if (data.failed && data.failed.length) {
        showError('#ba-form-error',
          '일부 파일을 올리지 못했습니다.\n' +
          data.failed.map(function (f) { return '· ' + f.name + ' — ' + f.message; }).join('\n'));
      } else {
        clearError('#ba-form-error');
      }
      toast(data.message || '등록했습니다.');
    }

    // 파일
    var upBtn = $('#ba-src-upload');
    if (upBtn) {
      upBtn.addEventListener('click', function () {
        var input = $('#ba-in-files');
        if (!input || !input.files || !input.files.length) {
          toast('파일을 선택하세요.', true);
          return;
        }
        var fd = new FormData();
        fd.append('project_id', pid);
        fd.append('source_type', 'file');
        Array.prototype.forEach.call(input.files, function (f) { fd.append('files[]', f); });

        upBtn.disabled = true;
        upload('api/project.php?act=upload_source', fd).then(function (data) {
          input.value = '';
          afterChange(data);
        }).catch(function (e) {
          showError('#ba-form-error', e.message);
        }).then(function () { upBtn.disabled = false; });
      });
    }

    // 링크
    var linkBtn = $('#ba-src-link');
    if (linkBtn) {
      linkBtn.addEventListener('click', function () {
        var url = ($('#ba-in-link-url') || {}).value || '';
        if (!url.trim()) { toast('주소를 입력하세요.', true); return; }

        linkBtn.disabled = true;
        api('api/project.php?act=upload_source', {
          method: 'POST',
          body: {
            project_id: pid, source_type: 'link',
            url: url, title: ($('#ba-in-link-title') || {}).value || ''
          }
        }).then(function (data) {
          $('#ba-in-link-url').value = '';
          $('#ba-in-link-title').value = '';
          afterChange(data);
        }).catch(function (e) {
          showError('#ba-form-error', e.message);
        }).then(function () { linkBtn.disabled = false; });
      });
    }

    // 직접 입력
    var textBtn = $('#ba-src-text');
    if (textBtn) {
      textBtn.addEventListener('click', function () {
        var text = ($('#ba-in-text') || {}).value || '';
        if (!text.trim()) { toast('내용을 입력하세요.', true); return; }

        textBtn.disabled = true;
        api('api/project.php?act=upload_source', {
          method: 'POST',
          body: {
            project_id: pid, source_type: 'text',
            text: text, title: ($('#ba-in-text-title') || {}).value || ''
          }
        }).then(function (data) {
          $('#ba-in-text').value = '';
          $('#ba-in-text-title').value = '';
          afterChange(data);
        }).catch(function (e) {
          showError('#ba-form-error', e.message);
        }).then(function () { textBtn.disabled = false; });
      });
    }

    // 삭제 — 목록이 다시 그려지므로 개별 버튼이 아니라 컨테이너에 건다.
    var list = $('#ba-srclist');
    if (list) {
      list.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-src-del]') : null;
        if (!btn) return;
        if (!window.confirm('이 문서를 삭제합니다. 올린 파일도 함께 지워집니다.')) return;

        api('api/project.php?act=delete_source', {
          method: 'POST',
          body: { source_id: parseInt(btn.dataset.srcDel, 10) }
        }).then(afterChange).catch(function (e2) {
          showError('#ba-form-error', e2.message);
        });
      });
    }
  }

  // =====================================================================
  // 프로젝트 목록
  // =====================================================================
  function initProjectList() {
    var root = $('#ba-list-app');
    if (!root) return;

    var state = { status: '', page: 1 };
    var debounceTimer;

    function filters() {
      return {
        act:     'list',
        status:  state.status,
        track:   ($('#ba-f-track')   || {}).value || '',
        from:    ($('#ba-f-from')    || {}).value || '',
        to:      ($('#ba-f-to')      || {}).value || '',
        keyword: ($('#ba-f-keyword') || {}).value || '',
        sort:    ($('#ba-f-sort')    || {}).value || 'recent',
        mine:    ($('#ba-f-mine')    || {}).checked ? '1' : '',
        with_deleted: ($('#ba-f-deleted') || {}).checked ? '1' : '',
        page:    state.page,
        size:    20
      };
    }

    function load() {
      var tbody = $('#ba-list tbody');
      tbody.innerHTML = '<tr><td colspan="9" class="ba-loading">불러오는 중…</td></tr>';

      api('api/project.php?' + qs(filters())).then(function (data) {
        renderTabs(data.counts);
        renderRows(data.rows);
        renderPager(data);
      }).catch(function (e) {
        tbody.innerHTML = '<tr><td colspan="9" class="ba-empty">' + esc(e.message) + '</td></tr>';
      });
    }

    function renderTabs(counts) {
      counts = counts || {};
      $('#ba-tabs').innerHTML = STATUS_ORDER.map(function (code) {
        var n = counts[code] || 0;
        return '<button type="button" role="tab" data-status="' + code + '"' +
               ' aria-selected="' + (state.status === code) + '"' +
               ' class="' + (n === 0 && code !== '' ? 'is-zero' : '') + '">' +
               esc(STATUS_LABEL[code] || code) + '<i>' + n + '</i></button>';
      }).join('');
    }

    function renderRows(rows) {
      var tbody = $('#ba-list tbody');
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="ba-empty">' +
          '<b>조건에 맞는 프로젝트가 없습니다.</b>조건을 바꾸거나 새 프로젝트를 등록하세요.' +
          '</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(function (r) {
        var period = (r.dev_start || '') + (r.dev_end ? ' ~ ' + r.dev_end : '');
        var badge = r.is_deleted
          ? '<span class="ba-badge ba-badge--deleted">삭제됨</span>'
          : '<span class="ba-badge ba-badge--' + esc(r.status) + '">' + esc(r.status_label) + '</span>';
        return '<tr class="' + (r.is_deleted ? 'is-deleted' : '') + '">' +
          '<td class="ba-code">' + esc(r.code) + '</td>' +
          '<td class="ba-name"><a href="project_view.php?id=' + r.id + '">' + esc(r.name) + '</a>' +
            (r.is_mine ? ' <span class="ba-badge">내 프로젝트</span>' : '') + '</td>' +
          '<td>' + esc(r.client || '') + '</td>' +
          '<td>' + esc(r.track_label || '') + '</td>' +
          '<td class="ba-period-cell">' + esc(period) + '</td>' +
          '<td class="ba-period-cell">' + esc(r.deploy_date || '') + '</td>' +
          '<td>' + esc(r.owner_name || '') + '</td>' +
          '<td class="ba-num">' + r.source_count + '</td>' +
          '<td>' + badge + '</td>' +
        '</tr>';
      }).join('');
    }

    function renderPager(d) {
      var box = $('#ba-pager');
      if (d.pages <= 1) { box.innerHTML = ''; return; }
      box.innerHTML =
        '<button type="button" class="ba-btn ba-btn--sm" data-page="' + (d.page - 1) + '"' +
          (d.page <= 1 ? ' disabled' : '') + '>이전</button>' +
        '<span>' + d.page + ' / ' + d.pages + ' (전체 ' + d.total + '건)</span>' +
        '<button type="button" class="ba-btn ba-btn--sm" data-page="' + (d.page + 1) + '"' +
          (d.page >= d.pages ? ' disabled' : '') + '>다음</button>';
    }

    // 상태 탭
    $('#ba-tabs').addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-status]') : null;
      if (!btn) return;
      state.status = btn.dataset.status;
      state.page = 1;
      load();
    });

    // 페이지 이동
    $('#ba-pager').addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-page]') : null;
      if (!btn || btn.disabled) return;
      state.page = parseInt(btn.dataset.page, 10);
      load();
    });

    // 조건 변경 — 검색어는 타이핑이 멎은 뒤에 부른다.
    ['#ba-f-track', '#ba-f-from', '#ba-f-to', '#ba-f-sort', '#ba-f-mine', '#ba-f-deleted']
      .forEach(function (sel) {
        var el = $(sel);
        if (el) el.addEventListener('change', function () { state.page = 1; load(); });
      });

    var kw = $('#ba-f-keyword');
    if (kw) {
      kw.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { state.page = 1; load(); }, 300);
      });
    }

    var reset = $('#ba-f-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        ['#ba-f-track', '#ba-f-from', '#ba-f-to', '#ba-f-keyword'].forEach(function (s) {
          var el = $(s); if (el) el.value = '';
        });
        ['#ba-f-mine', '#ba-f-deleted'].forEach(function (s) {
          var el = $(s); if (el) el.checked = false;
        });
        var sort = $('#ba-f-sort'); if (sort) sort.value = 'recent';
        state.status = '';
        state.page = 1;
        load();
      });
    }

    load();
  }

  // =====================================================================
  // 아직 안 만든 화면
  // =====================================================================

  // TODO(P6): 대시보드 — 내 태스크, 진척률, 지연 경고
  // ===================================================================
  // 대시보드 (명세서 §7.1 Step4 · §7.2)
  //
  // 서버가 한 번에 다 내려준다. 화면에서 태스크마다 다시 묻지 않는다 —
  // 그렇게 하면 태스크 수만큼 요청이 나간다(질의 수는 dev/query_count.php
  // 로 재고, 태스크 50/200/500건에서 같은 값이 나온다).
  // ===================================================================
  function initDashboard() {
    var root = $('#ba-dash');
    if (!root) return;

    var PID   = parseInt(root.dataset.project, 10) || 0;
    var BOARD = null;
    var VIEW  = 'kanban';

    var STATUS_CLASS = {
      todo: 'todo', doing: 'doing', review: 'review',
      dev_deployed: 'devdep', prod_deployed: 'proddep', done: 'done', hold: 'hold'
    };

    // ---- 내가 맡은 일 ---------------------------------------------------
    function loadMine() {
      api('api/dashboard.php?' + qs({ act: 'mine' })).then(function (d) {
        var box = $('#ba-mine');
        if (!d.rows.length) {
          box.innerHTML = '<p class="ba-empty-inline">' +
            (d.message || '확정된 배정안에서 맡은 태스크가 없습니다.') + '</p>';
          $('#ba-mine-sum').textContent = '';
          return;
        }
        var late = d.rows.filter(function (r) { return r.overdue; }).length;
        $('#ba-mine-sum').textContent = d.rows.length + '건' + (late ? ' · 지연 ' + late + '건' : '');

        box.innerHTML = '<div class="ba-mylist">' + d.rows.map(function (r) {
          return '<button type="button" class="ba-my' + (r.overdue ? ' is-late' : '') +
                 '" data-task="' + r.task_id + '">' +
            '<span class="ba-my__p">' + esc(r.project_name) + '</span>' +
            '<span class="ba-my__t">' + esc(r.wbs_no || '') + ' ' + esc(r.title) + '</span>' +
            '<span class="ba-my__s ba-st--' + (STATUS_CLASS[r.status] || 'todo') + '">' +
              esc(r.status_label) + '</span>' +
            '<span class="ba-my__b"><i style="width:' + r.progress_pct + '%"></i></span>' +
            '<span class="ba-my__n">' + r.progress_pct + '%</span>' +
            '<span class="ba-my__d">' + (r.plan_end ? esc(r.plan_end) : '기한 없음') +
              (r.overdue ? ' <b>지연</b>' : '') + '</span>' +
          '</button>';
        }).join('') + '</div>';
      }).catch(function (e) {
        $('#ba-mine').innerHTML = '<p class="ba-empty-inline">' + esc(e.message) + '</p>';
      });
    }

    // ---- 프로젝트 카드 ---------------------------------------------------
    function loadCards() {
      api('api/dashboard.php?' + qs({ act: 'projects' })).then(function (d) {
        var box = $('#ba-cards');
        if (!d.rows.length) {
          box.innerHTML = '<p class="ba-empty-inline">진행 중인 프로젝트가 없습니다.</p>';
          return;
        }
        $('#ba-cards-sum').textContent = d.rows.length + '건';
        box.innerHTML = '<div class="ba-cards">' + d.rows.map(card).join('') + '</div>';
        if (PID) openBoard(PID);
      }).catch(function (e) {
        $('#ba-cards').innerHTML = '<p class="ba-empty-inline">' + esc(e.message) + '</p>';
      });
    }

    function card(p) {
      var pr = p.progress || { pct: 0, counted: 0, no_est: 0 };
      var dd = p.dday;
      var ddText = dd === null ? '기한 미정'
                 : (dd === 0 ? 'D-DAY' : (dd > 0 ? 'D-' + dd : 'D+' + (-dd)));

      return '<button type="button" class="ba-card' + (dd !== null && dd < 0 ? ' is-past' : '') +
             '" data-project="' + p.id + '">' +
        '<div class="ba-card__top">' +
          '<span class="ba-card__name">' + esc(p.name) + '</span>' +
          '<span class="ba-card__dd">' + ddText + '</span>' +
        '</div>' +
        '<div class="ba-card__meta">' + esc(p.code) +
          (p.client ? ' · ' + esc(p.client) : '') +
          ' · ' + esc(p.status_label) +
          (p.dday_of ? ' · ' + esc(p.dday_of) + ' 기준' : '') + '</div>' +

        '<div class="ba-card__bar"><i style="width:' + pr.pct + '%"></i></div>' +
        '<div class="ba-card__nums">' +
          '<span><b>' + pr.pct + '%</b> 진행</span>' +
          '<span>' + pr.counted + '건</span>' +
          '<span>' + (p.people || 0) + '명</span>' +
          (p.overdue ? '<span class="is-late"><b>지연 ' + p.overdue + '</b></span>' : '') +
        '</div>' +

        stages(p.stage) +

        (p.allocation
          ? '<div class="ba-card__note">배정 ' + p.allocation.version + '차 확정</div>'
          : '<div class="ba-card__note ba-card__note--warn">배정 확정 전 — 보드가 비어 있습니다</div>') +
      '</button>';
    }

    function stages(s) {
      if (!s) return '';
      var L = { dev: '개발', test: '테스트', deploy: '배포' };
      return '<div class="ba-stages">' + Object.keys(L).map(function (k) {
        var x = s[k] || { state: 'none' };
        return '<span class="ba-stage ba-stage--' + x.state + '" title="' +
               esc((x.from || '?') + ' ~ ' + (x.to || '?')) + '">' + L[k] + '</span>';
      }).join('') + '</div>';
    }

    // ---- 보드 -------------------------------------------------------------
    function openBoard(id) {
      PID = id;
      clearError('#ba-dash-error');
      api('api/dashboard.php?' + qs({ act: 'board', project_id: id })).then(function (d) {
        BOARD = d;
        $('#ba-board').hidden = false;
        $('#ba-bd-name').textContent = d.project.name;
        $('#ba-bd-sub').textContent =
          d.project.code + (d.project.client ? ' · ' + d.project.client : '') +
          (d.allocation ? ' · 배정 ' + d.allocation.version + '차 확정' : '');

        var note = $('#ba-bd-note');
        if (d.message) { note.hidden = false; note.textContent = d.message; }
        else { note.hidden = true; }

        renderPeople(d.by_member || []);
        renderLate(d.overdue || []);
        renderKanban(d.columns || []);
        renderGantt(d.gantt || {});
        renderFeed(d.feed || []);
        setView(VIEW);

        $('#ba-board').scrollIntoView({ behavior: 'smooth', block: 'start' });
      }).catch(function (e) { showError('#ba-dash-error', e.message); });
    }

    function renderPeople(rows) {
      $('#ba-bd-people-sum').textContent = rows.length ? rows.length + '명' : '';
      $('#ba-bd-people').innerHTML = rows.length
        ? '<div class="ba-pcards">' + rows.map(function (m) {
            return '<div class="ba-pcard' + (m.overdue ? ' is-late' : '') + '">' +
              '<div class="ba-pcard__n">' + esc(m.emp_name) +
                (m.role_label ? ' <span class="ba-dim">' + esc(m.role_label) + '</span>' : '') +
              '</div>' +
              '<div class="ba-pcard__bar"><i style="width:' + m.progress_pct + '%"></i></div>' +
              '<div class="ba-pcard__m">' +
                '<span><b>' + m.progress_pct + '%</b></span>' +
                '<span>' + m.done_count + ' / ' + m.task_count + '건</span>' +
                '<span>' + m.assigned_md + ' M/D</span>' +
                (m.overdue ? '<span class="is-late"><b>지연 ' + m.overdue + '</b></span>' : '') +
              '</div></div>';
          }).join('') + '</div>'
        : '<p class="ba-empty-inline">배정된 사람이 없습니다.</p>';
    }

    function renderLate(rows) {
      var wrap = $('#ba-bd-late-wrap');
      wrap.hidden = rows.length === 0;
      if (!rows.length) return;
      $('#ba-bd-late-sum').textContent = rows.length + '건';
      $('#ba-bd-late').innerHTML = '<div class="ba-lates">' + rows.map(function (t) {
        return '<button type="button" class="ba-late" data-task="' + t.id + '">' +
          '<span class="ba-late__w">' + esc(t.wbs_no || '-') + '</span>' +
          '<span class="ba-late__t">' + esc(t.title) + '</span>' +
          '<span class="ba-late__s">' + esc(t.status_label) + ' ' + t.progress_pct + '%</span>' +
          '<span class="ba-late__d">' + esc(t.plan_end) + ' · <b>' + t.overdue_days +
            '일 지남</b></span>' +
        '</button>';
      }).join('') + '</div>';
    }

    function renderKanban(cols) {
      $('#ba-bd-kanban').innerHTML = '<div class="ba-kanban">' + cols.map(function (c) {
        return '<div class="ba-kcol ba-kcol--' + (STATUS_CLASS[c.key] || 'todo') + '">' +
          '<div class="ba-kcol__h">' + esc(c.label) +
            ' <span class="ba-dim">' + c.count + '</span></div>' +
          '<div class="ba-kcol__b">' + (c.cards.length
            ? c.cards.map(kcard).join('')
            : '<p class="ba-kcol__e">없음</p>') + '</div>' +
        '</div>';
      }).join('') + '</div>';
    }

    function kcard(t) {
      return '<button type="button" class="ba-kcard' + (t.overdue ? ' is-late' : '') +
             '" data-task="' + t.task_id + '">' +
        '<span class="ba-kcard__w">' + esc(t.wbs_no || '') + '</span>' +
        '<span class="ba-kcard__t">' + esc(t.title) + '</span>' +
        '<span class="ba-kcard__m">' +
          (t.owner_name ? esc(t.owner_name) : '<i>담당 없음</i>') +
          (t.est_md !== null ? ' · ' + t.est_md + ' M/D' : '') +
        '</span>' +
        '<span class="ba-kcard__b"><i style="width:' + t.progress_pct + '%"></i></span>' +
        (t.plan_end
          ? '<span class="ba-kcard__d">~ ' + esc(t.plan_end) +
            (t.overdue ? ' <b>지연</b>' : '') + '</span>'
          : '') +
      '</button>';
    }

    /**
     * 간트. 라이브러리 없이 CSS 로 그린다(이 저장소에는 차트 라이브러리가 없다).
     * 기간이 없는 태스크는 그릴 수 없으므로 **몇 건이 빠졌는지 적는다.**
     */
    function renderGantt(g) {
      var box = $('#ba-bd-gantt');
      if (!g.bars || !g.bars.length) {
        box.innerHTML = '<p class="ba-empty-inline">계획 기간이 있는 태스크가 없어 간트를 그릴 수 없습니다.' +
          (g.no_period ? ' (기간 미입력 ' + g.no_period + '건)' : '') + '</p>';
        return;
      }
      var from = new Date(g.from), to = new Date(g.to);
      var span = Math.max(1, (to - from) / 86400000);
      var today = new Date(BOARD.today);
      var todayPct = Math.max(0, Math.min(100, (today - from) / 86400000 / span * 100));

      var h = '<div class="ba-gantt">';
      h += '<div class="ba-gantt__head"><span>' + esc(g.from) + '</span>' +
           '<span class="ba-spacer"></span><span>' + esc(g.to) + '</span></div>';
      h += '<div class="ba-gantt__rows" style="--today:' + todayPct + '%">';
      h += g.bars.map(function (b) {
        var s = Math.max(0, (new Date(b.from) - from) / 86400000 / span * 100);
        var w = Math.max(1.5, (new Date(b.to) - new Date(b.from)) / 86400000 / span * 100);
        return '<div class="ba-grow">' +
          '<span class="ba-grow__t" title="' + esc(b.title) + '">' +
            esc(b.wbs_no || '') + ' ' + esc(b.title) + '</span>' +
          '<span class="ba-grow__track">' +
            '<i class="ba-gbar' + (b.overdue ? ' is-late' : '') +
              '" style="left:' + s + '%;width:' + w + '%" ' +
              'data-task="' + b.task_id + '" ' +
              'title="' + esc(b.from + ' ~ ' + b.to + ' · ' + (b.owner_name || '담당 없음')) + '">' +
              '<u style="width:' + b.progress_pct + '%"></u>' +
            '</i>' +
          '</span>' +
        '</div>';
      }).join('');
      h += '</div>';
      if (g.no_period) {
        h += '<p class="ba-panel__hint">계획 기간이 없어 그리지 못한 태스크가 ' +
             g.no_period + '건 있습니다. 칸반에서는 보입니다.</p>';
      }
      h += '</div>';
      box.innerHTML = h;
    }

    function renderFeed(rows) {
      $('#ba-bd-feed-sum').textContent = rows.length ? rows.length + '건' : '';
      $('#ba-bd-feed').innerHTML = rows.length
        ? '<div class="ba-feed">' + rows.map(function (r) {
            return '<button type="button" class="ba-fitem" data-task="' + r.task_id + '">' +
              '<span class="ba-fitem__w">' + esc(r.emp_name) + '</span>' +
              '<span class="ba-fitem__t">' + esc(r.wbs_no || '') + ' ' + esc(r.task_title) +
                (r.status_label ? ' <em>' + esc(r.status_label) + '</em>' : '') +
                (r.progress_pct !== null ? ' <em>' + r.progress_pct + '%</em>' : '') + '</span>' +
              (r.content ? '<span class="ba-fitem__c">' + esc(r.content) + '</span>' : '') +
              (r.blocker ? '<span class="ba-fitem__x">블로커: ' + esc(r.blocker) + '</span>' : '') +
              '<span class="ba-fitem__d">' + esc(String(r.created_at).slice(0, 16)) + '</span>' +
            '</button>';
          }).join('') + '</div>'
        : '<p class="ba-empty-inline">아직 올라온 진행상황이 없습니다.</p>';
    }

    function setView(v) {
      VIEW = v;
      $('#ba-bd-kanban').hidden = v !== 'kanban';
      $('#ba-bd-gantt').hidden  = v !== 'gantt';
      $('#ba-bd-viewname').textContent = v === 'kanban' ? '칸반' : '간트';
      $$('.ba-bd__toggle [data-view]').forEach(function (b) {
        b.classList.toggle('is-on', b.dataset.view === v);
      });
    }

    // ---- 진행상황 드로어 (§7.2) --------------------------------------------
    function openTask(taskId) {
      $('#ba-pg-title').textContent = '진행상황';
      $('#ba-pg-body').innerHTML = '<div class="ba-loading">불러오는 중…</div>';
      openDrawer('ba-pg-drawer');

      api('api/progress.php?' + qs({ act: 'task', task_id: taskId }))
        .then(function (d) { renderTask(d); })
        .catch(function (e) {
          $('#ba-pg-body').innerHTML = '<p class="ba-empty-inline">' + esc(e.message) + '</p>';
        });
    }

    function renderTask(d) {
      var t = d.task;
      $('#ba-pg-title').textContent = (t.wbs_no ? t.wbs_no + ' ' : '') + t.title;

      var h = '';
      h += '<div class="ba-cd__sec"><h3>지금 상태</h3>' +
        '<div class="ba-pgnow">' +
          '<span class="ba-st--' + (STATUS_CLASS[t.status] || 'todo') + '">' +
            esc(t.status_label) + '</span>' +
          '<span class="ba-pgnow__b"><i style="width:' + t.progress_pct + '%"></i></span>' +
          '<span>' + t.progress_pct + '%</span>' +
        '</div>' +
        (t.plan_end
          ? '<p class="ba-cd__note">계획 ' + esc(t.plan_start || '?') + ' ~ ' + esc(t.plan_end) + '</p>'
          : '') +
        (t.description ? '<p class="ba-pre">' + esc(t.description) + '</p>' : '') +
        '</div>';

      // 등록 칸 — 본인이 맡은 태스크일 때만
      if (d.can_write) {
        h += '<div class="ba-cd__sec"><h3>진행상황 올리기</h3>' +
          '<div class="ba-pgform">' +
            '<label class="ba-field"><span>상태</span>' +
              '<select class="ba-input" id="ba-pg-status">' +
                '<option value="">그대로</option>' +
                Object.keys(d.statuses).map(function (k) {
                  return '<option value="' + k + '"' + (k === t.status ? ' selected' : '') +
                         '>' + esc(d.statuses[k]) + '</option>';
                }).join('') +
              '</select></label>' +
            '<label class="ba-field"><span>진행률 <b id="ba-pg-pct-v">' + t.progress_pct + '%</b></span>' +
              '<input type="range" id="ba-pg-pct" min="0" max="100" step="5" value="' +
              t.progress_pct + '"></label>' +
          '</div>' +
          '<label class="ba-field"><span>내용</span>' +
            '<textarea class="ba-input" id="ba-pg-content" rows="3" ' +
            'placeholder="무엇을 했는지"></textarea></label>' +
          '<label class="ba-field"><span>블로커 <span class="ba-dim">적으면 담당 PM 에게 따로 알립니다</span></span>' +
            '<textarea class="ba-input" id="ba-pg-blocker" rows="2" ' +
            'placeholder="막혀 있는 것이 있으면"></textarea></label>' +
          '<div class="ba-formbar"><span class="ba-spacer"></span>' +
            '<button type="button" class="ba-btn ba-btn--primary" id="ba-pg-save">올리기</button>' +
          '</div></div>';
      } else {
        h += '<div class="ba-note">' + esc(d.why || '진행상황을 올릴 수 없습니다.') + '</div>';
      }

      // 기록
      h += '<div class="ba-cd__sec"><h3>기록 <span class="ba-dim">' + d.rows.length + '건</span></h3>';
      h += d.rows.length
        ? '<div class="ba-pglist">' + d.rows.map(pgItem).join('') + '</div>'
        : '<p class="ba-empty-inline">아직 올라온 기록이 없습니다.</p>';
      h += '</div>';

      $('#ba-pg-body').innerHTML = h;
      bindTaskForm(t.id);
    }

    function pgItem(r) {
      var h = '<div class="ba-pg" data-pg="' + r.id + '">' +
        '<div class="ba-pg__h">' +
          '<b>' + esc(r.emp_name) + '</b>' +
          (r.status_label ? ' <span class="ba-st--' + (STATUS_CLASS[r.status] || 'todo') + '">' +
            esc(r.status_label) + '</span>' : '') +
          (r.progress_pct !== null ? ' <span class="ba-dim">' + r.progress_pct + '%</span>' : '') +
          '<span class="ba-spacer"></span>' +
          '<span class="ba-dim">' + esc(String(r.created_at).slice(0, 16)) + '</span>' +
        '</div>';
      if (r.content) h += '<p class="ba-pg__c">' + esc(r.content) + '</p>';
      if (r.blocker) h += '<p class="ba-pg__x">블로커: ' + esc(r.blocker) + '</p>';

      h += '<div class="ba-pg__cm">' + (r.comments || []).map(function (c) {
        return '<div class="ba-cm"><b>' + esc(c.user_name) + '</b> ' + esc(c.content) +
               ' <span class="ba-dim">' + esc(String(c.created_at).slice(0, 16)) + '</span></div>';
      }).join('') +
        '<div class="ba-cmadd">' +
          '<input type="text" class="ba-input" placeholder="댓글" data-cm="' + r.id + '">' +
          '<button type="button" class="ba-btn ba-btn--sm" data-cmbtn="' + r.id + '">등록</button>' +
        '</div></div>';
      return h + '</div>';
    }

    function bindTaskForm(taskId) {
      var pct = $('#ba-pg-pct');
      if (pct) {
        pct.addEventListener('input', function () {
          $('#ba-pg-pct-v').textContent = pct.value + '%';
        });
      }
      var save = $('#ba-pg-save');
      if (save) {
        save.addEventListener('click', function () {
          var body = { task_id: taskId };
          var st = $('#ba-pg-status').value;
          if (st) body.status = st;
          body.progress_pct = parseInt($('#ba-pg-pct').value, 10);
          body.content = $('#ba-pg-content').value;
          body.blocker = $('#ba-pg-blocker').value;

          save.disabled = true;
          api('api/progress.php?act=create', { method: 'POST', body: body })
            .then(function (d) {
              toast(d.message);
              openTask(taskId);
              if (PID) openBoard(PID);
              loadMine();
            })
            .catch(function (e) { toast(e.message, true); })
            .then(function () { save.disabled = false; });
        });
      }

      $$('[data-cmbtn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.dataset.cmbtn;
          var input = $('[data-cm="' + id + '"]');
          if (!input.value.trim()) return;
          btn.disabled = true;
          api('api/progress.php?act=comment', {
            method: 'POST', body: { progress_id: parseInt(id, 10), content: input.value }
          }).then(function () { openTask(taskId); })
            .catch(function (e) { toast(e.message, true); })
            .then(function () { btn.disabled = false; });
        });
      });
    }

    // ---- 붙이기 -------------------------------------------------------------
    document.addEventListener('click', function (e) {
      var card = e.target.closest('[data-project]');
      if (card && root.contains(card)) { openBoard(parseInt(card.dataset.project, 10)); return; }

      var t = e.target.closest('[data-task]');
      if (t) { openTask(parseInt(t.dataset.task, 10)); return; }

      var v = e.target.closest('[data-view]');
      if (v) { setView(v.dataset.view); }
    });

    var closeBtn = $('#ba-bd-close');
    if (closeBtn) closeBtn.addEventListener('click', function () {
      $('#ba-board').hidden = true;
      PID = 0;
    });

    loadMine();
    loadCards();
  }

  // ===================================================================
  // 프로젝트 상세 — 개요 / Step2 후보 리스트
  // ===================================================================
  function initProjectView() {
    var root = $('#ba-pv');
    if (!root) return;

    var PID        = parseInt(root.dataset.projectId, 10) || 0;
    var HAS_PERIOD = root.dataset.hasPeriod === '1';
    var picked     = loadPicked();
    var ROWS       = [];
    var timer;

    // ---- 탭 -----------------------------------------------------------
    $$('[data-pv-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.disabled) return;
        $$('[data-pv-tab]').forEach(function (b) {
          b.setAttribute('aria-selected', String(b === btn));
        });
        $$('[data-pv-pane]').forEach(function (p) {
          p.hidden = p.dataset.pvPane !== btn.dataset.pvTab;
        });
        if (btn.dataset.pvTab === 'candidate' && HAS_PERIOD && !ROWS.length) load();
      });
    });

    if (!HAS_PERIOD) return;

    // ---- 선택 상태 ------------------------------------------------------
    //
    // 화면을 옮겨 다녀도 고른 후보가 날아가지 않게 브라우저에 잠깐 둔다.
    // **DB 저장은 Step3(배정안 생성) 에서 한다** — 그때 bs_allocation.params_json
    // 에 들어간다. 여기서 저장하면 확정 전 선택이 영구 기록으로 남는다.
    function loadPicked() {
      try {
        return JSON.parse(sessionStorage.getItem('ba.picked.' + PID) || '[]');
      } catch (e) { return []; }
    }
    function savePicked() {
      try {
        sessionStorage.setItem('ba.picked.' + PID, JSON.stringify(picked));
      } catch (e) { /* 사생활 보호 모드 등 — 그냥 둔다 */ }
    }

    // ---- 조건 -----------------------------------------------------------
    function conds() {
      return {
        act: 'list',
        project_id: PID,
        min_availability: ($('#ba-c-av') || {}).value || 0,
        min_capability:   ($('#ba-c-cap') || {}).value || 0,
        domains: $$('#ba-c-domains input:checked').map(function (x) { return x.value; })
      };
    }

    function syncLabels() {
      var a = $('#ba-c-av'), c = $('#ba-c-cap');
      if (a) $('#ba-c-av-v').textContent  = a.value + '%';
      if (c) $('#ba-c-cap-v').textContent = c.value + '점';
    }

    ['#ba-c-av', '#ba-c-cap'].forEach(function (sel) {
      var el = $(sel);
      if (!el) return;
      el.addEventListener('input', syncLabels);
      el.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(load, 150); });
    });
    var dom = $('#ba-c-domains');
    if (dom) dom.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(load, 150); });
    syncLabels();

    // ---- 직접 등록한 점유 -------------------------------------------------
    //
    // 슬랙·메일에 안 잡히는 업무(상주, 교육, 타 부서 지원 …)를 여기서 넣는다.
    // 한 줄이 그 사람의 가용도를 그대로 깎으므로 **사유와 작성자가 남는다.**
    var WL_SOURCES = { slack: '슬랙', email: '메일', meeting: '회의·구두',
                       doc: '문서', etc: '기타' };
    var WL_MEMBER  = null;   // 지금 드로어가 보고 있는 사람
    var WL_NAME    = '';
    var WL_PERIOD  = null;

    function wlRow(w) {
      var manual = w.kind === 'manual';
      var meta = esc(w.start_date) + ' ~ ' + esc(w.end_date) +
                 ' (' + w.overlap_days + '영업일)';
      if (manual) {
        if (w.origin_label) {
          meta += ' · ' + esc(w.origin_label);
          if (w.origin_url) {
            meta += ' <a href="' + esc(w.origin_url) + '" target="_blank" rel="noopener">근거</a>';
          }
        }
        if (w.created_by_name) meta += ' · ' + esc(w.created_by_name) + ' 등록';
      }

      return '<div class="ba-wl ba-wl--c' + (manual ? ' ba-wl--m' : '') + '">' +
        '<span class="ba-wl__k">' + esc(w.kind_label || '확정') + '</span>' +
        '<span class="ba-wl__t">' + esc(w.label) +
          (manual && w.note ? '<em class="ba-wl__note">' + esc(w.note) + '</em>' : '') +
        '</span>' +
        '<span class="ba-wl__d">' + meta + '</span>' +
        '<span class="ba-wl__r">' + (w.load_ratio * 100).toFixed(0) + '%' +
          (manual ? '<button type="button" class="ba-mini ba-mini--x" ' +
                    'data-wl-del="' + w.id + '" title="지우기">&times;</button>' : '') +
        '</span></div>';
    }

    /** 등록 폼. 권한이 없으면 아예 그리지 않는다. */
    function wlAddForm(d) {
      if (!d.can_add_workload) {
        return '';
      }
      // 기본 기간은 이 프로젝트 기간이다. 대개 그 안의 일을 적는다.
      var p = d.period || {};
      return '<details class="ba-wladd"><summary>슬랙·메일에 없는 다른 업무 등록</summary>' +
        '<p class="ba-cd__note">여기 넣은 값은 <b>이 사람의 가용도를 그대로 깎습니다.</b> ' +
        '모든 프로젝트에 함께 반영되고, 누가 넣었는지 본인에게도 보입니다.</p>' +
        '<div class="ba-wlform">' +
          '<label class="ba-field ba-wlform__wide"><span>무슨 업무인가</span>' +
            '<input type="text" class="ba-input" id="ba-wl-label" ' +
            'placeholder="예: B대 상주 지원"></label>' +
          '<label class="ba-field"><span>시작</span>' +
            '<input type="date" class="ba-input" id="ba-wl-from" value="' +
            esc(p.from || '') + '"></label>' +
          '<label class="ba-field"><span>종료</span>' +
            '<input type="date" class="ba-input" id="ba-wl-to" value="' +
            esc(p.to || '') + '"></label>' +
          '<label class="ba-field"><span>점유율 <b id="ba-wl-pct-v">50%</b></span>' +
            '<input type="range" id="ba-wl-pct" min="5" max="100" step="5" value="50"></label>' +
          '<label class="ba-field"><span>어디서 알게 됐나</span>' +
            '<select class="ba-input" id="ba-wl-source">' +
              '<option value="">선택 안 함</option>' +
              Object.keys(WL_SOURCES).map(function (k) {
                return '<option value="' + k + '">' + esc(WL_SOURCES[k]) + '</option>';
              }).join('') +
            '</select></label>' +
          '<label class="ba-field ba-wlform__wide"><span>근거 링크 <span class="ba-dim">있으면</span></span>' +
            '<input type="url" class="ba-input" id="ba-wl-url" ' +
            'placeholder="https:// 로 시작하는 슬랙·메일 주소"></label>' +
          '<label class="ba-field ba-wlform__wide"><span>사유 <span class="ba-dim">필수</span></span>' +
            '<textarea class="ba-input" id="ba-wl-note" rows="2" ' +
            'placeholder="왜 이만큼 점유하는지. 나중에 이 줄을 근거로 따지게 됩니다."></textarea></label>' +
        '</div>' +
        '<div class="ba-formbar"><span class="ba-dim" id="ba-wl-msg"></span>' +
          '<span class="ba-spacer"></span>' +
          '<button type="button" class="ba-btn ba-btn--primary" id="ba-wl-save">등록</button>' +
        '</div></details>';
    }

    function wlBind() {
      var pct = $('#ba-wl-pct');
      if (pct) {
        pct.addEventListener('input', function () {
          $('#ba-wl-pct-v').textContent = pct.value + '%';
        });
      }
      var save = $('#ba-wl-save');
      if (save) {
        save.addEventListener('click', function () {
          var body = {
            member_id:  WL_MEMBER,
            label:      $('#ba-wl-label').value,
            note:       $('#ba-wl-note').value,
            source:     $('#ba-wl-source').value,
            source_url: $('#ba-wl-url').value,
            start_date: $('#ba-wl-from').value,
            end_date:   $('#ba-wl-to').value,
            load_pct:   $('#ba-wl-pct').value
          };
          if (WL_PERIOD) { body.from = WL_PERIOD.from; body.to = WL_PERIOD.to; }

          save.disabled = true;
          $('#ba-wl-msg').textContent = '';
          api('api/workload.php?act=create', { method: 'POST', body: body })
            .then(function (r) {
              toast(r.message);
              openCandidateDetail(WL_MEMBER, WL_NAME);   // 드로어를 다시 그린다
              load();                     // 후보 표의 가용도도 따라가야 한다
            })
            .catch(function (e) { $('#ba-wl-msg').textContent = e.message; })
            .then(function () { save.disabled = false; });
        });
      }

      $$('[data-wl-del]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!confirm('이 점유를 지웁니다. 가용도가 그만큼 돌아옵니다.')) return;
          var body = { id: parseInt(btn.dataset.wlDel, 10) };
          if (WL_PERIOD) { body.from = WL_PERIOD.from; body.to = WL_PERIOD.to; }
          btn.disabled = true;
          api('api/workload.php?act=delete', { method: 'POST', body: body })
            .then(function (r) { toast(r.message); openCandidateDetail(WL_MEMBER, WL_NAME); load(); })
            .catch(function (e) { toast(e.message, true); btn.disabled = false; });
        });
      });
    }

    // ---- 가용도 막대 -----------------------------------------------------
    //
    // **확정과 추정을 한 숫자로 합치지 않는다.** 막대도 무늬를 달리해
    // 눈으로 구분되게 한다. 추정을 확정처럼 보여 주면 그걸 사실로 읽는다.
    function availCell(a) {
      if (!a) return '<span class="ba-dim">—</span>';
      return '<div class="ba-av">' +
        '<div class="ba-av__bar">' +
          '<i class="ba-av__c" style="width:' + a.confirmed_pct + '%"></i>' +
          '<i class="ba-av__i" style="width:' + a.inferred_pct + '%"></i>' +
        '</div>' +
        '<div class="ba-av__txt">' +
          '<b>가용 ' + a.available_pct + '%</b> ' +
          '<span class="ba-dim">(확정 ' + a.confirmed_pct + '% + 추정 ' +
            a.inferred_pct + '% 점유' +
            // 반일 근무자는 기준이 100 이 아니다. 안 적으면 남는 칸이
            // 무엇인지 알 수 없다 — 점유가 아니라 애초의 근무량이다.
            (a.capacity_pct !== undefined && a.capacity_pct < 100
               ? ', 기준 근무 ' + a.capacity_pct + '%' : '') +
            ')</span>' +
        '</div></div>';
    }

    function scoreCell(v, insuf) {
      if (v === null || v === undefined) {
        return '<span class="ba-cell-none">' + (insuf ? '표본 부족' : '—') + '</span>';
      }
      return '<b>' + Number(v).toFixed(0) + '</b>';
    }

    // ---- 목록 -----------------------------------------------------------
    function load() {
      var tb = $('#ba-c-table tbody');
      // 권한이 없으면 서버가 표를 아예 안 그린다(안내문만 있다).
      // 탭도 disabled 라 여기까지 올 일이 없지만, 없는 것을 건드리지 않는다.
      if (!tb) return;
      tb.innerHTML = '<tr><td colspan="9" class="ba-loading">불러오는 중…</td></tr>';
      clearError('#ba-pv-error');

      api('api/candidate.php?' + qs(conds())).then(function (d) {
        ROWS = d.rows || [];
        renderScope(d.scope, d.message);
        render(ROWS);
      }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="9" class="ba-empty">' + esc(e.message) + '</td></tr>';
      });
    }

    function renderScope(sc, msg) {
      if (!sc) return;
      var cats = (sc.categories || []).map(function (c) { return c.label; }).join(', ');
      $('#ba-c-scope').innerHTML =
        '기간 <b>' + esc(sc.period.from) + ' ~ ' + esc(sc.period.to) + '</b>' +
        (cats ? ' · 대상 계열 <b>' + esc(cats) + '</b>' : ' · 분야 조건 없음') +
        (sc.eval_ver ? ' · 판정 회차 ' + sc.eval_ver : '') +
        (msg ? ' · <span class="ba-dim">' + esc(msg) + '</span>' : '');
    }

    function render(rows) {
      var tb = $('#ba-c-table tbody');
      if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="9" class="ba-empty">조건에 맞는 후보가 없습니다.</td></tr>';
        updatePicked();
        return;
      }
      var seenBreak = false;
      var html = '';

      rows.forEach(function (r) {
        // 점수를 낼 수 없는 사람은 **지우지 않고** 구분선 아래로 내린다.
        // 0 점으로 깔면 영영 배정되지 않는다(scoring-design.md §5).
        if (!seenBreak && (r.fit_score === null || r.filtered_out)) {
          seenBreak = true;
          html += '<tr class="ba-ct__break"><td colspan="9">' +
                  '판단 보류 — 표본이 부족하거나 조건에 못 미칩니다. ' +
                  '<span class="ba-dim">처리량이 적다는 뜻도 아닙니다 — 셀 자료가 모자란 것입니다.</span></td></tr>';
        }
        var on = picked.indexOf(r.member_id) >= 0;
        html += '<tr class="' + (r.filtered_out ? 'is-out' : '') + '" data-mid="' + r.member_id + '">' +
          '<td><input type="checkbox" class="ba-ct__pick" data-mid="' + r.member_id + '"' +
            (on ? ' checked' : '') + ' aria-label="후보 선택"></td>' +
          '<td class="ba-ct__name"><button type="button" class="ba-linkish" data-detail="' +
            r.member_id + '">' + esc(r.emp_name) + '</button></td>' +
          '<td>' + esc(r.role_label || '—') + '</td>' +
          '<td>' + availCell(r.availability) + '</td>' +
          '<td>' + scoreCell(r.domain_fit, r.insufficient_data) + '</td>' +
          '<td>' + scoreCell(r.capability, r.insufficient_data) + '</td>' +
          '<td>' + scoreCell(r.breadth, r.insufficient_data) + '</td>' +
          '<td class="ba-num">' + (r.active_items || 0) + '</td>' +
          '<td>' + scoreCell(r.fit_score, r.insufficient_data) +
            (r.filter_reason ? '<br><span class="ba-cell-none">' +
              esc(r.filter_reason) + '</span>' : '') + '</td>' +
          '</tr>';
      });

      tb.innerHTML = html;
      $('#ba-c-count').textContent = rows.length + '명';
      updatePicked();
    }

    // ---- 선택 -----------------------------------------------------------
    function updatePicked() {
      $('#ba-c-picked').textContent = '선택 ' + picked.length + '명';
    }

    $('#ba-c-table').addEventListener('change', function (e) {
      var cb = e.target.closest ? e.target.closest('.ba-ct__pick') : null;
      if (!cb) return;
      var mid = parseInt(cb.dataset.mid, 10);
      var i = picked.indexOf(mid);
      if (cb.checked && i < 0) picked.push(mid);
      if (!cb.checked && i >= 0) picked.splice(i, 1);
      savePicked();
      updatePicked();
    });

    var clr = $('#ba-c-clear');
    if (clr) clr.addEventListener('click', function () {
      picked = []; savePicked();
      $$('.ba-ct__pick').forEach(function (c) { c.checked = false; });
      updatePicked();
    });

    var cfm = $('#ba-c-confirm');
    if (cfm) cfm.addEventListener('click', function () {
      if (!picked.length) { toast('후보를 한 명 이상 고르세요.', true); return; }
      savePicked();
      toast(picked.length + '명을 후보로 확정했습니다. 배정안 생성(3단계)에서 씁니다.');
      // TODO(P5): 배정안 생성 시 bs_allocation.params_json 에 담아 보낸다.
    });

    // ---- 행 클릭 → 근거 드로어 --------------------------------------------
    $('#ba-c-table').addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-detail]') : null;
      if (!b) return;
      openCandidateDetail(parseInt(b.dataset.detail, 10), b.textContent);
    });

    function openCandidateDetail(mid, name) {
      var box = $('#ba-cd-body');
      WL_MEMBER = mid;
      WL_NAME   = name;
      $('#ba-cd-title').textContent = name + ' — 근거';
      box.innerHTML = '<div class="ba-loading">불러오는 중…</div>';
      openDrawer('ba-cand-drawer');

      api('api/candidate.php?' + qs({ act: 'detail', project_id: PID, member_id: mid }))
        .then(function (d) {
          var a = d.availability;
          var h = '';

          h += '<div class="ba-cd__sec"><h3>가용도</h3>' +
               availCell(a) +
               '<p class="ba-cd__note">기간 ' + esc(d.period.from) + ' ~ ' + esc(d.period.to) +
               ' · 영업일 ' + d.period.workdays + '일 · 기본 가용 ' +
               (a.base_capacity * 100).toFixed(0) + '%</p></div>';

          // 확정 점유
          //
          // 배정에서 나온 것과 사람이 적어 넣은 것을 갈라 그린다. 둘 다
          // '확정' 이지만 근거의 성격이 다르다 — 앞엣것은 확정된 배정안이
          // 만든 파생 데이터고, 뒤엣것은 사람의 진술이다. 그래서 뒤엣것은
          // 누가 언제 무슨 근거로 넣었는지 함께 보여 준다.
          h += '<div class="ba-cd__sec"><h3>확정 점유</h3>';
          if (!d.confirmed_breakdown.length) {
            h += '<p class="ba-empty-inline">확정된 점유가 없습니다.</p>';
          } else {
            h += d.confirmed_breakdown.map(wlRow).join('');
          }
          h += wlAddForm(d);
          h += '</div>';

          // 추정 점유 — 확정과 확실히 갈라 놓는다
          h += '<div class="ba-cd__sec"><h3>추정 점유 <span class="ba-tag-est">추정</span></h3>' +
               '<p class="ba-cd__note">아직 진행 중인 슬랙 건에서 어림한 값입니다. ' +
               '실제 소요를 잰 것이 아닙니다.</p>';
          if (!d.inferred_items.length) {
            h += '<p class="ba-empty-inline">진행 중인 건이 없습니다.</p>';
          } else {
            h += d.inferred_items.map(function (w) {
              var link = w.source_url
                ? '<a href="' + esc(w.source_url) + '" target="_blank" rel="noopener">원본</a>'
                : '<span class="ba-dim">링크 없음</span>';
              return '<div class="ba-wl ba-wl--i">' +
                '<span class="ba-wl__k">추정</span>' +
                '<span class="ba-wl__t">' + esc(w.title) + '</span>' +
                '<span class="ba-wl__d">' + esc(w.org_name || '기관 미상') + ' · ' +
                  esc(w.status_raw) + ' · ' + link + '</span>' +
                '<span class="ba-wl__r">' + (w.load * 100).toFixed(0) + '%</span></div>';
            }).join('');
          }
          h += '</div>';

          // 계열 점수
          if (d.categories && d.categories.length) {
            h += '<div class="ba-cd__sec"><h3>계열별 처리량</h3><div class="ba-catlist">' +
              d.categories.map(function (c) {
                var v = c.score === null
                  ? '<span class="ba-cat__none">' + esc(c.note || '표본 부족') + '</span>'
                  : '<span class="ba-cat__score">' + c.score.toFixed(0) + '</span>' +
                    '<span class="ba-cat__bar"><i style="width:' + c.score + '%"></i></span>';
                return '<div class="ba-cat ba-cat--ro"><span class="ba-cat__name">' +
                  esc(c.label) + '</span>' + v +
                  '<span class="ba-cat__n">' + c.case_count + '건</span></div>';
              }).join('') + '</div></div>';
          }

          // 최근 처리 건
          h += '<div class="ba-cd__sec"><h3>최근 처리 건</h3>';
          if (!d.can_see_evidence) {
            h += '<p class="ba-empty-inline">이 구성원의 처리 건을 볼 권한이 없습니다.</p>';
          } else if (!d.recent.length) {
            h += '<p class="ba-empty-inline">기록이 없습니다.</p>';
          } else {
            h += d.recent.map(function (r) {
              var link = r.source_url
                ? '<a href="' + esc(r.source_url) + '" target="_blank" rel="noopener">슬랙 원본</a>'
                : '<span class="ba-dim">링크 없음</span>';
              return '<div class="ba-ev"><div class="ba-ev__top">' +
                '<span class="ba-ev__diff">' + '★'.repeat(r.difficulty || 0) + '</span>' +
                '<span class="ba-ev__org">' + esc(r.org_name || '기관 미상') + '</span>' +
                '<span class="ba-ev__status">' + esc(r.status_raw || '') + '</span></div>' +
                '<div class="ba-ev__title">' + esc(r.title) + '</div>' +
                '<div class="ba-ev__meta">' + esc(r.domains || '') +
                  (r.closed_at ? ' · ' + esc(r.closed_at) : '') + ' · ' + link + '</div></div>';
            }).join('');
          }
          h += '</div>';

          box.innerHTML = h;
          WL_PERIOD = d.period;
          wlBind();
        }).catch(function (e) {
          box.innerHTML = '<p class="ba-empty-inline">' + esc(e.message) + '</p>';
        });
    }
  }


  // ===================================================================
  // WBS 편집기 (명세서 §7.1 Step3 앞단)
  //
  // 편집은 화면 안에서만 일어나고 [저장] 을 누를 때 트리를 통째로 보낸다.
  // 번호(1 / 1.1 / 1.1.1)는 **서버가 매긴다.** 여기서도 미리 매겨 보여
  // 주지만 그건 미리보기일 뿐이고, 저장하면 서버 값으로 덮어쓴다.
  // 두 군데서 정하면 언젠가 갈린다.
  //
  // 확정(confirmed)만은 예외로 즉시 서버에 보낸다. '사람이 검토했다' 는
  // 표시라서 편집 저장의 부산물이 되면 안 된다 — 서버도 같은 이유로
  // save_tree 에서 이 칸을 받지 않는다.
  // ===================================================================
  function initWbs() {
    var table = $('#ba-w-table');
    if (!table) return;

    var root   = $('#ba-pv');
    var PID    = parseInt(root.dataset.projectId, 10) || 0;
    var tbody  = table.querySelector('tbody');
    var CAN_EDIT = !!$('#ba-w-save');

    var MODEL = [];        // 화면이 들고 있는 트리
    var SAVED = '';        // 마지막으로 서버와 같았던 모습
    var REV   = '';        // 낙관적 잠금표
    var CAN_CONFIRM = false;
    var loaded = false;
    var uid = 0;

    var DOMAINS = (function () {
      var el = document.getElementById('ba-domain-data');
      try { return el ? JSON.parse(el.textContent) : []; } catch (e) { return []; }
    })();
    var DOMAIN_BY_ID = {};
    DOMAINS.forEach(function (d) { DOMAIN_BY_ID[d.id] = d; });

    var MAX_DEPTH = 3;
    var DEPTH_LABEL = { 1: '대분류', 2: '중분류', 3: '소분류' };

    // ---- 탭에 들어올 때 한 번만 읽는다 ---------------------------------
    var tabBtn = $('[data-pv-tab="wbs"]');
    if (tabBtn) {
      tabBtn.addEventListener('click', function () { if (!loaded) load(); });
    }

    // ---- 모델 -----------------------------------------------------------
    function newNode(src) {
      src = src || {};
      return {
        key: 'n' + (++uid),
        id: src.id || null,
        title: src.title || '',
        description: src.description || '',
        est_md: src.est_md === 0 || src.est_md ? src.est_md : '',
        difficulty: src.difficulty || '',
        plan_start: src.plan_start || '',
        plan_end: src.plan_end || '',
        domains: src.domains ? src.domains.slice() : [],
        confirmed: !!src.confirmed,
        status: src.status || 'todo',
        status_label: src.status_label || '',
        origin: src.origin || 'manual',
        source_id: src.source_id || null,
        source_ref: src.source_ref || null,
        wbs_no: src.wbs_no || '',
        children: []
      };
    }

    function fromServer(nodes) {
      return (nodes || []).map(function (r) {
        var n = newNode(r);
        n.children = fromServer(r.children);
        return n;
      });
    }

    /** 저장용 — 서버가 받지 않는 칸(confirmed/status/wbs_no)은 빼고 보낸다. */
    function toPayload(nodes) {
      return nodes.map(function (n) {
        return {
          id: n.id,
          title: n.title,
          description: n.description || null,
          // 숫자로 맞춰 보낸다. 서버가 준 3 과 사람이 친 "3" 이 다르게 보이면
          // 아무것도 안 고쳤는데 '저장 안 함' 으로 뜬다.
          est_md: n.est_md === '' ? null : Number(n.est_md),
          difficulty: n.difficulty === '' ? null : Number(n.difficulty),
          plan_start: n.plan_start || null,
          plan_end: n.plan_end || null,
          domain_ids: n.domains.map(function (d) { return d.domain_id; }),
          // 어디서 나왔는지는 새로 만드는 줄에서만 서버가 받는다.
          // 저장된 줄의 출처는 바뀌지 않는다 — 근거 추적이 끊기지 않게.
          origin: n.origin,
          source_id: n.source_id,
          source_ref: n.source_ref,
          children: toPayload(n.children)
        };
      });
    }

    function snapshot() { return JSON.stringify(toPayload(MODEL)); }
    function isDirty()  { return snapshot() !== SAVED; }

    /** 평면화 — 렌더와 위치 찾기에 쓴다. */
    function flat(nodes, depth, parent, out) {
      nodes.forEach(function (n, i) {
        out.push({ node: n, depth: depth, parent: parent, list: nodes, idx: i });
        flat(n.children, depth + 1, n, out);
      });
      return out;
    }

    function locate(key) {
      var all = flat(MODEL, 1, null, []);
      for (var i = 0; i < all.length; i++) {
        if (all[i].node.key === key) return all[i];
      }
      return null;
    }

    /** 미리보기용 번호. 저장하면 서버 값으로 바뀐다. */
    function numbering(nodes, prefix) {
      nodes.forEach(function (n, i) {
        n._no = (prefix ? prefix + '.' : '') + (i + 1);
        numbering(n.children, n._no);
      });
    }

    function rollup(n) {
      if (!n.children.length) return n.est_md === '' ? 0 : Number(n.est_md) || 0;
      var s = 0;
      n.children.forEach(function (c) { s += rollup(c); });
      return s;
    }

    // ---- 편집 ------------------------------------------------------------
    function addRoot() {
      MODEL.push(newNode({ title: '' }));
      render(); focusLast();
    }

    function addSibling(key) {
      var p = locate(key);
      if (!p) return;
      p.list.splice(p.idx + 1, 0, newNode({}));
      render();
      focusTitle(p.list[p.idx + 1].key);
    }

    function addChild(key) {
      var p = locate(key);
      if (!p) return;
      if (p.depth >= MAX_DEPTH) {
        toast(MAX_DEPTH + '단계(대/중/소)까지만 만들 수 있습니다.', true);
        return;
      }
      var n = newNode({});
      p.node.children.push(n);
      render();
      focusTitle(n.key);
    }

    /** 들여쓰기 — 바로 앞 형제의 자식이 된다. 앞 형제가 없으면 못 한다. */
    function indent(key) {
      var p = locate(key);
      if (!p || p.idx === 0) return;
      var prev = p.list[p.idx - 1];
      if (p.depth + subHeight(p.node) > MAX_DEPTH) {
        toast('하위까지 옮기면 ' + MAX_DEPTH + '단계를 넘습니다.', true);
        return;
      }
      p.list.splice(p.idx, 1);
      prev.children.push(p.node);
      render(); focusTitle(key);
    }

    /** 내어쓰기 — 부모의 바로 다음 형제가 된다. */
    function outdent(key) {
      var p = locate(key);
      if (!p || !p.parent) return;
      var gp = locate(p.parent.key);
      if (!gp) return;
      p.list.splice(p.idx, 1);
      gp.list.splice(gp.idx + 1, 0, p.node);
      render(); focusTitle(key);
    }

    function moveBy(key, dir) {
      var p = locate(key);
      if (!p) return;
      var to = p.idx + dir;
      if (to < 0 || to >= p.list.length) return;
      var tmp = p.list[to];
      p.list[to] = p.node;
      p.list[p.idx] = tmp;
      render(); focusTitle(key);
    }

    /** 자기 포함 하위 트리의 높이. */
    function subHeight(n) {
      if (!n.children.length) return 1;
      var m = 0;
      n.children.forEach(function (c) { m = Math.max(m, subHeight(c)); });
      return m + 1;
    }

    function removeNode(key) {
      var p = locate(key);
      if (!p) return;
      var kids = flat([p.node], 1, null, []).length - 1;
      var msg = '「' + (p.node.title || '(제목 없음)') + '」';
      if (kids) msg += ' 와 하위 ' + kids + '건';
      if (p.node.id && !confirm(msg + ' 을 지웁니다. 저장하면 되돌릴 수 없습니다.')) return;
      p.list.splice(p.idx, 1);
      render();
    }

    // ---- 그리기 ----------------------------------------------------------
    function render() {
      numbering(MODEL, '');
      var rows = flat(MODEL, 1, null, []);

      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="ba-empty">' +
          (CAN_EDIT ? '아직 태스크가 없습니다. [대분류 추가] 로 시작하거나 엑셀에서 붙여 넣으세요.'
                    : '아직 태스크가 없습니다.') + '</td></tr>';
        syncSummary();
        return;
      }

      tbody.innerHTML = rows.map(function (r) { return rowHtml(r); }).join('');
      syncSummary();
    }

    function rowHtml(r) {
      var n = r.node;
      var ro = CAN_EDIT ? '' : ' disabled';
      var roll = rollup(n);
      var isLeaf = !n.children.length;

      // 확정은 저장된 태스크에만 걸 수 있다. 아직 서버에 없는 줄은
      // 확정할 대상 자체가 없다 — 켜 두면 눌렀을 때 조용히 아무 일도 안 난다.
      var cbTitle = !n.id ? '저장한 뒤에 확정할 수 있습니다'
                  : (!CAN_CONFIRM ? '확정 권한이 없습니다' : '배정 대상으로 확정');
      var cbOff = (!n.id || !CAN_CONFIRM) ? ' disabled' : '';

      // 초안(origin=auto)은 배경으로 구분한다. 사람이 쓴 줄과 모델이 뽑은
      // 줄이 같아 보이면 검토가 형식만 남는다.
      var h = '<tr data-k="' + n.key + '" class="ba-wr ba-wr--d' + r.depth +
              (n.confirmed ? ' is-confirmed' : '') +
              (n.origin === 'auto' ? ' is-draft' : '') + '">';

      h += '<td class="ba-wr__cb"><input type="checkbox" class="ba-wr__confirm"' +
           (n.confirmed ? ' checked' : '') + cbOff + ' title="' + esc(cbTitle) + '"></td>';

      h += '<td class="ba-wr__no"><span>' + esc(n._no) + '</span>' +
           '<em class="ba-wr__dl">' + DEPTH_LABEL[r.depth] + '</em></td>';

      h += '<td class="ba-wr__title" style="--ind:' + (r.depth - 1) + '">' +
           '<input class="ba-input ba-wr__t" value="' + esc(n.title) + '"' + ro +
           ' placeholder="' + DEPTH_LABEL[r.depth] + ' 제목" data-f="title">' +
           (n.origin === 'auto' ? '<span class="ba-tag-auto" title="문서에서 자동으로 뽑은 초안입니다. 검토가 필요합니다.">자동</span>' : '') +
           (n.source_ref ? '<span class="ba-wr__src" title="출처: ' + esc(srcLabel(n)) + '">' +
                           esc(shortRef(n.source_ref)) + '</span>' : '') +
           (n.description ? '<span class="ba-wr__memo" title="상세 설명이 있습니다">설명</span>' : '') +
           '</td>';

      // 상위 행은 자기 공수를 받지 않는다. 하위 합계가 곧 그 행의 공수다.
      // 둘 다 입력받으면 어느 쪽이 진짜인지 화면에서 알 수 없다.
      h += '<td class="ba-wr__md">' + (isLeaf
            ? '<input class="ba-input ba-wr__n" value="' + esc(n.est_md) + '"' + ro +
              ' inputmode="decimal" data-f="est_md" placeholder="-">'
            : '<span class="ba-wr__roll" title="하위 합계">' + (roll ? roll.toFixed(2).replace(/\.?0+$/, '') : '-') + '</span>' +
              (n.est_md !== '' ? '<em class="ba-wr__warn" title="하위가 있어 이 값은 합계에서 빠집니다">무시됨</em>' : ''))
          + '</td>';

      h += '<td class="ba-wr__df"><select class="ba-input ba-wr__s"' + ro + ' data-f="difficulty">' +
           '<option value="">-</option>';
      for (var d = 1; d <= 5; d++) {
        h += '<option value="' + d + '"' + (String(n.difficulty) === String(d) ? ' selected' : '') + '>' + d + '</option>';
      }
      h += '</select></td>';

      h += '<td class="ba-wr__pd">' +
           '<input type="date" class="ba-input ba-wr__d" value="' + esc(n.plan_start) + '"' + ro + ' data-f="plan_start">' +
           '<span>~</span>' +
           '<input type="date" class="ba-input ba-wr__d" value="' + esc(n.plan_end) + '"' + ro + ' data-f="plan_end">' +
           '</td>';

      h += '<td class="ba-wr__dm">' + (n.domains.length
            ? '<span class="ba-wr__dmtag" title="' + esc(n.domains.map(function (x) { return x.name; }).join(', ')) + '">' +
              esc(n.domains[0].name) + (n.domains.length > 1 ? ' 외 ' + (n.domains.length - 1) : '') + '</span>'
            : '<span class="ba-cell-none">-</span>') + '</td>';

      h += '<td class="ba-wr__act">' +
           '<button type="button" class="ba-mini" data-a="detail" title="상세 설명·분야">상세</button>';
      if (CAN_EDIT) {
        h += '<button type="button" class="ba-mini" data-a="child" title="하위 추가 (Alt+→ 는 들여쓰기)">+하위</button>' +
             '<button type="button" class="ba-mini ba-mini--x" data-a="del" title="삭제">&times;</button>';
      }
      h += '</td></tr>';
      return h;
    }

    // 출처 표시 ---------------------------------------------------------
    var SRC_BY_ID = {};      // 문서 분석 결과에서 채운다

    function srcLabel(n) {
      var doc = n.source_id ? SRC_BY_ID[n.source_id] : null;
      return (doc ? (doc.title || doc.kind) + ' · ' : '') + (n.source_ref || '');
    }
    /** 표 안에 들어갈 짧은 형태. 전체는 title 속성과 드로어에서 본다. */
    function shortRef(ref) {
      var s = String(ref || '');
      return s.length > 16 ? s.slice(0, 15) + '…' : s;
    }

    function syncSummary() {
      var rows = flat(MODEL, 1, null, []);
      var leafNoEst = 0, est = 0, conf = 0, unsaved = 0;
      rows.forEach(function (r) {
        if (r.node.confirmed) conf++;
        if (!r.node.id) unsaved++;
        if (!r.node.children.length) {
          if (r.node.est_md === '') leafNoEst++;
          else est += Number(r.node.est_md) || 0;
        }
      });

      var s = '전체 ' + rows.length + '건 · 확정 ' + conf + '건 · 총 공수 ' +
              (est ? est.toFixed(2).replace(/\.?0+$/, '') : 0) + ' M/D';
      if (leafNoEst) s += ' · 공수 미입력 ' + leafNoEst + '건';
      $('#ba-w-sum').textContent = s;

      var dirty = $('#ba-w-dirty');
      if (dirty) {
        var d = isDirty();
        dirty.textContent = d ? ('저장 안 한 변경 있음' + (unsaved ? ' (새 줄 ' + unsaved + ')' : '')) : '';
        dirty.className = d ? 'ba-dirty' : 'ba-dim';
        var rb = $('#ba-w-revert');
        if (rb) rb.hidden = !d;
      }
    }

    function focusTitle(key) {
      var el = tbody.querySelector('tr[data-k="' + key + '"] .ba-wr__t');
      if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
    }
    function focusLast() {
      var els = $$('.ba-wr__t', tbody);
      if (els.length) els[els.length - 1].focus();
    }

    // ---- 입력 받기 --------------------------------------------------------
    tbody.addEventListener('input', function (e) {
      var f = e.target.dataset.f;
      if (!f) return;
      var tr = e.target.closest('tr');
      var p  = locate(tr.dataset.k);
      if (!p) return;
      p.node[f] = e.target.value;
      if (f === 'est_md') {
        // 상위 행의 합계가 즉시 따라가야 한다. 통째로 다시 그리면 입력 중인
        // 칸에서 포커스가 튄다 — 합계 칸만 고쳐 쓴다. (syncSummary 도 그 안에서 부른다)
        refreshRollups();
      } else {
        syncSummary();
      }
    });

    tbody.addEventListener('change', function (e) {
      if (e.target.classList.contains('ba-wr__confirm')) {
        onConfirmToggle(e.target);
        return;
      }
      var f = e.target.dataset.f;
      if (!f) return;
      var p = locate(e.target.closest('tr').dataset.k);
      if (p) { p.node[f] = e.target.value; syncSummary(); }
    });

    function refreshRollups() {
      numbering(MODEL, '');
      flat(MODEL, 1, null, []).forEach(function (r) {
        if (r.node.children.length) {
          var el = tbody.querySelector('tr[data-k="' + r.node.key + '"] .ba-wr__roll');
          if (el) {
            var v = rollup(r.node);
            el.textContent = v ? v.toFixed(2).replace(/\.?0+$/, '') : '-';
          }
        }
      });
      syncSummary();
    }

    tbody.addEventListener('keydown', function (e) {
      if (!e.target.classList.contains('ba-wr__t')) return;
      var key = e.target.closest('tr').dataset.k;

      if (e.altKey) {
        var map = { ArrowRight: indent, ArrowLeft: outdent };
        if (map[e.key])            { e.preventDefault(); map[e.key](key); return; }
        if (e.key === 'ArrowUp')   { e.preventDefault(); moveBy(key, -1); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); moveBy(key, 1);  return; }
      }
      if (e.key === 'Enter' && CAN_EDIT) { e.preventDefault(); addSibling(key); }
    });

    tbody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-a]');
      if (!btn) return;
      var key = btn.closest('tr').dataset.k;
      if (btn.dataset.a === 'detail') openDetail(key);
      if (btn.dataset.a === 'child')  addChild(key);
      if (btn.dataset.a === 'del')    removeNode(key);
    });

    // ---- 확정 -------------------------------------------------------------
    //
    // 저장과 따로 간다. 서버도 save_tree 에서 confirmed 를 받지 않는다.
    function onConfirmToggle(cb) {
      var tr = cb.closest('tr');
      var p  = locate(tr.dataset.k);
      if (!p || !p.node.id) { cb.checked = false; return; }

      var on = cb.checked;
      cb.disabled = true;
      api('api/task.php?act=' + (on ? 'confirm' : 'unconfirm'), {
        method: 'POST',
        body: { task_ids: [p.node.id] }
      }).then(function (d) {
        p.node.confirmed = on;
        tr.classList.toggle('is-confirmed', on);
        REV = d.revision || REV;
        toast(d.message);
        syncSummary();
      }).catch(function (err) {
        cb.checked = !on;                 // 서버가 거절했으면 화면도 되돌린다
        showError('#ba-pv-error', err.message);
      }).then(function () {
        cb.disabled = false;
      });
    }

    // ---- 상세 드로어 ------------------------------------------------------
    function openDetail(key) {
      var p = locate(key);
      if (!p) return;
      var n = p.node;

      $('#ba-td-title').textContent = (n._no ? n._no + ' ' : '') + (n.title || '(제목 없음)');

      var h = '';
      if (n.id) {
        h += '<p class="ba-cd__note">' +
             (n.origin === 'auto' ? '문서에서 자동으로 뽑은 초안입니다. ' : '직접 입력한 태스크입니다. ') +
             (n.confirmed ? '<b>확정됨 — 배정 대상입니다.</b>'
                          : '<b>아직 확정 전이라 배정 대상이 아닙니다.</b>') +
             (n.status_label ? ' 상태: ' + esc(n.status_label) : '') + '</p>';
      } else {
        h += '<p class="ba-cd__note">아직 저장하지 않은 태스크입니다.</p>';
      }

      if (n.source_ref || n.source_id) {
        h += '<div class="ba-cd__sec"><h3>출처</h3>' +
             '<p class="ba-wr__srcfull">' + esc(srcLabel(n)) + '</p>' +
             '<p class="ba-cd__note">문서에서 뽑은 위치입니다. 원문을 열어 맞는지 '
             + '확인한 뒤 확정하세요.</p></div>';
      }

      h += '<div class="ba-cd__sec"><h3>상세 설명</h3>' +
           '<textarea class="ba-input" id="ba-td-desc" rows="6"' + (CAN_EDIT ? '' : ' disabled') +
           ' placeholder="이 태스크가 무엇인지, 무엇이 되면 끝인지">' + esc(n.description) + '</textarea></div>';

      h += '<div class="ba-cd__sec"><h3>분야 <span class="ba-dim">최대 5개</span></h3>' +
           '<div class="ba-chips" id="ba-td-doms">' +
           DOMAINS.map(function (d) {
             var on = n.domains.some(function (x) { return x.domain_id === d.id; });
             // 역량 점수를 내지 않는 계열(기획 등)도 고를 수는 있다. 다만
             // 배정 때 역량으로 맞춰 볼 수 없으므로 그 사실을 칩에 적는다.
             return '<label class="ba-chip' + (d.scored === false ? ' ba-chip--noscore' : '') +
                    '" title="' + esc(d.cat_label) +
                    (d.scored === false ? ' — 처리량 점수를 내지 않는 계열입니다' : '') + '">' +
                    '<input type="checkbox" value="' + d.id + '"' +
                    (on ? ' checked' : '') + (CAN_EDIT ? '' : ' disabled') + '>' +
                    '<span>' + esc(d.name) + '</span></label>';
           }).join('') + '</div>' +
           '<p class="ba-cd__note">고른 분야는 배정할 때 <b>계열</b> 단위로 처리량과 맞춰 봅니다. ' +
           '가중치는 고른 개수로 균등하게 나눕니다.' +
           (n.domains.some(function (x) { return (DOMAIN_BY_ID[x.domain_id] || {}).scored === false; })
              ? '<br><b>처리량 점수를 내지 않는 계열이 섞여 있습니다.</b> ' +
                '그 분야만으로는 배정 후보를 처리 이력으로 가려낼 수 없습니다.'
              : '') +
           '</p></div>';

      $('#ba-td-body').innerHTML = h;
      openDrawer('ba-task-drawer');

      var ta = $('#ba-td-desc');
      if (ta) {
        ta.addEventListener('input', function () {
          n.description = ta.value;
          var cell = tbody.querySelector('tr[data-k="' + key + '"] .ba-wr__title');
          if (cell) {
            var memo = cell.querySelector('.ba-wr__memo');
            if (n.description && !memo) {
              cell.insertAdjacentHTML('beforeend', '<span class="ba-wr__memo">설명</span>');
            } else if (!n.description && memo) {
              memo.remove();
            }
          }
          syncSummary();
        });
      }

      var box = $('#ba-td-doms');
      if (box) {
        box.addEventListener('change', function (e) {
          var picked = $$('input:checked', box);
          if (picked.length > 5) {
            e.target.checked = false;
            toast('분야는 5개까지만 고를 수 있습니다.', true);
            return;
          }
          n.domains = picked.map(function (i) {
            var d = DOMAIN_BY_ID[parseInt(i.value, 10)] || {};
            return { domain_id: parseInt(i.value, 10), name: d.name || '', category: d.category };
          });
          render();
        });
      }
    }

    // ---- 엑셀 붙여넣기 ----------------------------------------------------
    //
    // 규칙: **줄 앞의 탭 개수 = 단계.** 그 뒤 탭은 칸 구분이다.
    // 엑셀에서 셀 범위를 복사하면 칸 사이가 탭, 줄 사이가 줄바꿈으로 온다.
    // 계층을 표현하려면 왼쪽 빈 칸이 그대로 앞쪽 탭으로 따라온다.
    /* ==WBS-PARSE-BEGIN==
       아래 네 함수는 DOM 을 타지 않는 순수 변환이다. studio/dev/wbs_parse_test.js
       가 이 표시 사이를 그대로 떼어 내 node 에서 돌린다 — 표시를 지우지 말 것.
       바깥에서 쓰는 것은 newNode() 와 MAX_DEPTH 둘뿐이고, 시험은 그 둘을 대신 넣는다. */
    function parsePaste(text) {
      var lines = String(text).replace(/\r\n?/g, '\n').split('\n');
      var tree = [], stack = [], warn = [], n = 0;

      lines.forEach(function (raw, li) {
        if (!raw.trim()) return;

        var lead = (raw.match(/^\t*/) || [''])[0].length;
        var cols = raw.slice(lead).split('\t');
        var title = (cols[0] || '').trim();
        if (!title) {
          warn.push((li + 1) + '번째 줄: 제목이 비어 있어 건너뜁니다.');
          return;
        }

        var depth = lead + 1;
        if (depth > MAX_DEPTH) {
          warn.push((li + 1) + '번째 줄 「' + title + '」: ' + MAX_DEPTH +
                    '단계를 넘어 소분류로 올렸습니다.');
          depth = MAX_DEPTH;
        }
        // 한 단계씩만 내려갈 수 있다. 2단계를 건너뛰고 3단계가 오면 붙일 곳이 없다.
        if (depth > stack.length + 1) {
          warn.push((li + 1) + '번째 줄 「' + title + '」: 상위가 없어 ' +
                    (stack.length + 1) + '단계로 올렸습니다.');
          depth = stack.length + 1;
        }

        var node = newNode({
          title: title,
          est_md: numCell(cols[1]),
          difficulty: diffCell(cols[2], li, title, warn),
          plan_start: dateCell(cols[3], li, warn),
          plan_end: dateCell(cols[4], li, warn)
        });

        stack.length = depth - 1;
        if (depth === 1) tree.push(node);
        else stack[depth - 2].children.push(node);
        stack[depth - 1] = node;
        n++;
      });

      return { tree: tree, count: n, warn: warn };
    }

    function numCell(v) {
      v = String(v == null ? '' : v).trim().replace(/,/g, '');
      if (v === '') return '';
      return isFinite(Number(v)) && Number(v) >= 0 ? Number(v) : '';
    }

    function diffCell(v, li, title, warn) {
      v = String(v == null ? '' : v).trim();
      if (v === '') return '';
      var i = parseInt(v, 10);
      if (!(i >= 1 && i <= 5)) {
        warn.push((li + 1) + '번째 줄 「' + title + '」: 난이도 "' + v + '" 는 1~5 가 아니라 비웁니다.');
        return '';
      }
      return i;
    }

    // 엑셀은 2026-07-06 / 2026.7.6 / 2026/7/6 / "2026. 7. 6" 을 다 뱉는다.
    function dateCell(v, li, warn) {
      v = String(v == null ? '' : v).trim();
      if (v === '') return '';
      var m = v.match(/^(\d{4})\s*[-./]\s*(\d{1,2})\s*[-./]?\s*(\d{1,2})\.?$/);
      if (!m) {
        warn.push((li + 1) + '번째 줄: 날짜 "' + v + '" 를 못 읽어 비웁니다.');
        return '';
      }
      var mm = ('0' + m[2]).slice(-2), dd = ('0' + m[3]).slice(-2);
      return m[1] + '-' + mm + '-' + dd;
    }
    /* ==WBS-PARSE-END== */

    function initPaste() {
      var ta = $('#ba-ps-text');
      if (!ta) return;
      var parsed = null;

      function preview() {
        clearError('#ba-ps-error');
        var r = parsePaste(ta.value);
        parsed = r;
        $('#ba-ps-ok').disabled = r.count === 0;
        $('#ba-ps-count').textContent = r.count ? r.count + '건 읽었습니다' : '';

        var h = '';
        if (r.warn.length) {
          h += '<div class="ba-note ba-note--warn"><b>확인해 주세요</b><ul>' +
               r.warn.slice(0, 12).map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') +
               (r.warn.length > 12 ? '<li>… 외 ' + (r.warn.length - 12) + '건</li>' : '') +
               '</ul></div>';
        }
        if (r.count) {
          h += '<h3 class="ba-ps__h">이렇게 들어갑니다</h3><div class="ba-ps__pv">' +
               previewHtml(r.tree, 0) + '</div>';
        }
        $('#ba-ps-preview').innerHTML = h;
      }

      function previewHtml(nodes, ind) {
        return nodes.map(function (n) {
          return '<div class="ba-ps__row" style="--ind:' + ind + '">' +
                 '<b>' + esc(n.title) + '</b>' +
                 (n.est_md !== '' ? '<span>' + n.est_md + ' M/D</span>' : '') +
                 (n.difficulty !== '' ? '<span>난이도 ' + n.difficulty + '</span>' : '') +
                 (n.plan_start || n.plan_end
                    ? '<span>' + esc(n.plan_start || '?') + ' ~ ' + esc(n.plan_end || '?') + '</span>' : '') +
                 '</div>' + previewHtml(n.children, ind + 1);
        }).join('');
      }

      ta.addEventListener('input', preview);
      ta.addEventListener('paste', function () { setTimeout(preview, 0); });

      $('#ba-w-paste').addEventListener('click', function () {
        ta.value = '';
        $('#ba-ps-preview').innerHTML = '';
        $('#ba-ps-count').textContent = '';
        $('#ba-ps-ok').disabled = true;
        clearError('#ba-ps-error');
        openDrawer('ba-paste-drawer');
      });

      $('#ba-ps-ok').addEventListener('click', function () {
        if (!parsed || !parsed.count) return;
        var mode = ($$('input[name="ba-ps-mode"]:checked')[0] || {}).value || 'append';

        if (mode === 'replace') {
          var existing = flat(MODEL, 1, null, []).filter(function (r) { return r.node.id; }).length;
          if (existing && !confirm('지금 있는 태스크 ' + existing +
              '건을 버리고 붙여 넣은 것으로 바꿉니다. 저장하면 되돌릴 수 없습니다.')) {
            return;
          }
          MODEL = parsed.tree;
        } else {
          MODEL = MODEL.concat(parsed.tree);
        }
        closeDrawer('ba-paste-drawer');
        render();
        toast(parsed.count + '건을 넣었습니다. 확인한 뒤 [저장] 을 누르세요.');
      });
    }

    // ---- 통신 -------------------------------------------------------------
    function load() {
      loaded = true;
      tbody.innerHTML = '<tr><td colspan="8" class="ba-loading">불러오는 중…</td></tr>';
      api('api/task.php?' + qs({ act: 'tree', project_id: PID })).then(function (d) {
        apply(d);
      }).catch(function (e) {
        tbody.innerHTML = '<tr><td colspan="8" class="ba-empty">' + esc(e.message) + '</td></tr>';
      });
    }

    function apply(d) {
      MODEL = fromServer(d.tree);
      REV   = d.revision || '';
      if (d.can_confirm !== undefined) CAN_CONFIRM = !!d.can_confirm;
      SAVED = snapshot();
      render();
      showCounts(d.counts);
    }

    function showCounts(c) {
      var el = $('#ba-w-next');
      if (!el || !c) return;
      var h = '배정 대상이 될 수 있는 태스크는 <b>' + c.assignable + '건</b>입니다';
      if (c.unconfirmed) h += ' (미확정 ' + c.unconfirmed + '건은 빠집니다)';
      h += '. 배정안 생성은 다음 단계입니다.';
      if (c.leaf_no_est) {
        h += '<br>공수가 비어 있는 말단 태스크가 <b>' + c.leaf_no_est +
             '건</b> 있습니다. 그만큼 배정 공수가 비어 계산됩니다.';
      }
      el.innerHTML = h;
    }

    function save() {
      var btn = $('#ba-w-save');
      var bad = firstInvalid();
      if (bad) {
        showError('#ba-pv-error', bad.msg);
        focusTitle(bad.key);
        return;
      }
      btn.disabled = true;
      clearError('#ba-pv-error');

      api('api/task.php?act=save_tree', {
        method: 'POST',
        body: { project_id: PID, tree: toPayload(MODEL), revision: REV }
      }).then(function (d) {
        // 서버가 돌려준 트리로 갈아 끼운다 — 번호와 id 가 여기서 정해진다.
        apply({ tree: d.tree, revision: d.revision, counts: d.counts, can_confirm: CAN_CONFIRM });
        toast(d.message);
      }).catch(function (e) {
        showError('#ba-pv-error', e.message);
      }).then(function () {
        btn.disabled = false;
      });
    }

    /** 서버에 보내기 전에 걸러지는 것 — 왕복 한 번을 아끼고 어디가 문제인지 짚어 준다. */
    function firstInvalid() {
      var rows = flat(MODEL, 1, null, []);
      for (var i = 0; i < rows.length; i++) {
        var n = rows[i].node;
        if (!String(n.title).trim()) {
          return { key: n.key, msg: (n._no || '') + ' 행의 제목이 비어 있습니다.' };
        }
        if (n.plan_start && n.plan_end && n.plan_start > n.plan_end) {
          return { key: n.key, msg: (n._no || '') + ' 「' + n.title + '」 의 계획 시작일이 종료일보다 늦습니다.' };
        }
      }
      return null;
    }

    // ---- 문서 분석 --------------------------------------------------------
    //
    // 도출과 나눠 둔다. 무엇이 읽혔고 무엇이 실패했는지 사람이 먼저 보고
    // 나서 도출을 눌러야, 결과가 부실할 때 원인을 짚을 수 있다.
    function parseDocs(all) {
      var btn = $('#ba-w-parse');
      btn.disabled = true;
      clearError('#ba-pv-error');

      return api('api/task.php?act=parse', {
        method: 'POST', body: { project_id: PID, all: all ? 1 : 0 }
      }).then(function (d) {
        (d.sources || []).forEach(function (x) { SRC_BY_ID[x.id] = x; });
        renderDocs(d);
        toast(d.message);
        return d;
      }).catch(function (e) {
        showError('#ba-pv-error', e.message);
        throw e;
      }).then(function (x) {
        btn.disabled = false;
        return x;
      }, function (e) {
        btn.disabled = false;
        throw e;
      });
    }

    function renderDocs(d) {
      var box = $('#ba-w-docs');
      if (!box) return;
      var rows = d.sources || [];
      if (!rows.length) {
        box.hidden = false;
        box.innerHTML = '<div class="ba-note">이 프로젝트에 등록된 문서가 없습니다. ' +
                        '[수정] 에서 파일을 올린 뒤 다시 분석하세요.</div>';
        return;
      }

      var LABEL = { ok: '읽음', fail: '못 읽음', skip: '해당 없음', pending: '대기' };
      var h = '<div class="ba-docs">';
      rows.forEach(function (x) {
        h += '<div class="ba-doc ba-doc--' + esc(x.status) + '">' +
             '<span class="ba-doc__k">' + esc(x.kind) + '</span>' +
             '<span class="ba-doc__t">' + esc(x.title || '(제목 없음)') + '</span>' +
             '<span class="ba-doc__s">' + esc(LABEL[x.status] || x.status) + '</span>' +
             '<span class="ba-doc__n">' +
               (x.status === 'ok' && x.chars ? x.chars + '자 / ' + x.blocks + '곳' : '') +
             '</span>';
        if (x.error) {
          h += '<p class="ba-doc__e">' + esc(x.error) + '</p>';
        }
        (x.notes || []).forEach(function (nt) {
          h += '<p class="ba-doc__e ba-doc__e--warn">' + esc(nt) + '</p>';
        });
        h += '</div>';
      });
      h += '</div>';
      h += '<p class="ba-panel__hint">' +
           '\'해당 없음\'은 링크나 직접 입력이라 글자를 뽑을 것이 없다는 뜻입니다. ' +
           '실패가 아닙니다. 이미 읽은 문서를 다시 읽으려면 ' +
           '<button type="button" class="ba-linkish" id="ba-w-reparse">전체 다시 분석</button>' +
           ' 하세요.</p>';
      box.hidden = false;
      box.innerHTML = h;

      var rp = $('#ba-w-reparse');
      if (rp) rp.addEventListener('click', function () { parseDocs(true); });
    }

    // ---- 문서에서 WBS 도출 --------------------------------------------------
    function extract() {
      var btn = $('#ba-w-extract');
      btn.disabled = true;
      clearError('#ba-pv-error');

      api('api/task.php?act=extract', {
        method: 'POST', body: { project_id: PID }
      }).then(function (d) {
        var draft = fromServer(d.tree);
        var n = countNodes(draft);
        if (!n) {
          showError('#ba-pv-error', '문서에서 뽑아낼 업무를 찾지 못했습니다.');
          return;
        }

        var how = '문서에서 ' + n + '건을 뽑았습니다.\n\n' +
                  '[확인] 지금 목록 아래에 이어 붙입니다\n' +
                  '[취소] 아무것도 하지 않습니다';
        if (MODEL.length && !confirm(how)) return;

        MODEL = MODEL.concat(draft);
        render();

        var m = d.meta || {};
        var msg = [];
        if (m.fallback_reason) msg.push(m.fallback_reason);
        if (m.quality_note)    msg.push(m.quality_note);
        msg.push('초안 ' + n + '건을 넣었습니다. 검토해 고친 뒤 [저장] 하세요. ' +
                 '저장해도 확정 전에는 배정 대상이 아닙니다.');
        noteDraft(msg.join(' '), m);
        toast('초안 ' + n + '건을 넣었습니다.');
      }).catch(function (e) {
        // 문서를 아직 안 읽었으면 그것부터 하라고 말한다.
        showError('#ba-pv-error', e.message);
      }).then(function () {
        btn.disabled = false;
      });
    }

    function countNodes(ns) {
      var n = 0;
      ns.forEach(function (x) { n += 1 + countNodes(x.children); });
      return n;
    }

    function noteDraft(msg, meta) {
      var el = $('#ba-w-next');
      if (!el) return;
      var how = meta.method === 'llm'
        ? 'LLM(' + esc(meta.backend || meta.llm || '?') + ')으로 뽑았습니다.'
        : '규칙만으로 뽑았습니다.';
      el.innerHTML = '<b>초안이 들어왔습니다.</b> ' + esc(msg) + '<br><span class="ba-dim">' +
                     how + ' 문서 ' + (meta.source_count || 0) + '건 기준.</span>';
    }

    // ---- 붙이기 -----------------------------------------------------------
    if (CAN_EDIT) {
      $('#ba-w-parse').addEventListener('click', function () { parseDocs(false); });
      $('#ba-w-extract').addEventListener('click', extract);
      $('#ba-w-add').addEventListener('click', addRoot);
      $('#ba-w-save').addEventListener('click', save);
      $('#ba-w-revert').addEventListener('click', function () {
        if (!confirm('저장하지 않은 변경을 모두 버리고 마지막 저장 상태로 되돌립니다.')) return;
        load();
      });
      initPaste();

      // 편집 중에 실수로 창을 닫는 것을 막는다.
      window.addEventListener('beforeunload', function (e) {
        if (!isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
      });
    }
  }


  // ===================================================================
  // 배정안 (명세서 §6 · §7.1 Step3 뒷단)
  //
  // 담당자를 바꾸면 그 자리에서 서버로 보내고, 돌아온 부하를 그대로
  // 다시 그린다. 화면에서 따로 더하지 않는다 — 공수 합계를 두 군데서
  // 계산하면 언젠가 갈린다(WBS 롤업에서 이미 겪은 것과 같은 이유).
  // ===================================================================
  function initAllocation() {
    var root = $('#ba-al');
    if (!root) return;

    var pv  = $('#ba-pv');
    var PID = parseInt(pv.dataset.projectId, 10) || 0;
    var CAN_EDIT = !!$('#ba-al-propose');

    var CUR      = null;   // 지금 보고 있는 배정안
    var ITEMS    = [];
    var MEMBERS  = [];     // 담당자 선택지
    var WEIGHTS  = null;
    var loaded   = false;

    var W_LABEL = {
      domain: '분야 적합', cap: '처리량', avail: '참여 가능',
      career: '경력', growth: '성장 기회'
    };
    var W_HINT = {
      growth: '올리면 잘하는 사람 대신 배울 사람에게 갑니다'
    };

    var tabBtn = $('[data-pv-tab="wbs"]');
    if (tabBtn) tabBtn.addEventListener('click', function () { if (!loaded) load(); });

    // ---- 통신 -----------------------------------------------------------
    function load() {
      loaded = true;
      api('api/allocate.php?' + qs({ act: 'versions', project_id: PID }))
        .then(function (d) {
          renderVersions(d.rows || [], d.confirmed_version);
          if (d.rows && d.rows.length) openVersion(d.rows[0].id);
          else renderEmpty();
        })
        .catch(function (e) { showError('#ba-al-error', e.message); });
    }

    function openVersion(id) {
      api('api/allocate.php?' + qs({ act: 'detail', allocation_id: id }))
        .then(apply)
        .catch(function (e) { showError('#ba-al-error', e.message); });
    }

    function apply(d) {
      CUR   = d.allocation || CUR;
      ITEMS = d.items || [];
      if (CUR && CUR.params && CUR.params.weights) WEIGHTS = CUR.params.weights;
      clearError('#ba-al-error');
      renderBadge();
      renderLoad(d.load || []);
      renderTable(d.unassigned || []);
      syncVersionSelect();
    }

    // ---- 그리기 ----------------------------------------------------------
    function renderEmpty() {
      $('#ba-al-badge').textContent = '';
      $('#ba-al-load').innerHTML = '';
      $('#ba-al-table').querySelector('tbody').innerHTML =
        '<tr><td colspan="8" class="ba-empty">' +
        (CAN_EDIT ? 'WBS 를 확정한 뒤 [배정안 산출] 을 누르세요.'
                  : '아직 확정된 배정안이 없습니다.') + '</td></tr>';
    }

    function renderVersions(rows, confirmedVer) {
      var sel = $('#ba-al-ver');
      if (!sel) return;
      sel.innerHTML = rows.map(function (r) {
        return '<option value="' + r.id + '">' + r.version + '차 · ' +
               esc(r.status_label) + (r.version === confirmedVer ? ' ✓' : '') + '</option>';
      }).join('');
      sel.disabled = rows.length === 0;
    }

    function syncVersionSelect() {
      var sel = $('#ba-al-ver');
      if (sel && CUR) sel.value = String(CUR.id);
    }

    function renderBadge() {
      var el = $('#ba-al-badge');
      if (!CUR) { el.textContent = ''; return; }
      var s = CUR.version + '차 · ' + CUR.status_label;
      if (CUR.eval_ver) s += ' · 처리량 판정 ' + CUR.eval_ver + '회차';
      if (CUR.engine_ver) s += ' · 엔진 ' + CUR.engine_ver;
      el.textContent = s;
      el.className = 'ba-dim ba-al__badge ba-al__badge--' + CUR.status;
    }

    /**
     * 인원별 부하 막대.
     * 가용 공수를 넘으면 눈에 띄게 한다 — 넘긴 채로 확정하는 것은
     * 사람 판단이지만, 모르고 넘기는 일은 없어야 한다.
     */
    function renderLoad(load) {
      var box = $('#ba-al-load');
      if (!load.length) { box.innerHTML = ''; return; }

      var maxCap = 0;
      load.forEach(function (l) { maxCap = Math.max(maxCap, l.capacity_md, l.assigned_md); });
      if (maxCap <= 0) maxCap = 1;

      box.innerHTML = '<div class="ba-loads">' + load.map(function (l) {
        var pctOfMax = Math.min(100, l.assigned_md / maxCap * 100);
        var capMark  = Math.min(100, l.capacity_md / maxCap * 100);
        return '<div class="ba-loadrow' + (l.over ? ' is-over' : '') + '">' +
          '<span class="ba-loadrow__n">' + esc(l.emp_name) + '</span>' +
          '<span class="ba-loadrow__bar">' +
            '<i style="width:' + pctOfMax + '%"></i>' +
            '<u style="left:' + capMark + '%" title="가용 공수 ' + l.capacity_md + ' M/D"></u>' +
          '</span>' +
          '<span class="ba-loadrow__v">' + l.assigned_md + ' / ' + l.capacity_md + ' M/D</span>' +
          '<span class="ba-loadrow__p">' +
            (l.load_pct === null ? '-' : l.load_pct + '%') +
            (l.over ? ' <b>초과</b>' : '') + '</span>' +
          '<span class="ba-loadrow__c">' + l.task_count + '건</span>' +
        '</div>';
      }).join('') + '</div>';
    }

    function renderTable(unassigned) {
      var tb = $('#ba-al-table').querySelector('tbody');
      if (!ITEMS.length) { renderEmpty(); return; }

      var canEdit = CAN_EDIT && CUR && CUR.status !== 'confirmed' && CUR.status !== 'archived';

      var h = ITEMS.map(function (it) {
        return '<tr data-id="' + it.id + '"' + (it.is_manual ? ' class="is-manual"' : '') + '>' +
          '<td class="ba-al__no">' + esc(it.wbs_no || '-') + '</td>' +
          '<td class="ba-al__t">' + esc(it.task_title) + '</td>' +
          '<td class="ba-al__md">' + (it.est_md === null ? '-' : it.est_md) + '</td>' +
          '<td class="ba-al__df">' + (it.difficulty === null ? '-' : it.difficulty) + '</td>' +
          '<td>' + memberSelect(it, canEdit) + '</td>' +
          '<td class="ba-al__role">' + esc(it.role_name) +
            (it.is_manual ? '<em class="ba-al__manual" title="사람이 바꿈">수동</em>' : '') + '</td>' +
          '<td class="ba-al__fit">' + fitCell(it) + '</td>' +
          '<td class="ba-al__act">' +
            (canEdit && it.role !== 'owner'
              ? '<button type="button" class="ba-mini ba-mini--x" data-a="del">&times;</button>' : '') +
          '</td>' +
        '</tr>';
      }).join('');

      if (unassigned.length) {
        h += '<tr class="ba-al__break"><td colspan="8">담당자가 없는 태스크 ' +
             unassigned.length + '건 — 채워야 확정할 수 있습니다</td></tr>';
        h += unassigned.map(function (u) {
          return '<tr class="is-orphan"><td class="ba-al__no">' + esc(u.wbs_no || '-') + '</td>' +
                 '<td class="ba-al__t">' + esc(u.title) + '</td>' +
                 '<td colspan="6" class="ba-cell-none">담당자 없음</td></tr>';
        }).join('');
      }

      tb.innerHTML = h;
    }

    function memberSelect(it, canEdit) {
      if (!canEdit) return '<span class="ba-al__who">' + esc(it.emp_name) + '</span>';
      var opts = MEMBERS.map(function (m) {
        return '<option value="' + m.id + '"' +
               (m.id === it.member_id ? ' selected' : '') + '>' + esc(m.emp_name) + '</option>';
      }).join('');
      return '<select class="ba-input ba-al__sel" data-a="member">' + opts + '</select>';
    }

    /** 적합도 칸. 누르면 근거가 열린다. 수동으로 바꾼 항목은 점수가 없다. */
    function fitCell(it) {
      if (it.fit_score === null || it.fit_score === undefined) {
        return '<span class="ba-cell-none" title="사람이 정한 배정이라 엔진 점수가 없습니다">—</span>';
      }
      return '<button type="button" class="ba-linkish ba-al__fitb" data-a="why">' +
             Number(it.fit_score).toFixed(0) + '</button>';
    }

    // ---- 근거 드로어 ------------------------------------------------------
    function openWhy(itemId) {
      var it = null;
      ITEMS.forEach(function (x) { if (x.id === itemId) it = x; });
      if (!it) return;

      $('#ba-ar-title').textContent =
        (it.wbs_no ? it.wbs_no + ' ' : '') + it.task_title + ' — ' + it.emp_name;

      var r = it.reason || {};
      var h = '';

      h += '<div class="ba-cd__sec"><h3>왜 이 사람인가</h3><ol class="ba-why">' +
           (r.lines || []).map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') +
           '</ol></div>';

      if ((r.notes || []).length) {
        h += '<div class="ba-cd__sec"><h3>알아 둘 것</h3><ul class="ba-why__notes">' +
             r.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') +
             '</ul></div>';
      }

      if (r.parts) {
        h += '<div class="ba-cd__sec"><h3>점수 구성</h3><div class="ba-parts">';
        Object.keys(r.parts).forEach(function (k) {
          h += '<div class="ba-part"><span>' + esc(W_LABEL[k] || k) + '</span>' +
               '<i><b style="width:' + Math.max(0, Math.min(100, r.parts[k])) + '%"></b></i>' +
               '<em>' + r.parts[k] + '</em></div>';
        });
        h += '</div>';
        h += '<p class="ba-cd__note">가중평균 ' + (r.base === null ? '-' : r.base) +
             (r.penalty ? ' · 감점 ' + r.penalty : '') +
             (r.bonus ? ' · 가산 ' + r.bonus : '') + '</p></div>';
      }

      var wi = r.work_items || [];
      h += '<div class="ba-cd__sec"><h3>근거가 된 처리 건 <span class="ba-dim">' +
           wi.length + '건</span></h3>';
      if (!wi.length) {
        h += '<p class="ba-empty-inline">조회된 건이 없습니다.</p>';
      } else {
        h += '<div class="ba-evlist">' + wi.map(function (w) {
          var t = esc(w.title);
          return '<div class="ba-ev">' +
            (w.source_url
              ? '<a href="' + esc(w.source_url) + '" target="_blank" rel="noopener">' + t + '</a>'
              : '<span>' + t + '</span>') +
            '<span class="ba-ev__m">' + esc(w.org_name || '') +
            (w.difficulty ? ' · 난이도 ' + w.difficulty : '') +
            (w.closed_at ? ' · ' + esc(String(w.closed_at).slice(0, 10)) : '') + '</span></div>';
        }).join('') + '</div>';
      }
      h += '</div>';

      if (it.is_manual && it.manual_note) {
        h += '<div class="ba-cd__sec"><h3>수동 조정 사유</h3>' +
             '<p class="ba-wr__srcfull">' + esc(it.manual_note) + '</p></div>';
      }

      $('#ba-ar-body').innerHTML = h;
      openDrawer('ba-al-drawer');
    }

    // ---- 가중치 패널 ------------------------------------------------------
    function renderWeights() {
      var box = $('#ba-al-wsliders');
      if (!box) return;
      var w = WEIGHTS || { domain: 0.35, cap: 0.20, avail: 0.25, career: 0.10, growth: 0 };
      box.innerHTML = Object.keys(W_LABEL).map(function (k) {
        var v = w[k] === undefined ? 0 : w[k];
        return '<label class="ba-slider">' +
          '<span>' + esc(W_LABEL[k]) + ' <b id="ba-alw-' + k + '-v">' + v.toFixed(2) + '</b></span>' +
          '<input type="range" data-w="' + k + '" min="0" max="1" step="0.05" value="' + v + '">' +
          (W_HINT[k] ? '<em class="ba-slider__hint">' + esc(W_HINT[k]) + '</em>' : '') +
        '</label>';
      }).join('');
      syncWeightNote();
    }

    function currentWeights() {
      var w = {};
      $$('#ba-al-wsliders input[data-w]').forEach(function (el) {
        w[el.dataset.w] = parseFloat(el.value);
      });
      return w;
    }

    function syncWeightNote() {
      var w = currentWeights();
      var sum = 0;
      Object.keys(w).forEach(function (k) { sum += w[k]; });
      var el = $('#ba-al-wnote');
      if (el) {
        el.textContent = sum <= 0
          ? '전부 0 이면 산출할 수 없습니다.'
          : '쓰는 가중치 합 ' + sum.toFixed(2) + ' (이 합으로 나눠 계산합니다)';
      }
    }

    // ---- 동작 -------------------------------------------------------------
    function propose(weights, keepManual) {
      var btn = $('#ba-al-propose');
      btn.disabled = true;
      clearError('#ba-al-error');
      var body = { project_id: PID };
      if (weights) body.weights = weights;
      if (keepManual && CUR) body.keep_manual_from = CUR.id;

      api('api/allocate.php?act=propose', { method: 'POST', body: body })
        .then(function (d) {
          WEIGHTS = (d.meta && d.meta.weights) || WEIGHTS;
          return api('api/allocate.php?' + qs({ act: 'versions', project_id: PID }))
            .then(function (v) {
              renderVersions(v.rows || [], v.confirmed_version);
              return api('api/allocate.php?' + qs({ act: 'detail', allocation_id: d.allocation_id }));
            })
            .then(function (det) { apply(det); toast(d.message); });
        })
        .catch(function (e) { showError('#ba-al-error', e.message); })
        .then(function () { btn.disabled = false; });
    }

    function changeMember(itemId, memberId) {
      var it = null;
      ITEMS.forEach(function (x) { if (x.id === itemId) it = x; });
      if (!it) return;

      // 사유를 받는다. 나중에 왜 바꿨는지 알아야 한다(서버도 요구한다).
      var note = prompt('담당자를 ' + it.emp_name + ' 에서 바꿉니다.\n사유를 적어 주세요.', '');
      if (note === null || !note.trim()) {
        renderTable([]);   // 선택을 되돌린다
        openVersion(CUR.id);
        return;
      }

      api('api/allocate.php?act=update_item', {
        method: 'POST',
        body: { item_id: itemId, member_id: memberId, manual_note: note.trim() }
      }).then(function (d) {
        apply(d);
        toast(d.message);
      }).catch(function (e) {
        showError('#ba-al-error', e.message);
        openVersion(CUR.id);
      });
    }

    function confirmAll(accept) {
      if (!CUR) return;
      if (!accept && !confirm(
            '배정안 ' + CUR.version + '차를 확정합니다.\n\n' +
            '· 다른 버전은 지난 안으로 내려갑니다\n' +
            '· 담당자별 점유 기록이 만들어집니다\n' +
            '· 확정 뒤에는 고칠 수 없습니다(새 버전을 내야 합니다)')) {
        return;
      }
      var btn = $('#ba-al-confirm');
      btn.disabled = true;
      clearError('#ba-al-error');

      var body = { allocation_id: CUR.id };
      if (accept) body.accept_overload = 1;

      api('api/allocate.php?act=confirm', { method: 'POST', body: body })
        .then(function (d) {
          toast(d.message);
          if (d.notify_notice) showError('#ba-al-error', d.notify_notice);
          loaded = false;
          load();
        })
        .catch(function (e) {
          // 과배정은 막는 것이 아니라 한 번 더 묻는 것이다.
          if (e.code === 'OVERLOAD') {
            if (confirm(e.message)) { btn.disabled = false; confirmAll(true); return; }
          } else {
            showError('#ba-al-error', e.message);
          }
        })
        .then(function () { btn.disabled = false; });
    }

    // ---- 붙이기 -----------------------------------------------------------
    $('#ba-al-table').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-a]');
      if (!btn) return;
      var tr = btn.closest('tr');
      if (!tr || !tr.dataset.id) return;
      var id = parseInt(tr.dataset.id, 10);

      if (btn.dataset.a === 'why') openWhy(id);
      if (btn.dataset.a === 'del') {
        if (!confirm('이 배정 항목을 지웁니다.')) return;
        api('api/allocate.php?act=delete_item', { method: 'POST', body: { item_id: id } })
          .then(function (d) { apply(d); toast(d.message); })
          .catch(function (err) { showError('#ba-al-error', err.message); });
      }
    });

    $('#ba-al-table').addEventListener('change', function (e) {
      if (!e.target.classList.contains('ba-al__sel')) return;
      var tr = e.target.closest('tr');
      changeMember(parseInt(tr.dataset.id, 10), parseInt(e.target.value, 10));
    });

    if (CAN_EDIT) {
      $('#ba-al-propose').addEventListener('click', function () { propose(null, false); });
      $('#ba-al-confirm').addEventListener('click', function () { confirmAll(false); });

      $('#ba-al-ver').addEventListener('change', function (e) {
        openVersion(parseInt(e.target.value, 10));
      });

      $('#ba-al-weights').addEventListener('click', function () {
        var p = $('#ba-al-wpanel');
        if (p.hidden) renderWeights();
        p.hidden = !p.hidden;
      });
      $('#ba-al-wsliders').addEventListener('input', function (e) {
        if (!e.target.dataset.w) return;
        var v = $('#ba-alw-' + e.target.dataset.w + '-v');
        if (v) v.textContent = parseFloat(e.target.value).toFixed(2);
        syncWeightNote();
      });
      $('#ba-al-wreset').addEventListener('click', function () {
        WEIGHTS = null;
        renderWeights();
      });
      $('#ba-al-wapply').addEventListener('click', function () {
        var w = currentWeights();
        var sum = 0;
        Object.keys(w).forEach(function (k) { sum += w[k]; });
        if (sum <= 0) { showError('#ba-al-error', '가중치가 전부 0 입니다.'); return; }
        propose(w, true);
      });

      // 담당자 선택지 — 배정 가능한 사람만.
      api('api/allocate.php?' + qs({ act: 'members', project_id: PID }))
        .then(function (d) { MEMBERS = d.rows || []; })
        .catch(function () { /* 목록을 못 받아도 표는 읽기용으로 뜬다 */ });
    }
  }

  // TODO(P4): 구성원 목록 — 역할·팀 필터
  //           종합점수 열로 전체 정렬하는 기능을 넣지 말 것 (CLAUDE.md)
  function initMemberList() {}

  // ===================================================================
  // 프로파일 — 계열별 역량 레이더 + 근거 추적
  //
  // 차트 라이브러리를 쓰지 않는다. 저장소에 원래 없고(EditorJS 뿐),
  // 이 모듈은 '의존 라이브러리 없음' 을 지킨다. 레이더는 다각형 하나라
  // 인라인 SVG 로 충분하다.
  // ===================================================================
  function initMemberProfile() {
    var root = $('#ba-profile');
    if (!root) return;

    var MEMBER_ID = parseInt(root.dataset.memberId, 10) || 0;
    var IS_SELF   = root.dataset.isSelf === '1';
    var CAN_ADJ   = root.dataset.canAdjust === '1';
    var DATA      = null;

    // ---- 레이더 (인라인 SVG) ----------------------------------------
    //
    // 표본이 모자란 축(score === null)은 **꼭짓점을 0 으로 찍지 않는다.**
    // 0 으로 찍으면 "못한다" 로 읽힌다. 축은 그리되 다각형에서 빼고,
    // 축 이름 옆에 사유를 적는다.
    function radar(cats) {
      var N = cats.length;
      if (N < 3) {
        return '<p class="ba-empty-inline">레이더를 그리려면 계열이 3개 이상 필요합니다.</p>';
      }
      var S = 260, C = S / 2, R = S / 2 - 46;
      var rings = [25, 50, 75, 100];
      var p = [];

      function xy(i, v) {
        var a = (Math.PI * 2 * i / N) - Math.PI / 2;
        var r = R * (v / 100);
        return [C + r * Math.cos(a), C + r * Math.sin(a)];
      }

      // 눈금 다각형
      rings.forEach(function (ring) {
        var pts = [];
        for (var i = 0; i < N; i++) { pts.push(xy(i, ring).join(',')); }
        p.push('<polygon class="ba-radar__ring" points="' + pts.join(' ') + '"/>');
      });
      // 축
      for (var i = 0; i < N; i++) {
        var e = xy(i, 100);
        p.push('<line class="ba-radar__axis" x1="' + C + '" y1="' + C +
               '" x2="' + e[0] + '" y2="' + e[1] + '"/>');
      }
      // 값 다각형 — 점수가 있는 축만
      var scored = cats.map(function (c, i) { return { c: c, i: i }; })
                       .filter(function (o) { return o.c.score !== null; });
      if (scored.length >= 3) {
        var vp = scored.map(function (o) { return xy(o.i, o.c.score).join(','); });
        p.push('<polygon class="ba-radar__area" points="' + vp.join(' ') + '"/>');
      }
      // 꼭짓점 — 누르면 근거가 열린다
      cats.forEach(function (c, i) {
        if (c.score === null) return;
        var q = xy(i, c.score);
        p.push('<circle class="ba-radar__dot" cx="' + q[0] + '" cy="' + q[1] + '" r="4"' +
               ' data-cat="' + esc(c.category) + '"><title>' +
               esc(c.label + ' ' + c.score + '점 (' + c.case_count + '건)') +
               '</title></circle>');
      });
      // 축 이름
      cats.forEach(function (c, i) {
        var t = xy(i, 122);
        var anchor = Math.abs(t[0] - C) < 12 ? 'middle' : (t[0] > C ? 'start' : 'end');
        p.push('<text class="ba-radar__label' + (c.score === null ? ' is-dim' : '') +
               '" x="' + t[0] + '" y="' + t[1] + '" text-anchor="' + anchor +
               '" data-cat="' + esc(c.category) + '">' + esc(c.label) + '</text>');
      });

      return '<svg viewBox="0 0 ' + S + ' ' + S + '" class="ba-radar__svg" ' +
             'role="img" aria-label="계열별 처리량 레이더">' + p.join('') + '</svg>';
    }

    // ---- 계열 목록 ---------------------------------------------------
    function catList(cats) {
      if (!cats.length) return '<p class="ba-empty-inline">아직 판정 결과가 없습니다.</p>';
      return cats.map(function (c) {
        var val;
        if (c.score === null) {
          val = '<span class="ba-cat__none">' + esc(c.note || '표본 부족') + '</span>';
        } else {
          val = '<span class="ba-cat__score' + (c.confidence === 'partial' ? ' is-partial' : '') +
                '">' + c.score.toFixed(0) + '</span>' +
                '<span class="ba-cat__bar"><i style="width:' + c.score + '%"></i></span>';
        }
        return '<button type="button" class="ba-cat" data-cat="' + esc(c.category) + '">' +
               '<span class="ba-cat__name">' + esc(c.label) +
                 '<em>' + esc(c.moodle || '') + '</em></span>' +
               val +
               '<span class="ba-cat__n">' + c.case_count + '건' +
                 (c.confidence === 'partial' ? ' · 참고' : '') + '</span>' +
               '</button>';
      }).join('');
    }

    // ---- 종합 지표 ---------------------------------------------------
    function metrics(m) {
      if (!m) return '<p class="ba-empty-inline">아직 판정 결과가 없습니다.</p>';
      var items = [
        ['처리 실적', m.cap_score, m.total_cases + '건'],
        ['경험 범위', m.breadth_score, null],
        ['경력 환산', m.career_score, null]
      ];
      var html = items.map(function (it) {
        var v = it[1] === null ? '—' : it[1].toFixed(0);
        return '<div class="ba-metric"><span class="ba-metric__k">' + esc(it[0]) + '</span>' +
               '<span class="ba-metric__v">' + v + '</span>' +
               (it[2] ? '<span class="ba-metric__s">' + esc(it[2]) + '</span>' : '') + '</div>';
      }).join('');
      if (m.manual_adjust) {
        html += '<div class="ba-metric ba-metric--adj">' +
                '<span class="ba-metric__k">관리자 보정</span>' +
                '<span class="ba-metric__v">' + (m.manual_adjust > 0 ? '+' : '') +
                  m.manual_adjust + '</span>' +
                '<span class="ba-metric__s">' + esc(m.adjust_reason || '') + '</span></div>';
      }
      if (m.insufficient_data) {
        html += '<p class="ba-empty-inline">전체 표본이 부족합니다 (' +
                m.total_cases + '건). 점수를 참고용으로만 보세요.</p>';
      }
      return html;
    }

    // ---- 분야별 실적 -------------------------------------------------
    function domainList(ds) {
      if (!ds.length) return '<p class="ba-empty-inline">기록이 없습니다.</p>';
      var max = Math.max.apply(null, ds.map(function (d) { return d.case_count; }));
      return ds.map(function (d) {
        return '<button type="button" class="ba-dom" data-domain="' + d.domain_id + '">' +
               '<span class="ba-dom__cat">' + esc(d.cat_label) + '</span>' +
               '<span class="ba-dom__name">' + esc(d.name) + '</span>' +
               '<span class="ba-dom__bar"><i style="width:' +
                 (max ? (100 * d.case_count / max) : 0) + '%"></i></span>' +
               '<span class="ba-dom__n">' + d.case_count + '건</span></button>';
      }).join('');
    }

    // ---- 근거 드로어 -------------------------------------------------
    function openEvidence(params, title) {
      var box = $('#ba-ev-body');
      $('#ba-ev-title').textContent = title + ' — 근거';
      box.innerHTML = '<div class="ba-loading">불러오는 중…</div>';
      $('#ba-ev-count').textContent = '';
      openDrawer('ba-evidence');

      var q = { act: 'evidence', member_id: MEMBER_ID };
      if (params.cat) q.category = params.cat;
      if (params.domain) q.domain_id = params.domain;

      api('api/profile.php?' + qs(q)).then(function (d) {
        if (!d.rows.length) {
          box.innerHTML = '<p class="ba-empty-inline">해당하는 처리 건이 없습니다.</p>';
          return;
        }
        $('#ba-ev-count').textContent = d.total + '건';
        box.innerHTML = d.rows.map(function (r) {
          var link = r.source_url
            ? '<a href="' + esc(r.source_url) + '" target="_blank" rel="noopener">슬랙 원본</a>'
            : '<span class="ba-dim">링크 없음</span>';
          return '<div class="ba-ev">' +
            '<div class="ba-ev__top">' +
              '<span class="ba-ev__diff" title="난이도">' +
                '★'.repeat(r.difficulty || 0) +
                '<em>' + (r.difficulty_by === 'rule' ? '규칙' :
                          r.difficulty_by === 'llm' ? '채점' : '수동') + '</em></span>' +
              '<span class="ba-ev__org">' + esc(r.org_name || '기관 미상') + '</span>' +
              '<span class="ba-ev__status">' + esc(r.status_raw || '') + '</span>' +
            '</div>' +
            '<div class="ba-ev__title">' + esc(r.title) + '</div>' +
            '<div class="ba-ev__meta">' +
              esc(r.domains || '') +
              (r.closed_at ? ' · 완료 ' + esc(r.closed_at) :
               r.requested_at ? ' · 요청 ' + esc(r.requested_at) : '') +
              (r.msg_count ? ' · 댓글 ' + r.msg_count : '') +
              ' · ' + link +
            '</div></div>';
        }).join('');
      }).catch(function (e) {
        box.innerHTML = '<p class="ba-empty-inline">' + esc(e.message) + '</p>';
      });
    }

    // ---- 그리기 -------------------------------------------------------
    function render(d) {
      DATA = d;
      if (d.eval_run) {
        $('#ba-prof-run').innerHTML =
          '<span class="ba-dim">판정 ' + esc(d.eval_run.period_from) + ' ~ ' +
          esc(d.eval_run.period_to) + ' · ' + esc(d.eval_run.formula_ver) +
          ' · 회차 ' + d.eval_ver + '</span>';
      }
      $('#ba-radar').innerHTML     = radar(d.categories);
      $('#ba-catlist').innerHTML   = catList(d.categories);
      $('#ba-metrics').innerHTML   = metrics(d.metric);
      $('#ba-domainlist').innerHTML = domainList(d.domains || []);

      var sel = $('#ba-obj-domain');
      if (sel && d.domains) {
        sel.innerHTML = '<option value="">전체</option>' + d.domains.map(function (x) {
          return '<option value="' + x.domain_id + '">' + esc(x.name) + '</option>';
        }).join('');
      }
    }

    api('api/profile.php?' + qs({ act: 'view', member_id: MEMBER_ID }))
      .then(render)
      .catch(function (e) { showError('#ba-prof-error', e.message); });

    // ---- 클릭 → 근거 ---------------------------------------------------
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-cat],[data-domain]') : null;
      if (!t || !$('#ba-profile')) return;
      if (t.dataset.cat) {
        var c = (DATA && DATA.categories || []).filter(function (x) {
          return x.category === t.dataset.cat; })[0];
        openEvidence({ cat: t.dataset.cat }, c ? c.label : '계열');
      } else if (t.dataset.domain) {
        var nm = t.querySelector('.ba-dom__name');
        openEvidence({ domain: t.dataset.domain }, nm ? nm.textContent : '분야');
      }
    });

    // ---- 이의 제기 -----------------------------------------------------
    var objBtn = $('#ba-obj-submit');
    if (objBtn) {
      objBtn.addEventListener('click', function () {
        var content = ($('#ba-obj-content') || {}).value || '';
        if (!content.trim()) { toast('내용을 입력하세요.', true); return; }
        objBtn.disabled = true;
        api('api/profile.php?act=objection', { method: 'POST', body: {
          content: content,
          domain_id: ($('#ba-obj-domain') || {}).value || '',
          eval_ver: DATA ? DATA.eval_ver : ''
        }}).then(function (d) {
          $('#ba-obj-content').value = '';
          toast(d.message || '접수했습니다.');
        }).catch(function (e) {
          showError('#ba-prof-error', e.message);
        }).then(function () { objBtn.disabled = false; });
      });
    }

    // ---- 관리자 보정 ---------------------------------------------------
    var adjBtn = $('#ba-adj-submit');
    if (adjBtn) {
      adjBtn.addEventListener('click', function () {
        var reason = ($('#ba-adj-reason') || {}).value || '';
        if (!reason.trim()) { toast('보정 사유를 입력하세요.', true); return; }
        adjBtn.disabled = true;
        api('api/profile.php?act=adjust', { method: 'POST', body: {
          member_id: MEMBER_ID,
          adjust: ($('#ba-adj-value') || {}).value || '0',
          reason: reason
        }}).then(function (d) {
          toast(d.message || '반영했습니다.');
          return api('api/profile.php?' + qs({ act: 'view', member_id: MEMBER_ID })).then(render);
        }).catch(function (e) {
          showError('#ba-prof-error', e.message);
        }).then(function () { adjBtn.disabled = false; });
      });
    }

    // ---- 평가 대상 전환 -------------------------------------------------
    var evOn = $('#ba-eval-on');
    if (evOn) {
      evOn.addEventListener('change', function () {
        $('#ba-eval-reason-wrap').hidden = evOn.checked;
      });
    }
    var evBtn = $('#ba-eval-submit');
    if (evBtn) {
      evBtn.addEventListener('click', function () {
        var on = ($('#ba-eval-on') || {}).checked;
        var reason = ($('#ba-eval-reason') || {}).value || '';
        if (!on && !reason.trim()) { toast('제외 사유를 입력하세요.', true); return; }
        evBtn.disabled = true;
        api('api/profile.php?act=set_evaluable', { method: 'POST', body: {
          member_id: MEMBER_ID, evaluable: on ? '1' : '0', reason: reason
        }}).then(function (d) {
          toast(d.message || '적용했습니다.');
          setTimeout(function () { location.reload(); }, 800);
        }).catch(function (e) {
          showError('#ba-prof-error', e.message);
          evBtn.disabled = false;
        });
      });
    }
  }

  // ---- 우측 드로어 ---------------------------------------------------
  //
  // conventions.md §5.1 의 BlueCart .bc-modal 계약을 그대로 옮겼다.
  // 가운데 팝업이 아니라 우측에서 밀려 나온다 — 목록을 보면서 상세를 연다.
  var lastFocus = null;

  function anyDrawerOpen() {
    return $$('.ba-drawer').some(function (d) { return !d.hidden; });
  }

  function openDrawer(id) {
    lastFocus = document.activeElement;
    var d = document.getElementById(id);
    if (!d) return;
    d.hidden = false;
    document.body.style.overflow = 'hidden';   // 뒤 목록이 같이 스크롤되면 위치를 잃는다
    var f = d.querySelector('button,a,input,select,textarea');
    if (f) f.focus();
  }

  function closeDrawer(id) {
    var d = document.getElementById(id);
    if (d) d.hidden = true;
    if (!anyDrawerOpen()) document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function initDrawer() {
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (t.hasAttribute && t.hasAttribute('data-close')) {
        var d = t.closest('.ba-drawer');
        if (d) closeDrawer(d.id);
      } else if (t.classList && t.classList.contains('ba-drawer')) {
        closeDrawer(t.id);          // 배경 클릭
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        $$('.ba-drawer').forEach(function (d) { if (!d.hidden) closeDrawer(d.id); });
      }
    });
  }

  // ---- 진입 ---------------------------------------------------------
  initDrawer();

  switch (NAV) {
    case 'dashboard': initDashboard(); break;
    case 'project':   initProjectList(); initProjectForm(); initProjectView(); initWbs(); initAllocation(); break;
    case 'member':    initMemberList(); initMemberProfile(); break;
  }

  window.BA = { api: api, upload: upload, qs: qs, toast: toast, esc: esc, $: $, $$: $$,
                ME_ID: ME_ID, IS_ADMIN: IS_ADMIN };
})();
