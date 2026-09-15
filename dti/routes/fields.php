<?php

use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;

function dti_route_fields(array $ctx, array $req): Response {
    $pdo = $ctx['pdo'];
    $fid = $req['seg'][1] ?? null;

    if ($fid === null && $req['method'] === 'GET') {
        return Response::json(dti_field_all($pdo));
    }

    if ($fid === null && $req['method'] === 'POST') {
        dti_require_admin($ctx);

        $name = Input::str($req['body'], 'name', '분야 이름', required: true);
        if (dti_field_exists($pdo, $name)) {
            throw new ApiException('이미 있는 분야입니다', 409);
        }
        return Response::json(['id' => dti_field_insert($pdo, $name), 'name' => $name], 201);
    }

    if ($fid !== null && $req['method'] === 'DELETE') {
        dti_require_admin($ctx);

        if (!dti_field_delete($pdo, (int)$fid)) {
            throw new ApiException('없는 분야입니다', 404);
        }
        return Response::json(['ok' => true]);
    }

    throw new ApiException('없는 API 입니다', 404);
}
