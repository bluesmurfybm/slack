<?php
/**
 * 반려된 지 오래된 요청을 자동 철회한다.
 * 요청자가 재요청도 철회도 하지 않은 건이 목록에 계속 남는 것을 막으려는 처리.
 * 기간은 bc_setting.reject_auto_close_days (기본 14일).
 *
 *   30 2 * * * /usr/bin/php /var/www/iworks/bluecart/cron/close_stale_rejected.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI 전용');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$days = (int)(Setting::get('reject_auto_close_days', '14') ?? 14);
if ($days <= 0) {
    echo "자동 철회가 꺼져 있습니다.\n";
    exit;
}

$rows = bc_fetch_all(
    'SELECT id, req_no FROM bc_request
      WHERE status = "REJECTED" AND reviewed_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
    [$days]
);

$system = ['id' => 'system', 'name' => '시스템'];
foreach ($rows as $r) {
    bc_query('UPDATE bc_request SET status = "CANCELED", closed_at = NOW() WHERE id = ?', [(int)$r['id']]);
    PurchaseRequest::log(
        (int)$r['id'], 'REQUEST_CANCELED', 'REJECTED', 'CANCELED', $system,
        sprintf('반려 후 %d일 동안 조치가 없어 자동 철회했습니다.', $days)
    );
}

printf("[%s] 자동 철회 %d건%s", date('Y-m-d H:i:s'), count($rows), PHP_EOL);
