/* Coursemos EnvLog — 로그 뷰어 화면 스크립트. 모든 요청은 api.php?a=… (포털 로그인 필요).
   큰 파일도 바이트 구간(256KB)씩만 읽고, 시간 이동은 서버에서 이진탐색한다. */
(() => {
const $ = id => document.getElementById(id);
// 포털 상단바 높이만큼 빼고 나머지를 뷰어가 차지한다 (css 의 --topbar-h)
{ const tb = document.querySelector('.topbar'); if (tb) document.querySelector('.wrap').style.setProperty('--topbar-h', tb.offsetHeight + 'px'); }
const CH = 256 * 1024;        // 한 번에 읽는 바이트
const MAX_LINES = 8000;       // 화면 버퍼 최대 줄 수 (넘으면 반대쪽부터 버림)
const ROW_H = 24;
const api = async (url, opt) => {
  const r = await fetch(url, opt);
  const j = await r.json().catch(() => ({ error: 'HTTP ' + r.status }));
  if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
};
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const fmtSize = n => n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(1) + ' KB' : n < 1073741824 ? (n / 1048576).toFixed(1) + ' MB' : (n / 1073741824).toFixed(2) + ' GB';
const fmtTime = ts => { const d = new Date(ts * 1000); const p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; };
const status = (msg, err) => { const s = $('status'); s.textContent = msg; s.className = err ? 'err' : ''; };

// ---------------------------------------------------------------- 로그 줄 파싱
const MON = { Jan: '01', Feb: '02', Mar: '03', Apr: '04', May: '05', Jun: '06', Jul: '07', Aug: '08', Sep: '09', Oct: '10', Nov: '11', Dec: '12' };
const RE_ACCESS = /^(\S+) (\S+) (\S+) \[(\d\d)\/([A-Za-z]{3})\/(\d{4}):(\d\d):(\d\d):(\d\d)[^\]]*\] "([^"]*)" (\d{3}) (\S+)(?: "([^"]*)" "([^"]*)")?/;
const RE_ERROR = /^\[[A-Za-z]{3} ([A-Za-z]{3}) ([\d ]\d) (\d\d):(\d\d):(\d\d)(?:\.\d+)? (\d{4})\] (.*)$/;
// PHP(무들) 오류 로그: "[29-Oct-2026 17:22:34 Asia/Seoul] PHP Warning: …" — IP 는 없다. 뒤따르는 "* line …" 스택은 앞 줄 시간을 따른다
const RE_PHP = /^\[(\d\d)-([A-Za-z]{3})-(\d{4}) (\d\d):(\d\d):(\d\d)(?: [^\]]*)?\] (.*)$/;
function parseLine(text) {
  let m = RE_ACCESS.exec(text);
  if (m) {
    const key = m[6] + (MON[m[5]] || '00') + m[4] + m[7] + m[8] + m[9];
    return { key, time: `${m[6]}-${MON[m[5]]}-${m[4]} ${m[7]}:${m[8]}:${m[9]}`, ip: m[1], req: m[10], status: m[11], bytes: m[12], ref: m[13] || '', ua: m[14] || '', kind: 'access' };
  }
  m = RE_ERROR.exec(text);
  if (m) {
    const d = m[2].replace(' ', '0');
    const key = m[6] + (MON[m[1]] || '00') + d + m[3] + m[4] + m[5];
    let rest = m[7], level = '', ip = '', mod = '';
    let b;
    while ((b = /^\[([^\]]*)\]\s*/.exec(rest))) {
      const v = b[1];
      if (/^client /.test(v)) ip = v.slice(7).replace(/:\d+$/, '');
      else if (/^pid /.test(v)) { /* skip */ }
      else if (/^(?:[\w-]+:)?(emerg|alert|crit|error|warn|notice|info|debug|trace\d?)$/.test(v)) { level = v.replace(/^.*:/, ''); mod = v.includes(':') ? v.split(':')[0] : ''; }
      rest = rest.slice(b[0].length);
    }
    return { key, time: `${m[6]}-${MON[m[1]]}-${d} ${m[3]}:${m[4]}:${m[5]}`, ip, msg: rest, level, mod, kind: 'error' };
  }
  m = RE_PHP.exec(text);
  if (m) {
    const key = m[3] + (MON[m[2]] || '00') + m[1] + m[4] + m[5] + m[6];
    let msg = m[7], level = '';
    const lv = /^PHP (Fatal error|Warning|Notice|Deprecated|Parse error|Stack trace)\s*:?\s*/i.exec(msg);
    if (lv) { level = { 'fatal error': 'error', 'parse error': 'error', 'warning': 'warn', 'notice': 'notice', 'deprecated': 'notice', 'stack trace': 'info' }[lv[1].toLowerCase()] || ''; msg = msg.slice(lv[0].length); }
    const ipm = /(?:client|ip|from)[ :=]+(\d{1,3}(?:\.\d{1,3}){3})/i.exec(msg);
    return { key, time: `${m[3]}-${MON[m[2]]}-${m[1]} ${m[4]}:${m[5]}:${m[6]}`, ip: ipm ? ipm[1] : '', msg, level, mod: 'php', kind: 'error' };
  }
  return { key: '', time: '', ip: '', kind: 'raw' };
}
function mkLine(off, text, prevKey) {
  const p = parseLine(text); p.off = off; p.raw = text;
  if (!p.key) p.key = prevKey || '';      // 시간 없는 줄(스택트레이스 등)은 앞 줄 시간을 따른다
  return p;
}
function hl(s, term) {
  const e = esc(s);
  if (!term) return e;
  const t = esc(term);
  const i = e.toLowerCase().indexOf(t.toLowerCase());
  return i < 0 ? e : e.slice(0, i) + '<mark>' + e.slice(i, i + t.length) + '</mark>' + e.slice(i + t.length);
}
// User-Agent 해석 → { device, browser }. 줄에는 "디바이스 · 브라우저" 요약, 전체 문자열은 hover(title)·상세 창에서
//   디바이스: Windows 10/11 · Mac 10.15 · iPhone(iOS 17) · iPad · Android 13 · Linux · App(Dalbit 앱) · 봇 · 도구
function uaParse(ua) {
  const r = { device: '', browser: '' };
  if (!ua || ua === '-') return r;
  let m;
  // 앱(달빛 앱): 디바이스는 App 으로. 브라우저는 안에 든 웹뷰 종류가 있으면 같이
  const isApp = /Dalbit/i.test(ua) || /MoodleMobile|Coursemos/i.test(ua);
  if ((m = /(Googlebot|bingbot|Yeti|Daum|kakaotalk-scrap|facebookexternalhit|Twitterbot|Slackbot|AhrefsBot|SemrushBot|PetalBot|YandexBot|Applebot|DuckDuckBot)/i.exec(ua))) return { device: '봇', browser: m[1] };
  if (/bot|crawl|spider|scrap/i.test(ua)) return { device: '봇', browser: (ua.split(/[\s/(]/)[0] || '').slice(0, 20) };
  if ((m = /^(curl|Wget|python-requests|Python-urllib|okhttp|axios|Go-http-client|Java|Apache-HttpClient|PostmanRuntime|libwww-perl|PHP)\b[^\s]*/i.exec(ua))) return { device: '도구', browser: m[0].slice(0, 24) };
  // 디바이스 (운영체제 + 버전)
  if ((m = /Windows NT (\d+\.\d+)/.exec(ua))) r.device = 'Windows ' + ({ '10.0': '10/11', '6.3': '8.1', '6.2': '8', '6.1': '7', '6.0': 'Vista', '5.1': 'XP' }[m[1]] || m[1]);
  else if ((m = /iPhone OS (\d+)[_.](\d+)/.exec(ua))) r.device = `iPhone (iOS ${m[1]}.${m[2]})`;
  else if (/iPhone/.test(ua)) r.device = 'iPhone';
  else if ((m = /iPad.*OS (\d+)[_.](\d+)/.exec(ua))) r.device = `iPad (iPadOS ${m[1]}.${m[2]})`;
  else if (/iPad/.test(ua)) r.device = 'iPad';
  else if ((m = /Android (\d+(?:\.\d+)?)/.exec(ua))) r.device = 'Android ' + m[1];
  else if ((m = /Mac OS X (\d+)[_.](\d+)/.exec(ua))) r.device = `Mac ${m[1]}.${m[2]}`;
  else if (/Mac OS X|Macintosh/.test(ua)) r.device = 'Mac';
  else if (/CrOS/.test(ua)) r.device = 'ChromeOS';
  else if (/Linux/.test(ua)) r.device = 'Linux';
  if (isApp) r.device = 'App' + (r.device ? ` (${r.device})` : '');
  // 브라우저 (버전)
  if ((m = /Edg(?:e|A|iOS)?\/(\d+)/.exec(ua))) r.browser = 'Edge ' + m[1];
  else if ((m = /OPR\/(\d+)/.exec(ua))) r.browser = 'Opera ' + m[1];
  else if ((m = /Whale\/(\d+)/.exec(ua))) r.browser = 'Whale ' + m[1];
  else if ((m = /SamsungBrowser\/(\d+)/.exec(ua))) r.browser = 'Samsung ' + m[1];
  else if (/KAKAOTALK/i.test(ua)) r.browser = '카카오톡';
  else if (/NAVER\(inapp/i.test(ua)) r.browser = '네이버앱';
  else if ((m = /(?:CriOS|Chrome)\/(\d+)/.exec(ua))) r.browser = 'Chrome ' + m[1];
  else if ((m = /(?:FxiOS|Firefox)\/(\d+)/.exec(ua))) r.browser = 'Firefox ' + m[1];
  else if (/Safari\//.test(ua) && (m = /Version\/(\d+)/.exec(ua))) r.browser = 'Safari ' + m[1];
  else if (/Safari\//.test(ua)) r.browser = 'Safari';
  else if ((m = /MSIE (\d+)/.exec(ua)) || (m = /Trident\/.*rv:(\d+)/.exec(ua))) r.browser = 'IE ' + m[1];
  else if (isApp) r.browser = (/Dalbit[^\s;)]*/i.exec(ua) || ['앱'])[0];
  else r.browser = (ua.split(/[\s/(]/)[0] || '').slice(0, 20);
  if (!r.device && /Mobile/.test(ua)) r.device = 'Mobile';
  return r;
}
function uaSummary(ua) { const u = uaParse(ua); return [u.device, u.browser].filter(Boolean).join(' · '); }

function rowHtml(l, idx, sid, hitOff, simple) {
  const term = (S.searchType === 'ip' || S.searchType === 'text') ? S.searchTerm : '';
  const srv = sid != null ? `<span class="srv s${sid % 9}" title="${esc(srvName(sid))}">S${sid}</span>` : '';
  const cls = 'lrow' + (hitOff != null && l.off === hitOff ? ' hit' : '');
  if (simple) {   // access_log 가 아닌 파일: 시간 + 메시지 두 칸만 (IP·User-Agent 칸 없음). 섞여 있는 access 줄은 메시지 안에 IP 를 붙인다
    if (l.kind === 'access') return `<div class="${cls}" data-i="${idx}">${srv}<span class="t">${l.time}</span><span><span class="ip">${hl(l.ip, term)}</span> <b class="st${l.status[0]}">${l.status}</b> ${hl(l.req, term)}</span></div>`;
    if (l.kind === 'error') return `<div class="${cls}" data-i="${idx}">${srv}<span class="t">${l.time}</span><span><b class="lv-${l.level}">${esc(l.level || l.mod)}</b> ${l.ip ? `<span class="ip">${hl(l.ip, term)}</span> ` : ''}${hl(l.msg, term)}</span></div>`;
    return `<div class="${cls}" data-i="${idx}">${srv}<span class="t"></span><span class="raw">${hl(l.raw, term)}</span></div>`;
  }
  if (l.kind === 'access') {
    const st = `<b class="st${l.status[0]}">${l.status}</b>`;
    return `<div class="${cls}" data-i="${idx}">${srv}<span class="t">${l.time}</span><span class="ip">${hl(l.ip, term)}</span><span>${st} ${hl(l.req, term)} <span class="hint">${l.bytes}</span></span><span class="ua" title="${esc(l.ua)}">${esc(uaSummary(l.ua))}</span></div>`;
  }
  if (l.kind === 'error') {
    return `<div class="${cls}" data-i="${idx}">${srv}<span class="t">${l.time}</span><span class="ip">${hl(l.ip, term)}</span><span><b class="lv-${l.level}">${esc(l.level || l.mod)}</b> ${hl(l.msg, term)}</span><span class="ua">${esc(l.mod)}</span></div>`;
  }
  return `<div class="${cls}" data-i="${idx}">${srv}<span class="t"></span><span class="ip"></span><span class="raw" style="grid-column: span 2">${hl(l.raw, term)}</span></div>`;
}

// ---------------------------------------------------------------- 상태
const S = { servers: [], selected: new Set(), panes: [], file: '', merge: false, sync: true, active: null, school: null, searchTerm: '', searchType: 'ip', logDir: '/usr/local/apache2/logs',
            hits: null,
            syncSrc: null, syncUntil: 0 };   // 동기화 기준 패널과 잠금 시한 — 따라가는 패널이 기준을 되돌리지 않게   // 마지막 검색 결과(줄 목록) — "검색된 줄만 보기" 가 켜져 있으면 패널에 이것만 그린다
const srvName = sid => { const s = S.servers.find(x => x.id === sid); return s ? `${s.name} ${s.host}` : 'S' + sid; };
const filePath = () => S.logDir.replace(/\/+$/, '') + '/' + S.file;

// ---------------------------------------------------------------- 대학 자동완성
let acItems = [], acCur = -1, acTimer = null, acSel = null;
// 검색칸 오른쪽의 무들 버전 뱃지 — 대학을 고르면 보이고, 다시 타이핑하면 사라진다
const setQVer = ver => { const b = $('qVer'); b.textContent = ver || ''; b.hidden = !ver; };
$('q').addEventListener('input', () => { acSel = null; setQVer(''); clearTimeout(acTimer); acTimer = setTimeout(acFetch, 150); });
$('q').addEventListener('focus', () => { if (acItems.length) $('dd').classList.add('on'); });
$('q').addEventListener('keydown', e => {
  if (!$('dd').classList.contains('on')) { if (e.key === 'Enter') doConnect(); return; }
  if (e.key === 'ArrowDown') { acCur = Math.min(acCur + 1, acItems.length - 1); acRender(); e.preventDefault(); }
  else if (e.key === 'ArrowUp') { acCur = Math.max(acCur - 1, 0); acRender(); e.preventDefault(); }
  else if (e.key === 'Enter') { if (acCur >= 0) acPick(acItems[acCur]); else if (acItems.length) acPick(acItems[0]); e.preventDefault(); }
  else if (e.key === 'Escape') $('dd').classList.remove('on');
});
document.addEventListener('click', e => { if (!e.target.closest('.ac')) $('dd').classList.remove('on'); });
async function acFetch() {
  const q = $('q').value.trim();
  try {
    const j = await api('api.php?a=schools&q=' + encodeURIComponent(q));
    if (j.error && !j.items.length) { $('dd').innerHTML = `<div class="item" style="color:var(--err)">${esc(j.error)}</div>`; $('dd').classList.add('on'); return; }
    acItems = j.items; acCur = -1; acRender(); $('dd').classList.toggle('on', acItems.length > 0);
  } catch (e) { status('대학 목록 조회 실패: ' + e.message, true); }
}
function acRender() {
  $('dd').innerHTML = acItems.map((s, i) => `<div class="item${i === acCur ? ' cur' : ''}${s.ok ? '' : ' off'}" data-i="${i}" title="${esc(s.reason || '')}"><span class="nm">${esc(s.name)}</span>${s.ver ? `<span class="bdg ver">${esc(s.ver)}</span>` : ''}${s.vpn ? `<span class="bdg vpn">VPN · ${esc(s.vpn)}</span>` : ''}${!s.ok && !s.vpn ? '<span class="bdg no">접속정보 없음</span>' : ''}<span class="site">${esc(s.site)}</span><span class="site">${esc(s.host)}${s.host && s.port !== 22 ? ':' + s.port : ''}</span></div>`).join('');
  $('dd').querySelectorAll('.item').forEach(el => el.onclick = () => acPick(acItems[+el.dataset.i]));
  const cur = $('dd').querySelector('.cur'); if (cur) cur.scrollIntoView({ block: 'nearest' });
}
function acPick(s) {
  $('dd').classList.remove('on');
  if (!s.ok) { acSel = null; status(`${s.name}: ${s.reason || '접속할 수 없는 대학입니다'}`, true); return; }   // VPN · 접속정보 없음
  acSel = s; $('q').value = s.name; setQVer(s.ver); status('');
}
$('chkManual').onchange = () => $('manual').classList.toggle('on', $('chkManual').checked);

// ---------------------------------------------------------------- 접속
$('btnConnect').onclick = doConnect;
async function doConnect() {
  let body;
  if ($('chkManual').checked) body = { host: $('mHost').value.trim(), user: $('mUser').value.trim(), password: $('mPw').value, name: $('q').value.trim() || $('mHost').value.trim() };
  else {
    if (!acSel) { const q = $('q').value.trim(); if (!q) { status('대학을 선택하세요', true); return; } await acFetch(); if (acItems.length === 1) acSel = acItems[0]; else { status('목록에서 대학을 선택하세요', true); return; } }
    body = { school_id: acSel.id };
  }
  $('btnConnect').disabled = true; status('접속 중... (테스트서버 → checkWeb.sh → 운영서버)');
  try {
    const j = await api('api.php?a=connect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    applySession(j.school, j.servers);
    status(`접속 완료: ${j.school.name} — 운영서버 ${j.servers.length - 1}대 (WebRoot ${j.webroot || '미판별'}${j.school.sites && j.school.sites.length ? ', 사이트 ' + j.school.sites.join(', ') : ''})`);
    loadFiles();
  } catch (e) { status(e.message, true); }
  $('btnConnect').disabled = false;
}
$('btnDisconnect').onclick = async () => { await api('api.php?a=disconnect', { method: 'POST' }); applySession(null, []); $('files').innerHTML = ''; status('연결 끊김'); };
function applySession(school, servers) {
  S.school = school; S.servers = servers; S.file = ''; S.selected = new Set(); logdirCache = {}; allowedDirs = [];
  $('btnDisconnect').disabled = !school;
  // 접속 중인 대학: 이름 + 무들 버전 뱃지 + 테스트서버 주소 (버전은 자동완성과 같은 모양의 뱃지)
  $('connInfo').innerHTML = school ? `<b>${esc(school.name)}</b>${school.ver ? ` <span class="bdg ver">${esc(school.ver)}</span>` : ''} <span class="hint">${esc(school.host)}${school.port && school.port !== 22 ? ':' + school.port : ''}</span>` : '';
  const ops = servers.filter(s => s.kind === 'ops');
  (ops.length ? ops : servers).forEach(s => S.selected.add(s.id));
  renderServers(); rebuildPanes();
}
function renderServers() {
  if (!S.servers.length) { $('servers').innerHTML = '<div class="hint">접속하면 테스트서버 checkWeb.sh 에서 운영서버 목록을 읽습니다.</div>'; return; }
  $('servers').innerHTML = S.servers.map(s => `<label class="srvline chk"><input type="checkbox" data-id="${s.id}" ${S.selected.has(s.id) ? 'checked' : ''}><span class="s${s.id % 9}">S${s.id}</span> ${esc(s.name)}<span class="meta">${esc(s.host)}${s.port !== 22 ? ':' + s.port : ''} ${s.path === 'fallback' ? '<span class="fb" title="' + esc(s.status) + '">폴백</span>' : ''}</span></label>`).join('');
  $('servers').querySelectorAll('input').forEach(cb => cb.onchange = () => { cb.checked ? S.selected.add(+cb.dataset.id) : S.selected.delete(+cb.dataset.id); rebuildPanes(); });
}
$('chkSync').onchange = () => S.sync = $('chkSync').checked;
$('chkMerge').onchange = () => { S.merge = $('chkMerge').checked; rebuildPanes(); };

// ---------------------------------------------------------------- 파일 목록
$('btnFiles').onclick = loadFiles;
$('logDir').readOnly = true;   // 경로 직접 입력은 막는다 — 로그 폴더 밖은 서버(데몬)도 거부한다. 칩·폴더 클릭으로만 이동
async function loadFiles() {
  if (!S.servers.length) return;
  S.logDir = ($('logDir').value.trim() || S.logDir).replace(/\/+$/, '') || '/'; $('logDir').value = S.logDir;
  const sids = [...S.selected]; if (!sids.length) sids.push(S.servers[0].id);
  $('files').innerHTML = '<div class="hint" style="padding:6px">불러오는 중...</div>';
  const res = await Promise.all(sids.map(sid => api(`api.php?a=files&server=${sid}&path=${encodeURIComponent(S.logDir)}`).then(j => ({ sid, files: j.files })).catch(e => ({ sid, error: e.message }))));
  const map = new Map();   // name → {name,type,size,mtime,has:[sid]}
  for (const r of res) for (const f of (r.files || [])) { const m = map.get(f.name) || { ...f, has: [] }; m.has.push(r.sid); if (f.mtime > m.mtime) { m.mtime = f.mtime; m.size = f.size; } map.set(f.name, m); }
  // 폴더 먼저, 그 다음 파일 — 둘 다 이름 오름차순 (날짜가 이름에 들어가는 로그가 많아 이름순이 곧 날짜순)
  const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true });
  S.fileList = [...map.values()].sort((a, b) => (a.type !== b.type) ? (a.type === 'd' ? -1 : 1) : byName(a, b));
  S.fileSids = sids; S.fileErrs = res.filter(r => r.error);
  renderFiles();
  if (window.ENVLOG_AI && $('aibox').classList.contains('open')) aiFillAccess();   // AI 상자의 access_log/php_errors 후보도 새 목록으로
  status(`${S.logDir}: 파일 ${S.fileList.filter(f => f.type !== 'd').length}개 (서버 ${sids.length}대 합침)` + (S.fileErrs.length ? ` — 실패 ${S.fileErrs.length}대` : ''), S.fileErrs.length > 0);
  loadLogDirs(sids);
}
// 파일명 검색: 입력한 글자가 들어간 파일만 (대소문자 무시). 폴더는 항상 보인다
$('fileFilter').addEventListener('input', () => { renderFiles(); $('files').scrollTop = 0; });   // 검색어를 바꾸면 목록을 맨 위부터 보여 준다
$('fileFilterClear').onclick = () => { $('fileFilter').value = ''; renderFiles(); $('files').scrollTop = 0; $('fileFilter').focus(); };
function renderFiles() {
  const q = $('fileFilter').value.trim().toLowerCase(), sids = S.fileSids || [], all = S.fileList || [];
  const files = q ? all.filter(f => f.type === 'd' || f.name.toLowerCase().includes(q)) : all;
  const parent = S.logDir.replace(/\/[^/]*$/, '') || '/';
  const up = S.logDir !== '/' && insideAllowed(parent) ? `<div class="item dir" data-d=".."><span class="n">📁 ..</span></div>` : '';
  $('files').innerHTML = up + files.map(f => f.type === 'd'
    ? `<div class="item dir" data-d="${esc(f.name)}"><span class="n" title="${esc(f.name)}">📁 ${esc(f.name)}/</span><span class="has">${f.has.map(s => `<b class="s${s % 9}">S${s}</b>`).join('')}</span></div>`
    : `<div class="item file${f.name === S.file ? ' sel' : ''}${isCompressed(f.name) ? ' off' : ''}" data-n="${esc(f.name)}" title="${isCompressed(f.name) ? '압축 파일은 열 수 없습니다 (텍스트 로그만)' : esc(f.name)}"><div class="n">${hl(f.name, q)}</div><div class="meta"><span>${fmtSize(f.size)}</span><span>${fmtTime(f.mtime)}</span><span class="has">${f.has.length === sids.length ? '' : f.has.map(s => `<b class="s${s % 9}">S${s}</b>`).join('')}</span></div></div>`).join('')
    + (all.length ? (q && !files.some(f => f.type !== 'd') ? `<div class="hint" style="padding:6px">"${esc(q)}" 에 맞는 파일 없음</div>` : '') : '<div class="hint" style="padding:6px">파일 없음 — 아래 "설정에서 찾은 로그 위치"를 눌러 보세요</div>')
    + S.fileErrs.map(r => `<div class="hint" style="padding:4px 6px;color:var(--err)">${esc(r.error)}</div>`).join('');
  $('files').querySelectorAll('.item.dir').forEach(el => el.onclick = () => { const d = el.dataset.d; $('logDir').value = d === '..' ? (S.logDir.replace(/\/[^/]*$/, '') || '/') : S.logDir.replace(/\/$/, '') + '/' + d; loadFiles(); });
  $('files').querySelectorAll('.item:not(.dir)').forEach(el => el.onclick = () => { if (isCompressed(el.dataset.n)) { status('압축 파일은 열 수 없습니다 — 텍스트 로그만 볼 수 있습니다', true); return; } S.file = el.dataset.n; $('files').querySelectorAll('.item').forEach(x => x.classList.toggle('sel', x === el)); rebuildPanes(); });
}
// 압축 파일은 화면에서 미리 막는다 (서버 쪽 envlog_remote.sh 도 바이너리·압축을 거부한다)
const isCompressed = n => /\.(gz|bz2|xz|zst|lz4|zip|tar|tgz|tbz|7z|rar|Z)$/i.test(n);
let logdirCache = {};
// 허용 로그 폴더(데몬이 알려 줌) — 그 안에서만 폴더를 오르내릴 수 있다
let allowedDirs = [];
const insideAllowed = p => !allowedDirs.length || allowedDirs.some(d => p === d || p.startsWith(d.replace(/\/$/, '') + '/'));
async function loadLogDirs(sids) {
  const out = [];
  await Promise.all(sids.map(async sid => {
    try { if (!logdirCache[sid]) { const j = await api(`api.php?a=logdirs&server=${sid}`); logdirCache[sid] = j.items; for (const d of (j.allowed || [])) if (!allowedDirs.includes(d)) allowedDirs.push(d); } } catch (e) { logdirCache[sid] = []; }
    for (const it of logdirCache[sid]) if (it.dir) out.push({ sid, ...it });
  }));
  const dirs = new Map();
  for (const it of out) { const m = dirs.get(it.dir) || { dir: it.dir, has: new Set(), paths: new Set() }; m.has.add(it.sid); m.paths.add(it.path.replace(it.dir + '/', '')); dirs.set(it.dir, m); }
  $('logdirs').innerHTML = dirs.size ? '설정에서 찾은 로그 위치: ' + [...dirs.values()].map(m => `<span class="chip" data-d="${esc(m.dir)}" title="${esc([...m.paths].join('\n'))}">${esc(m.dir)} <b>${[...m.has].map(s => 'S' + s).join(' ')}</b></span>`).join('') : '';
  $('logdirs').querySelectorAll('.chip').forEach(el => el.onclick = () => { $('logDir').value = el.dataset.d; loadFiles(); });
  renderFiles();   // 허용 폴더를 알게 됐으니 ".." 표시 여부를 다시 계산
}

// ---------------------------------------------------------------- 패널 (서버별 / 병합)
function rebuildPanes() {
  S.panes = []; S.active = null; S.hits = null;
  const P = $('panes'); P.innerHTML = '';
  const sids = S.servers.filter(s => S.selected.has(s.id)).map(s => s.id);
  if (!sids.length || !S.file) { P.style.gridTemplateColumns = '1fr'; P.innerHTML = `<div class="empty">${!S.servers.length ? '대학을 검색해 접속하세요.' : !sids.length ? '왼쪽에서 서버를 선택하세요.' : '왼쪽에서 로그 파일을 선택하세요.'}</div>`; return; }
  if (S.merge) { P.style.gridTemplateColumns = '1fr'; S.panes.push(createPane(null, sids)); }
  else { P.style.gridTemplateColumns = `repeat(${sids.length}, minmax(0, 1fr))`; sids.forEach(sid => S.panes.push(createPane(sid, [sid]))); }
  S.active = S.panes[0];
  S.panes.forEach(p => openPane(p));
}
function createPane(sid, sids) {
  const el = document.createElement('div'); el.className = 'pane';
  const cols = (sid == null ? '38px ' : '') + '140px 118px minmax(0,1fr) 170px';
  el.style.setProperty('--cols', cols);
  const title = sid == null ? `병합 (${sids.map(s => 'S' + s).join(' + ')})` : `<span class="s${sid % 9}">S${sid}</span> ${esc(srvName(sid))}`;
  el.innerHTML = `<div class="ph"><span class="nm">${title}</span><span class="hint">${esc(S.file)}</span><span class="busy">● 읽는 중</span><span class="pos"></span></div>
    <div class="cols">${sid == null ? '<span>서버</span>' : ''}<span>시간</span><span>IP</span><span>메시지</span><span>User-Agent</span></div>
    <div class="body"><div class="rows"></div></div>
    <div class="pf"><button data-a="first" title="파일 처음">⏮</button><input type="range" min="0" max="1000" value="1000"><button data-a="last" title="파일 끝">⏭</button><button data-a="reload" title="다시 읽기(파일이 커졌으면 끝으로)">↻</button></div>`;
  $('panes').appendChild(el);
  const pane = { sid, sids, el, body: el.querySelector('.body'), rows: el.querySelector('.rows'), pos: el.querySelector('.pos'), range: el.querySelector('input[type=range]'),
    lines: [], src: {}, loading: 0, ignoreUntil: 0, hitOff: null, hitSid: null, timer: null, firstKey: '', lastKey: '', gen: 0 };
  sids.forEach(s => pane.src[s] = { lines: [], start: 0, end: 0, size: 0, bof: true, eof: true, info: null });
  pane.body.addEventListener('scroll', () => onScroll(pane));
  pane.body.addEventListener('click', e => { const r = e.target.closest('.lrow'); if (r) { S.active = pane; S.panes.forEach(p => p.el.classList.toggle('active', p === pane)); showDetail(pane, +r.dataset.i, r); } });
  pane.body.addEventListener('dblclick', e => { const r = e.target.closest('.lrow'); if (r && pane.hitsMode) { const l = pane.lines[+r.dataset.i]; $('chkOnlyHits').checked = false; leaveHitsView(); jumpTo(l, null); } });
  el.querySelector('[data-a=first]').onclick = () => gotoOffset(pane, 0);
  el.querySelector('[data-a=last]').onclick = () => gotoEnd(pane);
  el.querySelector('[data-a=reload]').onclick = () => openPane(pane);
  pane.range.onchange = () => { const s = mainSrc(pane); gotoOffset(pane, Math.floor(s.size * pane.range.value / 1000)); };
  return pane;
}
const mainSrc = pane => pane.src[pane.sid ?? pane.sids[0]];
// access_log 줄이 하나도 없으면(오류 로그·PHP 로그 등) IP·User-Agent 칸을 없앤다. 읽을 때마다 다시 판단
function applyLayout(pane) {
  const simple = !pane.lines.some(l => l.kind === 'access');
  if (pane.simple === simple) return simple;
  pane.simple = simple;
  const srvCol = pane.sid == null ? '38px ' : '';
  pane.el.style.setProperty('--cols', srvCol + (simple ? '140px minmax(0,1fr)' : '140px 118px minmax(0,1fr) 220px'));
  pane.el.querySelector('.cols').innerHTML = (pane.sid == null ? '<span>서버</span>' : '') + (simple ? '<span>시간</span><span>메시지</span>' : '<span>시간</span><span>IP</span><span>메시지</span><span>User-Agent</span>');
  return simple;
}
function setLoading(pane, on) { pane.loading += on ? 1 : -1; pane.el.classList.toggle('loading', pane.loading > 0); }

async function openPane(pane) {
  setLoading(pane, true);
  try {
    await Promise.all(pane.sids.map(async sid => {
      const s = pane.src[sid];
      s.info = await api(`api.php?a=info&server=${sid}&path=${encodeURIComponent(filePath())}`);
      s.size = s.info.size;
    }));
    await gotoEnd(pane);
  } catch (e) { pane.rows.innerHTML = `<div class="empty">${esc(e.message)}${/없습니다/.test(e.message) ? '<br><span class="hint">이 서버에는 같은 경로에 파일이 없습니다. 왼쪽 목록의 S번호로 어느 서버에 있는지 확인하거나, "설정에서 찾은 로그 위치"로 이동해 보세요.</span>' : ''}</div>`; status(e.message, true); }
  setLoading(pane, false);
}
async function fetchChunk(sid, start, end) {
  const j = await api(`api.php?a=chunk&server=${sid}&path=${encodeURIComponent(filePath())}&start=${Math.max(0, start)}&end=${end}`);
  let prev = '';
  const lines = j.lines.map(([off, text]) => { const l = mkLine(off, text, prev); prev = l.key; return l; });
  return { lines, start: j.start, end: j.end, size: j.size };
}
// 소스 버퍼를 [off-CH, off+CH] 구간으로 교체
async function loadSrcAt(sid, s, off) {
  const c = await fetchChunk(sid, off - CH, off + CH);
  s.lines = c.lines; s.start = c.start; s.end = c.end; s.size = c.size; s.bof = c.start <= 0 || off - CH <= 0; s.eof = c.end >= c.size;
  backfillKeys(s.lines);
}
async function extendSrc(sid, s, dir) {
  if (dir > 0) { if (s.eof) return false; const c = await fetchChunk(sid, s.end, s.end + CH); s.lines = s.lines.concat(fixKeys(c.lines, s.lines.length ? s.lines[s.lines.length - 1].key : '')); s.end = c.end; s.size = c.size; s.eof = c.end >= c.size; return c.lines.length > 0; }
  if (s.bof) return false; const c = await fetchChunk(sid, s.start - CH, s.start); s.lines = backfillKeys(c.lines).concat(s.lines); s.start = c.start; s.bof = c.start <= 0 || s.start - CH <= 0; return c.lines.length > 0;
}
function fixKeys(lines, prevKey) { for (const l of lines) { if (!l.key) l.key = prevKey; else prevKey = l.key; } return lines; }
// 구간 맨 앞의 키 없는 줄들(앞 구간 스택트레이스의 꼬리)은 그 뒤 첫 시간으로 채운다
function backfillKeys(lines) { const f = lines.find(l => l.key); if (!f) return lines; for (const l of lines) { if (l.key) break; l.key = f.key; } return lines; }

// 보이는 줄 목록 재계산 (단일: 소스 그대로 / 병합: 시간순 k-way 병합, 모든 소스가 가진 시간 범위만)
function compose(pane) {
  if (pane.sid != null) { pane.lines = pane.src[pane.sid].lines.map(l => (l.sid = pane.sid, l)); }
  else {
    let lo = '', hi = '99999999999999';
    for (const sid of pane.sids) {
      const s = pane.src[sid]; if (!s.lines.length) { if (!s.bof || !s.eof) { lo = hi; } continue; }
      if (!s.bof) lo = s.lines[0].key > lo ? s.lines[0].key : lo;
      if (!s.eof) { const k = s.lines[s.lines.length - 1].key; hi = k < hi ? k : hi; }
    }
    const all = [];
    for (const sid of pane.sids) for (const l of pane.src[sid].lines) if (l.key >= lo && l.key <= hi) { l.sid = sid; all.push(l); }
    all.sort((a, b) => a.key < b.key ? -1 : a.key > b.key ? 1 : a.sid - b.sid || a.off - b.off);
    pane.lines = all;
  }
  pane.firstKey = pane.lines.length ? pane.lines[0].key : ''; pane.lastKey = pane.lines.length ? pane.lines[pane.lines.length - 1].key : '';
}
function render(pane, keepTopKey) {
  if (pane.hitsMode) { renderHitsView(pane); return; }
  const L = pane.lines;
  const simple = applyLayout(pane);
  pane.rows.innerHTML = L.length ? L.map((l, i) => rowHtml(l, i, pane.sid == null ? l.sid : null, pane.hitSid === l.sid || pane.sid != null ? pane.hitOff : null, simple)).join('') : '<div class="empty">표시할 줄이 없습니다</div>';
  updatePos(pane);
}
function updatePos(pane) {
  if (pane.hitsMode) { pane.pos.textContent = `검색 결과 ${pane.lines.length}건 — 더블클릭하면 원문 위치로`; return; }
  const i = Math.floor(pane.body.scrollTop / ROW_H); const l = pane.lines[i];
  const s = mainSrc(pane);
  pane.pos.textContent = l ? `${fmtKey(l.key)} · ${fmtSize(l.off)} / ${fmtSize(s.size)}` : '';
  if (l && s.size) { pane.ignore_range = true; pane.range.value = Math.round(l.off / s.size * 1000); }
}
const fmtKey = k => k ? `${k.slice(0, 4)}-${k.slice(4, 6)}-${k.slice(6, 8)} ${k.slice(8, 10)}:${k.slice(10, 12)}:${k.slice(12, 14)}` : '';
function scrollToIndex(pane, i, pos) {
  const top = Math.max(0, pos === 'center' ? i * ROW_H - pane.body.clientHeight / 2 : i * ROW_H);
  if (Math.abs(pane.body.scrollTop - top) > 0.5) {
    // 코드가 움직인 스크롤 표시: 시간(150ms) + 목표 위치. 패널이 많아 그리기가 늦으면 이벤트가 150ms 를 넘겨 오므로
    // "지금 스크롤 위치가 코드가 지정한 곳" 이면 시간과 무관하게 코드 스크롤로 본다
    pane.ignoreUntil = performance.now() + 150; pane.expectTop = top;
    pane.body.scrollTop = top;
  }
  updatePos(pane);
}
// 코드가 움직인 스크롤인가 (사용자 스크롤이 아니면 동기화를 일으키지 않는다)
function isProgrammatic(pane) {
  const st = pane.body.scrollTop;
  if (pane.expectTop != null && Math.abs(st - pane.expectTop) <= 1) { pane.expectTop = null; return true; }
  if (performance.now() < (pane.ignoreUntil || 0)) return true;
  // 동기화가 진행 중이면 따라가는 패널은 기준 패널이 될 수 없다 — 2~6번이 1번을 되돌리는 되돌이 방지
  if (S.syncSrc && S.syncSrc !== pane && performance.now() < S.syncUntil) return true;
  return false;
}
function topKey(pane) { const i = Math.floor(pane.body.scrollTop / ROW_H); for (let j = i; j < pane.lines.length; j++) if (pane.lines[j].key) return pane.lines[j].key; return ''; }
function indexOfKey(pane, key, sid, off) {
  if (sid != null && off != null) { const j = pane.lines.findIndex(l => l.sid === sid && l.off === off); if (j >= 0) return j; }
  return pane.lines.findIndex(l => l.key >= key);
}

async function gotoOffset(pane, off) {
  const g = ++pane.gen;
  setLoading(pane, true);
  try {
    if (pane.sid != null) { await loadSrcAt(pane.sid, pane.src[pane.sid], off); if (g !== pane.gen) throw 0; compose(pane); render(pane); const i = pane.lines.findIndex(l => l.off >= off); scrollToIndex(pane, Math.max(0, i)); }
    else { // 병합: 기준 소스의 그 위치 시간으로 모두 이동
      const s0 = mainSrc(pane); await loadSrcAt(pane.sids[0], s0, off); if (g !== pane.gen) throw 0; const l = s0.lines.find(l => l.off >= off); await gotoKey(pane, l ? l.key : (off === 0 ? '00000000000000' : '99999999999999'), true);
    }
  } catch (e) { if (e !== 0) status(e.message, true); }
  setLoading(pane, false);
  if (S.sync) syncOthers(pane);
}
async function gotoEnd(pane) {
  const g = ++pane.gen;
  setLoading(pane, true);
  try {
    await Promise.all(pane.sids.map(sid => loadSrcAt(sid, pane.src[sid], pane.src[sid].size)));
    if (g !== pane.gen) throw 0;
    compose(pane); render(pane); scrollToIndex(pane, pane.lines.length);
  } catch (e) { if (e !== 0) status(e.message, true); }
  setLoading(pane, false);
  if (S.sync) syncOthers(pane);
}
// 시간키로 이동 (버퍼에 있으면 스크롤만, 없으면 서버 이진탐색 → 재적재). hit: 강조할 {sid, off}
async function gotoKey(pane, key, force, hit) {
  if (!key) return;
  if (hit) { pane.hitSid = hit.sid; pane.hitOff = hit.off; }
  let i = force ? -1 : indexOfKey(pane, key, hit && hit.sid, hit && hit.off);
  const inRange = i >= 0 && !(i === 0 && !allBof(pane)) && !(i >= pane.lines.length - 1 && !allEof(pane));
  if (inRange && !force) { pane.gen++; if (hit) render(pane); scrollToIndex(pane, i, 'center'); return; }
  const g = ++pane.gen;   // 더 새로운 이동/스크롤이 생기면 이 결과는 버린다
  setLoading(pane, true);
  try {
    const loaded = await Promise.all(pane.sids.map(async sid => {
      const s = { ...pane.src[sid] };
      const off = (hit && hit.sid === sid) ? hit.off : (await api(`api.php?a=seek&server=${sid}&path=${encodeURIComponent(filePath())}&key=${key}`)).offset;
      await loadSrcAt(sid, s, off);
      return [sid, s];
    }));
    if (g !== pane.gen) throw 0;
    for (const [sid, s] of loaded) pane.src[sid] = s;
    compose(pane); render(pane);
    i = indexOfKey(pane, key, hit && hit.sid, hit && hit.off);
    scrollToIndex(pane, i < 0 ? pane.lines.length : i, 'center');
  } catch (e) { if (e !== 0) status(e.message, true); }
  setLoading(pane, false);
}
const allBof = pane => pane.sids.every(s => pane.src[s].bof);
const allEof = pane => pane.sids.every(s => pane.src[s].eof);

// 스크롤: 가장자리에서 앞/뒤 구간 추가 적재 + 동일 시간 동기화
function onScroll(pane) {
  if (pane.hitsMode) {                                                              // 검색 결과만 보는 중 — 더 읽을 건 없지만 시간 맞추기는 한다
    if (isProgrammatic(pane)) return;
    if (S.sync && S.panes.length > 1) { clearTimeout(pane.timer); pane.timer = setTimeout(() => syncHits(pane), 150); }
    return;
  }
  if (isProgrammatic(pane)) { updatePos(pane); return; }                           // 코드가 움직인 스크롤 → 동기화 트리거 아님
  pane.gen++;           // 사용자가 스크롤 중이면 진행 중이던 자동 이동은 취소
  updatePos(pane);
  const b = pane.body;
  if (b.scrollTop < ROW_H * 10) extend(pane, -1);
  else if (b.scrollTop + b.clientHeight > b.scrollHeight - ROW_H * 10) extend(pane, 1);
  if (S.sync && S.panes.length > 1) { clearTimeout(pane.timer); pane.timer = setTimeout(() => syncOthers(pane), 250); }
}
async function extend(pane, dir) {
  if (pane.loading || pane.extending) return;
  pane.extending = true; setLoading(pane, true);
  try {
    let targets;
    if (pane.sid != null) targets = [pane.sid];
    else { // 병합: 경계를 만드는 소스만 더 읽는다
      targets = pane.sids.filter(sid => { const s = pane.src[sid]; if (!s.lines.length) return dir > 0 ? !s.eof : !s.bof; return dir > 0 ? (!s.eof && s.lines[s.lines.length - 1].key <= pane.lastKey) : (!s.bof && s.lines[0].key >= pane.firstKey); });
    }
    const g = pane.gen;
    const got = await Promise.all(targets.map(sid => extendSrc(sid, pane.src[sid], dir)));
    if (g === pane.gen && (got.some(Boolean) || pane.sid == null)) {
      const topI = Math.floor(pane.body.scrollTop / ROW_H); const anchor = pane.lines[topI];
      // 버퍼 상한: 반대쪽에서 버린다
      for (const sid of pane.sids) { const s = pane.src[sid]; if (s.lines.length > MAX_LINES) { const n = s.lines.length - MAX_LINES; if (dir > 0) { s.lines.splice(0, n); s.start = s.lines[0].off; s.bof = false; } else { s.lines.splice(-n); s.end = s.lines[s.lines.length - 1].off; s.eof = false; } } }
      compose(pane); render(pane);
      if (anchor) { const j = pane.lines.findIndex(l => l.sid === anchor.sid && l.off === anchor.off); if (j >= 0) scrollToIndex(pane, j); }
    }
  } catch (e) { status(e.message, true); }
  setLoading(pane, false); pane.extending = false;
}
// 검색 결과 뷰에서의 동일 시간 스크롤: 다른 패널의 결과 목록에서 같은 시각(이후) 줄로 맞춘다 (서버 왕복 없음)
function syncHits(pane) {
  const key = topKey(pane); if (!key) return;
  S.syncSrc = pane; S.syncUntil = performance.now() + 800;
  for (const p of S.panes) {
    if (p === pane || !p.hitsMode) continue;
    let i = p.lines.findIndex(l => l.key >= key); if (i < 0) i = p.lines.length - 1;
    scrollToIndex(p, Math.max(0, i));
  }
}
function syncOthers(pane) {
  const key = topKey(pane); if (!key) return;
  // 이 패널이 기준. 따라가는 패널들의 스크롤 이벤트(서버 탐색 뒤 늦게 오는 것 포함)는 기준을 바꾸지 못한다
  S.syncSrc = pane; S.syncUntil = performance.now() + 1500;
  const jobs = S.panes.filter(p => p !== pane).map(p => gotoKey(p, key, false));
  Promise.all(jobs).then(() => { S.syncUntil = performance.now() + 300; });   // 다 끝난 뒤 잠깐 더 잠갔다가 푼다
}

// ---------------------------------------------------------------- 상세
function showDetail(pane, i, rowEl) {
  pane.rows.querySelectorAll('.lrow.sel').forEach(r => r.classList.remove('sel')); rowEl.classList.add('sel');
  const l = pane.lines[i]; if (!l) return;
  const d = $('detail'); d.classList.add('on');
  const rows = [['서버', srvName(l.sid)], ['시간', fmtKey(l.key)], ['IP', l.ip]];
  if (l.kind === 'access') { const u = uaParse(l.ua); rows.push(['요청', l.req], ['상태/크기', `${l.status} / ${l.bytes}`], ['Referer', l.ref], ['디바이스', u.device], ['브라우저', u.browser], ['User-Agent', l.ua]); }
  if (l.kind === 'error') rows.push(['레벨', `${l.mod ? l.mod + ':' : ''}${l.level}`], ['메시지', l.msg]);
  rows.push(['원문', l.raw]);
  d.innerHTML = `<dl>${rows.map(([k, v]) => `<dt>${k}</dt><dd>${esc(v || '')}</dd>`).join('')}</dl>`;
}

// ---------------------------------------------------------------- 검색
const HINTS = { ip: 'IP: 단어 단위 정확 일치 (10.1.2.3 은 10.1.2.33 과 구분) · 결과 줄 더블클릭 → 원문 위치', time: '시간: 14:03 / 14:03:22 / 2026-10-29 14:03 / 10-29 14:03 — 그 시각(이후)으로 이동', text: '텍스트: 부분 일치 (대소문자 구분) · 결과 줄 더블클릭 → 원문 위치', regex: '정규식(grep -E) · 결과 줄 더블클릭 → 원문 위치' };
$('sType').onchange = () => { $('sHint').textContent = HINTS[$('sType').value]; $('sQ').placeholder = { ip: '예) 10.1.2.3', time: '예) 14:03', text: '예) /login/index.php', regex: '예) " 50[0-9] ' }[$('sType').value]; };
$('sQ').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(0); });
$('btnSearch').onclick = () => doSearch(0);
$('btnNext').onclick = () => doSearch(1);
function parseTimeInput(q, refKey) {
  q = q.trim(); let m;
  const ref = refKey || new Date().toISOString().replace(/\D/g, '').slice(0, 8) + '000000';
  if ((m = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2})(?::(\d{1,2}))?(?::(\d{1,2}))?)?$/.exec(q))) return m[1] + m[2].padStart(2, '0') + m[3].padStart(2, '0') + (m[4] || '0').padStart(2, '0') + (m[5] || '0').padStart(2, '0') + (m[6] || '0').padStart(2, '0');
  if ((m = /^(\d{1,2})-(\d{1,2})(?: (\d{1,2})(?::(\d{1,2}))?(?::(\d{1,2}))?)?$/.exec(q))) return ref.slice(0, 4) + m[1].padStart(2, '0') + m[2].padStart(2, '0') + (m[3] || '0').padStart(2, '0') + (m[4] || '0').padStart(2, '0') + (m[5] || '0').padStart(2, '0');
  if ((m = /^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/.exec(q))) return ref.slice(0, 8) + m[1].padStart(2, '0') + m[2].padStart(2, '0') + (m[3] || '0').padStart(2, '0');
  if ((m = /^(\d\d)\/([A-Za-z]{3})\/(\d{4})(?::(\d\d))?(?::(\d\d))?(?::(\d\d))?/.exec(q))) return m[3] + (MON[m[2]] || '00') + m[1] + (m[4] || '00') + (m[5] || '00') + (m[6] || '00');
  if (/^\d{8,14}$/.test(q)) return q.padEnd(14, '0');
  return null;
}
async function doSearch(fromCurrent) {
  const type = $('sType').value, q = $('sQ').value.trim();
  if (!q || !S.panes.length) { status('접속·파일 선택 후 검색어를 입력하세요', true); return; }
  if (type === 'time') {
    const pane = S.active || S.panes[0];
    const key = parseTimeInput(q, topKey(pane) || mainSrc(pane).info?.last || '');
    if (!key) { status('시간 형식을 인식하지 못했습니다', true); return; }
    S.searchTerm = ''; $('results').innerHTML = ''; $('sInfo').hidden = true; $('resultsBox').hidden = true; status(fmtKey(key) + ' 로 이동');
    for (const p of S.panes) gotoKey(p, key, true);
    return;
  }
  S.searchType = type; S.searchTerm = type === 'regex' ? '' : q;
  const mode = type === 'ip' ? 'W' : type === 'regex' ? 'E' : 'F';
  const sids = S.merge ? S.panes[0].sids : S.panes.map(p => p.sid);
  const fromOf = sid => { if (!fromCurrent) return 0; const p = S.merge ? S.panes[0] : S.panes.find(x => x.sid === sid); const i = Math.floor(p.body.scrollTop / ROW_H); const l = p.lines.slice(i).find(l => l.sid === sid); return l ? l.off + 1 : 0; };
  $('btnSearch').disabled = $('btnNext').disabled = true; status('검색 중... (큰 파일은 시간이 걸립니다)'); $('results').innerHTML = ''; $('sInfo').hidden = true; $('resultsBox').hidden = true;
  try {
    const res = await Promise.all(sids.map(async sid => ({ sid, ...(await api(`api.php?a=grep&server=${sid}&path=${encodeURIComponent(filePath())}&q=${encodeURIComponent(q)}&mode=${mode}&from=${fromOf(sid)}&max=300`)) })));
    const items = [];
    for (const r of res) for (const [off, text] of r.hits) { const l = mkLine(off, text, ''); l.sid = r.sid; items.push(l); }
    items.sort((a, b) => a.key < b.key ? -1 : a.key > b.key ? 1 : a.sid - b.sid);
    const capped = res.filter(r => r.capped).map(r => 'S' + r.sid);
    $('sInfo').textContent = `${items.length}건 ▾`; $('sInfo').hidden = false;
    $('sInfo').title = capped.length ? `서버 ${capped.join(',')} 는 300건까지만 — "현재 위치 이후"로 이어서 검색` : '검색 결과 목록 펼치기/접기';
    // "검색된 줄만 보기" 가 꺼져 있으면 패널엔 원문이 그대로라 목록을 바로 펼쳐 준다
    $('resultsBox').hidden = $('chkOnlyHits').checked;
    status(`검색 ${items.length}건` + (capped.length ? ` — 서버 ${capped.join(',')} 는 300건까지만, "현재 위치 이후"로 이어서` : ''));
    $('results').innerHTML = items.map((l, i) => `<div class="item" data-i="${i}"><div class="l1"><span class="s${l.sid % 9}">S${l.sid}</span><span class="t">${l.time || ''}</span><span class="ip">${esc(l.ip)}</span></div><div class="l2">${esc(l.kind === 'access' ? `${l.status} ${l.req}` : l.kind === 'error' ? l.msg : l.raw)}</div></div>`).join('');
    $('results').querySelectorAll('.item').forEach(el => el.onclick = () => jumpTo(items[+el.dataset.i], el));
    S.hits = items;
    if ($('chkOnlyHits').checked) S.panes.forEach(renderHitsView);
    else S.panes.forEach(p => render(p));
  } catch (e) { $('sInfo').hidden = true; $('resultsBox').hidden = true; status('검색 실패: ' + e.message, true); }
  $('btnSearch').disabled = $('btnNext').disabled = false;
}
// "검색된 줄만 보기": 패널에 그 서버(들)의 검색 결과만 시간순으로 그린다. 원문 버퍼(src)는 그대로 두므로 끄면 바로 돌아온다
function renderHitsView(pane) {
  const L = (S.hits || []).filter(l => pane.sids.includes(l.sid));
  pane.hitsMode = true; pane.lines = L;
  const simple = applyLayout(pane);
  pane.rows.innerHTML = L.length ? L.map((l, i) => rowHtml(l, i, pane.sid == null ? l.sid : null, null, simple)).join('') : '<div class="empty">검색 결과 없음</div>';
  updatePos(pane);
}
function leaveHitsView() {
  for (const p of S.panes) { if (!p.hitsMode) continue; p.hitsMode = false; compose(p); render(p); scrollToIndex(p, Math.max(0, p.lines.length - 1)); }
}
$('chkOnlyHits').onchange = () => { if ($('chkOnlyHits').checked) { if (S.hits) S.panes.forEach(renderHitsView); } else leaveHitsView(); };

