<?php
/**
 * 유사/중복 요청 찾기 API. 로그인 필요. (보관 포함 전체 대상)
 *  점수 계산은 similar_lib.php (워커 CLI similar_cli.php 와 공유).
 *  GET ?id=Rec...  또는  ?q=<텍스트>   &min=0.15  &limit=30
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/similar_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $id    = trim($_GET['id'] ?? '');
    $q     = trim($_GET['q'] ?? '');
    $min   = isset($_GET['min']) ? (float)$_GET['min'] : 0.15;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    $out   = similar_query(similar_load_index(db()), $id, $q, $min, $limit);
    echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
