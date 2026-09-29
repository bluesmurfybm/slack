<?php
/**
 * POST api/request_delete.php — 요청 영구 삭제 (관리자 전용)
 * 본문: id, confirm(요청번호를 그대로), reason
 *
 * 되돌릴 수 없는 처리라 요청번호를 직접 적게 한다. 목록에서는 승인·반려
 * 같은 처리 버튼과 한 줄에 놓이므로, 잘못 눌러도 여기서 걸려야 한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bc_require_post();
$user = bc_require_admin_api();
bc_verify_csrf();

$id = bc_param_int('id');
if (!$id) {
    bc_json_error('삭제할 요청을 지정하세요.');
}

$req = PurchaseRequest::find($id);
if (!$req) {
    bc_json_error('요청을 찾을 수 없습니다.', 404);
}

if (bc_param_str('confirm') !== $req['req_no']) {
    bc_json_error(sprintf('삭제하려면 요청번호 %s 을(를) 그대로 입력하세요.', $req['req_no']));
}

$deleted = PurchaseRequest::delete($id, $user, bc_param_str('reason'));

bc_json_ok([
    'message' => sprintf('%s · %s 요청을 삭제했습니다.', $deleted['req_no'], $deleted['item_name']),
]);