$('sInfo').onclick = () => { $('resultsBox').hidden = !$('resultsBox').hidden; };
async function jumpTo(l, el) {
  if (S.panes.some(p => p.hitsMode)) { $('chkOnlyHits').checked = false; leaveHitsView(); }   // 결과 클릭 = 원문 위치로
  $('results').querySelectorAll('.item').forEach(x => x.classList.toggle('sel', x === el));
  const pane = S.merge ? S.panes[0] : S.panes.find(p => p.sid === l.sid); if (!pane) return;
  await gotoKey(pane, l.key || '00000000000000', true, { sid: l.sid, off: l.off });
  if (S.sync) for (const p of S.panes) if (p !== pane) gotoKey(p, l.key, false);
}

// ---------------------------------------------------------------- AI 분석 (Claude) — config envlog_ai_enabled 가 false 면 이 절은 건너뛴다(요소도 없음)
if (window.ENVLOG_AI) {
//  흐름: IP·시간 범위 입력 → "자료 모으기"(서버에서 추림, 로테이션 파일 자동 선택) → 보낼 자료 확인 → "질문" → 답 스트리밍
//  무엇이 보내지는지는 자료 미리보기에서 보인다. 키는 서버에만 있다(ai_key).
const AI = { parts: null, key: null, busy: false };
const pad2 = n => String(n).padStart(2, '0');
const fmtLocal = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
function aiStatus(msg, err) { const e = $('aiMsg'); e.textContent = msg || ''; e.className = 'hint' + (err ? ' err' : ''); }
// 머리글의 "이번 달 사용 / 상한" — ai_key·ai_ask 응답에 실려 온다
function aiBudget(b) {
  if (!b) return; const el = $('aiBudget'); const won = n => '₩' + Math.round(n).toLocaleString();
  el.innerHTML = `AI 사용 <b>${won(b.month_krw)}</b> / ${won(b.cap_krw)} (이번 달 ${b.calls}회 · 1회 ${won(b.per_request_krw)} 이하)`;
  el.classList.toggle('warn', b.month_krw >= b.cap_krw * 0.8);
}

// 기본 시간 범위: 지금부터 2시간 전 ~ 지금
(() => { const now = new Date(); const from = new Date(now.getTime() - 2 * 3600 * 1000); $('aiFrom').value = fmtLocal(from); $('aiTo').value = fmtLocal(now); })();
$('aiToggle').onclick = () => { $('aibox').classList.toggle('open'); if ($('aibox').classList.contains('open')) { aiLoadKey(); aiFillAccess(); } };
// IP 는 칩으로 쌓인다: '＋ IP 추가' → 입력칸 → 확인(Enter) → 칩. 칩의 ✕ 로 뺀다. 서버에는 콤마로 이어 보낸다
AI.ips = [];
const aiIps = () => AI.ips.slice();
function aiRenderIps() {
  $('aiIps').innerHTML = AI.ips.length ? AI.ips.map((ip, i) => `<span class="chip">${esc(ip)}<button type="button" data-i="${i}" title="이 IP 빼기">✕</button></span>`).join('') : '<span class="hint" id="aiIpsEmpty">없음 — 시간 범위 전체를 봅니다</span>';
  $('aiIps').querySelectorAll('button').forEach(b => b.onclick = () => { AI.ips.splice(+b.dataset.i, 1); aiRenderIps(); });
}
function aiIpOpen() { $('aiIpEdit').hidden = false; $('aiIpAdd').hidden = true; $('aiIpNew').value = ''; $('aiIpNew').focus(); }
function aiIpClose() { $('aiIpEdit').hidden = true; $('aiIpAdd').hidden = false; }
function aiIpCommit() {
  const vals = $('aiIpNew').value.split(/[\s,]+/).map(v => v.trim()).filter(Boolean);
  for (const v of vals) {
    if (!/^[0-9A-Za-z.:_-]{3,64}$/.test(v)) { aiStatus(`IP 형식이 아닙니다: ${v}`, true); return; }
    if (AI.ips.length >= 10) { aiStatus('IP 는 최대 10개까지입니다', true); break; }
    if (!AI.ips.includes(v)) AI.ips.push(v);
  }
  aiRenderIps(); aiIpClose(); aiStatus('');
}
$('aiIpAdd').onclick = aiIpOpen;
$('aiIpOk').onclick = aiIpCommit;
$('aiIpCancel').onclick = aiIpClose;
$('aiIpNew').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); aiIpCommit(); } else if (e.key === 'Escape') aiIpClose(); });
$('aiWin').onchange = () => {   // 빠른 범위: 끝 시각 기준으로 N분
  const m = +$('aiWin').value; if (!m) return;
  const to = $('aiTo').value ? new Date($('aiTo').value) : new Date(); $('aiFrom').value = fmtLocal(new Date(to.getTime() - m * 60000)); $('aiTo').value = fmtLocal(to);
};

