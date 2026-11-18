<?php

/**
 * @param array $ctx
 * @param array $req
 * @return array
 */
function schooltodo_route_sheets($ctx, $req) {
    $pdo = $ctx['pdo'];
    $method = $req['method'];
    $seg = $req['seg'];
    $id = isset($seg[1]) ? (int)$seg[1] : null;

    if ($id === null) {
        if ($method === 'GET') return schooltodo_json(schooltodo_sheets_all($pdo, !empty($req['query']['all'])));
        if ($method === 'POST') return schooltodo_json(schooltodo_sheet_create($ctx, $req['body']), 201);
        throw new SchooltodoError('없는 API 입니다', 404);
    }
    if ($id <= 0) throw new SchooltodoError('없는 API 입니다', 404);

    if (($seg[2] ?? '') === 'cells' && !isset($seg[3]) && $method === 'PUT') {
        return schooltodo_json(schooltodo_cells_fill($ctx, $id, $req['body']));
    }
    if (isset($seg[2])) throw new SchooltodoError('없는 API 입니다', 404);

    if ($method === 'GET') {
        $status = isset($req['query']['status']) ? schooltodo_want_status($req['query']['status'], 'status') : null;
        return schooltodo_json(schooltodo_sheet_detail($pdo, $id, $status));
    }
    if ($method === 'PUT') return schooltodo_json(schooltodo_sheet_update($ctx, $id, $req['body']));
    if ($method === 'DELETE') {
        schooltodo_sheet_delete($ctx, $id);
        return schooltodo_json(['ok' => true]);
    }
    throw new SchooltodoError('없는 API 입니다', 404);
}
