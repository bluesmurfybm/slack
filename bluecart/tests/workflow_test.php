<?php
/**
 * 전체 워크플로 통합 점검.
 *   php tests/workflow_test.php
 *
 * 실제 DB에 붙어 요청 → 승인/반려 → 재요청 → 구매진행 → 구비완료 경로를
 * 모두 태우고, 권한 차단과 알림 적재까지 확인합니다.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI 전용');
}

// 운영/개발 설정을 건드리지 않고 테스트 전용 설정만 쓴다.
define('BC_CONFIG_FILE', __DIR__ . '/../config/config.test.php');

$_SESSION = [];
require_once dirname(__DIR__) . '/includes/bootstrap.php';

// ---------------------------------------------------------------------
// 아주 작은 테스트 러너
// ---------------------------------------------------------------------
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $what\n";
    } else {
        $fail++;
        echo "  FAIL  $what" . ($detail ? "  → $detail" : '') . "\n";
    }
}

function throws(string $what, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($what, false, '예외가 발생하지 않았습니다');
    } catch (Throwable $e) {
        ok($what, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

/** 로그인 사용자를 바꿔 끼운다 (auth.php 의 static 캐시를 우회). */
function login(string $id): array
{
    $row = bc_directory_find($id);
    return ['id' => $id, 'name' => $row['name'] ?? $id, 'email' => $row['email'] ?? null];
}

echo "\n=== BlueCart 워크플로 통합 점검 ===\n\n";

// ---------------------------------------------------------------------
echo "[1] 스키마와 초기 데이터\n";
$cats = Category::all();
ok('카테고리 3건 적재', count($cats) === 3, '실제: ' . count($cats));
ok('알림 기본 규칙 적재', count(bc_fetch_all('SELECT * FROM bc_notify_setting')) >= 20);

$catId = (int)$cats[0]['id'];

// ---------------------------------------------------------------------
echo "\n[2] 요청 등록과 번호 채번\n";
$hoyoung = login('hoyoung');

$id1 = PurchaseRequest::create([
    'category_id' => $catId,
    'item_name'   => '16OZ 아이스 컵',
    'quantity'    => 2,
    'unit'        => '박스',
    'est_amount'  => 35000,
    'ref_url'     => 'https://example.com/item/1',
    'deliver_to'  => '사무실',
    'need_by'     => date('Y-m-d', strtotime('+7 days')),
    'note'        => '사무실로 배송해 주세요',
], $hoyoung);

$r1 = PurchaseRequest::find($id1);
ok('요청이 생성됨', $r1 !== null);
ok('요청번호가 연도-일련번호 형식', (bool)preg_match('/^\d{4}-\d{4}$/', $r1['req_no']), $r1['req_no']);
ok('초기 상태는 검토 대기', $r1['status'] === 'REQUESTED', $r1['status']);
ok('요청자 스냅샷 저장', $r1['requester_id'] === 'hoyoung');

$id2 = PurchaseRequest::create([
    'category_id' => $catId, 'item_name' => '원두', 'quantity' => 2, 'unit' => '봉',
    'est_amount' => null, 'ref_url' => '', 'deliver_to' => '', 'need_by' => '', 'note' => '',
], $hoyoung);
$r2 = PurchaseRequest::find($id2);
ok('일련번호가 1씩 증가', (int)$r2['req_seq'] === (int)$r1['req_seq'] + 1,
   $r1['req_no'] . ' → ' . $r2['req_no']);

// ---------------------------------------------------------------------
echo "\n[3] 입력 검증\n";
throws('물품명 없이 등록하면 거부', function () use ($catId, $hoyoung) {
    PurchaseRequest::create(['category_id' => $catId, 'item_name' => '  ', 'quantity' => 1,
        'unit' => '개', 'est_amount' => null, 'ref_url' => '', 'deliver_to' => '',
        'need_by' => '', 'note' => ''], $hoyoung);
}, '필요 물품');

throws('갯수 0이면 거부', function () use ($catId, $hoyoung) {
    PurchaseRequest::create(['category_id' => $catId, 'item_name' => '테스트', 'quantity' => 0,
        'unit' => '개', 'est_amount' => null, 'ref_url' => '', 'deliver_to' => '',
        'need_by' => '', 'note' => ''], $hoyoung);
}, '필요 갯수');