async function aiLoadKey() {
  try { AI.key = await api('api.php?a=ai_key'); }
  catch (e) { aiStatus(e.message, true); return; }
  $('aiKeyState').textContent = AI.key.has ? (AI.key.source === 'user' ? '개인 키 사용 중' : '회사 공용 키 사용 중 — 바로 쓸 수 있음') : '키 없음 — config.php 의 anthropic_api_key 또는 개인 키 필요';
  $('aiKeyNote').textContent = AI.key.source === 'config'
    ? '이미 회사 공용 키로 동작합니다. 아무것도 입력하지 않아도 됩니다. 내 계정 비용으로 따로 쓰고 싶을 때만 개인 키를 등록하세요(서버에 암호화 저장, 내 요청에만 사용). 등록한 키를 지우려면 비우고 저장.'
    : AI.key.source === 'user' ? '개인 키가 등록되어 있습니다. 바꾸려면 새 키를 넣고 저장, 지우고 공용 키로 돌아가려면 비우고 저장.'
    : '회사 공용 키가 설정되어 있지 않습니다. config.php 에 anthropic_api_key 를 넣거나, 여기에 개인 키를 등록하세요.';
  $('aiKeyState').className = 'bdg ' + (AI.key.has ? 'ver' : 'no');
  // 공용 키가 있으면 개인 키는 선택 사항 — 단추 이름으로 알린다
  $('aiKeyBtn').textContent = AI.key.source === 'config' ? '개인 키(선택)' : (AI.key.source === 'user' ? '개인 키 변경' : 'API 키 등록');
  $('aiKeyBtn').title = AI.key.source === 'config' ? '회사 공용 키가 설정되어 있습니다. 내 계정 비용으로 따로 쓰고 싶을 때만 개인 키를 등록하세요' : '';
  $('aiKeyHint').textContent = AI.key.source === 'config' ? '개인 키로 쓰려면 →' : AI.key.source === 'user' ? '' : '키를 등록하세요 →';
  const sel = $('aiModel'); if (!sel.options.length) for (const m of AI.key.models) { const o = document.createElement('option'); o.value = m; o.textContent = { 'claude-opus-5-5': 'Opus 5.5 (정확)', 'claude-sonnet-5-5': 'Sonnet 5.5', 'claude-haiku-5-5': 'Haiku 5.5 (저렴)' }[m] || m; sel.appendChild(o); }
  $('aiRotHint').textContent = `로테이션 매일 ${AI.key.rotate_hour}:00 기준으로 날짜별 파일을 자동 선택`;
  $('aiWinHint').textContent = `IP 없이 보면 최대 ${AI.key.max_window_min / 60}시간, IP 를 넣으면 최대 ${AI.key.max_window_ip_hours}시간`;
  aiBudget(AI.key.budget);
}
$('aiKeyBtn').onclick = () => { $('aiKeyRow').hidden = !$('aiKeyRow').hidden; };
$('aiKeyClose').onclick = () => { $('aiKeyRow').hidden = true; $('aiKeyIn').value = ''; };
$('aiKeySave').onclick = async () => {
  try { AI.key = await api('api.php?a=ai_key', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: $('aiKeyIn').value.trim() }) }); $('aiKeyIn').value = ''; $('aiKeyRow').hidden = true; aiLoadKey(); aiStatus('API 키를 저장했습니다'); }
  catch (e) { aiStatus(e.message, true); }
};

