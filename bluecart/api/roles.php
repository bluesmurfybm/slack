<?php
/**
 * GET  api/roles.php          현재 역할 배정 현황
 * POST api/roles.php          role_type + user_ids[] 통째로 교체 (관리자)
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    bc_require_login_api();
    if (!bc_can_see_admin()) {
        bc_json_error('관리자 권한이 필요합니다.', 403);
    }
    bc_json_ok([
        'REVIEWER' => RoleAssign::byType('REVIEWER'),
        'BUYER'    => RoleAssign::byType('BUYER'),
        'ADMIN'    => RoleAssign::byType('ADMIN'),
    ]);
}

$user = bc_require_admin_api();
bc_verify_csrf();

$roleType = bc_param_str('role_type');
if (!in_array($roleType, RoleAssign::TYPES, true)) {
    bc_json_error('알 수 없는 역할입니다.');
}
$userIds = bc_param('user_ids', []);
if (!is_array($userIds)) {
    $userIds = array_filter(array_map('trim', explode(',', (string)$userIds)));
}

// 관리자를 전부 비우면 아무도 설정을 못 바꾸게 되므로 막는다.
if ($roleType === 'ADMIN' && !$userIds && !bc_config('iworks.superadmins')) {
    bc_json_error('관리자는 최소 1명 이상 있어야 합니다.');
}

RoleAssign::replace($roleType, $userIds, $user['id']);

bc_json_ok([
    'message' => sprintf('%s %d명을 저장했습니다.', BC_ROLE_LABEL[$roleType] ?? $roleType, count($userIds)),
    'rows'    => RoleAssign::byType($roleType),
]);
