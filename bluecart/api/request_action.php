<?php
/**
 * POST api/request_action.php — 워크플로 처리
 * 본문: id, action(approve|reject|start_purchase|complete|resubmit|cancel),
 *       comment, purchase_note, actual_amount
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bc_require_post();
$user = bc_require_login_api();
bc_verify_csrf();

$id     = bc_param_int('id');
$action = bc_param_str('action');
if (!$id || $action === '') {
    bc_json_error('처리할 요청과 동작을 지정하세요.');
}

$updated = PurchaseRequest::transition($id, $action, $user, [
    'comment'       => bc_param_str('comment'),
    'purchase_note' => bc_param_str('purchase_note') ?: null,
    'actual_amount' => bc_param_str('actual_amount'),
    'assignee_id'   => bc_param_str('assignee_id'),
]);

bc_json_ok([
    'message' => sprintf('%s 처리했습니다.', BC_ACTIONS[$action]['label']),
    'request' => bc_present_request($updated, $user),
]);