throws('javascript: 링크 거부', function () use ($catId, $hoyoung) {
    PurchaseRequest::create(['category_id' => $catId, 'item_name' => '테스트', 'quantity' => 1,
        'unit' => '개', 'est_amount' => null, 'ref_url' => 'javascript:alert(1)',
        'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
}, '참고 링크');

throws('목록에 없는 수령 장소 거부', function () use ($catId, $hoyoung) {
    PurchaseRequest::create(['category_id' => $catId, 'item_name' => '테스트', 'quantity' => 1,
        'unit' => '개', 'est_amount' => null, 'ref_url' => '', 'deliver_to' => '3층 회의실',
        'need_by' => '', 'note' => ''], $hoyoung);
}, '수령 장소');

$idPlace = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '컵홀더', 'quantity' => 1,
    'unit' => '개', 'est_amount' => null, 'ref_url' => '', 'deliver_to' => 'CAFE',
    'need_by' => '', 'note' => ''], $hoyoung);
ok('목록에 있는 수령 장소는 그대로 저장', PurchaseRequest::find($idPlace)['deliver_to'] === 'CAFE');

// 선택 목록으로 바꾸기 전에 자유 입력으로 들어간 값이 수정을 막지 않아야 한다.
bc_query("UPDATE bc_request SET deliver_to = '3층 회의실' WHERE id = ?", [$idPlace]);
PurchaseRequest::updateByOwner($idPlace, ['category_id' => $catId, 'item_name' => '컵홀더 200개입',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '3층 회의실', 'need_by' => '', 'note' => ''], $hoyoung);
ok('예전 자유 입력 장소는 그대로 두면 수정 가능',
   PurchaseRequest::find($idPlace)['item_name'] === '컵홀더 200개입');

throws('예전 건이라도 다른 목록 밖 값으로는 못 바꿈', function () use ($catId, $hoyoung, $idPlace) {
    PurchaseRequest::updateByOwner($idPlace, ['category_id' => $catId, 'item_name' => '컵홀더',
        'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
        'deliver_to' => '4층 탕비실', 'need_by' => '', 'note' => ''], $hoyoung);
}, '수령 장소');

throws('없는 카테고리 거부', function () use ($hoyoung) {
    PurchaseRequest::create(['category_id' => 99999, 'item_name' => '테스트', 'quantity' => 1,
        'unit' => '개', 'est_amount' => null, 'ref_url' => '', 'deliver_to' => '',
        'need_by' => '', 'note' => ''], $hoyoung);
}, '사용처');

// ---------------------------------------------------------------------
echo "\n[4] 역할 배정\n";
RoleAssign::replace('REVIEWER', ['jian'], 'admin');
RoleAssign::replace('BUYER',    ['jian', 'seongcheol'], 'admin');
ok('검토승인자 1명 배정', count(RoleAssign::byType('REVIEWER')) === 1);
ok('구매담당자 2명 배정', count(RoleAssign::byType('BUYER')) === 2);
ok('배정 시 성명 스냅샷 저장', RoleAssign::byType('REVIEWER')[0]['user_name'] === '김지안',
   RoleAssign::byType('REVIEWER')[0]['user_name']);

$recips = RoleAssign::resolveRecipients('REVIEWER', $r1);
ok('수신자 이메일 해석', ($recips[0]['email'] ?? '') === 'jian@example.com', json_encode($recips));

// ---------------------------------------------------------------------
echo "\n[5] 권한 차단\n";
$byeongmun = login('byeongmun'); // 아무 역할도 없는 구성원

throws('역할 없는 사람은 승인 불가', function () use ($id1, $byeongmun) {
    PurchaseRequest::transition($id1, 'approve', $byeongmun);
}, '권한');

throws('승인 전 구매진행 불가', function () use ($id1) {
    PurchaseRequest::transition($id1, 'start_purchase', login('jian'));
}, '처리할 수 없');

// ---------------------------------------------------------------------
echo "\n[6] 정상 경로: 승인 → 구매진행 → 구비완료\n";
$jian = login('jian');

PurchaseRequest::transition($id1, 'approve', $jian, ['comment' => '필요해 보입니다']);
$r1 = PurchaseRequest::find($id1);
ok('승인 후 상태 APPROVED', $r1['status'] === 'APPROVED', $r1['status']);
ok('검토자 기록', $r1['reviewer_id'] === 'jian');
ok('검토 시각 기록', !empty($r1['reviewed_at']));

PurchaseRequest::transition($id1, 'start_purchase', $jian, ['purchase_note' => '쿠팡 주문']);
$r1 = PurchaseRequest::find($id1);
ok('구매진행 후 상태 PURCHASING', $r1['status'] === 'PURCHASING', $r1['status']);
ok('구매담당자 기록', $r1['buyer_id'] === 'jian');
ok('구매 메모 저장', $r1['purchase_note'] === '쿠팡 주문');