// 현재 폴더의 파일 목록에서 access_log 후보(로테이션 이름을 묶어 기본 이름별 총 용량)와 php_errors 기본 이름을 고른다
function aiFillAccess() {
  const all = S.fileList || []; const groups = new Map();
  for (const f of all) {
    if (f.type === 'd' || isCompressed(f.name)) continue;
    const base = f.name.replace(/[-.](\d{8})(\.\d+)?$/, '');
    const g = groups.get(base) || { base, size: 0, n: 0 }; g.size += f.size; g.n++; groups.set(base, g);
  }
  const acc = [...groups.values()].filter(g => /access/i.test(g.base)).sort((a, b) => b.size - a.size);
  const sel = $('aiAccess'); const prev = sel.value; sel.innerHTML = '<option value="">(access_log 안 봄)</option>' + acc.map(g => `<option value="${esc(g.base)}" title="${fmtSize(g.size)} · ${g.n}개 파일">${esc(g.base)}</option>`).join('');
  sel.value = acc.some(g => g.base === prev) ? prev : (acc[0] ? acc[0].base : '');   // 기본: 가장 큰 것
  const php = [...groups.values()].filter(g => /php_error/i.test(g.base)).sort((a, b) => b.size - a.size);
  const ps = $('aiPhp'); ps.innerHTML = '<option value="">(php_errors 안 봄)</option>' + php.map(g => `<option value="${esc(g.base)}" title="${fmtSize(g.size)} · ${g.n}개 파일">${esc(g.base)}</option>`).join('');
  ps.value = php.some(g => g.base === 'php_errors.log') ? 'php_errors.log' : (php[0] ? php[0].base : '');
}

