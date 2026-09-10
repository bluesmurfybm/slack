<?php
/**
 * learn(BlueLearn) 인증 가드 + 도메인 상수.
 *
 *  FastAPI 버전은 포털이 심어 준 SSO 쿠키(blueiwork_id)를 직접 검증했지만, 이 모듈은
 *  포털과 같은 PHP 앱 안에 있으므로 세션을 그대로 쓴다(access 모듈과 같은 방식).
 *  구성원 명단도 코드에 박지 않고 포털의 portal_users 를 본다 — 입·퇴사 때 고칠 곳이
 *  한 군데로 줄어든다.
 */

date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../auth.php';   // 세션 시작 + current_portal_user()
require_once __DIR__ . '/lib/http.php';  // LearnError

const LEVELS = ['초급', '중급', '고급'];

const ACCOUNT_COMPANY  = '회사계정';
const ACCOUNT_PERSONAL = '개인계정';
const ACCOUNT_TYPES    = [ACCOUNT_COMPANY, ACCOUNT_PERSONAL];

const PROGRESSES = ['시작전', '진행중', '완료'];

// 관리자 명단은 화면에서 바뀌지만(learn_admins), 이 둘은 코드에 고정한다.
// DB 가 비거나 잘못 저장돼도 관리자 없는 상태로 잠기지 않게 하는 안전장치다.
const OWNER_EMAILS = ['kimhy@bluesoft.co.kr', 'venus@bluesoft.co.kr'];

// 최초 기동 때 learn_admins 에 심을 초기 명단. 그 뒤로는 DB 가 원본이다.
const SEED_ADMINS = ['jian@bluesoft.co.kr', 'kimhy@bluesoft.co.kr', 'venus@bluesoft.co.kr'];

/** 화면(HTML)용 — 미로그인이면 포털 로그인 화면으로 보낸다 */
function learn_require_page_login() {
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php');
        exit;
    }
    return $u;
}

/** API 용 — 로그인했으면 ['email','name','color'], 아니면 null */
function learn_identity() {
    static $ident = null;
    static $done  = false;
    if ($done) return $ident;
    $done = true;
    $u = current_portal_user();
    if ($u) {
        $ident = ['email' => $u['email'], 'name' => $u['name'], 'color' => user_color($u)];
    }
    return $ident;
}

/** 구성원 명단. 포털 계정이 곧 명단이다. */
function learn_members() {
    static $rows = null;
    if ($rows !== null) return $rows;
    $rows = [];
    foreach (portal_db()->query("SELECT name, email FROM portal_users ORDER BY id")->fetchAll() as $r) {
        $rows[] = ['name' => $r['name'], 'email' => $r['email']];
    }
    return $rows;
}

function learn_email_to_name($email) {
    foreach (learn_members() as $m) {
        if (strcasecmp($m['email'], $email) === 0) return $m['name'];
    }
    return '';
}

function learn_is_owner($email) {
    if (!$email) return false;
    return in_array(strtolower($email), array_map('strtolower', OWNER_EMAILS), true);
}

/**
 * DB 명단 + 고정 관리자. 고정 관리자는 DB 에 없어도 항상 포함된다.
 * 명단을 고친 직후에는 $reload 로 캐시를 갈아끼워야 같은 요청 안에서 판정이 맞는다.
 */
function learn_admin_emails($reload = false) {
    static $set = null;
    if ($set === null || $reload) {
        $rows = learn_db()->query("SELECT email FROM learn_admins")->fetchAll(PDO::FETCH_COLUMN);
        $set  = array_values(array_unique(array_map('strtolower',
                    array_merge($rows, OWNER_EMAILS))));
    }
    return $set;
}

function learn_is_admin($email) {
    return $email && in_array(strtolower($email), learn_admin_emails(), true);
}

function learn_require_admin_action(array $identity) {
    if (!learn_is_admin($identity['email'])) {
        throw new LearnError('관리자만 할 수 있습니다', 403);
    }
}