PurchaseRequest::transition($id1, 'complete', $jian, ['actual_amount' => '32800']);
$r1 = PurchaseRequest::find($id1);
ok('구비완료 후 상태 STOCKED', $r1['status'] === 'STOCKED', $r1['status']);
ok('입고 시각 기록', !empty($r1['stocked_at']));
ok('실구매 금액 저장', (int)$r1['actual_amount'] === 32800);
ok('기존 구매 메모 유지', $r1['purchase_note'] === '쿠팡 주문');

throws('완료 건은 재처리 불가', function () use ($id1, $jian) {
    PurchaseRequest::transition($id1, 'approve', $jian);
}, '처리할 수 없');

// ---------------------------------------------------------------------
echo "\n[7] 반려 경로와 재요청\n";
throws('사유 없이 반려하면 거부', function () use ($id2, $jian) {
    PurchaseRequest::transition($id2, 'reject', $jian, ['comment' => '   ']);
}, '반려 사유');

PurchaseRequest::transition($id2, 'reject', $jian, ['comment' => '재고가 아직 남아 있습니다']);
$r2 = PurchaseRequest::find($id2);
ok('반려 후 상태 REJECTED', $r2['status'] === 'REJECTED', $r2['status']);
ok('반려 사유 저장', $r2['review_comment'] === '재고가 아직 남아 있습니다');

// 요청자가 내용을 고친 뒤 재요청
PurchaseRequest::updateByOwner($id2, [
    'category_id' => $catId, 'item_name' => '원두(디카페인)', 'quantity' => 1, 'unit' => '봉',
    'est_amount' => 24000, 'ref_url' => '', 'deliver_to' => '사무실', 'need_by' => '', 'note' => '재고 소진 후 필요',
], $hoyoung);

throws('남의 요청은 수정 불가', function () use ($id2, $catId, $byeongmun) {
    PurchaseRequest::updateByOwner($id2, ['category_id' => $catId, 'item_name' => '가로채기',
        'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
        'deliver_to' => '', 'need_by' => '', 'note' => ''], $byeongmun);
}, '본인');

PurchaseRequest::transition($id2, 'resubmit', $hoyoung);
$r2 = PurchaseRequest::find($id2);
ok('재요청 후 상태 REQUESTED', $r2['status'] === 'REQUESTED', $r2['status']);
ok('재요청 횟수 1', (int)$r2['resubmit_count'] === 1);
ok('재요청 시 반려 사유 초기화', $r2['review_comment'] === null);
ok('요청번호는 그대로 유지', $r2['req_no'] === PurchaseRequest::find($id2)['req_no']);

// ---------------------------------------------------------------------
echo "\n[8] 철회\n";
$id3 = PurchaseRequest::create([
    'category_id' => $catId, 'item_name' => '테스트 철회건', 'quantity' => 1, 'unit' => '개',
    'est_amount' => null, 'ref_url' => '', 'deliver_to' => '', 'need_by' => '', 'note' => '',
], $hoyoung);

throws('남의 요청은 철회 불가', function () use ($id3, $byeongmun) {
    PurchaseRequest::transition($id3, 'cancel', $byeongmun);
}, '권한');

PurchaseRequest::transition($id3, 'cancel', $hoyoung);
ok('철회 후 상태 CANCELED', PurchaseRequest::find($id3)['status'] === 'CANCELED');
ok('종료 시각 기록', !empty(PurchaseRequest::find($id3)['closed_at']));

// ---------------------------------------------------------------------
echo "\n[9] 처리 이력\n";
$hist = PurchaseRequest::history($id1);
$events = array_column($hist, 'event_code');
ok('이력에 전 단계가 기록됨',
   $events === ['REQUEST_CREATED', 'REVIEW_APPROVED', 'PURCHASE_STARTED', 'PURCHASE_DONE'],
   implode(' → ', $events));

$hist2 = array_column(PurchaseRequest::history($id2), 'event_code');
ok('반려·수정·재요청 이력 기록',
   $hist2 === ['REQUEST_CREATED', 'REVIEW_REJECTED', 'REQUEST_UPDATED', 'REQUEST_RESUBMITTED'],
   implode(' → ', $hist2));

// ---------------------------------------------------------------------
echo "\n[10] 알림 적재\n";
$logs = bc_fetch_all('SELECT event_code, target_role, channel, status, recipient FROM bc_notify_log ORDER BY id');
ok('알림 로그가 쌓임', count($logs) > 0, '건수: ' . count($logs));

$approved = array_filter($logs, fn($l) => $l['event_code'] === 'REVIEW_APPROVED');
$roles = array_unique(array_column($approved, 'target_role'));
sort($roles);
ok('승인 시 구매담당자와 요청자 모두 대상', $roles === ['BUYER', 'REQUESTER'], implode(',', $roles));

