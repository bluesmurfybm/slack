<?php
/** 문서 파서 + WBS 도출기 시험 — 실제 xlsx·pptx·docx 파일로 돌린다. DB 를 타지 않는다. */
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
require ROOT . '/studio/inc/service/DocumentParser.php';
require ROOT . '/studio/inc/service/OfficeDocumentParser.php';
require ROOT . '/studio/inc/service/LlmClient.php';

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 이 시험이 DB 를 안 타는 이유                                       │
   │                                                                  │
   │ 파싱과 스키마 검증은 순수 변환이다. DB 를 붙이면 시험이 느려지고   │
   │ 실패했을 때 원인이 두 겹이 된다. DB 가 필요한 부분(parseSource 가  │
   │ parse_status 를 남기는지 등)은 api_test.php 가 HTTP 로 확인한다.   │
   │                                                                  │
   │ 시험 문서는 dev/fixtures/ 에 들어 있다. 다시 만들려면             │
   │   python studio/dev/fixtures/make_fixtures.py studio/dev/fixtures │
   └──────────────────────────────────────────────────────────────────┘ */

const FIX = __DIR__ . '/fixtures';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra ? " — $extra" : '') . "\n"; }
}
function throws(string $what, callable $fn, string $cls = DocumentParseError::class): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $what — 예외가 안 났다\n"; }
    catch (Throwable $e) {
        if ($e instanceof $cls) { $pass++; echo "  OK   $what → " . $e->getMessage() . "\n"; }
        else { $fail++; echo "  FAIL $what — 다른 예외: " . get_class($e) . ' ' . $e->getMessage() . "\n"; }
    }
}
function fixture(string $n): string {
    $p = FIX . '/' . $n;
    if (!is_file($p)) {
        fwrite(STDERR, "\n시험 문서가 없습니다: $p\n"
            . "만들려면:  python studio/dev/fixtures/make_fixtures.py studio/dev/fixtures\n\n");
        exit(2);
    }
    return $p;
}
function blockByRef(ParsedDoc $d, string $needle): ?ParsedBlock {
    foreach ($d->blocks as $b) {
        if (str_contains($b->ref, $needle)) { return $b; }
    }
    return null;
}

$p = new OfficeDocumentParser(null);

// =====================================================================
echo "\n[1] 지원 형식\n";
foreach (['xlsx', 'pptx', 'docx', 'pdf'] as $k) {
    ok("$k 지원", $p->supports($k));
}
ok('figma 는 지원 안 함', !$p->supports('figma'));
ok('image 는 지원 안 함', !$p->supports('image'));

// =====================================================================
echo "\n[2] xlsx — 시트마다 한 블록, ref 에 시트명과 범위\n";
$d = $p->parse(fixture('sample.xlsx'), 'xlsx');
ok('블록 2개 (빈 시트는 빠짐)', count($d->blocks) === 2,
   implode(' / ', array_map(fn($b) => $b->ref, $d->blocks)));
$b = blockByRef($d, '요구사항');
ok('첫 블록 ref = 요구사항!A1:F6', $b?->ref === '요구사항!A1:F6', (string)$b?->ref);
ok('머리글 줄이 들어 있다', str_contains((string)$b?->text, "구분\t요구사항"));
ok('두 번째 시트도 있다', blockByRef($d, '일정') !== null);

// ┌──────────────────────────────────────────────────────────────────┐
// │ 시트 하나만 읽기 (2026-10-06)                                     │
// │                                                                  │
// │ 구글 시트 주소의 gid 는 **탭 하나**를 가리킨다. 사람이 "이 시트를 │
// │ 보라" 고 줬는데 통합문서를 통째로 읽어, WBS 초안이 `목차` 탭의    │
// │ `목차`·`코드`·`M1` 같은 항목 300건으로 채워졌다.                  │
// └──────────────────────────────────────────────────────────────────┘
$one = $p->parse(fixture('sample.xlsx'), 'xlsx', '일정');
ok('★ 지정한 시트만 읽는다', count($one->blocks) === 1
   && blockByRef($one, '일정') !== null,
   implode(' / ', array_map(fn($b) => $b->ref, $one->blocks)));
