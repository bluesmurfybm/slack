/* 엑셀 붙여넣기 파서 시험 — node assign/dev/wbs_parse_test.js
 *
 * assign.js 의 파서는 DOM 을 타지 않는 순수 변환이라 브라우저 없이 돌릴 수 있다.
 * 같은 코드를 여기 옮겨 적으면 둘이 갈라지므로, 원본에서 표시 구간을 떼어 온다.
 * 표시가 사라지면 이 시험이 먼저 실패한다 — 그게 맞다.
 */
'use strict';

const fs   = require('fs');
const path = require('path');

const SRC = fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'assign.js'), 'utf8');

const BEGIN = '/* ==WBS-PARSE-BEGIN==';
const END   = '/* ==WBS-PARSE-END== */';
const i = SRC.indexOf(BEGIN);
const j = SRC.indexOf(END);
if (i < 0 || j < 0) {
  console.error('assign.js 에서 파서 구간 표시를 찾지 못했습니다.');
  console.error('  ' + BEGIN + ' ~ ' + END + ' 가 그대로 있어야 합니다.');
  process.exit(2);
}
const BLOCK = SRC.slice(i, j);

// 파서가 바깥에서 쓰는 것 두 가지를 대신 넣어 준다.
const MAX_DEPTH = 3;
let uid = 0;
function newNode(src) {
  src = src || {};
  return {
    key: 'n' + (++uid),
    id: null,
    title: src.title || '',
    est_md: src.est_md === 0 || src.est_md ? src.est_md : '',
    difficulty: src.difficulty || '',
    plan_start: src.plan_start || '',
    plan_end: src.plan_end || '',
    children: []
  };
}

const parsePaste = new Function('newNode', 'MAX_DEPTH',
  BLOCK + '\n return parsePaste;')(newNode, MAX_DEPTH);

let pass = 0, fail = 0;
function ok(what, cond, extra) {
  if (cond) { pass++; console.log('  OK   ' + what); }
  else { fail++; console.log('  FAIL ' + what + (extra ? ' -- ' + extra : '')); }
}
function section(s) { console.log('\n' + s); }

const T = '\t';

// =====================================================================
section('[1] 줄 앞 탭이 단계다');
let r = parsePaste([
  '요구사항 분석',
  T + '현행 조사',
  T + '인터뷰',
  T + T + '교수 인터뷰',
  '개발',
  T + '출석부 개선'
].join('\n'));
ok('6건', r.count === 6, String(r.count));
ok('뿌리 2개', r.tree.length === 2, String(r.tree.length));
ok('1단계 하위 2개', r.tree[0].children.length === 2);
ok('2단계 하위 1개', r.tree[0].children[1].children.length === 1);
ok('3단계 제목', r.tree[0].children[1].children[0].title === '교수 인터뷰');
ok('경고 없음', r.warn.length === 0, JSON.stringify(r.warn));

// =====================================================================
section('[2] 나머지 탭은 칸이다');
r = parsePaste([
  '분석',
  T + '현행 조사' + T + '3' + T + '2' + T + '2026-07-06' + T + '2026-07-31'
].join('\n'));
const n = r.tree[0].children[0];
ok('제목',   n.title === '현행 조사', n.title);
ok('공수 3', n.est_md === 3, String(n.est_md));
ok('난이도 2', n.difficulty === 2, String(n.difficulty));
ok('시작일', n.plan_start === '2026-07-06', n.plan_start);
ok('종료일', n.plan_end === '2026-07-31', n.plan_end);

