<?php
/** DB 행 → API 응답 형태 변환. 내부 식별자는 필요한 만큼만 노출한다. */
declare(strict_types=1);

function bc_present_request(array $r, array $user): array
{
    return [
        'id'             => (int)$r['id'],
        'req_no'         => $r['req_no'],
        'category_id'    => (int)$r['category_id'],
        'category_name'  => $r['category_name'],
        'item_name'      => $r['item_name'],
        'quantity'       => (int)$r['quantity'],
        'unit'           => $r['unit'],
        'est_amount'     => $r['est_amount'] !== null ? (int)$r['est_amount'] : null,
        'actual_amount'  => $r['actual_amount'] !== null ? (int)$r['actual_amount'] : null,
        'ref_url'        => $r['ref_url'],
        'deliver_to'     => $r['deliver_to'],
        'need_by'        => bc_date($r['need_by']),
        'note'           => $r['note'],
        'status'         => $r['status'],
        'status_label'   => bc_status_label($r['status']),
        'status_tone'    => bc_status_tone($r['status']),
        'requester_name' => $r['requester_name'],
        'is_mine'        => $r['requester_id'] === $user['id'],
        'requested_at'   => bc_date($r['requested_at'], 'Y-m-d H:i'),
        'reviewer_name'  => $r['reviewer_name'],
        'reviewed_at'    => bc_date($r['reviewed_at'], 'Y-m-d H:i'),
        'review_comment' => $r['review_comment'],
        'assignee_id'    => $r['assignee_id'],
        'assignee_name'  => $r['assignee_name'],
        'assignee_label' => bc_assignee_label($r),
        'is_my_job'      => $r['assignee_id'] === $user['id'],
        'buyer_name'     => $r['buyer_name'],
        'purchasing_at'  => bc_date($r['purchasing_at'], 'Y-m-d H:i'),
        'stocked_at'     => bc_date($r['stocked_at'], 'Y-m-d H:i'),
        'purchase_note'  => $r['purchase_note'],
        'resubmit_count' => (int)$r['resubmit_count'],
        'attach_count'   => Attachment::countFor((int)$r['id']),
        'actions'        => bc_available_actions($r, $user),
    ];
}

function bc_present_history(array $rows): array
{
    $out = [];
    foreach ($rows as $h) {
        $out[] = [
            'event'      => $h['event_code'],
            'label'      => BC_EVENT[$h['event_code']] ?? $h['event_code'],
            'from'       => $h['from_status'] ? bc_status_label($h['from_status']) : null,
            'to'         => $h['to_status'] ? bc_status_label($h['to_status']) : null,
            'actor'      => $h['actor_name'],
            'comment'    => $h['comment'],
            'created_at' => bc_date($h['created_at'], 'Y-m-d H:i'),
        ];
    }
    return $out;
}
