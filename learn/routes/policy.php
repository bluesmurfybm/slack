<?php
/** 환급 정책. 켜면 값이 반드시 있어야 한다 — 값 없이 켜면 조용히 아무 검사도 안 하게 된다. */

const POLICY_PAIRS = [
    ['partial_enabled',        'partial_cap',         '환급 기준 금액'],
    ['annual_amount_enabled',  'annual_amount_limit', '연간 환급 한도'],
    ['annual_count_enabled',   'annual_count_limit',  '연간 신청 건수'],
    ['claim_deadline_enabled', 'claim_deadline_days', '청구 기한'],
];

function learn_route_policy(PDO $pdo, array $identity, $method) {
    if ($method === 'GET') jsend(learn_policy($pdo));

    if ($method !== 'PUT') throw new LearnError('없는 API 입니다', 404);
    learn_require_admin_action($identity);

    $body  = body_json();
    $patch = [];
    foreach (POLICY_PAIRS as [$flag, $value, $label]) {
        $on  = to_flag($body[$flag] ?? false);
        $num = want_int($body, $value, $label);
        if ($on && !$num) throw new LearnError("{$label}을(를) 입력해 주세요", 422);
        $patch[$flag]  = $on;
        $patch[$value] = $num;
    }
    $patch['updated_by'] = $identity['email'];
    $patch['updated_at'] = now_stamp();

    $set = [];
    foreach (array_keys($patch) as $c) $set[] = "`$c`=?";
    $pdo->prepare("UPDATE learn_policy SET " . implode(',', $set) . " WHERE id=?")
        ->execute(array_merge(array_values($patch), [POLICY_ID]));

    jsend(learn_policy_reload($pdo));
}
