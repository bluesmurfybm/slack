<?php
/**
 * 스키마 적용 — CLI 전용. 화면을 처음 열 때도 저절로 적용되지만, 배포 직후
 * 배치를 먼저 돌리려면 이것을 한 번 부른다.
 *
 *     php onwatch/bin/migrate.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('OW_CLI', true);
require_once dirname(__DIR__) . '/inc/bootstrap.php';

$applied = ow_migrate(portal_db());
echo $applied ? '적용: ' . implode(', ', $applied) . "\n" : "적용할 것이 없습니다(최신)\n";
