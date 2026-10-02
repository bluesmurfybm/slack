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
