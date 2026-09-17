<?php
/** GET api/stats.php?year=2026 — 관리자 통계 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bc_require_login_api();
if (!bc_can_see_admin()) {
    bc_json_error('관리자 권한이 필요합니다.', 403);
}

$year = bc_param_int('year', (int)date('Y'));
bc_json_ok(['stats' => PurchaseRequest::statistics($year), 'years' => PurchaseRequest::years()]);