$('aiCollect').onclick = () => aiCollect();
// 자료 수집. 성공(보낼 줄이 있음)이면 true. "질문" 에서도 자료가 없으면 자동으로 먼저 부른다
async function aiCollect() {
  if (!S.servers.length) { aiStatus('먼저 대학에 접속하세요', true); return false; }
  const sids = S.servers.filter(s => S.selected.has(s.id)).map(s => s.id);
  if (!sids.length) { aiStatus('왼쪽에서 서버를 선택하세요', true); return false; }
  if (!$('aiFrom').value || !$('aiTo').value) { aiStatus('시간 범위는 필수입니다', true); $('aiFrom').focus(); return false; }
  const body = { servers: sids, dir: S.logDir, access_base: $('aiAccess').value, php_base: $('aiPhp').value, ip: aiIps().join(','), from: $('aiFrom').value.replace('T', ' '), to: $('aiTo').value.replace('T', ' ') };
  $('aiCollect').disabled = true; aiStatus('서버에서 로그를 추리는 중... (IP grep 은 파일 크기만큼 걸립니다)'); $('aiParts').innerHTML = ''; AI.parts = null; $('aiAsk').disabled = true;
  try {
    const j = await api('api.php?a=ai_collect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    AI.parts = j.parts; AI.sig = JSON.stringify([aiIps(), $('aiFrom').value, $('aiTo').value, $('aiAccess').value, $('aiPhp').value, [...S.selected]]);
    const rows = j.parts.map(p => `<tr class="${p.missing || p.skipped ? 'off' : ''}"><td><span class="s${p.server % 9}">S${p.server}</span></td><td>${esc(p.file)}</td><td class="num">${p.missing ? '없음' : p.skipped ? esc(p.skipped) : p.lines + '줄'}</td><td class="num">${p.missing ? '' : fmtSize(p.bytes)}</td><td>${p.capped ? '<span class="bdg vpn">상한에서 끊김</span>' : ''}${p.trimmed ? '<span class="bdg vpn">용량 상한으로 잘림</span>' : ''}</td><td><a href="#" data-k="${esc(p.file)}|${p.server}" class="peek">${p.lines ? '내용 보기' : ''}</a></td></tr>`).join('');
    $('aiParts').innerHTML = `<table><tr><th>서버</th><th>파일</th><th>줄</th><th>크기</th><th></th><th></th></tr>${rows}</table>
      <div class="hint">보낼 자료 합계 ${fmtSize(j.total_bytes)} ≈ ${j.approx_tokens.toLocaleString()} 토큰 · 예상 비용 ${Object.entries(j.estimate).map(([m, e]) => `${m.replace('claude-', '').replace(/-/g, ' ')} ≈ ₩${e.krw}${e.ok ? '' : ' (상한 초과)'}`).join(' / ')}${j.errors.length ? ' · 오류: ' + esc(j.errors.join(' / ')) : ''}</div><pre id="aiPeek" hidden></pre>`;
    if (!j.parts.some(p => p.kind === 'php' && p.lines)) { const sk = j.parts.filter(p => p.skipped); if (sk.length) aiStatus(`php_errors 는 IP 요청이 없는 서버(${[...new Set(sk.map(p => 'S' + p.server))].join(', ')})에서는 건너뛰었습니다`); }
    for (const o of $('aiModel').options) { const e = j.estimate[o.value]; o.disabled = e ? !e.ok : false; o.textContent = o.textContent.replace(/ — .*$/, '') + (e ? ` — ≈₩${e.krw}` : ''); }
    if ($('aiModel').selectedOptions[0]?.disabled) { const ok = [...$('aiModel').options].find(o => !o.disabled); if (ok) $('aiModel').value = ok.value; }
    $('aiParts').querySelectorAll('.peek').forEach(a => a.onclick = e => { e.preventDefault(); const [f, s] = a.dataset.k.split('|'); const p = AI.parts.find(x => x.file === f && x.server === +s); const pre = $('aiPeek'); pre.hidden = !pre.hidden || pre.dataset.k !== a.dataset.k ? false : true; pre.dataset.k = a.dataset.k; pre.textContent = p.text; });
    aiStatus(j.total_bytes ? `자료 준비됨 — 파일 ${j.files.join(', ')}` : '조건에 맞는 줄이 없습니다 (IP·시간·파일 확인)', j.total_bytes === 0);
    $('aiCollect').disabled = false;
    return j.total_bytes > 0;
  } catch (e) { aiStatus(e.message, true); $('aiCollect').disabled = false; return false; }
}

$('aiAsk').onclick = async () => {
  const prompt = $('aiPrompt').value.trim();
  if (!prompt) { aiStatus('질문을 입력하세요', true); $('aiPrompt').focus(); return; }
  if (!(AI.key && AI.key.has)) { aiStatus('Anthropic API 키가 없습니다 — "API 키" 로 등록하세요', true); return; }
  // 조건(시간·IP·파일)이 바뀌었거나 아직 안 모았으면 자동으로 먼저 모은다 — "조회 대상 파일 확인" 은 미리 보고 싶을 때만
  const sig = JSON.stringify([aiIps(), $('aiFrom').value, $('aiTo').value, $('aiAccess').value, $('aiPhp').value, [...S.selected]]);
  if (!AI.parts || AI.sig !== sig) { $('aiAsk').disabled = true; const ok = await aiCollect(); $('aiAsk').disabled = false; if (!ok) return; }
  const body = { prompt, model: $('aiModel').value, context: AI.parts.filter(p => p.text).map(p => ({ server: p.server, file: p.file, text: p.text })),
                 ip: aiIps().join(', '), from: $('aiFrom').value.replace('T', ' '), to: $('aiTo').value.replace('T', ' '), school: S.school ? S.school.name : '' };
  const out = $('aiAnswer'); out.textContent = ''; out.hidden = false; $('aiAsk').disabled = true; AI.busy = true; aiStatus('Claude 에 보내는 중...');
  try {
    const r = await fetch('api.php?a=ai_ask', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    if (!r.ok || !r.headers.get('content-type')?.includes('text/event-stream')) { const j = await r.json().catch(() => ({ error: 'HTTP ' + r.status })); throw new Error(j.error || ('HTTP ' + r.status)); }
    const rd = r.body.getReader(); const dec = new TextDecoder(); let buf = '';
    while (true) {
      const { value, done } = await rd.read(); if (done) break;
      buf += dec.decode(value, { stream: true });
      let i; while ((i = buf.indexOf('\n\n')) >= 0) {
        const chunk = buf.slice(0, i); buf = buf.slice(i + 2);
        for (const ln of chunk.split('\n')) {
          if (!ln.startsWith('data: ')) continue;
          const ev = JSON.parse(ln.slice(6));
          if (ev.t) { out.textContent += ev.t; out.scrollTop = out.scrollHeight; }
          else if (ev.start) aiStatus(`응답 중... (${ev.model}, 보낸 자료 ${fmtSize(ev.sent_bytes)}${ev.ssl ? ', ' + ev.ssl : ''})`);
          else if (ev.error) aiStatus(ev.error, true);
          else if (ev.done) { aiStatus(`완료 — 입력 ${ev.usage.input.toLocaleString()} 토큰(캐시 읽기 ${ev.usage.cache_read.toLocaleString()}) · 출력 ${ev.usage.output.toLocaleString()} 토큰 · 약 ₩${ev.cost_krw} ($${ev.cost_usd})` + (ev.stop === 'max_tokens' ? ' · 답이 길어 잘렸습니다' : '')); aiBudget(ev.budget); }
        }
      }
    }
  } catch (e) { aiStatus(e.message, true); }
  $('aiAsk').disabled = false; AI.busy = false;
};
$('aiPrompt').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) $('aiAsk').click(); });
}   // ENVLOG_AI

