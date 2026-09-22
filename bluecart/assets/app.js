/* =====================================================================
   BlueCart client
   의존성 없음. iworks 페이지 안에 얹히므로 전역을 오염시키지 않도록
   IIFE 안에 전부 가둔다.
   ===================================================================== */
(function () {
  'use strict';

  var app = document.getElementById('bc-app');
  if (!app) return;

  var CSRF     = app.dataset.csrf;
  var CAN_ADMIN= app.dataset.canAdmin === '1';
  var IS_ADMIN = app.dataset.isAdmin === '1';
  var CAN_REVIEW = app.dataset.canReview === '1';
  // 구매담당자가 2명 이상일 때만 담당 지정이 의미가 있다.
  var NEEDS_ASSIGNEE = app.dataset.needsAssignee === '1';
  var THIS_YEAR= parseInt(app.dataset.year, 10);

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(n) { return (n == null ? '' : Number(n).toLocaleString('ko-KR')); }

  // ---- 통신 ---------------------------------------------------------
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
        if (!res.ok || !json.ok) throw new Error(json.error || '처리에 실패했습니다.');
        return json;
      });
    });
  }

  // multipart 업로드. JSON 이 아니므로 api() 와 헤더 구성이 다르다.
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
        if (!res.ok || !json.ok) throw new Error(json.error || '업로드에 실패했습니다.');
        return json;
      });
    });
  }

  // 파일 내려받기는 fetch 로 하면 브라우저 저장 대화상자가 안 뜨므로 이동시킨다.
  function download(url) { window.location.href = url; }

  function qs(params) {
    var out = [];
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v === null || v === undefined || v === '') return;
      if (Array.isArray(v)) v.forEach(function (x) { out.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(x)); });
      else out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    });
    return out.join('&');
  }

  // ---- 토스트 -------------------------------------------------------
  var toastTimer;
  function toast(msg, bad) {
    var el = $('#bc-toast');
    el.textContent = msg;
    el.className = 'bc-toast' + (bad ? ' bc-toast--bad' : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3600);
  }

  // ---- 모달 ---------------------------------------------------------
  var lastFocus = null;

  function anyOpen() {
    return $$('.bc-modal').some(function (m) { return !m.hidden; });
  }

  function openModal(id) {
    lastFocus = document.activeElement;
    var m = document.getElementById(id);
    m.hidden = false;
    // 드로어가 떠 있는 동안 뒤 목록이 같이 스크롤되면 위치를 잃는다.
    document.body.style.overflow = 'hidden';
    var f = m.querySelector('input,select,textarea,button');
    if (f) f.focus();
  }

  function closeModal(id) {
    document.getElementById(id).hidden = true;
    if (!anyOpen()) document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.hasAttribute && t.hasAttribute('data-close')) {
      var m = t.closest('.bc-modal');
      if (m) closeModal(m.id);
    } else if (t.classList && t.classList.contains('bc-modal')) {
      closeModal(t.id);
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      $$('.bc-modal').forEach(function (m) { if (!m.hidden) closeModal(m.id); });
    }
  });

  // =====================================================================
  // 최상위 탭
  // =====================================================================
  $$('.bc-tabs button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      $$('.bc-tabs button').forEach(function (b) { b.setAttribute('aria-selected', String(b === btn)); });
      $('#bc-view-member').hidden = btn.dataset.view !== 'member';
      var adm = $('#bc-view-admin');
      if (adm) adm.hidden = btn.dataset.view !== 'admin';
      if (btn.dataset.view === 'admin') admin.init();
    });
  });

  // =====================================================================
  // 파이프라인 집계
  // =====================================================================
  // 상태 축은 이 탭 줄이 전담한다. 고르는 자리가 하나뿐이라야 선택 표시가
  // 서로 어긋나지 않는다. 건수는 라벨 옆에 달아 숫자와 그 숫자를 쓰는
  // 컨트롤이 떨어지지 않게 한다.
  // 서버의 BC_STATUS_TABS 와 짝이다 — 한쪽만 고치면 안 된다.
  var STATUS_TABS = [
    { key: 'all',        label: '전체',      tone: 'all' },
    { key: 'REQUESTED',  label: '검토 대기', tone: 'wait' },
    { key: 'APPROVED',   label: '구매 대기', tone: 'ready' },
    { key: 'PURCHASING', label: '구매 진행', tone: 'work' },
    { key: 'STOCKED',    label: '구비 완료', tone: 'done' },
    { key: 'OUT',        label: '반려·철회', tone: 'stop' }
  ];

  /** 탭 하나가 걸러 낼 건수. 전체는 여섯 상태의 합(반려·철회 포함)이다. */
  function tabCount(counts, key) {
    if (key === 'all') { return counts.TOTAL || 0; }
    if (key === 'OUT') { return (counts.REJECTED || 0) + (counts.CANCELED || 0); }
    return counts[key] || 0;
  }

  function renderStatusTabs(el, counts, active, onPick) {
    el.innerHTML = STATUS_TABS.map(function (t) {
      var n = tabCount(counts, t.key);
      // 0건은 흐려지되 누르는 것은 막지 않는다. 막아 두면 왜 안 눌리는지
      // 설명할 자리가 없다. 눌리면 '조건에 맞는 요청이 없습니다' 가 뜬다.
      return '<button type="button" role="tab" class="bc-tone-' + t.tone +
             (n ? '' : ' is-zero') + '" data-tab="' + t.key + '"' +
             ' aria-selected="' + (active === t.key) + '">' +
             esc(t.label) + '<i>' + num(n) + '</i></button>';
    }).join('');

    $$('button', el).forEach(function (btn) {
      btn.addEventListener('click', function () { onPick(btn.dataset.tab); });
    });
  }

  // =====================================================================
  // 목록 렌더링 (구성원/관리자 공용)
  // =====================================================================
  var ACTION_META = {
    approve:        { label: '승인',      cls: 'bc-btn--primary', title: '요청 승인',
                      desc: '승인하면 구매담당자와 요청자에게 알림이 갑니다.',
                      commentLabel: '승인 의견 (선택)', needComment: false },
    reject:         { label: '반려',      cls: 'bc-btn--danger',  title: '요청 반려',
                      desc: '반려하면 요청자에게 사유와 함께 알림이 갑니다. 요청자는 내용을 고쳐 다시 올릴 수 있습니다.',
                      commentLabel: '반려 사유', needComment: true },
    start_purchase: { label: '구매 진행', cls: 'bc-btn--primary', title: '구매 진행으로 변경',
                      desc: '구매를 시작한 것으로 표시하고 요청자에게 알립니다.',
                      commentLabel: '메모 (선택)', needComment: false, purchase: true },
    complete:       { label: '구비 완료', cls: 'bc-btn--primary', title: '구비 완료 처리',
                      desc: '물품이 입고된 것으로 처리하고 요청자와 검토승인자에게 알립니다.',
                      commentLabel: '메모 (선택)', needComment: false, purchase: true },
    resubmit:       { label: '재요청',    cls: 'bc-btn--primary', title: '반려 건 재요청',
                      desc: '수정한 내용으로 다시 검토를 요청합니다. 먼저 내용을 고쳤는지 확인하세요.',
                      commentLabel: '검토자에게 남길 말 (선택)', needComment: false },
    cancel:         { label: '철회',      cls: 'bc-btn--danger',  title: '요청 철회',
                      desc: '요청을 철회합니다. 철회한 건은 다시 되돌릴 수 없습니다.',
                      commentLabel: '사유 (선택)', needComment: false },
    assign:         { label: '담당 지정', cls: '',                title: '구매 담당 지정',
                      desc: '이 건을 맡을 구매담당자를 정합니다. 지정하면 그 사람만 구매 진행과 구비 완료로 바꿀 수 있습니다.',
                      commentLabel: '메모 (선택)', needComment: false,
                      assignee: true, assigneeRequired: true }
  };
  ACTION_META.approve.assignee = true;   // 승인하면서 담당까지 지정할 수 있다

  function rowHtml(r, withAssignee) {
    var actions = (r.actions || []).map(function (a) {
      var m = ACTION_META[a];
      if (!m) return '';
      return '<button type="button" class="bc-btn bc-btn--sm ' + (m.cls || '') +
             '" data-act="' + a + '" data-id="' + r.id +
             '" data-assignee="' + esc(r.assignee_id || '') + '">' + m.label + '</button>';
    }).join('');

    if (r.is_mine && (r.status === 'REQUESTED' || r.status === 'REJECTED')) {
      actions = '<button type="button" class="bc-btn bc-btn--sm" data-edit="' + r.id + '">수정</button>' + actions;
    }

    var handled = r.stocked_at || r.purchasing_at || r.reviewed_at || '';
    var handler = r.buyer_name || r.reviewer_name || '';

    var clip = r.attach_count
      ? '<span class="bc-clip" title="첨부 ' + r.attach_count + '개">📎 ' + r.attach_count + '</span>' : '';

    var assigneeCell = '';
    if (withAssignee) {
      var cls = r.assignee_name ? (r.is_my_job ? 'bc-assignee--me' : '') : 'bc-assignee--none';
      assigneeCell = '<td><span class="bc-assignee ' + cls + '">' +
                     esc(r.assignee_label || '') + '</span></td>';
    }

    return '<tr>' +
      '<td class="bc-num"><a href="#" data-detail="' + r.id + '">' + esc(r.req_no) + '</a></td>' +
      '<td>' + esc(r.category_name) + '</td>' +
      '<td><span class="bc-item">' + esc(r.item_name) + '</span>' + clip +
        (r.note ? '<div class="bc-muted">' + esc(r.note.slice(0, 60)) + (r.note.length > 60 ? '…' : '') + '</div>' : '') +
        (r.resubmit_count ? '<div class="bc-muted">재요청 ' + r.resubmit_count + '회</div>' : '') +
      '</td>' +
      '<td class="bc-num">' + num(r.quantity) + esc(r.unit) + '</td>' +
      '<td>' + esc(r.requester_name) + '</td>' +
      '<td class="bc-num bc-muted">' + esc(r.requested_at) + '</td>' +
      '<td><span class="bc-chip bc-tone-' + r.status_tone + '">' + esc(r.status_label) + '</span></td>' +
      assigneeCell +
      '<td class="bc-num bc-muted">' + esc(handled) + (handler ? '<br>' + esc(handler) : '') + '</td>' +
      '<td><div class="bc-row-actions">' + actions + '</div></td>' +
      '</tr>';
  }

  /**
   * 카드 한 장. 표의 rowHtml 과 같은 데이터·같은 data-* 훅을 쓴다.
   * onRowClick 이 data-detail / data-edit / data-act 만 보므로 그대로 공용이다.
   */
  function cardHtml(r, withAssignee) {
    var actions = (r.actions || []).map(function (a) {
      var m = ACTION_META[a];
      if (!m) return '';
      return '<button type="button" class="bc-btn bc-btn--sm ' + (m.cls || '') +
             '" data-act="' + a + '" data-id="' + r.id +
             '" data-assignee="' + esc(r.assignee_id || '') + '">' + m.label + '</button>';
    }).join('');

    if (r.is_mine && (r.status === 'REQUESTED' || r.status === 'REJECTED')) {
      actions = '<button type="button" class="bc-btn bc-btn--sm" data-edit="' + r.id + '">수정</button>' + actions;
    }

    var meta = [esc(r.category_name), num(r.quantity) + esc(r.unit)];
    if (withAssignee && r.assignee_label) meta.push('담당 ' + esc(r.assignee_label));

    var clip = r.attach_count
      ? ' <span class="bc-clip" title="첨부 ' + r.attach_count + '개">📎 ' + r.attach_count + '</span>' : '';

    return '<article class="bc-card bc-tone-' + r.status_tone + '">' +
      '<div class="bc-card__top">' +
        '<a href="#" class="bc-card__no" data-detail="' + r.id + '">' + esc(r.req_no) + '</a>' +
        '<span class="bc-chip bc-tone-' + r.status_tone + '">' + esc(r.status_label) + '</span>' +
      '</div>' +
      '<h3 class="bc-card__item" data-detail="' + r.id + '">' + esc(r.item_name) + clip + '</h3>' +
      '<p class="bc-card__meta">' + meta.join(' · ') + '</p>' +
      (r.note ? '<p class="bc-card__note">' + esc(r.note.slice(0, 80)) + (r.note.length > 80 ? '…' : '') + '</p>' : '') +
      '<p class="bc-card__who">' + esc(r.requester_name) + '<time>' + esc(r.requested_at) + '</time></p>' +
      (actions ? '<div class="bc-card__actions">' + actions + '</div>' : '') +
      '</article>';
  }

  function renderCards(el, rows, emptyMsg, withAssignee) {
    if (!rows.length) {
      el.innerHTML = '<div class="bc-empty"><b>' + esc(emptyMsg.title) + '</b>' + esc(emptyMsg.body) + '</div>';
      return;
    }
    el.innerHTML = rows.map(function (r) { return cardHtml(r, withAssignee); }).join('');
  }

  function renderList(tbody, rows, emptyMsg, withAssignee) {
    var cols = withAssignee ? 10 : 9;
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="' + cols + '"><div class="bc-empty"><b>' + esc(emptyMsg.title) +
                        '</b>' + esc(emptyMsg.body) + '</div></td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (r) { return rowHtml(r, withAssignee); }).join('');
  }

  function renderPager(el, total, page, size, onGo) {
    var pages = Math.max(1, Math.ceil(total / size));
    if (total === 0) { el.innerHTML = ''; return; }
    el.innerHTML =
      '<button type="button" class="bc-btn bc-btn--sm" data-go="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>이전</button>' +
      '<span>' + page + ' / ' + pages + ' · 전체 ' + num(total) + '건</span>' +
      '<button type="button" class="bc-btn bc-btn--sm" data-go="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>다음</button>';
    $$('[data-go]', el).forEach(function (b) {
      b.addEventListener('click', function () { onGo(parseInt(b.dataset.go, 10)); });
    });
  }

  // =====================================================================
  // 구성원 화면
  // =====================================================================
  var member = {
    // 상태 축은 tab 하나뿐이다. 예전의 status 는 탭으로 흡수됐다.
    state: { tab: 'all', page: 1, view: 'list' },

    init: function () {
      var self = this;

      $('#bc-new').addEventListener('click', function () { form.open(null); });

      ['bc-f-year', 'bc-f-category', 'bc-f-sort', 'bc-f-mine', 'bc-f-from', 'bc-f-to'].forEach(function (id) {
        $('#' + id).addEventListener('change', function () { self.state.page = 1; self.load(); });
      });

      var kwTimer;
      $('#bc-f-keyword').addEventListener('input', function () {
        clearTimeout(kwTimer);
        kwTimer = setTimeout(function () { self.state.page = 1; self.load(); }, 350);
      });

      $('#bc-f-export').addEventListener('click', function () {
        var p = self.params();
        delete p.page; delete p.size; delete p.scope;
        download('api/export.php?type=list&' + qs(p));
      });

      // 처리 역할이 없는 사람은 남의 요청까지 볼 일이 드물다. 자기 것만 켜 두고
      // 시작하되 잠그지는 않는다 — 끄면 전체가 보인다.
      $('#bc-f-mine').checked = !CAN_ADMIN;

      $$('[data-view-mode]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (self.state.view === b.dataset.viewMode) return;
          self.state.view = b.dataset.viewMode;
          $$('[data-view-mode]').forEach(function (x) {
            x.setAttribute('aria-pressed', String(x === b));
          });
          self.applyView();
          self.load();
        });
      });

      var pick = onRowClick(function () { self.load(); });
      $('#bc-list').addEventListener('click', pick);
      $('#bc-cards').addEventListener('click', pick);

      this.applyView();
      this.load();
    },

    params: function () {
      var p = {
        scope: 'member',
        year: $('#bc-f-year').value,
        category_id: $('#bc-f-category').value,
        keyword: $('#bc-f-keyword').value.trim(),
        from: $('#bc-f-from').value,
        to: $('#bc-f-to').value,
        sort: $('#bc-f-sort').value,
        tab: this.state.tab,
        page: this.state.page,
        size: 30
      };
      if ($('#bc-f-mine').checked) p.mine = '1';
      return p;
    },

    /** 표와 카드 중 한쪽만 띄운다. */
    applyView: function () {
      var card = this.state.view === 'card';
      $('#bc-list').closest('.bc-table-wrap').hidden = card;
      $('#bc-cards').hidden = !card;
    },

    load: function () {
      var self = this;
      var tbody = $('#bc-list tbody');
      var cardBox = $('#bc-cards');
      if (self.state.view === 'card') {
        cardBox.innerHTML = '<p class="bc-loading">불러오는 중…</p>';
      } else {
        tbody.innerHTML = '<tr><td colspan="9" class="bc-loading">불러오는 중…</td></tr>';
      }

      api('api/requests.php?' + qs(this.params())).then(function (res) {
        renderStatusTabs($('#bc-tabs'), res.counts, self.state.tab, function (tab) {
          self.state.tab = tab;
          self.state.page = 1;
          self.load();
        });
        var empty = {
          title: '조건에 맞는 요청이 없습니다.',
          body: '필터를 넓히거나 새 구매 요청을 올려 보세요.'
        };
        if (self.state.view === 'card') {
          renderCards(cardBox, res.rows, empty, false);
        } else {
          renderList(tbody, res.rows, empty, false);
        }
        renderPager($('#bc-pager'), res.total, res.page, res.size, function (p) {
          self.state.page = p; self.load();
          $('#bc-view-member').scrollIntoView({ block: 'start' });
        });
      }).catch(function (e) {
        var msg = '<div class="bc-empty"><b>목록을 불러오지 못했습니다.</b>' + esc(e.message) + '</div>';
        if (self.state.view === 'card') {
          cardBox.innerHTML = msg;
        } else {
          tbody.innerHTML = '<tr><td colspan="9">' + msg + '</td></tr>';
        }
      });
    }
  };

  // 목록 행 클릭 (상세 / 수정 / 처리) 공용 핸들러
  function onRowClick(reload) {
    return function (e) {
      var t = e.target;
      if (t.dataset.detail) { e.preventDefault(); detail.open(parseInt(t.dataset.detail, 10), reload); }
      else if (t.dataset.edit) { form.open(parseInt(t.dataset.edit, 10)); }
      else if (t.dataset.act) {
        action.open(parseInt(t.dataset.id, 10), t.dataset.act, reload, t.dataset.assignee || '');
      }
    };
  }

  // =====================================================================
  // 요청 작성 / 수정
  // =====================================================================
  /**
   * 수령 장소 선택. 목록에 없는 값(선택 목록으로 바꾸기 전에 자유 입력으로
   * 저장된 건)이면 그 값을 임시 항목으로 붙여 고른 상태로 둔다. 장소만
   * 손대지 않으면 예전 내용 그대로 다시 저장된다.
   */
  function setDeliver(value) {
    var sel = $('#bc-in-deliver');
    var legacy = sel.querySelector('option[data-legacy]');
    if (legacy) sel.removeChild(legacy);

    sel.value = value;
    if (sel.value !== value) {
      var opt = document.createElement('option');
      opt.value = value;
      opt.textContent = value + ' (이전 입력값)';
      opt.setAttribute('data-legacy', '1');
      sel.appendChild(opt);
      sel.value = value;
    }
  }

  var form = {
    pending: [],   // 아직 서버에 올리지 않은 파일

    renderPending: function () {
      var self = this;
      var box = $('#bc-form-files');
      box.innerHTML = self.pending.map(function (f, i) {
        return '<div class="bc-file"><span class="bc-file__name">' + esc(f.name) + '</span>' +
               '<span class="bc-file__size">' + esc(fileSize(f.size)) + '</span>' +
               '<button type="button" class="bc-file__x" data-drop="' + i + '" aria-label="빼기">&times;</button></div>';
      }).join('');
      $$('[data-drop]', box).forEach(function (b) {
        b.addEventListener('click', function () {
          self.pending.splice(parseInt(b.dataset.drop, 10), 1);
          self.renderPending();
        });
      });
    },

    open: function (id) {
      $('#bc-form-error').hidden = true;
      $('#bc-in-id').value = id || '';
      $('#bc-m-form-title').textContent = id ? '구매 요청 수정' : '새 구매 요청';
      $('#bc-form-submit').textContent = id ? '수정 저장' : '요청 올리기';

      this.pending = [];
      this.renderPending();
      $('#bc-in-files').value = '';

      // 검토 권한자가 새로 올릴 때만 승인 생략이 가능하다.
      // 남의 요청을 대신 수정하는 경우까지 건너뛰게 하면 검토 기록이 비어 버린다.
      $('#bc-form-submit-skip').hidden = !(CAN_REVIEW && !id);

      if (!id) {
        $('#bc-in-category').selectedIndex = 0;
        $('#bc-in-item').value = '';
        $('#bc-in-qty').value = 1;
        $('#bc-in-unit').value = '개';
        $('#bc-in-amount').value = '';
        setDeliver('');
        $('#bc-in-needby').value = '';
        $('#bc-in-url').value = '';
        $('#bc-in-note').value = '';
        openModal('bc-m-form');
        return;
      }

      api('api/request_detail.php?id=' + id).then(function (res) {
        var r = res.request;
        $('#bc-in-category').value = r.category_id;
        $('#bc-in-item').value = r.item_name;
        $('#bc-in-qty').value = r.quantity;
        $('#bc-in-unit').value = r.unit;
        $('#bc-in-amount').value = r.est_amount == null ? '' : r.est_amount;
        setDeliver(r.deliver_to || '');
        $('#bc-in-needby').value = r.need_by || '';
        $('#bc-in-url').value = r.ref_url || '';
        $('#bc-in-note').value = r.note || '';
        openModal('bc-m-form');
      }).catch(function (e) { toast(e.message, true); });
    },

    submit: function (skipReview) {
      var btn  = skipReview ? $('#bc-form-submit-skip') : $('#bc-form-submit');
      var both = [$('#bc-form-submit'), $('#bc-form-submit-skip')];
      var err  = $('#bc-form-error');
      err.hidden = true;
      both.forEach(function (b) { b.disabled = true; });

      var body = {
        id: $('#bc-in-id').value || null,
        skip_review: skipReview ? '1' : '',
        category_id: $('#bc-in-category').value,
        item_name: $('#bc-in-item').value.trim(),
        quantity: $('#bc-in-qty').value,
        unit: $('#bc-in-unit').value.trim() || '개',
        est_amount: $('#bc-in-amount').value,
        deliver_to: $('#bc-in-deliver').value,
        need_by: $('#bc-in-needby').value,
        ref_url: $('#bc-in-url').value.trim(),
        note: $('#bc-in-note').value.trim()
      };

      var self = this;
      api('api/request_save.php', { method: 'POST', body: body }).then(function (res) {
        // 요청을 먼저 저장해 번호를 받은 뒤 파일을 붙인다. 업로드가 실패해도
        // 요청 자체는 남으므로 상세 화면에서 다시 올릴 수 있다.
        if (!self.pending.length) return res;

        var fd = new FormData();
        fd.append('op', 'upload');
        fd.append('request_id', res.id);
        self.pending.forEach(function (f) { fd.append('files[]', f); });

        return upload('api/attachments.php', fd).then(function (up) {
          if (up.errors && up.errors.length) {
            toast(up.errors.join(' / '), true);
          }
          return res;
        }).catch(function (e) {
          toast('요청은 저장됐지만 첨부에 실패했습니다: ' + e.message, true);
          return res;
        });
      }).then(function (res) {
        self.pending = [];
        closeModal('bc-m-form');
        toast(res.message);
        member.load();
        if (CAN_ADMIN) admin.reloadQueue();
      }).catch(function (e) {
        err.textContent = e.message;
        err.hidden = false;
      }).finally(function () {
        both.forEach(function (b) { b.disabled = false; });
      });
    }
  };

  function fileSize(bytes) {
    if (bytes < 1024) return bytes + 'B';
    if (bytes < 1048576) return Math.round(bytes / 1024) + 'KB';
    return (bytes / 1048576).toFixed(1) + 'MB';
  }

  // =====================================================================
  // 상세
  // =====================================================================
  var detail = {
    open: function (id, reload) {
      $('#bc-detail-body').innerHTML = '<p class="bc-loading">불러오는 중…</p>';
      $('#bc-detail-actions').innerHTML = '';
      openModal('bc-m-detail');

      api('api/request_detail.php?id=' + id).then(function (res) {
        var r = res.request;
        var rows = [
          ['요청번호', esc(r.req_no)],
          ['사용처', esc(r.category_name)],
          ['필요 물품', esc(r.item_name)],
          ['필요 갯수', num(r.quantity) + esc(r.unit)],
          ['요청자', esc(r.requester_name) + ' · ' + esc(r.requested_at)],
          ['처리상태', '<span class="bc-chip bc-tone-' + r.status_tone + '">' + esc(r.status_label) + '</span>']
        ];
        if (r.need_by)     rows.push(['희망 수령일', esc(r.need_by)]);
        if (r.deliver_to)  rows.push(['수령 장소', esc(r.deliver_to)]);
        if (r.est_amount != null)    rows.push(['예상 금액', num(r.est_amount) + '원']);
        if (r.actual_amount != null) rows.push(['실구매 금액', num(r.actual_amount) + '원']);
        if (r.ref_url)     rows.push(['참고 링크', '<a href="' + esc(r.ref_url) + '" target="_blank" rel="noopener noreferrer">' + esc(r.ref_url) + '</a>']);
        if (r.note)        rows.push(['비고', esc(r.note).replace(/\n/g, '<br>')]);
        if (r.reviewer_name)  rows.push(['검토', esc(r.reviewer_name) + ' · ' + esc(r.reviewed_at)]);
        if (r.review_comment) rows.push([r.status === 'REJECTED' ? '반려 사유' : '검토 의견', esc(r.review_comment)]);
        if (r.assignee_name)  rows.push(['구매 담당', esc(r.assignee_name)]);
        else if (r.status === 'APPROVED' || r.status === 'PURCHASING')
                              rows.push(['구매 담당', '<span class="bc-assignee--none">미지정 — 구매담당자 누구나 처리 가능</span>']);
        if (r.buyer_name)     rows.push(['처리자', esc(r.buyer_name)]);
        if (r.purchase_note)  rows.push(['구매 메모', esc(r.purchase_note)]);
        if (r.stocked_at)     rows.push(['구비 완료', esc(r.stocked_at)]);

        var trail = res.history.map(function (h) {
          return '<li><b>' + esc(h.label) + '</b> · ' + esc(h.actor) +
                 '<time>' + esc(h.created_at) + '</time>' +
                 (h.comment ? '<span class="bc-trail__c">' + esc(h.comment) + '</span>' : '') + '</li>';
        }).join('');

        $('#bc-detail-body').innerHTML =
          '<dl class="bc-dl">' + rows.map(function (kv) {
            return '<dt>' + kv[0] + '</dt><dd>' + kv[1] + '</dd>';
          }).join('') + '</dl>' +
          '<h3 style="margin-top:20px">첨부파일</h3>' +
          '<div id="bc-detail-files"><p class="bc-muted">불러오는 중…</p></div>' +
          '<h3 style="margin-top:20px">처리 이력</h3>' +
          '<ul class="bc-trail">' + trail + '</ul>';

        attach.render(r.id, reload);

        $('#bc-detail-actions').innerHTML =
          '<button type="button" class="bc-btn" data-close>닫기</button>' +
          (r.actions || []).map(function (a) {
            var m = ACTION_META[a];
            return m ? '<button type="button" class="bc-btn ' + (m.cls || '') + '" data-act="' + a +
                       '" data-id="' + r.id + '">' + m.label + '</button>' : '';
          }).join('');

        $$('#bc-detail-actions [data-act]').forEach(function (b) {
          b.addEventListener('click', function () {
            closeModal('bc-m-detail');
            action.open(parseInt(b.dataset.id, 10), b.dataset.act, reload, r.assignee_id || '');
          });
        });
      }).catch(function (e) {
        $('#bc-detail-body').innerHTML = '<div class="bc-alert">' + esc(e.message) + '</div>';
      });
    }
  };

  // =====================================================================
  // 첨부파일 (상세 화면)
  // =====================================================================
  var attach = {
    render: function (requestId, reload) {
      var self = this;
      var box = $('#bc-detail-files');
      if (!box) return;

      api('api/attachments.php?request_id=' + requestId).then(function (res) {
        var list = res.rows.length
          ? res.rows.map(function (f) {
              var del = (res.can_modify && f.is_mine)
                ? '<button type="button" class="bc-file__x" data-del="' + f.id + '" aria-label="삭제">&times;</button>'
                : '';
              return '<div class="bc-file">' +
                     '<span class="bc-file__name"><a href="' + esc(f.url) + '">' + esc(f.name) + '</a></span>' +
                     '<span class="bc-file__size">' + esc(f.size) + '</span>' + del + '</div>';
            }).join('')
          : '<p class="bc-muted">첨부된 파일이 없습니다.</p>';

        var uploader = res.can_modify
          ? '<div class="bc-upload-row">' +
            '<input type="file" id="bc-att-input" multiple>' +
            '<button type="button" class="bc-btn bc-btn--sm" id="bc-att-up">올리기</button>' +
            '</div>'
          : '';

        box.innerHTML = '<div class="bc-filelist">' + list + '</div>' + uploader;

        $$('[data-del]', box).forEach(function (b) {
          b.addEventListener('click', function () {
            if (!confirm('이 첨부파일을 지울까요?')) return;
            api('api/attachments.php', { method: 'POST', body: { op: 'delete', id: b.dataset.del } })
              .then(function (r) { toast(r.message); self.render(requestId, reload); if (reload) reload(); })
              .catch(function (e) { toast(e.message, true); });
          });
        });

        var up = $('#bc-att-up', box);
        if (up) {
          up.addEventListener('click', function () {
            var input = $('#bc-att-input', box);
            if (!input.files.length) { toast('올릴 파일을 선택하세요.', true); return; }

            var fd = new FormData();
            fd.append('op', 'upload');
            fd.append('request_id', requestId);
            Array.prototype.forEach.call(input.files, function (f) { fd.append('files[]', f); });

            up.disabled = true;
            up.textContent = '올리는 중…';
            upload('api/attachments.php', fd).then(function (r) {
              toast(r.message, r.errors && r.errors.length ? true : false);
              self.render(requestId, reload);
              if (reload) reload();
            }).catch(function (e) {
              toast(e.message, true);
              up.disabled = false;
              up.textContent = '올리기';
            });
          });
        }
      }).catch(function (e) {
        box.innerHTML = '<div class="bc-alert">' + esc(e.message) + '</div>';
      });
    }
  };

  // =====================================================================
  // 처리 (승인/반려/구매진행/구비완료/재요청/철회/담당지정)
  // =====================================================================
  var action = {
    ctx: null,

    buyers: null,   // 구매담당자 목록 캐시

    open: function (id, act, reload, current) {
      var m = ACTION_META[act];
      if (!m) return;
      this.ctx = { id: id, act: act, reload: reload };

      $('#bc-action-error').hidden = true;
      $('#bc-m-action-title').textContent = m.title;
      $('#bc-action-desc').textContent = m.desc;
      $('#bc-action-comment-label').textContent = m.commentLabel;
      $('#bc-action-comment').value = '';
      $('#bc-action-purchase-wrap').hidden = !m.purchase;
      $('#bc-action-amount').value = '';
      $('#bc-action-pnote').value = '';
      // 구매담당자가 한 명뿐이면 고를 게 없으므로 칸을 띄우지 않는다.
      var wantAssignee = m.assignee && NEEDS_ASSIGNEE;
      $('#bc-action-assignee-wrap').hidden = !wantAssignee;
      $('#bc-action-assignee-label').textContent =
        act === 'approve' ? '구매 담당 (선택)' : '구매 담당';
      $('#bc-action-submit').textContent = m.label;
      $('#bc-action-submit').className = 'bc-btn ' + (m.cls || '');

      openModal('bc-m-action');
      if (wantAssignee) this.loadBuyers(current);
    },

    loadBuyers: function (current) {
      var sel = $('#bc-action-assignee');
      var fill = function (rows) {
        sel.innerHTML = '<option value="">지정하지 않음 — 구매담당자 누구나 처리</option>' +
          rows.map(function (b) {
            return '<option value="' + esc(b.user_id) + '">' + esc(b.user_name) + '</option>';
          }).join('');
        if (current) sel.value = current;
      };

      if (this.buyers) { fill(this.buyers); return; }

      var self = this;
      sel.innerHTML = '<option value="">불러오는 중…</option>';
      api('api/roles.php').then(function (res) {
        self.buyers = res.BUYER || [];
        if (self.buyers.length < 2) {
          // 화면을 띄운 뒤 배정이 바뀌었을 수 있다. 고를 게 없으면 접는다.
          $('#bc-action-assignee-wrap').hidden = true;
          return;
        }
        fill(self.buyers);
      }).catch(function () {
        sel.innerHTML = '<option value="">목록을 불러오지 못했습니다</option>';
      });
    },

    submit: function () {
      var ctx = this.ctx;
      if (!ctx) return;
      var m = ACTION_META[ctx.act];
      var comment = $('#bc-action-comment').value.trim();
      var err = $('#bc-action-error');

      if (m.needComment && !comment) {
        err.textContent = '반려 사유를 입력해야 요청자가 무엇을 고쳐야 할지 알 수 있습니다.';
        err.hidden = false;
        $('#bc-action-comment').focus();
        return;
      }

      var assigneeShown = !$('#bc-action-assignee-wrap').hidden;
      var assignee = assigneeShown ? $('#bc-action-assignee').value : '';
      if (m.assigneeRequired && assigneeShown && !assignee) {
        err.textContent = '담당할 구매담당자를 선택하세요.';
        err.hidden = false;
        $('#bc-action-assignee').focus();
        return;
      }

      var btn = $('#bc-action-submit');
      btn.disabled = true;
      err.hidden = true;

      api('api/request_action.php', {
        method: 'POST',
        body: {
          id: ctx.id,
          action: ctx.act,
          comment: comment,
          assignee_id: assignee,
          actual_amount: $('#bc-action-amount').value,
          purchase_note: $('#bc-action-pnote').value.trim()
        }
      }).then(function (res) {
        closeModal('bc-m-action');
        toast(res.message);
        member.load();
        if (CAN_ADMIN) admin.reloadQueue();
        if (ctx.reload) ctx.reload();
      }).catch(function (e) {
        err.textContent = e.message;
        err.hidden = false;
      }).finally(function () { btn.disabled = false; });
    }
  };

  // =====================================================================
  // 관리자 화면
  // =====================================================================
  var admin = {
    ready: false,
    // tab = 상태 축, assign = 사람 축. 담당 기본값은 '내가 처리할 건' 이라
    // 화면을 열면 바로 작업 큐가 보인다.
    state: { tab: 'all', page: 1 },

    init: function () {
      if (this.ready || !CAN_ADMIN) return;
      this.ready = true;
      var self = this;

      $$('.bc-subtabs [data-adm]').forEach(function (b) {
        b.addEventListener('click', function () {
          $$('.bc-subtabs [data-adm]').forEach(function (x) { x.setAttribute('aria-selected', String(x === b)); });
          ['queue', 'stats', 'category', 'roles', 'notify'].forEach(function (k) {
            var el = $('#bc-adm-' + k);
            if (el) el.hidden = (k !== b.dataset.adm);
          });
          if (b.dataset.adm === 'stats')    self.loadStats();
          if (b.dataset.adm === 'category') self.loadCategories();
          if (b.dataset.adm === 'roles')    self.loadRoles();
          if (b.dataset.adm === 'notify')   self.loadNotify();
        });
      });

      ['bc-af-year', 'bc-af-category', 'bc-af-sort', 'bc-af-assign'].forEach(function (id) {
        $('#' + id).addEventListener('change', function () { self.state.page = 1; self.loadQueue(); });
      });
      var t;
      $('#bc-af-keyword').addEventListener('input', function () {
        clearTimeout(t); t = setTimeout(function () { self.state.page = 1; self.loadQueue(); }, 350);
      });
      $('#bc-af-export').addEventListener('click', function () {
        download('api/export.php?type=list&' + qs(self.exportParams()));
      });
      $('#bc-st-export').addEventListener('click', function () {
        download('api/export.php?type=stats&year=' + $('#bc-st-year').value);
      });

      $('#bc-af-reset').addEventListener('click', function () {
        $('#bc-af-year').value = THIS_YEAR;
        $('#bc-af-category').value = '';
        $('#bc-af-keyword').value = '';
        $('#bc-af-sort').value = 'status';
        $('#bc-af-assign').value = 'todo';
        self.state.tab = 'all'; self.state.page = 1; self.loadQueue();
      });

      $('#bc-adm-list').addEventListener('click', onRowClick(function () { self.loadQueue(); }));

      if (IS_ADMIN) {
        $('#bc-cat-add').addEventListener('click', function () { self.addCategory(); });
        $('#bc-cat-list').addEventListener('click', function (e) { self.categoryAction(e); });
        $$('[data-save-role]').forEach(function (b) {
          b.addEventListener('click', function () { self.saveRole(b.dataset.saveRole); });
        });
        var rt;
        $('#bc-role-search').addEventListener('input', function () {
          clearTimeout(rt); rt = setTimeout(function () { self.loadRoles(); }, 350);
        });
        $('#bc-nt-save').addEventListener('click', function () { self.saveNotify(); });
      }

      // 통계 탭은 검토승인자·구매담당자에게도 보이므로 관리자 전용 블록 밖에 둔다.
      $('#bc-st-year').addEventListener('change', function () { self.loadStats(); });

      this.loadQueue();
    },

    reloadQueue: function () { if (this.ready) this.loadQueue(); },

    queueParams: function () {
      var p = {
        scope: 'admin',
        year: $('#bc-af-year').value,
        category_id: $('#bc-af-category').value,
        keyword: $('#bc-af-keyword').value.trim(),
        sort: $('#bc-af-sort').value,
        assign: $('#bc-af-assign').value,
        tab: this.state.tab,
        page: this.state.page,
        size: 30
      };
      return p;
    },

    // 엑셀은 조건에 맞는 전체를 담으므로 페이지 정보는 뺀다.
    exportParams: function () {
      var p = this.queueParams();
      delete p.page; delete p.size; delete p.scope;
      // assign 의 'todo'/'assigned' 는 서버가 로그인 사용자 기준으로 푼다
      return p;
    },

    // ---- 신청 물품 관리 ---------------------------------------------
    loadQueue: function () {
      var self = this;
      var tbody = $('#bc-adm-list tbody');
      tbody.innerHTML = '<tr><td colspan="10" class="bc-loading">불러오는 중…</td></tr>';

      var p = this.queueParams();

      api('api/requests.php?' + qs(p)).then(function (res) {
        renderStatusTabs($('#bc-adm-tabs'), res.counts, self.state.tab, function (tab) {
          self.state.tab = tab;
          self.state.page = 1;
          self.loadQueue();
        });
        renderList(tbody, res.rows, {
          title: '처리할 요청이 없습니다.',
          body: '새 요청이 올라오면 여기에 표시됩니다.'
        }, true);
        renderPager($('#bc-adm-pager'), res.total, res.page, res.size, function (pg) {
          self.state.page = pg; self.loadQueue();
        });
      }).catch(function (e) {
        tbody.innerHTML = '<tr><td colspan="10"><div class="bc-empty"><b>목록을 불러오지 못했습니다.</b>' +
                          esc(e.message) + '</div></td></tr>';
      });
    },

    // ---- 통계 -------------------------------------------------------
    loadStats: function () {
      var body = $('#bc-st-body');
      body.innerHTML = '<p class="bc-loading">불러오는 중…</p>';

      api('api/stats.php?year=' + $('#bc-st-year').value).then(function (res) {
        var s = res.stats;
        var lt = s.lead_time;

        function hours(h) {
          if (h == null) return '–';
          return h < 48 ? h.toFixed(1) + '시간' : (h / 24).toFixed(1) + '일';
        }

        var cards = [
          ['전체 요청', num(s.status.TOTAL) + '건'],
          ['구비 완료', num(s.status.STOCKED) + '건'],
          ['진행 중', num(s.status.REQUESTED + s.status.APPROVED + s.status.PURCHASING) + '건'],
          ['반려 · 철회', num(s.status.REJECTED + s.status.CANCELED) + '건'],
          ['평균 검토 소요', hours(lt.review_hours)],
          ['요청→구비 평균', hours(lt.total_hours)]
        ].map(function (c) {
          return '<div class="bc-stat"><b>' + esc(c[1]) + '</b><span>' + esc(c[0]) + '</span></div>';
        }).join('');

        function bars(rows, labelKey, valKey, suffix) {
          if (!rows.length) return '<p class="bc-muted">집계할 자료가 없습니다.</p>';
          var max = Math.max.apply(null, rows.map(function (r) { return Number(r[valKey]); })) || 1;
          return rows.map(function (r) {
            var v = Number(r[valKey]);
            return '<div class="bc-bar"><span>' + esc(r[labelKey]) + '</span>' +
                   '<span class="bc-bar__track"><span class="bc-bar__fill" style="width:' +
                   Math.max(2, (v / max) * 100) + '%"></span></span>' +
                   '<span class="bc-bar__n">' + num(v) + (suffix || '') + '</span></div>';
          }).join('');
        }

        var monthRows = s.by_month.map(function (m) {
          return { name: m.m + '월', cnt: m.cnt };
        });

        body.innerHTML =
          '<div class="bc-stat-cards">' + cards + '</div>' +
          '<div class="bc-grid2">' +
            '<div class="bc-panel"><h2>월별 요청 건수</h2><p class="bc-panel__hint">' + s.year + '년</p>' +
              bars(monthRows, 'name', 'cnt', '건') + '</div>' +
            '<div class="bc-panel"><h2>사용처별</h2><p class="bc-panel__hint">요청 건수 기준</p>' +
              bars(s.by_category, 'name', 'cnt', '건') + '</div>' +
            '<div class="bc-panel"><h2>요청자별</h2><p class="bc-panel__hint">상위 15명</p>' +
              bars(s.by_requester, 'name', 'cnt', '건') + '</div>' +
            '<div class="bc-panel"><h2>구매담당자별</h2><p class="bc-panel__hint">구비 완료 처리 건수</p>' +
              bars(s.by_buyer, 'name', 'cnt', '건') + '</div>' +
            '<div class="bc-panel"><h2>자주 구비한 물품</h2><p class="bc-panel__hint">구비 완료 건 기준 상위 15개</p>' +
              bars(s.top_items, 'name', 'cnt', '회') + '</div>' +
          '</div>';
      }).catch(function (e) {
        body.innerHTML = '<div class="bc-alert">' + esc(e.message) + '</div>';
      });
    },

    // ---- 카테고리 ---------------------------------------------------
    loadCategories: function () {
      var tbody = $('#bc-cat-list tbody');
      api('api/categories.php?all=1').then(function (res) {
        if (!res.rows.length) {
          tbody.innerHTML = '<tr><td colspan="5"><div class="bc-empty"><b>카테고리가 없습니다.</b>위에서 추가하세요.</div></td></tr>';
          return;
        }
        tbody.innerHTML = res.rows.map(function (c) {
          return '<tr data-id="' + c.id + '">' +
            '<td class="bc-num">' + esc(c.code) + '</td>' +
            '<td><input type="text" data-f="name" value="' + esc(c.name) + '" style="width:100%"></td>' +
            '<td><input type="number" data-f="sort" value="' + c.sort_order + '" style="width:70px"></td>' +
            '<td><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-f="active"' +
              (Number(c.is_active) ? ' checked' : '') + '> 사용</label></td>' +
            '<td><div class="bc-row-actions">' +
              '<button type="button" class="bc-btn bc-btn--sm" data-cat-save>저장</button>' +
              '<button type="button" class="bc-btn bc-btn--sm bc-btn--danger" data-cat-del>삭제</button>' +
            '</div></td></tr>';
        }).join('');
      }).catch(function (e) {
        tbody.innerHTML = '<tr><td colspan="5"><div class="bc-alert">' + esc(e.message) + '</div></td></tr>';
      });
    },

    addCategory: function () {
      var self = this;
      api('api/categories.php', {
        method: 'POST',
        body: {
          op: 'create',
          code: $('#bc-cat-code').value.trim().toUpperCase(),
          name: $('#bc-cat-name').value.trim(),
          sort_order: $('#bc-cat-sort').value
        }
      }).then(function (res) {
        toast(res.message);
        $('#bc-cat-code').value = '';
        $('#bc-cat-name').value = '';
        self.loadCategories();
      }).catch(function (e) { toast(e.message, true); });
    },

    categoryAction: function (e) {
      var self = this;
      var tr = e.target.closest('tr');
      if (!tr || !tr.dataset.id) return;
      var id = tr.dataset.id;

      if (e.target.hasAttribute('data-cat-save')) {
        api('api/categories.php', {
          method: 'POST',
          body: {
            op: 'update', id: id,
            name: $('[data-f="name"]', tr).value.trim(),
            sort_order: $('[data-f="sort"]', tr).value,
            is_active: $('[data-f="active"]', tr).checked ? '1' : '0'
          }
        }).then(function (res) { toast(res.message); self.loadCategories(); })
          .catch(function (err) { toast(err.message, true); });
      } else if (e.target.hasAttribute('data-cat-del')) {
        if (!confirm('이 카테고리를 삭제할까요?')) return;
        api('api/categories.php', { method: 'POST', body: { op: 'delete', id: id } })
          .then(function (res) { toast(res.message); self.loadCategories(); })
          .catch(function (err) { toast(err.message, true); });
      }
    },

    // ---- 역할 배정 --------------------------------------------------
    loadRoles: function () {
      var q = $('#bc-role-search').value.trim();
      Promise.all([
        api('api/members.php?q=' + encodeURIComponent(q)),
        api('api/roles.php')
      ]).then(function (r) {
        var members = r[0].rows, assigned = r[1];
        ['REVIEWER', 'BUYER', 'ADMIN'].forEach(function (type) {
          var picked = {};
          (assigned[type] || []).forEach(function (a) { picked[a.user_id] = true; });

          var box = $('#bc-role-' + type);
          if (!members.length) {
            box.innerHTML = '<div class="bc-empty"><b>구성원을 불러오지 못했습니다.</b>' +
                            'config.php 의 iworks.member 매핑을 확인하세요.</div>';
            return;
          }
          box.innerHTML = members.map(function (m) {
            return '<label><input type="checkbox" value="' + esc(m.id) + '"' +
                   (picked[m.id] ? ' checked' : '') + '> ' + esc(m.name) +
                   '<span class="bc-picker__id">' + esc(m.id) + '</span></label>';
          }).join('');
        });
      }).catch(function (e) { toast(e.message, true); });
    },

    saveRole: function (type) {
      var ids = $$('#bc-role-' + type + ' input:checked').map(function (i) { return i.value; });
      api('api/roles.php', { method: 'POST', body: { role_type: type, user_ids: ids } })
        .then(function (res) { toast(res.message); })
        .catch(function (e) { toast(e.message, true); });
    },

    // ---- 알림 설정 --------------------------------------------------
    notifyMeta: null,

    loadNotify: function () {
      var self = this;
      api('api/notify_settings.php').then(function (res) {
        self.notifyMeta = res;
        $('#bc-nt-channel').value = res.slack_channel || '';

        var chKeys = Object.keys(res.channels);
        var html = '<thead><tr><th style="min-width:180px">처리 단계</th><th style="min-width:110px">알림 대상</th>' +
                   chKeys.map(function (c) { return '<th>' + esc(res.channels[c]) + '</th>'; }).join('') +
                   '</tr></thead><tbody>';

        Object.keys(res.events).forEach(function (ev) {
          var roles = res.targets[ev] || [];
          if (!roles.length) return;
          roles.forEach(function (role, i) {
            html += '<tr>';
            if (i === 0) {
              html += '<td class="bc-matrix__ev" rowspan="' + roles.length + '">' + esc(res.events[ev]) + '</td>';
            }
            html += '<td>' + esc(res.roles[role] || role) + '</td>';
            chKeys.forEach(function (ch) {
              var on = res.matrix[ev] && res.matrix[ev][role] && res.matrix[ev][role][ch];
              html += '<td><input type="checkbox" data-ev="' + ev + '" data-role="' + role +
                      '" data-ch="' + ch + '"' + (on ? ' checked' : '') + '></td>';
            });
            html += '</tr>';
          });
        });
        html += '</tbody>';
        $('#bc-nt-matrix').innerHTML = html;
      }).catch(function (e) { toast(e.message, true); });
    },

    saveNotify: function () {
      var matrix = {};
      $$('#bc-nt-matrix input[type="checkbox"]').forEach(function (cb) {
        var ev = cb.dataset.ev, role = cb.dataset.role, ch = cb.dataset.ch;
        matrix[ev] = matrix[ev] || {};
        matrix[ev][role] = matrix[ev][role] || {};
        matrix[ev][role][ch] = cb.checked ? 1 : 0;
      });
      api('api/notify_settings.php', {
        method: 'POST',
        body: { matrix: matrix, slack_channel: $('#bc-nt-channel').value.trim() }
      }).then(function (res) { toast(res.message); })
        .catch(function (e) { toast(e.message, true); });
    }
  };

  // ---- 바인딩 -------------------------------------------------------
  $('#bc-in-files').addEventListener('change', function () {
    Array.prototype.forEach.call(this.files, function (f) { form.pending.push(f); });
    form.renderPending();
    this.value = '';   // 같은 파일을 다시 고를 수 있게 비운다
  });

  $('#bc-form-submit').addEventListener('click', function () { form.submit(false); });
  $('#bc-form-submit-skip').addEventListener('click', function () {
    if (!confirm('검토 승인 단계를 건너뛰고 바로 구매 대기로 넘깁니다.\n계속할까요?')) return;
    form.submit(true);
  });
  $('#bc-action-submit').addEventListener('click', function () { action.submit(); });

  member.init();

  // 링크로 특정 요청을 열고 들어온 경우 (?id=123)
  var m = location.search.match(/[?&]id=(\d+)/);
  if (m) detail.open(parseInt(m[1], 10), function () { member.load(); });
})();
