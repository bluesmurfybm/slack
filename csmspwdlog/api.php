<?php
/**
 * 요청 이력 조회 API — index.php 가 쓴다. 포털 로그인한 사람이면 누구나 본다.
 *  GET ?q=&result=&from=YYYY-MM-DD&to=YYYY-MM-DD&limit=100&offset=0 → {rows, total, limit, offset, results}
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (array $data, int $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!current_portal_user()) $out(['detail' => '로그인이 필요합니다.'], 401);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // 동시 AJAX 가 세션 잠금에 줄 서지 않게
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $out(['detail' => 'GET 만 지원합니다.'], 405);

try {
    $pdo = portal_db();
    csmspwdlog_table($pdo);
    $out(csmspwdlog_list($pdo, $_GET));
} catch (Throwable $e) {
    error_log('[csmspwdlog] ' . $e->getMessage());
    $out(['detail' => '이력을 불러오지 못했습니다.'], 500);
}
