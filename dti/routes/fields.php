<?php

function dti_route_fields(array $ctx, array $req): array {
    $pdo = $ctx['pdo'];
    $fid = $req['seg'][1] ?? null;

    if ($fid === null && $req['method'] === 'GET') {
        return dti_json(dti_field_all($pdo));
    }

    if ($fid === null && $req['method'] === 'POST') {
        dti_require_admin($ctx);

        $name = dti_want_str($req['body'], 'name', '분야 이름', required: true);
        if (dti_field_exists($pdo, $name)) {
            throw new DtiError('이미 있는 분야입니다', 409);
        }
        return dti_json(['id' => dti_field_insert($pdo, $name), 'name' => $name], 201);
    }

    if ($fid !== null && $req['method'] === 'DELETE') {
        dti_require_admin($ctx);

        if (!dti_field_delete($pdo, (int)$fid)) {
            throw new DtiError('없는 분야입니다', 404);
        }
        return dti_json(['ok' => true]);
    }

    throw new DtiError('없는 API 입니다', 404);
}
