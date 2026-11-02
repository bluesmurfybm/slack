<?php
/**
 * 바깥 조회 — 교보문고 상품 페이지 · 카카오 책 검색.
 * 둘 다 실패하면 조용히 빈 값이다. 신청 자체는 바깥 서비스와 무관하게 저장돼야 한다.
 */

const BOOK_LOOKUP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

function book_http_get(string $url, array $headers = [], int $timeout = 5): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => BOOK_LOOKUP_UA,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $status === 200) ? $body : null;
}

/** 교보문고 검색결과에서 첫 상품의 상품페이지 URL. 못 찾으면 null */
function book_kyobo_url(string $title, string $author = ''): ?string {
    $kw = trim($title);
    if ($kw === '') return null;
    if (trim($author) !== '') $kw .= ' ' . trim($author);

    $html = book_http_get('https://search.kyobobook.co.kr/search?keyword=' . rawurlencode($kw));
    if ($html === null) return null;
    if (!preg_match('~<ul class="prod_list">[\s\S]*?/detail/(S\d+)~', $html, $m)) return null;
    return 'https://product.kyobobook.co.kr/detail/' . $m[1];
}

/** 카카오 책 검색 — 제목/저자/출판사/가격/표지 후보. 키가 없거나 실패하면 빈 배열 */
function book_search_kakao(string $key, string $query): array {
    $query = trim($query);
    if ($key === '' || $query === '') return [];

    $raw = book_http_get(
        'https://dapi.kakao.com/v3/search/book?' . http_build_query(['query' => $query, 'size' => 10]),
        ['Authorization: KakaoAK ' . $key]);
    if ($raw === null) return [];

    $json = json_decode($raw, true);
    $out = [];
    foreach ($json['documents'] ?? [] as $it) {
        $price = $it['sale_price'] ?? null;
        if (!is_int($price) || $price <= 0) $price = $it['price'] ?? null;
        $out[] = [
            'title' => (string)($it['title'] ?? ''),
            'author' => implode(', ', $it['authors'] ?? []),
            'publisher' => (string)($it['publisher'] ?? ''),
            'price' => (is_int($price) && $price > 0) ? $price : null,
            'thumbnail' => (string)($it['thumbnail'] ?? ''),
        ];
    }
    return $out;
}