// ---------------------------------------------------------------- 초기화
// 브라우저가 저장된 로그인 계정을 검색칸에 채워 넣는 경우(비밀번호 칸을 보고 로그인 폼으로 오해) — 선택한 대학이 없는데
// 값이 들어와 있으면 지운다. 자동완성은 로드 직후나 조금 뒤에 일어나므로 두 번 본다
const clearAutofill = () => { if (!acSel && $('q').value && !$('dd').classList.contains('on')) { $('q').value = ''; setQVer(''); } };
setTimeout(clearAutofill, 100); setTimeout(clearAutofill, 800);
if (window.ENVLOG_AI) aiLoadKey();   // 키 유무·모델 목록 (상자를 열기 전에도 머리글 뱃지에 보이게)
(async () => {
  try {
    const c = await api('api.php?a=state');
    S.logDir = c.log_dir; $('logDir').value = c.log_dir;
    if (c.local_test) status('로컬 테스트 모드 — 어느 대학에 접속해도 데몬의 로컬 폴더를 봅니다');
    if (c.connected) {
      // 데몬이 이 사용자의 SSH 접속을 들고 있다(새로고침해도 다시 접속하지 않도록). 화면도 그 상태에 맞춘다 —
      // 검색칸에 대학명을 채우고, 이어서 쓰는 중임을 알린다. 새로 시작하려면 "연결 끊기".
      applySession(c.school, c.servers);
      acSel = { id: c.school.id, name: c.school.name, ver: c.school.ver, ok: true }; $('q').value = c.school.name; setQVer(c.school.ver);
      status(`이전 접속을 이어서 사용 중: ${c.school.name} (${c.school.host}) — 다른 대학을 보려면 검색해서 접속하거나 "연결 끊기"`);
      loadFiles();
    }
  } catch (e) { status(e.message, true); }
})();
})();