$rejected = array_filter($logs, fn($l) => $l['event_code'] === 'REVIEW_REJECTED');
ok('반려 시 요청자에게만 발송',
   array_unique(array_column($rejected, 'target_role')) === ['REQUESTER']);

$done = array_filter($logs, fn($l) => $l['event_code'] === 'PURCHASE_DONE');
$doneRoles = array_unique(array_column($done, 'target_role'));
sort($doneRoles);
ok('구비완료 시 요청자와 검토승인자 대상', $doneRoles === ['REQUESTER', 'REVIEWER'], implode(',', $doneRoles));

// 알림 설정을 끄면 발송 대상에서 빠지는지
Setting::saveNotifyMatrix([
    'REVIEW_REJECTED' => ['REQUESTER' => ['EMAIL' => 0, 'SLACK_DM' => 0, 'SLACK_CHANNEL' => 0]],
], 'admin');
ok('설정을 끄면 채널이 비워짐', Setting::enabledChannels('REVIEW_REJECTED', 'REQUESTER') === []);

$before = count(bc_fetch_all('SELECT id FROM bc_notify_log'));
$id4 = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '알림 차단 시험',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
PurchaseRequest::transition($id4, 'reject', $jian, ['comment' => '불필요']);
$after = count(bc_fetch_all('SELECT id FROM bc_notify_log'));
ok('꺼진 이벤트는 로그가 늘지 않음', $before === $after, "$before → $after");

// ---------------------------------------------------------------------
echo "\n[11] 목록 검색과 필터\n";
$year = (int)date('Y');

$all = PurchaseRequest::search(['year' => $year, 'size' => 100]);
ok('연도 필터 동작', $all['total'] >= 4, '건수: ' . $all['total']);

$prog = PurchaseRequest::search(['year' => $year, 'status' => ['REQUESTED', 'APPROVED', 'PURCHASING']]);
$progStatus = array_unique(array_column($prog['rows'], 'status'));
ok('진행중 탭 필터', !array_diff($progStatus, ['REQUESTED', 'APPROVED', 'PURCHASING']),
   implode(',', $progStatus));

$stocked = PurchaseRequest::search(['year' => $year, 'status' => ['STOCKED']]);
ok('구비완료 탭 필터', $stocked['total'] === 1, '건수: ' . $stocked['total']);

$kw = PurchaseRequest::search(['year' => $year, 'keyword' => '디카페인']);
ok('키워드 검색', $kw['total'] === 1 && $kw['rows'][0]['item_name'] === '원두(디카페인)');

$mine = PurchaseRequest::search(['year' => $year, 'requester_id' => 'hoyoung', 'size' => 100]);
ok('요청자 필터', $mine['total'] === $all['total']);

$sorted = PurchaseRequest::search(['year' => $year, 'sort' => 'recent', 'size' => 100]);
$times = array_column($sorted['rows'], 'requested_at');
$desc = $times;
rsort($desc);
ok('기본 정렬은 최신 요청 순', $times === $desc);

// 상태값은 화이트리스트를 통과한 것만 쓰이므로, 조작된 값은 조건에서 빠지고
// 필터가 없는 것과 같은 결과가 나와야 한다.
$injected = PurchaseRequest::search([
    'year' => $year, 'size' => 100,
    'status' => ["'; DROP TABLE bc_request; --", 'STOCKED'],
]);
ok('조작된 상태값은 버리고 정상 값만 적용',
   $injected['total'] === $stocked['total'],
   $injected['total'] . ' vs ' . $stocked['total']);

ok('주입 시도 후에도 테이블이 그대로 있음',
   count(bc_fetch_all('SHOW TABLES LIKE "bc_request"')) === 1);

// 정렬 파라미터도 화이트리스트
$badSort = PurchaseRequest::search(['year' => $year, 'sort' => 'id; DROP TABLE bc_request', 'size' => 100]);
ok('조작된 정렬값은 기본 정렬로 대체', $badSort['total'] === $all['total']);

// 키워드는 바인딩되므로 따옴표가 그대로 검색어로 취급된다
$quoted = PurchaseRequest::search(['year' => $year, 'keyword' => "' OR 1=1 --"]);
ok('키워드의 따옴표는 검색어로만 취급', $quoted['total'] === 0, '건수: ' . $quoted['total']);

$counts = PurchaseRequest::statusCounts(['year' => $year]);
ok('상태별 집계 합이 전체와 일치', $counts['TOTAL'] === $all['total'],
   $counts['TOTAL'] . ' vs ' . $all['total']);

// ---------------------------------------------------------------------
echo "\n[12] 통계\n";
$stats = PurchaseRequest::statistics($year);
ok('월별 집계 반환', is_array($stats['by_month']) && count($stats['by_month']) >= 1);
ok('사용처별 집계 반환', count($stats['by_category']) >= 1);
ok('요청자별 집계 반환', count($stats['by_requester']) >= 1);
ok('평균 소요시간 산출', $stats['lead_time']['sample'] === 1, json_encode($stats['lead_time']));

