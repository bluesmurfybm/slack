<?php
/**
 * 블루 클러스터 에이전트 — 시도 결과 파일 내려받기.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/pages.php';

bcl_require_login();

$pdo = bcl_db();
$attempt_id = (int)($_GET['attempt'] ?? 0);
$kind = (string)($_GET['kind'] ?? '');
$path = bcl_page_download_path($pdo, $attempt_id, $kind);
if ($path === null) {
    http_response_code(404);
    exit('파일을 찾을 수 없습니다.');
}

header('Content-Type: ' . ($kind === 'log' ? 'application/json' : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . bcl_page_download_name($pdo, $attempt_id, $kind) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
