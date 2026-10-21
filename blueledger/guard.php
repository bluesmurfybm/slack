<?php
/**
 * BlueLedger 인증 가드 + 출장여비 상수.
 *
 *  파이썬 버전은 이름+비밀번호(처음 1234)로 따로 로그인했지만, 이 모듈은 포털 안에 있으므로
 *  포털 세션을 그대로 쓴다(learn 과 같은 방식). 직원 명단도 portal_users 가 원본이다 —
 *  입·퇴사 때 고칠 곳이 포털 한 군데로 줄고, 출장여비 전용 비밀번호가 사라진다.
 */

date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../core/auth.php';   // 세션 시작 + current_portal_user()
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/db.php';

const ROLE_ADMIN    = 'admin';      // 정산 담당 — 전체 조회·수정·삭제, 정산, 설정
const ROLE_APPROVER = 'approver';   // 대표 승인 — 전체 조회, 승인·반려만
const ROLE_STAFF    = 'staff';      // 직원 — 본인 청구만, 승인 전까지만 수정
const ROLES = [ROLE_ADMIN, ROLE_APPROVER, ROLE_STAFF];

// 최초 기동 때 bl_travel_roles 에 심는다. 그 뒤로는 설정 화면에서 바꾼 DB 값이 원본이다.
const BL_SEED_ROLES = [
    'jian@bluesoft.co.kr'  => ROLE_ADMIN,
    'kimhy@bluesoft.co.kr' => ROLE_APPROVER,
];

const APPROVALS  = ['승인대기', '승인', '반려'];
const STATUSES   = ['미처리', '처리완료'];
const RC_KINDS   = ['transport', 'toll', 'meal', 'etc'];
const PERIODS    = ['당일', '1박2일', '2박3일', '3박4일', '4박5일', '1주 이상'];
const PAY_METHODS = ['', '계좌이체', '법인카드', '현금', '법인카드+계좌이체'];

/** 화면(HTML)용 — 미로그인이면 포털 로그인 화면으로 보낸다 */
function bl_require_page_login() {
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php?need_login=blueledger');
        exit;
    }
    return $u;
}

/** API 용 — 로그인했으면 ['email','name','color','role'], 아니면 null */
function bl_identity() {
    static $ident = null;
    static $done  = false;
    if ($done) return $ident;
    $done = true;
    $u = current_portal_user();
    if ($u) {
        $email = strtolower($u['email']);
        $ident = [
            'email' => $email,
            'name'  => $u['name'],
            'color' => user_color($u),
            'role'  => bl_role_of($email),
        ];
    }
    return $ident;
}

function bl_role_of($email) {
    $st = bl_db()->prepare("SELECT role FROM bl_travel_roles WHERE email=?");
    $st->execute([strtolower((string)$email)]);
    $role = $st->fetchColumn();
    return in_array($role, [ROLE_ADMIN, ROLE_APPROVER], true) ? $role : ROLE_STAFF;
}

/** 직원 명단(출장자 고르기·동행자 버튼). 포털 계정이 곧 명단이다. */
function bl_members() {
    static $rows = null;
    if ($rows !== null) return $rows;
    $rows = bl_db()->query("SELECT name, LOWER(email) AS email FROM portal_users ORDER BY id")->fetchAll();
    return $rows;
}

function bl_can_see_all(array $me) {
    return in_array($me['role'], [ROLE_ADMIN, ROLE_APPROVER], true);
}

function bl_require_admin(array $me, $what = '이 일') {
    if ($me['role'] !== ROLE_ADMIN) throw new BlError("{$what}은(는) 정산 담당자만 할 수 있습니다", 403);
}

/* ---------- CSRF ----------
 * 세션 쿠키가 SameSite=Lax 라 다른 사이트의 POST 에는 원래 실리지 않지만, 외부에 공개되는
 * 서버이므로 한 겹 더 둔다. 토큰은 화면(travel.php)이 meta 로 받아 모든 쓰기 요청 머리에 싣는다.
 */
function bl_csrf_token() {
    if (empty($_SESSION['bl_csrf'])) $_SESSION['bl_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['bl_csrf'];
}

function bl_verify_csrf() {
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['bl_csrf'] ?? ''), $sent)) {
        throw new BlError('화면이 오래되었습니다. 새로고침한 뒤 다시 해 주세요', 419);
    }
}
