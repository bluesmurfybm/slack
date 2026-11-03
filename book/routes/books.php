<?php
/** GET /booksearch?q=  — 카카오 책 검색. 키가 없으면 빈 배열(화면은 자동완성만 안 보인다) */

function book_route_booksearch(array $ctx, array $req): array {
    if ($req['method'] !== 'GET') throw new BookError('없는 API 입니다', 404);
    $q = trim((string)($req['query']['q'] ?? ''));
    if (mb_strlen($q) < 2) return book_json([]);
    return book_json(book_search_kakao($ctx['config']['kakao_key'], $q));
}
