<?php
/**
 * BlueLedger 회계 장부 화면 — 입구 하나(ledger.php?p=화면).
 *
 *  출장여비(travel.php)는 화면 한 장 + JSON API 이지만, 장부는 파이썬 버전처럼 화면마다 서버가 HTML 을
 *  만들어 보낸다. 표·입력칸이 많은 화면을 그대로 옮기고, 파이썬과 숫자를 맞춰 보기 쉽게 하려는 것이다.
 *
 *  출입: 포털 로그인 + 출입 명단(bl_ledger_roles). 요청마다 서버가 검사한다.
 *   - 담당자(clerk): 모든 기능
 *   - 대표(ceo): 보기만. 저장·삭제 요청은 서버가 막는다.
 *  저장 요청(POST)은 모두 CSRF 토큰(_csrf)이 맞아야 한다.
 *
 *  화면 목록은 lg_routes() 한 곳에 있다: p => [제목, 값 만들기, 그리기, 저장 처리, 왼쪽 메뉴에서 켤 항목].
 */

require_once __DIR__ . '/lib/ledger_views.php';
require_once __DIR__ . '/lib/ledger_xlsx.php';
require_once __DIR__ . '/lib/views_import.php';
require_once __DIR__ . '/lib/views_classify.php';
require_once __DIR__ . '/lib/ledger_cards.php';

// 포털 카드 key 는 'ledger'(출장여비 BlueExpense 가 'blueledger'). 로그인 안내에 BlueLedger 이름이 나오게 한다.
$u = current_portal_user();
if (!$u) {
    header('Location: ../index.php?need_login=ledger');
    exit;
}
$email = strtolower($u['email']);
$role = lg_role_of($email);
if (!$role) {
    http_response_code(403);
    echo lg_view_denied($u);
    exit;
}
$me = ['email' => $email, 'name' => $u['name'], 'role' => $role];

function lg_routes() {
    return [
        'home'          => ['장부 홈', 'lg_page_home', null, null, 'home'],
        'today'         => ['오늘의 출납장', 'lg_page_today', 'lg_view_today', 'lg_post_today', 'today'],
        'daybook'       => ['일일출납장', 'lg_page_daybook', 'lg_view_daybook', 'lg_post_daybook', 'daybook'],
        'cashbook'      => ['잔액·자금일보', 'lg_page_cashbook', 'lg_view_cashbook', null, 'cashbook'],
        'transactions'  => ['거래 목록', 'lg_page_transactions', 'lg_view_transactions', 'lg_post_transactions', 'transactions'],
        'accounts'      => ['기준정보 · 계좌와 카드', 'lg_page_accounts', 'lg_view_accounts', 'lg_post_accounts', 'accounts'],
        'import'        => ['거래 가져오기', 'lg_page_import', 'lg_view_import', 'lg_post_import', 'import'],
        'import_map'    => ['거래 가져오기 · 열 맞추기', 'lg_page_import_map', 'lg_view_import_map', 'lg_post_import_map', 'import'],
        'import_done'   => ['거래 가져오기 · 완료', 'lg_page_import_done', 'lg_view_import_done', null, 'import'],
        'ledger_import' => ['일일출납장 가져오기', 'lg_page_ledger_import', 'lg_view_ledger_import', 'lg_post_ledger_import', 'import'],
        'ledger_done'   => ['일일출납장 가져오기 · 결과', 'lg_page_import_done', 'lg_view_ledger_done', null, 'import'],
        'inbox'         => ['여러 파일 한 번에 가져오기', 'lg_page_inbox', 'lg_view_inbox', 'lg_post_inbox', 'import'],
        'inbox_upload'  => ['여러 파일 한 번에 가져오기', null, null, 'lg_post_inbox_upload', 'import'],
        'inbox_done'    => ['한 번에 가져오기 · 결과', 'lg_page_import_done', 'lg_view_inbox_done', null, 'import'],
        'duplicates'    => ['중복 거래 찾기', 'lg_page_duplicates', 'lg_view_duplicates', 'lg_post_duplicates', 'import'],
        'imports'       => ['가져온 파일별로 보기', 'lg_page_imports', 'lg_view_imports', 'lg_post_imports', 'import'],
        'classify_groups' => ['분류 · 적요별로 묶어서', 'lg_page_classify_groups', 'lg_view_classify_groups', 'lg_post_classify_groups', 'classify_groups'],
        'classify'      => ['분류', 'lg_page_classify', 'lg_view_classify', 'lg_post_classify', 'classify_groups'],
        'categories'    => ['계정과목·규칙 관리', 'lg_page_categories', 'lg_view_categories', 'lg_post_categories', 'classify_groups'],
        'split'         => ['거래 나누기', 'lg_page_split', 'lg_view_split', 'lg_post_split', 'classify_groups'],
        'manual'        => ['수기 입력', 'lg_page_manual', 'lg_view_manual', 'lg_post_manual', 'manual'],
        'card_check'    => ['카드대금 맞춰보기', 'lg_page_card_check', 'lg_view_card_check', 'lg_post_card_check', 'accounts'],
        'soon'          => ['준비 중', null, null, null, 'soon'],
        'daybook_xlsx'  => ['', null, null, null, ''],
        'cashbook_xlsx' => ['', null, null, null, ''],
    ];
}

