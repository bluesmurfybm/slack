<?php
/**
 * GET api/item_suggest.php
 *   작성 폼의 '필요 물품' 칸에 띄울 지난 요청 후보.
 *
 * 파라미터
 *   keyword   물품명 일부. 비우면 최근 요청 순
 *   limit     최대 건수(기본 8, 최대 20)
 *
 * 화면이 이 값을 그대로 입력 칸에 옮겨 담으므로, 다시 쓸 수 있는 항목만
 * 싣는다. 희망 수령일처럼 그때그때 정해야 하는 값은 빼고 보낸다.
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$user = bc_require_login_api();

$rows = PurchaseRequest::suggestItems([
    'keyword'      => bc_param_str('keyword'),
    'requester_id' => $user['id'],
    'limit'        => bc_param_int('limit', 8),
]);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'              => (int)$r['id'],
        'req_no'          => $r['req_no'],
        'item_name'       => $r['item_name'],
        'quantity'        => (int)$r['quantity'],
        'unit'            => $r['unit'],
        'est_amount'      => $r['est_amount'] !== null ? (int)$r['est_amount'] : null,
        'category_id'     => (int)$r['category_id'],
        'category_name'   => $r['category_name'],
        // 사용처가 그 뒤 '사용 안 함' 으로 바뀌었으면 고를 수 없는 값이다.
        // 화면이 그때는 사용처 칸을 건드리지 않고 그대로 둔다.
        'category_active' => (int)$r['category_active'] === 1,
        'deliver_to'      => $r['deliver_to'],
        'ref_url'         => $r['ref_url'],
        'note'            => $r['note'],
        'status'          => $r['status'],
        'status_label'    => bc_status_label($r['status']),
        'status_tone'     => bc_status_tone($r['status']),
        'requester_name'  => $r['requester_name'],
        'is_mine'         => $r['requester_id'] === $user['id'],
        'requested_at'    => bc_date($r['requested_at']),
        'uses'            => (int)$r['uses'],
    ];
}

bc_json_ok(['rows' => $out]);