// ---------------------------------------------------------------------
echo "\n[13] 카테고리 관리\n";
$newId = Category::create('TEST_CAT', '시험용', 50);
ok('카테고리 추가', Category::find($newId)['name'] === '시험용');
Category::update($newId, '시험용(수정)', 60, false);
ok('카테고리 수정', Category::find($newId)['name'] === '시험용(수정)');
ok('비활성 카테고리는 기본 목록에서 제외',
   !in_array($newId, array_map('intval', array_column(Category::all(), 'id')), true));
ok('사용하지 않는 카테고리는 삭제 가능', Category::delete($newId) === true);
ok('사용 중인 카테고리는 삭제 거부', Category::delete($catId) === false);

// ---------------------------------------------------------------------
echo "\n[14] 사용 가능한 액션 판정\n";
$reqRow = PurchaseRequest::find($id2); // REQUESTED 상태, 요청자는 hoyoung

$a = bc_available_actions($reqRow, $jian);      // 검토승인자
sort($a);
ok('검토 대기 건에서 검토자는 승인/반려', $a === ['approve', 'reject'], implode(',', $a));

$a = bc_available_actions($reqRow, $hoyoung);   // 요청 본인
sort($a);
ok('검토 대기 건에서 요청자는 철회만', $a === ['cancel'], implode(',', $a));

$a = bc_available_actions($reqRow, $byeongmun); // 제3자
ok('제3자는 아무 액션도 없음', $a === [], implode(',', $a));

$stockedRow = PurchaseRequest::find($id1);
ok('구비완료 건은 더 이상 액션 없음', bc_available_actions($stockedRow, $jian) === []);

$approvedRow = PurchaseRequest::find($id4);     // REJECTED 상태
$a = bc_available_actions($approvedRow, $hoyoung);
sort($a);
ok('반려 건에서 요청자는 재요청/철회', $a === ['cancel', 'resubmit'], implode(',', $a));

// ---------------------------------------------------------------------
echo "\n[15] 반려 건 자동 철회 (cron 로직)\n";
$id5 = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '오래된 반려건',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
PurchaseRequest::transition($id5, 'reject', $jian, ['comment' => '보류']);
bc_query('UPDATE bc_request SET reviewed_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?', [$id5]);

$days = (int)Setting::get('reject_auto_close_days', '14');
$stale = bc_fetch_all(
    'SELECT id FROM bc_request WHERE status = "REJECTED" AND reviewed_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
    [$days]
);
ok('30일 지난 반려건이 자동 철회 대상으로 잡힘',
   in_array($id5, array_map('intval', array_column($stale, 'id')), true));
ok('최근 반려건은 대상에서 제외',
   !in_array($id4, array_map('intval', array_column($stale, 'id')), true));

// ---------------------------------------------------------------------
echo "\n[16] 건별 구매담당자 지정\n";

$idA = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '담당 지정 시험',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);

// 승인하면서 담당을 지목
PurchaseRequest::transition($idA, 'approve', $jian, ['assignee_id' => 'seongcheol']);
$rA = PurchaseRequest::find($idA);
ok('승인하며 담당 지정', $rA['assignee_id'] === 'seongcheol', (string)$rA['assignee_id']);
ok('담당자 성명 스냅샷', $rA['assignee_name'] === '박성철', (string)$rA['assignee_name']);
ok('지정 시각 기록', !empty($rA['assigned_at']));
ok('지정한 사람 기록', $rA['assigned_by'] === 'jian');

$seongcheol = login('seongcheol');
ok('담당자는 구매 진행 가능',
   in_array('start_purchase', bc_available_actions($rA, $seongcheol), true));
ok('다른 구매담당자는 진행 불가',
   !in_array('start_purchase', bc_available_actions($rA, $jian), true));
ok('다른 구매담당자도 담당 변경은 가능',
   in_array('assign', bc_available_actions($rA, $jian), true));

throws('담당이 아닌 구매담당자가 진행 시도', function () use ($idA, $jian) {
    PurchaseRequest::transition($idA, 'start_purchase', $jian);
}, '권한');

throws('역할 없는 사람을 담당으로 지정 불가', function () use ($idA, $jian) {
    PurchaseRequest::transition($idA, 'assign', $jian, ['assignee_id' => 'byeongmun']);
}, '구매담당자로 배정된');

throws('담당 지정 시 대상이 비면 거부', function () use ($idA, $jian) {
    PurchaseRequest::transition($idA, 'assign', $jian, ['assignee_id' => '']);
}, '선택하세요');

