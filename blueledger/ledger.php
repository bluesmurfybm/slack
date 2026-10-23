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
 */

require_once __DIR__ . '/lib/ledger_views.php';
require_once __DIR__ . '/lib/ledger_xlsx.php';

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

const LG_PAGES = [
    'home' => '홈', 'accounts' => '기준정보 · 계좌와 카드', 'transactions' => '거래 목록', 'daybook' => '일일출납장',
    'today' => '오늘의 출납장', 'cashbook' => '잔액·자금일보', 'soon' => '준비 중',
    'daybook_xlsx' => '', 'cashbook_xlsx' => '',
];
$p = (string)($_GET['p'] ?? 'home');
if (!isset(LG_PAGES[$p])) $p = 'home';
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
        lg_flash_add([['error', '화면이 오래되었습니다. 새로고침한 뒤 다시 해 주세요.']]);
        lg_redirect($back);
    }
    if ($me['role'] === LG_ROLE_CEO) {
        lg_flash_add([['error', '대표 계정은 확인용이라 보기만 할 수 있어요.']]);
        lg_redirect($back);
    }
    try {
        [$msgs, $to] = match ($p) {
            'accounts' => lg_post_accounts($_POST, $me),
            'transactions' => lg_post_transactions($_POST, $me),
            'daybook' => lg_post_daybook($_POST, $me, $self),
            'today' => lg_post_today($_POST, $me),
            default => [[['error', '이 화면에서는 저장할 수 없어요.']], $back],
        };
    } catch (Throwable $e) {
        error_log('[blueledger] ' . $e);
        [$msgs, $to] = [[['error', '저장하지 못했어요: ' . $e->getMessage()]], $back];
    }
    lg_flash_add($msgs);
    lg_redirect($to);
}

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
$heading = LG_PAGES[$p];
try {
    if ($p === 'home') {
        $heading = '장부 홈';
        $body = lg_view_home(lg_page_home($_GET, $me), $me, $flashes);
    } else {
        $content = match ($p) {
            'accounts' => lg_view_accounts(lg_page_accounts($_GET, $me), $me),
            'transactions' => lg_view_transactions(lg_page_transactions($_GET, $me), $me),
            'daybook' => lg_view_daybook(lg_page_daybook($_GET, $me), $me),
            'today' => lg_view_today($v = lg_page_today($_GET, $me), $me),
            'cashbook' => lg_view_cashbook(lg_page_cashbook($_GET, $me), $me),
            'soon' => lg_view_soon(clip($_GET['name'] ?? '이 메뉴', 40)),
        };
        $heading = match ($p) {
            'today' => '오늘의 출납장 · ' . (int)substr($v['d'], 5, 2) . '월 ' . (int)substr($v['d'], 8, 2) . '일 (' . lg_weekday($v['d']) . ')',
            'soon' => clip($_GET['name'] ?? '준비 중', 40),
            default => LG_PAGES[$p],
        };
        $body = lg_page($prev, $flashes, $content);
    }
} catch (Throwable $e) {
    error_log('[blueledger] ' . $e);
    http_response_code(500);
    $heading = '문제가 생겼어요';
    $body = lg_page($prev, [['error', '화면을 만들지 못했어요: ' . $e->getMessage()]], '');
}
echo lg_layout($p, $heading, $me, $u, $body);