$routes = lg_routes();
$p = (string)($_GET['p'] ?? 'home');
if (!isset($routes[$p])) $p = 'home';
[$title, $pageFn, $viewFn, $postFn, $nav] = $routes[$p];
$self = 'ledger.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

function lg_flash_add(array $msgs) {
    foreach ($msgs as $m) $_SESSION['bl_ledger_flash'][] = $m;
}

function lg_flash_take() {
    $f = $_SESSION['bl_ledger_flash'] ?? [];
    unset($_SESSION['bl_ledger_flash']);
    return $f;
}

/** 이 서버의 장부 화면에서 왔으면 그 주소('ledger.php?...'), 아니면 '' */
function lg_ref_local() {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '' || parse_url($ref, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)
        || !str_ends_with((string)parse_url($ref, PHP_URL_PATH), '/blueledger/ledger.php')) return '';
    $q = (string)parse_url($ref, PHP_URL_QUERY);
    return lg_safe_back('ledger.php' . ($q !== '' ? "?$q" : ''));
}

function lg_redirect($url) {
    header('Location: ' . $url, true, 303);
    exit;
}

/* ---------- 저장 요청 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = lg_ref_local() ?: $self;
    if (!hash_equals((string)($_SESSION['bl_csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        lg_flash_add([['error', '화면이 오래되었거나 파일이 너무 커요. 새로고침한 뒤 다시 해 주세요.']]);
        lg_redirect($back);
    }
    if ($me['role'] === LG_ROLE_CEO) {
        lg_flash_add([['error', '대표 계정은 확인용이라 보기만 할 수 있어요.']]);
        lg_redirect($back);
    }
    try {
        [$msgs, $to] = $postFn ? $postFn($_POST, $me, $self) : [[['error', '이 화면에서는 저장할 수 없어요.']], $back];
    } catch (Throwable $e) {
        error_log('[blueledger] ' . $e);
        [$msgs, $to] = [[['error', '저장하지 못했어요: ' . $e->getMessage()]], $back];
    }
    lg_flash_add($msgs);
    lg_redirect($to);
}
if (!$pageFn && $postFn) lg_redirect(lg_url($nav));       // 저장 전용 주소를 그냥 열면 해당 화면으로

/* ---------- 엑셀 받기 */
if ($p === 'daybook_xlsx' || $p === 'cashbook_xlsx') {
    session_write_close();
    [$wb, $name] = $p === 'daybook_xlsx' ? lg_daybook_book($_GET, $u['name']) : lg_cashbook_book($_GET, $u['name']);
    $wb->download($name);
}

/* ---------- [← 이전 화면] — 다른 장부 화면에서 들어왔으면 그곳을 기억한다(같은 화면에서 저장·조회하면 처음 들어온 곳 유지) */
if ($from = lg_ref_local()) {
    parse_str((string)parse_url($from, PHP_URL_QUERY), $rq);
    if (($rq['p'] ?? 'home') !== $p) $_SESSION['bl_ledger_bk'][$p] = $from;
}
$prev = lg_safe_back($_SESSION['bl_ledger_bk'][$p] ?? '');
$flashes = lg_flash_take();

/* ---------- 화면 */
$heading = $title;
try {
    if ($p === 'home') {
        $body = lg_view_home(lg_page_home($_GET, $me), $me, $flashes);
    } elseif ($p === 'soon') {
        $heading = clip($_GET['name'] ?? '준비 중', 40);
        $body = lg_page($prev, $flashes, lg_view_soon($heading));
    } else {
        $v = $pageFn($_GET, $me);
        if (isset($v['__redirect'])) {          // 화면 함수가 다른 화면으로 보냄 (예: 나눈 조각 → 원래 거래)
            lg_flash_add($v['__flash'] ?? []);
            lg_redirect($v['__redirect']);
        }
        if (isset($v['__heading'])) $heading = $v['__heading'];
        if ($p === 'today') $heading = '오늘의 출납장 · ' . (int)substr($v['d'], 5, 2) . '월 ' . (int)substr($v['d'], 8, 2) . '일 (' . lg_weekday($v['d']) . ')';
        $body = lg_page($prev, $flashes, $viewFn($v, $me));
    }
} catch (Throwable $e) {
    error_log('[blueledger] ' . $e);
    http_response_code(500);
    $heading = '문제가 생겼어요';
    $body = lg_page($prev, [['error', '화면을 만들지 못했어요: ' . $e->getMessage()]], '');
}
echo lg_layout($p === 'soon' ? 'soon' : $nav, $heading, $me, $u, $body);