// 담당 변경
PurchaseRequest::transition($idA, 'assign', $jian, ['assignee_id' => 'jian']);
$rA = PurchaseRequest::find($idA);
ok('담당 변경 반영', $rA['assignee_id'] === 'jian');
ok('담당 변경으로 상태는 바뀌지 않음', $rA['status'] === 'APPROVED', $rA['status']);

PurchaseRequest::transition($idA, 'start_purchase', $jian);
ok('새 담당자는 진행 가능', PurchaseRequest::find($idA)['status'] === 'PURCHASING');

// 담당 미지정 건은 아무 구매담당자나 처리
$idB = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '미지정 건',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
PurchaseRequest::transition($idB, 'approve', $jian);
$rB = PurchaseRequest::find($idB);
ok('담당 미지정 상태', $rB['assignee_id'] === null);
ok('미지정 건은 구매담당자 누구나 진행 가능',
   in_array('start_purchase', bc_available_actions($rB, $seongcheol), true) &&
   in_array('start_purchase', bc_available_actions($rB, $jian), true));

// 담당 지정 알림이 그 사람에게만 가는지
$assignLogs = bc_fetch_all(
    'SELECT recipient, target_role FROM bc_notify_log
      WHERE request_id = ? AND event_code = "PURCHASE_ASSIGNED"', [$idA]);
ok('담당 지정 알림 발생', count($assignLogs) > 0, '건수: ' . count($assignLogs));

// 한 사람에게 이메일과 슬랙 DM 이 각각 나가므로 채널을 하나로 좁혀서 센다.
$approveLogs = bc_fetch_all(
    'SELECT recipient FROM bc_notify_log
      WHERE request_id = ? AND event_code = "REVIEW_APPROVED"
        AND target_role = "BUYER" AND channel = "EMAIL"', [$idA]);
ok('담당 지정 건의 승인 알림은 담당자에게만',
   count($approveLogs) === 1 && $approveLogs[0]['recipient'] === 'seongcheol@example.com',
   json_encode(array_column($approveLogs, 'recipient')));

$unassignedLogs = bc_fetch_all(
    'SELECT recipient FROM bc_notify_log
      WHERE request_id = ? AND event_code = "REVIEW_APPROVED" AND target_role = "BUYER"
        AND channel = "EMAIL"', [$idB]);
ok('미지정 건의 승인 알림은 구매담당자 전원에게',
   count($unassignedLogs) === 2, '건수: ' . count($unassignedLogs));

// 담당자 기준 목록 필터
$assigned = PurchaseRequest::search(['year' => $year, 'assignee_id' => 'jian', 'size' => 100]);
ok('담당자 필터', $assigned['total'] >= 1 &&
   count(array_filter($assigned['rows'], fn($r) => $r['assignee_id'] !== 'jian')) === 0);

$scoped = PurchaseRequest::search([
    'year' => $year, 'assignee_id' => 'seongcheol',
    'include_unassigned' => true, 'status' => ['APPROVED', 'PURCHASING'], 'size' => 100,
]);
$scopedIds = array_column($scoped['rows'], 'assignee_id');
ok('내 담당 + 미지정 필터',
   count(array_filter($scopedIds, fn($a) => $a !== null && $a !== 'seongcheol')) === 0,
   json_encode($scopedIds));

// ---------------------------------------------------------------------
echo "\n[17] 첨부파일\n";

$uploadDir = bc_config('app.upload_dir');
@mkdir($uploadDir, 0750, true);

/** CLI 에서 업로드를 흉내 내는 헬퍼. */
function fake_upload(string $name, string $content): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'bcup');
    file_put_contents($tmp, $content);
    return ['name' => $name, 'type' => '', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
}

$pngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);

$idC = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '첨부 시험',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
$rC = PurchaseRequest::find($idC);

$attId = Attachment::store($idC, fake_upload('견적서.png', $pngBytes), $hoyoung);
ok('첨부 저장', $attId > 0);

$att = Attachment::find($attId);
ok('원본 파일명 보존', $att['orig_name'] === '견적서.png');
ok('저장 경로는 난수 이름', (bool)preg_match('/[0-9a-f]{32}\.png$/', $att['stored_path']), $att['stored_path']);
ok('저장 위치는 업로드 디렉터리 안', str_starts_with($att['stored_path'], $uploadDir));
ok('실제 파일 존재', is_file($att['stored_path']));
ok('MIME 감지', $att['mime_type'] === 'image/png', $att['mime_type']);
ok('목록 조회', count(Attachment::listFor($idC)) === 1);
ok('건수 조회', Attachment::countFor($idC) === 1);

throws('허용되지 않는 확장자 거부', function () use ($idC, $hoyoung) {
    Attachment::store($idC, fake_upload('shell.php', '<?php echo 1;'), $hoyoung);
}, '허용되지 않는 형식');

throws('확장자와 내용이 다르면 거부', function () use ($idC, $hoyoung) {
    Attachment::store($idC, fake_upload('가짜.png', '<?php echo "not a png";'), $hoyoung);
}, '맞지 않습니다');

throws('경로 조작 파일명 거부 또는 정규화', function () use ($idC, $hoyoung, $pngBytes) {
    Attachment::store($idC, fake_upload('../../../../etc/passwd', $pngBytes), $hoyoung);
}, '허용되지 않는 형식');

throws('빈 파일 거부', function () use ($idC, $hoyoung) {
    Attachment::store($idC, fake_upload('empty.png', ''), $hoyoung);
}, '빈 파일');

throws('용량 초과 거부', function () use ($idC, $hoyoung) {
    $big = ['name' => 'big.pdf', 'type' => '', 'tmp_name' => '/dev/null',
            'error' => UPLOAD_ERR_OK, 'size' => 999999999];
    Attachment::store($idC, $big, $hoyoung);
}, '너무 큽니다');

// 권한
ok('요청자는 검토 대기 상태에서 첨부 가능', Attachment::canModify($rC, $hoyoung));
ok('제3자는 첨부 불가', !Attachment::canModify($rC, $byeongmun));
ok('검토승인자는 첨부 가능', Attachment::canModify($rC, $jian));

PurchaseRequest::transition($idC, 'approve', $jian);
$rC = PurchaseRequest::find($idC);
ok('승인 후 요청자는 첨부 불가', !Attachment::canModify($rC, $hoyoung));
ok('승인 후에도 구매담당자는 첨부 가능(영수증)', Attachment::canModify($rC, $jian));

PurchaseRequest::transition($idC, 'complete', $jian);
$rC = PurchaseRequest::find($idC);
ok('완료 후에는 아무도 첨부 불가', !Attachment::canModify($rC, $jian));

// 삭제
$path = $att['stored_path'];
Attachment::delete($attId);
ok('첨부 레코드 삭제', Attachment::find($attId) === null);
ok('실제 파일도 삭제', !is_file($path));

// 요청 삭제 시 첨부 레코드 연쇄 삭제 (FK ON DELETE CASCADE)
$attId2 = Attachment::store($idC, fake_upload('영수증.png', $pngBytes), $jian);
bc_query('DELETE FROM bc_request WHERE id = ?', [$idC]);
ok('요청 삭제 시 첨부 레코드도 삭제', Attachment::find($attId2) === null);

// ---------------------------------------------------------------------
echo "\n[18] 엑셀 내보내기\n";
require_once BC_ROOT . '/includes/XlsxWriter.php';

$rows = PurchaseRequest::search(['year' => $year, 'size' => 200])['rows'];
$x = new XlsxWriter();
$x->addSheet('요청 목록',
    ['No.', '요청번호', '사용처', '필요 물품', '갯수', '신청자', '처리상태', '구매담당', '비고'],
    array_map(function ($r) {
        static $n = 0; $n++;
        return [$n, $r['req_no'], $r['category_name'], $r['item_name'], (int)$r['quantity'],
                $r['requester_name'], bc_status_label($r['status']),
                $r['assignee_name'] ?? '', $r['note'] ?? ''];
    }, $rows),
    [6, 12, 14, 30, 8, 10, 11, 10, 30]);

$xlsx = $x->build();
ok('엑셀 바이너리 생성', strlen($xlsx) > 1000, strlen($xlsx) . ' bytes');
ok('ZIP 시그니처', str_starts_with($xlsx, "PK\x03\x04"));
ok('중앙 디렉터리 종료 레코드 존재', str_contains($xlsx, "PK\x05\x06"));

$tmpXlsx = sys_get_temp_dir() . '/bc_export_test.xlsx';
file_put_contents($tmpXlsx, $xlsx);
ok('파일로 저장됨', filesize($tmpXlsx) === strlen($xlsx));

// 통계 시트도 만들어 본다
$stats = PurchaseRequest::statistics($year);
$x2 = new XlsxWriter();
$x2->addSheet('요약', ['항목', '값'], [['전체', $stats['status']['TOTAL']]], [20, 12]);
$x2->addSheet('사용처별', ['사용처', '건수'],
    array_map(fn($c) => [$c['name'], (int)$c['cnt']], $stats['by_category']), [20, 12]);
$x2->addSheet('구매담당자별', ['담당자', '건수'],
    array_map(fn($b) => [$b['name'], (int)$b['cnt']], $stats['by_buyer']), [20, 12]);
ok('통계 엑셀 생성', strlen($x2->build()) > 1000);

// 특수문자가 XML 을 깨뜨리지 않는지
$x3 = new XlsxWriter();
$x3->addSheet('시험', ['a', 'b'], [
    ['<script>&"\'', "줄바꿈\n포함"],
    ['0123', 42],
], [10, 10]);
$special = $x3->build();
ok('특수문자 포함 엑셀 생성', strlen($special) > 500);
ok('원시 꺾쇠가 남지 않음', !str_contains($special, '<script>'));

echo "\n  생성된 시험 파일: $tmpXlsx\n";

// ---------------------------------------------------------------------
echo "\n[19] 승인 생략 (검토 권한자가 직접 등록)\n";

// 검토 권한자가 올린 요청을 바로 승인 처리하는 경로
$idS = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '승인 생략 시험',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $jian);
PurchaseRequest::transition($idS, 'approve', $jian,
    ['comment' => '검토 권한자가 직접 등록하여 승인 단계를 생략했습니다.']);

