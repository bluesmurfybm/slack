<?php
/**
 * iworks 포털 인증 연동.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 여기가 유일한 통합 지점입니다.                                     │
 * │                                                                  │
 * │ 포털 안에 있을 때는 learn/dti 와 같은 방식으로 붙습니다.            │
 * │   <포털>/core/auth.php → 세션(BLUEIWORK_SESSID) + current_portal_user()│
 * │   portal_users 테이블이 곧 구성원 명단입니다.                      │
 * │                                                                  │
 * │ 사용자 식별자는 이메일을 씁니다. 포털의 다른 모듈(learn 등)도       │
 * │ 이메일을 기준으로 관리자 명단을 관리하고 있어 맞췄습니다.           │
 * │                                                                  │
 * │ 포털이 없으면(단독 실행) config 의 session_keys 로 떨어집니다.      │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);

/**
 * 현재 로그인 사용자. 비로그인이면 null.
 *
 * @return array{id:string,name:string,email:?string}|null
 */
function bc_current_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }
    $cached = true;

    // ---- (A) 포털 연동 -------------------------------------------------
    // bootstrap 이 포털 auth.php 를 먼저 읽었으면 이 함수가 있다.
    if (function_exists('current_portal_user')) {
        $row = current_portal_user();
        if ($row) {
            $user = [
                'id'    => (string)$row['email'],   // 식별자는 이메일
                'name'  => (string)$row['name'],
                'email' => (string)$row['email'],
                // 포털 상단바 아바타와 같은 색을 쓰기 위해 함께 들고 다닌다.
                'color' => function_exists('user_color') ? user_color($row) : null,
            ];
        }
        return $user;   // 포털이 있으면 여기서 끝. 아래 세션 탐색으로 내려가지 않는다.
    }

    // ---- (B) 단독 실행: 세션 키 탐색 ------------------------------------
    $keys = bc_config('iworks.session_keys', []);
    $id   = bc_session_pick($keys['id']    ?? []);
    if ($id === null || $id === '') {
        return null;
    }
    $name  = bc_session_pick($keys['name']  ?? []) ?? $id;
    $email = bc_session_pick($keys['email'] ?? []);

    $user = [
        'id'    => (string)$id,
        'name'  => (string)$name,
        'email' => $email !== null ? (string)$email : null,
    ];

    // 세션에 이름/이메일이 없으면 회원 테이블에서 보충
    if ($user['email'] === null || $user['name'] === $user['id']) {
        $row = bc_directory_find($user['id']);
        if ($row) {
            $user['name']  = $row['name'] ?: $user['name'];
            $user['email'] = $user['email'] ?: $row['email'];
        }
    }

    return $user;
}

function bc_session_pick(array $candidates): ?string
{
    foreach ($candidates as $key) {
        if (isset($_SESSION[$key]) && $_SESSION[$key] !== '') {
            return (string)$_SESSION[$key];
        }
    }
    return null;
}

/**
 * 로그인 필수. 화면 진입점에서 호출.
 */
function bc_require_login(): array
{
    $user = bc_current_user();
    if ($user === null) {
        // 포털은 ?need_login=<모듈키> 를 받아 왜 튕겼는지 알려 준다.
        $login = BC_PORTAL_ROOT !== ''
            ? '../index.php?need_login=' . rawurlencode((string)bc_config('iworks.module_key', 'bluecart'))
            : (string)bc_config('iworks.login_url', '/');
        header('Location: ' . $login);
        exit;
    }
    return $user;
}

/**
 * API 진입점용. 비로그인이면 401 JSON.
 */
function bc_require_login_api(): array
{
    $user = bc_current_user();
    if ($user === null) {
        bc_json_error('로그인이 필요합니다.', 401);
    }
    return $user;
}

// ---------------------------------------------------------------------
// 역할 판정
// ---------------------------------------------------------------------

/**
 * 특정 사용자의 역할 코드 배열.
 *
 * 사용자 ID 를 명시적으로 받는다. 웹 요청 한 건에서는 항상 로그인 사용자
 * 하나뿐이지만, 배치나 테스트에서는 여러 사용자를 번갈아 다루기 때문에
 * "현재 사용자" 캐시 하나로는 잘못된 결과가 나온다.
 *
 * @return string[] ['REVIEWER','BUYER','ADMIN','STAFF']
 */
function bc_roles_of(string $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $roles = [];
    $rows = bc_fetch_all(
        'SELECT role_type FROM bc_role_assign WHERE user_id = ? AND is_active = 1',
        [$userId]
    );
    foreach ($rows as $r) {
        $roles[] = $r['role_type'];
    }

    // config 에 등록된 최초 관리자
    if (in_array($userId, bc_config('iworks.superadmins', []), true)) {
        $roles[] = 'ADMIN';
    }

    // 검토승인자/구매담당자는 관리자 화면 접근 권한을 가진다.
    if (array_intersect($roles, ['REVIEWER', 'BUYER']) && !in_array('ADMIN', $roles, true)) {
        $roles[] = 'STAFF';
    }

    return $cache[$userId] = array_values(array_unique($roles));
}

/** 현재 로그인 사용자의 역할. */
function bc_my_roles(): array
{
    $user = bc_current_user();
    return $user === null ? [] : bc_roles_of($user['id']);
}

function bc_has_role(string $role): bool
{
    return in_array($role, bc_my_roles(), true);
}

/** 관리자 탭을 볼 수 있는가 (ADMIN 또는 처리 역할 보유자) */
function bc_can_see_admin(): bool
{
    return (bool)array_intersect(bc_my_roles(), ['ADMIN', 'STAFF', 'REVIEWER', 'BUYER']);
}

/** 역할/설정 변경 등 관리 기능은 ADMIN 만 */
function bc_require_admin_api(): array
{
    $user = bc_require_login_api();
    if (!bc_has_role('ADMIN')) {
        bc_json_error('관리자 권한이 필요합니다.', 403);
    }
    return $user;
}
