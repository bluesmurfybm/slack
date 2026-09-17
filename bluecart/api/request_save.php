<?php
/**
 * POST api/request_save.php — 신규 등록 / 본인 요청 수정
 * 본문: id(수정 시), category_id, item_name, quantity, unit,
 *       est_amount, ref_url, deliver_to, need_by, note
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bc_require_post();
$user = bc_require_login_api();
bc_verify_csrf();

$data = [
    'category_id' => bc_param_int('category_id'),
    'item_name'   => bc_param_str('item_name'),
    'quantity'    => bc_param_int('quantity', 1),
    'unit'        => bc_param_str('unit', '개'),
    'est_amount'  => bc_param_str('est_amount') !== '' ? bc_param_int('est_amount') : null,
    'ref_url'     => bc_param_str('ref_url'),
    'deliver_to'  => bc_param_str('deliver_to'),
    'need_by'     => bc_param_str('need_by'),
    'note'        => bc_param_str('note'),
];

$id = bc_param_int('id');

if ($id) {
    PurchaseRequest::updateByOwner($id, $data, $user);
    $message = '요청 내용을 수정했습니다.';
} else {
    // 검토 권한자가 직접 올릴 때는 승인 단계를 건너뛸 수 있다.
    // 자기가 올린 요청을 자기가 검토하는 셈이라 한 단계가 비어 있기 때문.
    $skipReview = bc_param_str('skip_review') === '1';
    if ($skipReview && !array_intersect(bc_roles_of($user['id']), ['REVIEWER', 'ADMIN'])) {
        bc_json_error('승인 단계를 생략하려면 검토승인 권한이 필요합니다.', 403);
    }

    $id = PurchaseRequest::create($data, $user);

    if ($skipReview) {
        // 등록 이력을 남긴 뒤 곧바로 승인 처리한다.
        // transition() 이 권한과 상태를 다시 확인하므로 우회가 아니다.
        PurchaseRequest::transition($id, 'approve', $user, [
            'comment'     => '검토 권한자가 직접 등록하여 승인 단계를 생략했습니다.',
            'assignee_id' => bc_param_str('assignee_id'),
        ]);
        $message = '구매 요청을 등록하고 바로 구매 대기로 넘겼습니다.';
    } else {
        // 등록 즉시 검토승인자에게 알림
        Notifier::dispatch('REQUEST_CREATED', PurchaseRequest::find($id), $user);
        $message = '구매 요청을 등록했습니다. 검토승인자에게 알림을 보냈습니다.';
    }
}

bc_json_ok([
    'id'      => $id,
    'message' => $message,
    'request' => bc_present_request(PurchaseRequest::find($id), $user),
]);