$rS = PurchaseRequest::find($idS);
ok('등록 직후 구매 대기 상태', $rS['status'] === 'APPROVED', $rS['status']);
ok('검토자가 본인으로 기록', $rS['reviewer_id'] === 'jian');
ok('생략 사유가 남음', str_contains((string)$rS['review_comment'], '생략'));

$hs = array_column(PurchaseRequest::history($idS), 'event_code');
ok('이력에 등록과 승인이 모두 남음',
   $hs === ['REQUEST_CREATED', 'REVIEW_APPROVED'], implode(' → ', $hs));

throws('권한 없는 사람은 자기 요청을 승인할 수 없음', function () use ($catId, $byeongmun) {
    $id = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '권한 없는 생략',
        'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
        'deliver_to' => '', 'need_by' => '', 'note' => ''], $byeongmun);
    PurchaseRequest::transition($id, 'approve', $byeongmun);
}, '권한');

// ---------------------------------------------------------------------
echo "\n[20] 구매담당자가 한 명일 때 담당 지정 생략\n";

RoleAssign::replace('BUYER', ['seongcheol'], 'admin');
RoleAssign::clearCache();
ok('구매담당자 1명', count(RoleAssign::byType('BUYER')) === 1);
ok('soleBuyer 가 그 사람을 돌려줌', RoleAssign::soleBuyer()['user_id'] === 'seongcheol');
ok('담당 지정 단계 불필요', bc_needs_assignee() === false);

