<?php

function dti_route_scores(array $ctx, array $req): array {
    if ($req['method'] !== 'GET' || ($req['seg'][1] ?? null) !== null) {
        throw new DtiError('없는 API 입니다', 404);
    }
    dti_require_admin($ctx);

    $start = dti_score_date_param($req['query']['start'] ?? '');
    $end = dti_score_date_param($req['query']['end'] ?? '');
    $members = dti_members_all($ctx['pdo'], $ctx['config']);

    return dti_json(dti_score_summary($ctx['pdo'], $members, $start, $end));
}

function dti_score_date_param($value): string {
    $date = trim((string)$value);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new DtiError('기간은 YYYY-MM-DD 형식이어야 합니다', 422);
    }
    return $date;
}
