<?php
/** GET api/members.php?q=검색어 — 역할 배정용 구성원 목록 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bc_require_login_api();
if (!bc_can_see_admin()) {
    bc_json_error('관리자 권한이 필요합니다.', 403);
}

$rows = bc_directory_all(bc_param_str('q'));
bc_json_ok(['rows' => array_map(fn($r) => [
    'id'    => $r['id'],
    'name'  => $r['name'],
    'email' => $r['email'],
], $rows)]);