$id1b = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '1인 담당 시험',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
PurchaseRequest::transition($id1b, 'approve', $jian);
$r1b = PurchaseRequest::find($id1b);

ok('담당 미지정 상태', $r1b['assignee_id'] === null);
$acts = bc_available_actions($r1b, $seongcheol);
ok('담당 지정 버튼이 사라짐', !in_array('assign', $acts, true), implode(',', $acts));
ok('유일한 담당자가 바로 구매 진행 가능',
   in_array('start_purchase', $acts, true), implode(',', $acts));
ok('목록에 그 담당자 이름이 표시됨',
   bc_assignee_label($r1b) === '박성철', bc_assignee_label($r1b));

PurchaseRequest::transition($id1b, 'start_purchase', $seongcheol);
PurchaseRequest::transition($id1b, 'complete', $seongcheol, ['actual_amount' => '5000']);
ok('지정 없이 구비 완료까지 진행', PurchaseRequest::find($id1b)['status'] === 'STOCKED');

// 두 명으로 늘리면 지정 단계가 다시 살아난다
RoleAssign::replace('BUYER', ['seongcheol', 'jian'], 'admin');
RoleAssign::clearCache();
ok('구매담당자 2명이면 지정 단계 부활', bc_needs_assignee() === true);

$id2b = PurchaseRequest::create(['category_id' => $catId, 'item_name' => '2인 담당 시험',
    'quantity' => 1, 'unit' => '개', 'est_amount' => null, 'ref_url' => '',
    'deliver_to' => '', 'need_by' => '', 'note' => ''], $hoyoung);
PurchaseRequest::transition($id2b, 'approve', $jian);
$r2b = PurchaseRequest::find($id2b);
ok('담당 지정 버튼이 다시 보임',
   in_array('assign', bc_available_actions($r2b, $seongcheol), true));
ok('미지정으로 표시', bc_assignee_label($r2b) === '미지정', bc_assignee_label($r2b));

// ---------------------------------------------------------------------
echo "\n";
echo str_repeat('─', 50) . "\n";
printf("결과: %d건 통과, %d건 실패\n", $pass, $fail);
echo str_repeat('─', 50) . "\n\n";

exit($fail > 0 ? 1 : 0);