ok('★ 다른 시트는 안 들어온다', blockByRef($one, '요구사항') === null);
ok('어느 시트만 읽었는지 알려 준다',
   str_contains(implode(' ', $one->notes), '일정'), implode(' | ', $one->notes));

// ★ 조용히 빈 결과를 주면 "문서가 비었다" 로 읽히고 사람이 엉뚱한 곳을 본다.
$miss = $p->parse(fixture('sample.xlsx'), 'xlsx', '없는시트');
ok('★ 없는 시트를 가리키면 전체를 읽는다', count($miss->blocks) === 2,
   implode(' / ', array_map(fn($b) => $b->ref, $miss->blocks)));
ok('그 사실을 적어 둔다',
   str_contains(implode(' ', $miss->notes), '찾지 못해'), implode(' | ', $miss->notes));

$all = $p->parse(fixture('sample.xlsx'), 'xlsx', null);
ok('안 가리키면 전부 읽는다(기존 그대로)', count($all->blocks) === 2);

// 이 줄이 이 파서의 핵심이다. 빈 칸을 건너뛰면 5 가 공수인지 난이도인지
// 알 수 없게 된다 — 처음에 그렇게 짰다가 고쳤다.
$lines = explode("\n", (string)$b?->text);
$bigo  = null;
foreach ($lines as $l) { if (str_starts_with($l, '비고')) { $bigo = $l; } }
ok('중간 빈 칸이 탭으로 자리를 지킨다', $bigo === "비고\t지난 학기 데이터 마이그레이션 필요\t\t5",
   json_encode($bigo, JSON_UNESCAPED_UNICODE));
ok('떨어진 셀도 같은 행에 붙는다', str_contains((string)$b?->text, "4\t\t떨어진 셀"));

// =====================================================================
echo "\n[3] pptx — 슬라이드 번호가 ref\n";
$d = $p->parse(fixture('sample.pptx'), 'pptx');
ok('블록 3개 (글자 없는 슬라이드는 빠짐)', count($d->blocks) === 3, (string)count($d->blocks));
ok('ref = 슬라이드 1', $d->blocks[0]->ref === '슬라이드 1', $d->blocks[0]->ref);
ok('제목과 본문이 같이 들어온다',
   str_contains($d->blocks[0]->text, 'A대 LXP 고도화')
   && str_contains($d->blocks[0]->text, '킥오프'));
ok('문단이 조각으로 흩어지지 않는다',
   str_contains($d->blocks[1]->text, '1. 출석 통합'), $d->blocks[1]->text);

// =====================================================================
echo "\n[4] docx — 문단과 표\n";
$d = $p->parse(fixture('sample.docx'), 'docx');
$t = $d->blocks[0]->text;
ok('제목 문단', str_contains($t, '요구사항 정의서'));
ok('한 문단 안의 조각을 붙인다', str_contains($t, '1. 출석 통합'), $t);
ok('<w:br/> 은 줄바꿈', str_contains($t, "출석을\n하나로"), json_encode($t, JSON_UNESCAPED_UNICODE));
ok('<w:tab/> 은 탭', str_contains($t, "2. 성적부\t연동"));
// 빈 문단 <w:p/> 뒤가 통째로 사라지던 버그. XMLReader 는 스스로 닫는
// 요소에 END_ELEMENT 를 주지 않는다.
ok('빈 문단 뒤의 본문이 살아 있다', str_contains($t, '성적부'), $t);
ok('표는 한 행을 한 줄로, 칸은 탭', str_contains($t, "항목\t비고"), $t);

// =====================================================================
echo "\n[2-H] 숨긴 시트 — 엑셀을 열어도 없는 내용이 나왔다\n";
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ 실제로 겪은 일 (2026-10-07)                                       │
// │                                                                  │
// │ 10장짜리 관리대장에서 9장이 숨김이고 보이는 것은 1장뿐이었다.     │
// │ 전부 읽으니 개정 이력·지난 화면 설계·다른 기관 건까지 WBS 로      │
// │ 나왔다. 쓰는 사람은 **엑셀을 열어 봐도 그런 내용이 없으니** 어디  │
// │ 서 온 글자인지 알 길이 없었다.                                    │
// │                                                                  │
// │ 숨겼다는 것은 "지금 보여 줄 것이 아니다" 라는 사람의 뜻이다.      │
// └──────────────────────────────────────────────────────────────────┘
// =====================================================================
$hid = $p->parse(fixture('hidden_sheets.xlsx'), 'xlsx');
$hidText = $hid->text();

