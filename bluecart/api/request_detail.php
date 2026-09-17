<?php
/** GET api/request_detail.php?id=123 — 상세 + 처리 이력 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$user = bc_require_login_api();
$id   = bc_param_int('id');
if (!$id) {
    bc_json_error('요청 번호가 없습니다.');
}

$req = PurchaseRequest::find($id);
if (!$req) {
    bc_json_error('요청을 찾을 수 없습니다.', 404);
}

bc_json_ok([
    'request' => bc_present_request($req, $user),
    'history' => bc_present_history(PurchaseRequest::history($id)),
]);
