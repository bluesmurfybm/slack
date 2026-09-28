<?php

function dti_route_rounds(array $ctx, array $req): array {
    $rid = $req['seg'][1] ?? null;

    if ($rid === null) {
        return match ($req['method']) {
            'GET' => dti_json(dti_round_all($ctx['pdo'])),
            'POST' => dti_round_create($ctx, $req['body']),
            default => throw new DtiError('없는 API 입니다', 404),
        };
    }

    return match ([$req['method'], $req['seg'][2] ?? null]) {
        ['PUT', null] => dti_round_edit($ctx, (int)$rid, $req['body']),
        ['DELETE', null] => dti_round_destroy($ctx, (int)$rid),
        ['POST', 'issue'] => dti_round_issue($ctx, (int)$rid, $req['body']),
        ['POST', 'topics'] => dti_round_topics($ctx, (int)$rid, $req['body']),
        default => throw new DtiError('없는 API 입니다', 404),
    };
}

function dti_round_create(array $ctx, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $round = [
        'id' => null,
        'no' => dti_want_round_no($body['no'] ?? null),
        'title' => dti_want_str($body, 'title', '회차 이름'),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    if (dti_round_no_taken($pdo, $round['no'])) {
        throw new DtiError('이미 있는 회차 번호입니다', 409);
    }
    dti_round_insert($pdo, $round);

    return dti_json($round, 201);
}

function dti_round_edit(array $ctx, int $rid, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $round = dti_round_find_or_fail($pdo, $rid);
    if (array_key_exists('no', $body)) {
        $round['no'] = dti_want_round_no($body['no']);
        if (dti_round_no_taken($pdo, $round['no'], $rid)) {
            throw new DtiError('이미 있는 회차 번호입니다', 409);
        }
    }
    if (array_key_exists('title', $body)) $round['title'] = dti_want_str($body, 'title', '회차 이름');
    dti_round_update($pdo, $round);

    return dti_json($round);
}

function dti_round_destroy(array $ctx, int $rid): array {
    dti_require_admin($ctx);
    dti_round_find_or_fail($ctx['pdo'], $rid);
    dti_round_delete($ctx['pdo'], $rid);

    return dti_json(['ok' => true]);
}

function dti_round_issue(array $ctx, int $rid, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    dti_round_find_or_fail($pdo, $rid);
    $magazine = dti_want_one_of($body['magazine'] ?? '', DTI_MAGAZINES, '매거진');
    $volume = dti_want_str($body, 'volume', 'Volume');
    [$count, $moved] = dti_round_add_issue($pdo, $rid, $magazine, $volume);

    return dti_json(['count' => $count, 'moved' => $moved]);
}

function dti_round_topics(array $ctx, int $rid, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    dti_round_find_or_fail($pdo, $rid);
    $flags = [];
    foreach (['active', 'archived'] as $column) {
        if (array_key_exists($column, $body)) $flags[$column] = dti_flag($body[$column]);
    }
    if (!$flags) throw new DtiError('바꿀 값(active·archived)을 보내 주세요', 422);

    return dti_json(['count' => dti_round_set_flags($pdo, $rid, $flags)]);
}
