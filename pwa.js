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
    unsubscribe: async function () {
      var sub = await this.status();
      if (!sub) return null;
      var data = null;
      try { data = await send('DELETE', { endpoint: sub.endpoint }); } finally { await sub.unsubscribe(); }
      return data;
    },
  };
})();
