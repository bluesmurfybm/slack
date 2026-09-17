<?php
/**
 * GET  api/categories.php              활성 카테고리 목록 (모든 로그인 사용자)
 * GET  api/categories.php?all=1        비활성 포함 (관리자)
 * POST api/categories.php              op=create|update|delete (관리자)
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    bc_require_login_api();
    $all = bc_param_str('all') === '1' && bc_can_see_admin();
    bc_json_ok(['rows' => Category::all(!$all)]);
}

$user = bc_require_admin_api();
bc_verify_csrf();

$op = bc_param_str('op');
switch ($op) {
    case 'create':
        $code = strtoupper(bc_param_str('code'));
        $name = bc_param_str('name');
        if (!preg_match('/^[A-Z0-9_]{2,40}$/', $code)) {
            bc_json_error('코드는 영문 대문자, 숫자, 밑줄 2~40자로 입력하세요.');
        }
        if ($name === '') {
            bc_json_error('표시명을 입력하세요.');
        }
        try {
            $id = Category::create($code, $name, bc_param_int('sort_order', 0));
        } catch (PDOException $e) {
            bc_json_error('이미 있는 코드입니다.');
        }
        bc_json_ok(['id' => $id, 'message' => '카테고리를 추가했습니다.']);

    case 'update':
        $id = bc_param_int('id');
        if (!$id || !Category::find($id)) {
            bc_json_error('카테고리를 찾을 수 없습니다.', 404);
        }
        $name = bc_param_str('name');
        if ($name === '') {
            bc_json_error('표시명을 입력하세요.');
        }
        Category::update($id, $name, bc_param_int('sort_order', 0), bc_param_str('is_active', '1') === '1');
        bc_json_ok(['message' => '카테고리를 수정했습니다.']);

    case 'delete':
        $id = bc_param_int('id');
        if (!$id || !Category::find($id)) {
            bc_json_error('카테고리를 찾을 수 없습니다.', 404);
        }
        if (!Category::delete($id)) {
            bc_json_error('이미 사용 중인 카테고리는 삭제할 수 없습니다. 대신 사용 안 함으로 바꾸세요.');
        }
        bc_json_ok(['message' => '카테고리를 삭제했습니다.']);

    default:
        bc_json_error('알 수 없는 요청입니다.');
}
