/*
 * PWA 공용 — service worker 등록 + 푸시 구독 도우미(window.bluePush).
 * core/pwa.php 의 pwa_head_tags() 가 모든 모듈 <head> 에 넣는다.
 * data-root 는 이 페이지에서 포털 루트까지의 접두사('' | '../' | '../../').
 *
 *   bluePush.supported()          이 브라우저가 웹 푸시를 받을 수 있는가
 *   bluePush.permission()         'default' | 'granted' | 'denied'
 *   await bluePush.status()       이 기기의 구독(PushSubscription) 또는 null
 *   await bluePush.subscribe(key) 권한 묻기 → 구독 → 서버 저장. 반드시 클릭 안에서 부른다(iOS)
 *   await bluePush.unsubscribe()  서버에서 지우고 브라우저 구독도 끊는다
 *   await bluePush.endpointHash() 이 기기 구독 주소의 sha256(16진) 또는 null — 서버 기기 목록과 견준다
 */
(function () {
  var script = document.currentScript;
  var root = (script && script.getAttribute('data-root')) || '';
  var api = root + 'api/push.php';

  // https 이거나 localhost 여야 한다. 그 밖(http://사내이름/)에서는 조용히 아무것도 하지 않는다.
  var ok = 'serviceWorker' in navigator && window.isSecureContext;
  var reg = ok
    ? navigator.serviceWorker.register(root + 'sw.js').catch(function (e) {
        console.warn('[pwa] service worker 등록 실패', e);
        return null;
      })
    : Promise.resolve(null);

  function b64urlToU8(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    s += '='.repeat((4 - (s.length % 4)) % 4);
    var raw = atob(s), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  async function send(method, body) {
    var r = await fetch(api, {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
    });
    var data = null;
    try { data = await r.json(); } catch (_) {}
    if (!r.ok) throw new Error((data && data.error) || '요청에 실패했습니다 (HTTP ' + r.status + ')');
    return data;
  }

  async function ready() {
    var r = await reg;
    if (!r) throw new Error('이 브라우저에서는 알림을 받을 수 없습니다');
    return navigator.serviceWorker.ready;
  }

  window.bluePush = {
    root: root,
    supported: function () {
      return ok && 'PushManager' in window && 'Notification' in window;
    },
    permission: function () {
      return 'Notification' in window ? Notification.permission : 'denied';
    },
    status: async function () {
      if (!this.supported()) return null;
      var r = await ready();
      return r.pushManager.getSubscription();
    },
    subscribe: async function (publicKey) {
      if (!this.supported()) throw new Error('이 브라우저에서는 알림을 받을 수 없습니다');
      var perm = await Notification.requestPermission();
      if (perm !== 'granted') throw new Error('알림 권한이 허용되지 않았습니다');
      var r = await ready();
      var key = b64urlToU8(publicKey);
      var sub = await r.pushManager.getSubscription();
      // 서버 키가 바뀌었으면(vapid.key 재생성) 옛 구독으로는 못 받는다 — 끊고 새로 받는다
      if (sub && sub.options && sub.options.applicationServerKey) {
        var old = new Uint8Array(sub.options.applicationServerKey);
        if (old.length !== key.length || old.some(function (b, i) { return b !== key[i]; })) {
          await sub.unsubscribe();
          sub = null;
        }
      }
      if (!sub) sub = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      try {
        return await send('POST', { subscription: sub.toJSON() });
      } catch (e) {
        // 서버에 못 남겼으면 브라우저 구독도 되돌린다 — 받는다고 믿게 두면 안 된다
        try { await sub.unsubscribe(); } catch (_) {}
        throw e;
      }
    },
    endpointHash: async function () {
      var sub = await this.status().catch(function () { return null; });
      if (!sub || !window.crypto || !crypto.subtle) return null;
      var buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint));
      return Array.from(new Uint8Array(buf)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    },
    unsubscribe: async function () {
      var sub = await this.status();
      if (!sub) return null;
      var data = null;
      try { data = await send('DELETE', { endpoint: sub.endpoint }); } finally { await sub.unsubscribe(); }
      return data;
    },
  };

  /* =================================================================
   * 앱 설치 안내 — window.bluePwa
   *
   *   bluePwa.available()   지금 설치를 권할 수 있는가(이미 설치돼 있으면 false)
   *   bluePwa.install()     설치 창 열기. iOS 는 '홈 화면에 추가' 안내를 띄운다
   *   document 의 'bluepwa:change' 이벤트로 available() 이 바뀐 것을 알린다
   *
   * 브라우저는 설치 가능한 사이트에도 팝업을 띄우지 않는다(주소창 아이콘뿐).
   * Chrome·Edge·Android 는 beforeinstallprompt 를 붙잡아 두었다가 우리 버튼에서
   * prompt() 를 부른다. iOS 는 그런 이벤트가 없어 방법을 글로 안내한다 —
   * iOS 는 홈 화면에 추가해야만 웹 푸시를 받을 수 있어 특히 필요하다.
   *
   * 안내 띠는 pwa.js 를 싣는 모든 페이지(포털·모듈)에 같이 뜬다. "다음에" 를 누르면
   * 이 브라우저에서는 DISMISS_DAYS 동안 저절로 뜨지 않는다(메뉴에서 직접 부르는 건 된다).
   * ================================================================= */
  var DISMISS_KEY = 'bluePwa.dismissedAt';
  var DISMISS_DAYS = 14;
  var deferred = null;      // beforeinstallprompt 이벤트
  var banner = null;

  function standalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
      window.navigator.standalone === true;
  }
  function isIOS() {
    var ua = navigator.userAgent;
    // iPadOS 는 맥처럼 자기를 소개한다 — 터치가 되는 'Mac' 이면 iPad 로 본다
    return /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function dismissedRecently() {
    try {
      var t = +localStorage.getItem(DISMISS_KEY) || 0;
      return Date.now() - t < DISMISS_DAYS * 86400000;
    } catch (_) { return false; }
  }
  function rememberDismiss() {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (_) {}
  }
  function changed() {
    document.dispatchEvent(new CustomEvent('bluepwa:change', { detail: { available: bluePwa.available() } }));
  }

  function injectStyle() {
    if (document.getElementById('bpwa-style')) return;
    var st = document.createElement('style');
    st.id = 'bpwa-style';
    // 챗봇 단추(오른쪽 아래)·토스트(가운데 아래)와 겹치지 않게 왼쪽 아래에 둔다
    st.textContent =
      '.bpwa{position:fixed;left:24px;bottom:24px;z-index:2147483000;max-width:380px;box-sizing:border-box;' +
      'display:flex;gap:12px;align-items:flex-start;padding:14px 16px;background:#fff;color:#1d2433;' +
      'border:1px solid #e4e7ec;border-radius:14px;box-shadow:0 10px 30px rgba(16,24,40,.18);' +
      'font:13.5px/1.5 Pretendard,system-ui,-apple-system,"Malgun Gothic",sans-serif;' +
      'opacity:0;transform:translateY(12px);transition:opacity .2s,transform .2s}' +
      '.bpwa.show{opacity:1;transform:none}' +
      '.bpwa img{width:40px;height:40px;border-radius:10px;flex:none;border:1px solid #eef0f4}' +
      '.bpwa b{display:block;font-size:14px;margin-bottom:2px}' +
      '.bpwa p{margin:0;color:#475467}' +
      '.bpwa .bpwa-act{display:flex;gap:8px;margin-top:10px}' +
      '.bpwa button{font:inherit;font-size:13px;padding:6px 14px;border-radius:999px;cursor:pointer}' +
      '.bpwa .bpwa-yes{background:#1C5DE5;color:#fff;border:0}' +
      '.bpwa .bpwa-no{background:#fff;color:#475467;border:1px solid #d0d5dd}' +
      '.bpwa .bpwa-x{position:absolute;top:6px;right:8px;border:0;background:none;color:#98a2b3;font-size:18px;padding:2px 6px}' +
      '.bpwa .bpwa-share{display:inline-block;vertical-align:-3px;width:16px;height:16px}' +
      '@media (max-width:520px){.bpwa{left:12px;right:88px;bottom:16px;max-width:none}}';
    document.head.appendChild(st);
  }

  function hideBanner() {
    if (!banner) return;
    var b = banner;
    banner = null;
    b.classList.remove('show');
    setTimeout(function () { b.remove(); }, 250);
  }

  // iOS 공유 아이콘(네모 위로 화살표)
  var SHARE_SVG = '<svg class="bpwa-share" viewBox="0 0 24 24" fill="none" stroke="#1C5DE5" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M6 11v9h12v-9"/></svg>';

  function showBanner() {
    if (banner || !document.body) return;
    injectStyle();
    var ios = !deferred && isIOS();
    banner = document.createElement('div');
    banner.className = 'bpwa';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', '앱 설치 안내');
    banner.innerHTML =
      '<img src="' + root + 'styles/icon-192.png" alt="">' +
      '<div><b>blue-iWorks 앱 설치</b>' +
      (ios
        ? '<p>아래 ' + SHARE_SVG + ' 공유 단추를 누르고 <b style="display:inline">홈 화면에 추가</b>를 고르세요. ' +
          '홈 화면 아이콘으로 열어야 알림을 받을 수 있습니다.</p>' +
          '<div class="bpwa-act"><button type="button" class="bpwa-no">닫기</button></div>'
        : '<p>바탕화면·홈 화면에서 바로 열고, 새 소식을 알림으로 받을 수 있습니다.</p>' +
          '<div class="bpwa-act"><button type="button" class="bpwa-yes">설치</button>' +
          '<button type="button" class="bpwa-no">다음에</button></div>') +
      '</div><button type="button" class="bpwa-x" aria-label="닫기">×</button>';
    document.body.appendChild(banner);
    requestAnimationFrame(function () { if (banner) banner.classList.add('show'); });

    var yes = banner.querySelector('.bpwa-yes');
    if (yes) yes.addEventListener('click', function () { bluePwa.install(); });
    banner.querySelectorAll('.bpwa-no,.bpwa-x').forEach(function (b) {
      b.addEventListener('click', function () { rememberDismiss(); hideBanner(); });
    });
  }

  window.bluePwa = {
    installed: standalone,
    available: function () {
      return !standalone() && (!!deferred || isIOS());
    },
    install: async function () {
      if (deferred) {
        var ev = deferred;
        deferred = null;          // prompt() 는 이벤트 하나에 한 번만 된다
        hideBanner();
        ev.prompt();
        var choice = null;
        try { choice = await ev.userChoice; } catch (_) {}
        if (!choice || choice.outcome !== 'accepted') rememberDismiss();
        changed();
        return choice ? choice.outcome : 'dismissed';
      }
      if (isIOS() && !standalone()) {
        showBanner();             // 직접 누른 것이므로 '다음에' 기억과 상관없이 보인다
        return 'guide';
      }
      return 'unavailable';
    },
  };

  if (!standalone()) {
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();         // 브라우저 기본 띠 대신 우리 안내를 쓴다
      deferred = e;
      changed();
      if (!dismissedRecently()) showBanner();
    });
    window.addEventListener('appinstalled', function () {
      deferred = null;
      hideBanner();
      changed();
    });
    // iOS 는 이벤트가 없다. 페이지가 자리 잡은 뒤 한 번 안내한다
    if (isIOS() && !dismissedRecently()) {
      window.addEventListener('load', function () { setTimeout(showBanner, 1200); }, { once: true });
    }
  }
})();
