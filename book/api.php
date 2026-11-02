<?php
/**
 * BlueBooks JSON API — 프런트 컨트롤러.
 *
 * 화면(static/*.js)은 FastAPI 시절 경로를 그대로 쓴다. core.js 의 bookApiURL() 이
 * `/bookapi/requests/3` 을 `api.php?p=/requests/3` 으로 바꿔 여기로 보낸다.
 *
 *   GET    /whoami            로그인 사용자 (미로그인도 답한다)
 *   GET    /members           구성원 명단 — 이름·이메일·고유색
 *   GET    /booksearch?q=     카카오 책 검색
 *   GET    /requests          전체 목록
 *   POST   /requests          신청 등록
 *   PUT    /requests/{id}     부분 수정
 *   DELETE /requests/{id}     삭제
 */

require_once __DIR__ . '/bootstrap.php';

$identity = book_identity();
// 화면이 API 를 동시에 여러 개 부른다 — 세션 잠금을 쥐고 있으면 그게 직렬화된다
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $req = book_request_from_globals();
} catch (BookError $e) {
    book_send(book_json(['detail' => $e->getMessage()], $e->status()));
    exit;
}

$config = book_config_from_portal();
$pdo = book_connect($config);
book_migrate($pdo, $config);

book_send(book_handle([
    'config' => $config,
    'pdo' => $pdo,
    'identity' => $identity,
], $req));
