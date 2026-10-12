/*
 * blue-iWorks service worker — 웹 푸시 알림을 받아 띄우고, 누르면 그 주소를 연다.
 *
 * 포털 루트에 있어야 한다. service worker 는 자기 파일이 있는 폴더 아래만 맡는다.
 *
 * 오프라인 캐시(fetch 처리)는 일부러 두지 않는다. 포털 화면은 로그인 상태에 따라
 * 매번 다르게 그려져 no-store 로 내려가고(index.php 머리 주석), 옛 화면이 캐시에서
 * 나오면 "저장했는데 안 바뀐다" 가 된다. 설치(홈 화면 추가)에는 fetch 처리가 필요 없다.
 *
 * 서버(core/push.php)가 보내는 데이터: { title, body, url, tag, icon, kind, nid, ts }
 * nid 는 알림함(portal_notification) 번호 — 누르면 읽음으로 바꾼다.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try {
    d = e.data ? e.data.json() : {};
  } catch (_) {
    d = { body: e.data ? e.data.text() : '' };
  }
  const opt = {
    body: d.body || '',
    icon: d.icon || 'styles/icon-192.png',
    badge: 'styles/icon-192.png',
    data: { url: d.url || './', kind: d.kind || '', nid: d.nid || null },
  };
  // 같은 tag 는 기기에서 하나로 겹쳐진다. 겹쳐도 다시 울리게 한다.
  if (d.tag) { opt.tag = d.tag; opt.renotify = true; }
  e.waitUntil(self.registration.showNotification(d.title || 'blue-iWorks', opt));
});

// 알림을 누르면 data.url 로 간다. 상대경로는 포털 루트(이 worker 의 scope) 기준이다.
// 그 주소가 이미 열려 있으면 그 탭을 앞으로, 없으면 새로 연다.
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const nid = e.notification.data && e.notification.data.nid;
  let url;
  try {
    url = new URL((e.notification.data && e.notification.data.url) || './', self.registration.scope).href;
  } catch (_) {
    url = self.registration.scope;
  }
  // 알림함에서 읽음으로. 로그인 쿠키가 같이 가므로 본인 것만 바뀐다.
  // 기다리지 않고 나란히 보낸다 — 창 열기는 클릭 직후에만 허락되므로 네트워크를 기다리면 막힐 수 있다.
  const markRead = nid
    ? fetch(new URL('api/notifications.php', self.registration.scope).href, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ read: [nid] }),
      }).catch(() => {})
    : Promise.resolve();
  const open = (async () => {
    const list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of list) {
      if (c.url === url && 'focus' in c) return c.focus();
    }
    if (self.clients.openWindow) return self.clients.openWindow(url);
  })();
  e.waitUntil(Promise.all([open, markRead]));
});
