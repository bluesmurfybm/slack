/**
 * assign.js 스코프 검사 — 모듈 안에 숨은 함수를 남의 모듈에서 부르는가.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 이 시험이 따로 있나 (2026-10-08)                               │
 * │                                                                  │
 * │ `node --check` 는 **문법만** 본다. 스코프는 안 본다. 그래서       │
 * │ 아래 두 가지가 시험을 전부 통과하고 운영 화면에서 터졌다 —        │
 * │                                                                  │
 * │   loadPicked is not defined   (2026-10-07)                       │
 * │   overMonths is not defined   (2026-10-08)                       │
 * │                                                                  │
 * │ 둘 다 같은 모양이다. 한 모듈(initProjectView) 안에 쓴 도우미를    │
 * │ 다른 모듈(initAllocation)에서 불렀다. 글자로만 보면 멀쩡하고      │
 * │ 문법도 맞다. **브라우저에서 그 화면을 열어야만** 알 수 있었다.    │
 * │                                                                  │
 * │ assign.js 는 들여쓰기 규칙이 일정하다(IIFE 바로 아래 2칸, 모듈    │
 * │ 안은 4칸 이상). 그 규칙을 이용해 기계로 센다.                     │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 토큰을 제대로 가르지 않는다 — 일부러 그렇게 한다                  │
 * │                                                                  │
 * │ 처음에는 주석과 **문자열**을 다 지우고 보려 했다. 그런데          │
 * │ `/[&<>"']/g` 같은 **정규식 리터럴**에서 따옴표 짝이 어긋나        │
 * │ 그 뒤 파일 전체가 "문자열 안" 으로 뭉개졌다. 검사기가 아무것도    │
 * │ 못 보면서 **조용히 통과**했다.                                    │
 * │                                                                  │
 * │ 제대로 하려면 JS 토크나이저가 필요하고, 그건 의존 라이브러리      │
 * │ 없음 규칙에 걸린다. 그래서 **주석 줄만** 건너뛴다. 거짓 경보가    │
 * │ 생길 자리는 문자열 안에 `someHelper(` 가 들어 있는 경우뿐인데     │
 * │ 이 파일에는 없고, 생기면 그때 이름을 빼면 된다.                   │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   node studio/dev/js_scope_test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const FILE = path.join(__dirname, '..', 'assets', 'assign.js');
const lines = fs.readFileSync(FILE, 'utf8').split('\n');

let pass = 0;
let fail = 0;
function ok(what, cond, extra) {
  if (cond) { pass++; console.log('  OK   ' + what); }
  else { fail++; console.log('  FAIL ' + what + (extra ? ' — ' + extra : '')); }
}

/** 설명글은 호출이 아니다. "여기서 부르면 안 된다" 가 곧 위반이 되면 안 된다. */
function isComment(line) {
  return /^\s*(\/\/|\*|\/\*)/.test(line);
}

/**
 * IIFE 바로 아래 2칸 들여쓴 function 하나가 모듈 하나다.
 * 닫는 중괄호도 같은 깊이에 홀로 선다.
 */
function findModules(src) {
  const out = [];
  for (let i = 0; i < src.length; i++) {
    const m = /^ {2}function\s+([A-Za-z_$][\w$]*)\s*\(/.exec(src[i]);
    if (!m) continue;
    let end = src.length - 1;
    for (let j = i + 1; j < src.length; j++) {
      if (/^ {2}\}\s*$/.test(src[j])) { end = j; break; }
    }
    out.push({ name: m[1], from: i, to: end });
  }
  return out;
}