// =====================================================================
section('[3] 엑셀이 뱉는 날짜 표기');
[['2026-07-06', '2026-07-06'],
 ['2026.7.6',   '2026-07-06'],
 ['2026/7/6',   '2026-07-06'],
 ['2026. 7. 6.', '2026-07-06'],
 ['2026-12-31', '2026-12-31']].forEach(function (c) {
  const x = parsePaste('A' + T + T + T + c[0]);
  ok('"' + c[0] + '" → ' + c[1], x.tree[0].plan_start === c[1], x.tree[0].plan_start);
});
r = parsePaste('A' + T + T + T + '내일');
ok('못 읽는 날짜는 비우고 알린다', r.tree[0].plan_start === '' && r.warn.length === 1,
   JSON.stringify(r.warn));

// =====================================================================
section('[4] 잘못된 값은 버리되 줄은 살린다');
r = parsePaste('A' + T + 'abc' + T + '9');
ok('숫자 아닌 공수는 비움', r.tree[0].est_md === '', String(r.tree[0].est_md));
ok('범위 밖 난이도는 비움', r.tree[0].difficulty === '', String(r.tree[0].difficulty));
ok('난이도는 경고로 알린다', r.warn.length === 1, JSON.stringify(r.warn));
ok('줄 자체는 살아 있다', r.count === 1);

r = parsePaste('A' + T + '1,250');
ok('천 단위 쉼표를 읽는다', r.tree[0].est_md === 1250, String(r.tree[0].est_md));

// =====================================================================
section('[5] 빈 줄과 빈 제목');
r = parsePaste(['A', '', '   ', T + 'B'].join('\n'));
ok('빈 줄은 건너뛴다', r.count === 2, String(r.count));
r = parsePaste(['A', ' ' + T + '3'].join('\n'));
ok('제목 칸이 비면 건너뛰고 알린다', r.count === 1 && r.warn.length === 1,
   JSON.stringify(r.warn));

// 규칙에서 따라오는 결과. 고칠 수 있는 것이 아니라 알고 있어야 하는 것이다 —
// "줄 앞 탭 = 단계" 로 정했으므로, 앞쪽 빈 칸과 들여쓰기는 같은 글자다.
// 제목을 비운 채 뒤 칸만 채운 줄은 '한 단계 들여쓴 줄' 로 읽힌다.
r = parsePaste(['A', T + '3'].join('\n'));
ok('앞 칸을 비우면 들여쓰기로 읽힌다(규칙상 구분 불가)',
   r.count === 2 && r.tree[0].children.length === 1
   && r.tree[0].children[0].title === '3',
   JSON.stringify(r.tree));

// =====================================================================
section('[6] 단계가 튀거나 넘칠 때 — 버리지 않고 끌어올린다');
r = parsePaste([T + T + '갑자기 3단계'].join('\n'));
ok('상위 없는 3단계는 1단계로', r.tree.length === 1 && r.tree[0].title === '갑자기 3단계');
ok('끌어올렸다고 알린다', r.warn.length === 1, JSON.stringify(r.warn));

r = parsePaste(['A', T + 'B', T + T + 'C', T + T + T + 'D'].join('\n'));
ok('4단계는 3단계로 내린다',
   r.tree[0].children[0].children.length === 2, JSON.stringify(r.warn));
ok('넘친 것을 알린다', r.warn.length >= 1);
ok('아무것도 잃지 않았다', r.count === 4, String(r.count));

// =====================================================================
section('[7] 줄바꿈 형식');
r = parsePaste('A\r\n' + T + 'B\r\n' + T + 'C');
ok('윈도우 줄바꿈(CRLF)', r.count === 3 && r.tree[0].children.length === 2, String(r.count));
r = parsePaste('A\r' + T + 'B');
ok('옛 맥 줄바꿈(CR)', r.count === 2, String(r.count));

// =====================================================================
section('[8] 아무것도 없을 때');
r = parsePaste('');
ok('빈 문자열은 0건', r.count === 0 && r.tree.length === 0);
r = parsePaste('\n\n\t\n');
ok('공백만 있어도 0건', r.count === 0);

console.log('\n' + '='.repeat(29));
console.log('통과 ' + pass + ' / 실패 ' + fail + '\n');
process.exit(fail ? 1 : 0);