ok('★ 숨긴 시트는 읽지 않는다', count($hid->blocks) === 1,
   json_encode(array_column($hid->blocks, 'ref'), JSON_UNESCAPED_UNICODE));
ok('보이는 시트는 그대로 읽는다', str_contains($hidText, '통합 출석부'));
ok('★ hidden 시트의 글자가 섞이지 않는다', !str_contains($hidText, 'Revision History'),
   '개정 이력이 WBS 로 나온다');
ok('★ veryHidden 시트의 글자도 섞이지 않는다', !str_contains($hidText, 'LMS 홈'),
   '지난 설계가 WBS 로 나온다');

// 조용히 버리면 반대쪽 같은 문제가 된다 — "왜 이 시트가 안 나오지".
$hidNote = implode(' | ', $hid->notes);
ok('★ 몇 장을 건너뛰었는지 말한다', str_contains($hidNote, '숨긴 시트 2장'), $hidNote);
ok('★ 건너뛴 시트의 이름을 적는다',
   str_contains($hidNote, '개정이력') && str_contains($hidNote, '지난설계'), $hidNote);
ok('되살리는 방법도 알려 준다', str_contains($hidNote, '숨김을 풀고'), $hidNote);

// 이름을 집어 요청하면 숨김이어도 읽는다. 사람이 그 시트를 지목한 것이다.
$pick = $p->parse(fixture('hidden_sheets.xlsx'), 'xlsx', '개정이력');
ok('★ 이름을 집으면 숨긴 시트도 읽는다',
   count($pick->blocks) === 1 && str_contains($pick->text(), 'Revision History'),
   json_encode(array_column($pick->blocks, 'ref'), JSON_UNESCAPED_UNICODE));

// 전부 숨김이면 거를 수 없다. 걸렀다가는 멀쩡한 문서를 "읽을 글자가
// 없습니다" 로 되돌려보내게 된다.
$allH = $p->parse(fixture('all_hidden.xlsx'), 'xlsx');
ok('★ 전부 숨김이면 그대로 읽는다', count($allH->blocks) === 2,
   (string)count($allH->blocks));
ok('그 사실을 적는다', str_contains(implode(' ', $allH->notes), '모든 시트가 숨김'),
   implode(' | ', $allH->notes));

// 숨긴 시트가 하나도 없는 문서는 **아무 말도 하지 않아야** 한다.
// 쓸데없는 메모가 쌓이면 진짜 메모를 안 읽는다.
$plain = $p->parse(fixture('sample.xlsx'), 'xlsx');
ok('숨긴 시트가 없으면 메모도 없다',
   !str_contains(implode(' ', $plain->notes), '숨긴 시트'),
   implode(' | ', $plain->notes));

// =====================================================================
echo "\n[5] 깨진 파일 — 던지되 알아볼 수 있게\n";
throws('zip 이 아니면 거절', fn() => $p->parse(fixture('broken.xlsx'), 'xlsx'));
throws('zip 이지만 office 가 아니면 거절', fn() => $p->parse(fixture('notoffice.xlsx'), 'xlsx'));
throws('없는 파일', fn() => $p->parse(FIX . '/없는파일.xlsx', 'xlsx'));
throws('모르는 형식', fn() => $p->parse(fixture('sample.xlsx'), 'hwp'));
throws('pdf 도구가 없으면 그렇다고 말한다',
       fn() => $p->parse(fixture('sample.xlsx'), 'pdf'));

// =====================================================================
echo "\n[6] parsed_text 왕복 — 위치 정보가 살아남는가\n";
$d = $p->parse(fixture('sample.xlsx'), 'xlsx');
$stored = $d->text();
$back   = bs_parse_blocks($stored);
ok('블록 수 보존', count($back) === count($d->blocks),
   count($back) . ' vs ' . count($d->blocks));