/** 모듈 안에만 있는 함수. 4칸 이상 들여쓴 것. */
function findLocals(src, modules) {
  const out = [];
  for (const mod of modules) {
    for (let i = mod.from + 1; i < mod.to; i++) {
      if (isComment(src[i])) continue;
      const m = /^ {4,}function\s+([A-Za-z_$][\w$]*)\s*\(/.exec(src[i]);
      if (m) out.push({ name: m[1], owner: mod.name, from: mod.from, to: mod.to });
    }
  }
  return out;
}

/** 제 모듈 밖에서 불린 자리. 비어 있어야 한다. */
function offsideCalls(src, modules, locals) {
  const dup = {};
  locals.forEach((l) => { dup[l.name] = (dup[l.name] || 0) + 1; });

  // 바깥(IIFE 바로 아래)에 같은 이름이 또 있으면 그쪽이 잡히므로 뺀다.
  const shared = new Set(modules.map((m) => m.name));
  for (const line of src) {
    const m = /^ {2}(?:function\s+([A-Za-z_$][\w$]*)|var\s+([A-Za-z_$][\w$]*))/.exec(line);
    if (m) shared.add(m[1] || m[2]);
  }

  const bad = [];
  for (const l of locals) {
    // 같은 이름이 여러 모듈에 따로 있으면 어느 것인지 가릴 수 없다.
    // 거짓 경보가 생기면 아무도 이 시험을 안 믿는다.
    if (dup[l.name] > 1 || shared.has(l.name)) continue;
    const re = new RegExp('(^|[^\\w$.])' + l.name + '\\s*\\(');
    for (let i = 0; i < src.length; i++) {
      if (i >= l.from && i <= l.to) continue;        // 제 모듈 안은 괜찮다
      if (isComment(src[i]) || !re.test(src[i])) continue;
      const at = modules.find((m) => i >= m.from && i <= m.to);
      bad.push(l.name + ' — ' + l.owner + ' 안에 있는데 '
             + (at ? at.name : '바깥') + ' 에서 부름 (' + (i + 1) + '번째 줄)');
    }
  }
  return bad;
}

// =====================================================================
console.log('\n[1] 검사기가 실제로 잡는가 — 자가 시험');
//
// 늘 통과하는 검사는 없는 것과 같다. 일부러 어긋나게 만든 코드를
// 못 잡으면 아래 [2] 의 'OK' 는 아무 뜻이 없다.
const probe = [
  '(function () {',
  '  function initA() {',
  '    function hiddenHelper() { return 1; }',
  '    hiddenHelper();',
  '  }',
  '  function initB() {',
  '    hiddenHelper();',                 // ← 이것을 잡아야 한다
  '  }',
  '})();',
].join('\n').split('\n');
const pMods = findModules(probe);
const pBad  = offsideCalls(probe, pMods, findLocals(probe, pMods));
ok('★ 어긋난 호출을 잡는다', pBad.length === 1, JSON.stringify(pBad));

const clean = [
  '(function () {',
  '  function sharedHelper() { return 1; }',
  '  function initA() { sharedHelper(); }',
  '  function initB() { sharedHelper(); }',
  '})();',
].join('\n').split('\n');
const cMods = findModules(clean);
ok('공용 자리에 있으면 안 잡는다',
   offsideCalls(clean, cMods, findLocals(clean, cMods)).length === 0);

// 주석 속 이름을 호출로 세면 설명글이 곧 위반이 된다.
const commented = [
  '(function () {',
  '  function initA() {',
  '    function hiddenHelper() { return 1; }',
  '    hiddenHelper();',
  '  }',
  '  function initB() {',
  '    // hiddenHelper() 는 여기서 부르면 안 된다',
  '    return 0;',
  '  }',
  '})();',
].join('\n').split('\n');
const kMods = findModules(commented);
ok('주석 속 이름은 호출이 아니다',
   offsideCalls(commented, kMods, findLocals(commented, kMods)).length === 0);

// =====================================================================
console.log('\n[2] assign.js');
const modules = findModules(lines);
const locals  = findLocals(lines, modules);
ok('모듈을 찾았다', modules.length > 10, String(modules.length));
ok('모듈 안 도우미를 찾았다', locals.length > 20, String(locals.length));

const bad = offsideCalls(lines, modules, locals);
ok('★ 남의 모듈 안 함수를 부르지 않는다', bad.length === 0, bad.join(' | '));

// =====================================================================
console.log('\n[3] 공용 도우미는 바깥에');
//
// 실제로 터졌던 둘(overMonths · PICK_KEY)이 지금은 제자리에 있는가.
for (const name of ['esc', 'api', 'qs', 'toast', 'showError', 'clearError',
                    'openDrawer', 'closeDrawer', 'overMonths']) {
  const at = lines.findIndex((l) =>
    new RegExp('^ {2}(function|var)\\s+' + name + '\\b').test(l));
  ok('바깥에 있다: ' + name, at >= 0, '모듈 안에 숨어 있다');
}
const pk = lines.filter((l) => /^ {2}var\s+PICK_KEY\b/.test(l)).length;
ok('후보 저장소 키도 바깥에 하나뿐', pk === 1, String(pk));

console.log('\n' + '='.repeat(29) + '\n통과 ' + pass + ' / 실패 ' + fail + '\n');
process.exit(fail > 0 ? 1 : 0);
