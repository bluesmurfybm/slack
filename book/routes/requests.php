<?php
/**
 *   GET    /requests        전체 목록 (date DESC, id DESC)
 *   POST   /requests        신청 등록 — 신청자는 로그인한 본인
 *   PUT    /requests/{id}   부분 수정 (본인·관리자. done 은 관리자만)
 *   DELETE /requests/{id}   삭제 (본인·관리자)
 */

function book_route_requests(array $ctx, array $req): array {
    $method = $req['method'];
    $id = isset($req['seg'][1]) ? (int)$req['seg'][1] : null;

    if ($id === null) {
        if ($method === 'GET') return book_json(book_requests_all($ctx['pdo']));
        if ($method === 'POST') return book_json(book_request_create($ctx, $req['body']), 201);
        throw new BookError('없는 API 입니다', 404);
    }
    if ($id <= 0 || isset($req['seg'][2])) throw new BookError('없는 API 입니다', 404);

    return match ($method) {
        'GET' => book_json(book_row_out(book_request_find($ctx['pdo'], $id))),
        'PUT' => book_json(book_request_update($ctx, $id, $req['body'])),
        'DELETE' => (function () use ($ctx, $id) {
            book_request_delete($ctx, $id);
            return book_json(['ok' => true]);
        })(),
        default => throw new BookError('없는 API 입니다', 404),
    };
}