ok('ref 보존', array_map(fn($b) => $b->ref, $back) === array_map(fn($b) => $b->ref, $d->blocks));
ok('본문 보존', trim($back[0]->text) === trim($d->blocks[0]->text));
ok('표시가 없는 글은 한 덩어리로', count(bs_parse_blocks('그냥 글자')) === 1);
ok('빈 글도 터지지 않는다', count(bs_parse_blocks('')) === 1);

// ref 에 표시 글자가 섞여 들어와도 경계가 깨지지 않아야 한다
ok('ref 안의 ]] 는 지운다', bs_parse_marker('시트]]이상!A1') === '[[시트이상!A1]]',
   bs_parse_marker('시트]]이상!A1'));

// =====================================================================
echo "\n[7] LLM 클라이언트 — 설정이 없을 때\n";
$null = new NullLlmClient();
ok('available=false', !$null->available());
ok('name=none', $null->name() === 'none');
// 빈 결과를 조용히 돌려주면 화면이 "문서가 부실하다" 로 읽는다. 던져야 한다.
try {
    define('BS_ROOT', ROOT . '/studio');
    $null->generate('a', 'b', []);
    ok('설정 없으면 예외', false, '예외가 안 났다');
} catch (LlmError $e) {
    ok('설정 없으면 예외', true);
    ok('재시도해도 소용없다고 알린다', $e->retryable === false);
    ok('무엇을 해야 하는지 알려 준다', str_contains($e->getMessage(), 'llm.config.php'));
}

echo "\n[8] LLM 클라이언트 — 고정 응답\n";
$fx = new FixtureLlmClient([['tasks' => [['title' => 'A', 'depth' => 1]]]]);
ok('available=true', $fx->available());
$r = $fx->generate('s', 'u', []);
ok('data 가 그대로 온다', ($r->data['tasks'][0]['title'] ?? '') === 'A');
ok('호출 횟수를 센다', $fx->callCount() === 1);
$r = $fx->generate('s', 'u', []);
ok('응답이 모자라면 마지막 것을 되쓴다', ($r->data['tasks'][0]['title'] ?? '') === 'A');
ok('호출 횟수 2', $fx->callCount() === 2);

$err = new FixtureLlmClient([new LlmError('일시 오류', retryable: true)]);
try { $err->generate('s', 'u', []); ok('오류를 흉내 낼 수 있다', false); }
catch (LlmError $e) { ok('오류를 흉내 낼 수 있다', $e->retryable === true); }

ok('LlmSchemaError 는 LlmError 다', new LlmSchemaError('x') instanceof LlmError);
ok('스키마 오류는 재시도 가능', (new LlmSchemaError('x'))->retryable === true);

// ---------------------------------------------------------------------
// IA 시트의 링크
//
// IA 시트는 항목마다 기획 화면(피그마·드라이브) 주소를 셀 링크로 걸어 두는
// 일이 흔하다. 보이는 글자만 뽑으면 그 연결이 통째로 사라진다 — 어느 항목이
// 어느 화면인지 알 수 없게 된다.
//
// 엑셀은 링크를 셀 안이 아니라 두 군데에 나눠 둔다. 구글 시트는 =HYPERLINK()
// 수식으로 거는 경우도 있다. 둘 다 읽어야 한다.
// ---------------------------------------------------------------------
$ia = (new OfficeDocumentParser(null))->parse(FIX . '/ia_links.xlsx', 'xlsx')->text();

ok('셀 링크를 글자 옆에 붙인다',
   str_contains($ia, '기획안 <https://www.figma.com/design/ABCDEFGH1234/plan?node-id=40006486-417499>'),
   $ia);
ok('=HYPERLINK() 수식 링크도 읽는다',
   str_contains($ia, '설계 <https://www.figma.com/design/KEY12345678/x?node-id=11-22>'));
ok('범위(C5:C9)에 건 링크는 그 안의 칸에 다 붙는다',
   str_contains($ia, '범위링크 <https://docs.google.com/spreadsheets/d/SHEETID123456/edit>'));

// 문서 안으로 가는 링크(location=)는 주소가 아니다. 붙이면 WBS 도출이
// 그걸 따라가려 한다.
ok('문서 안 링크는 붙이지 않는다', !str_contains($ia, '로그인 <'));

echo "\n" . str_repeat('=', 29) . "\n통과 $pass / 실패 $fail\n\n";
exit($fail > 0 ? 1 : 0);
