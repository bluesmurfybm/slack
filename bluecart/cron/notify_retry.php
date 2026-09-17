<?php
/**
 * 실패한 알림 재발송. crontab 에 5분 주기로 등록합니다.
 *
 *   ＊/5 ＊ ＊ ＊ ＊  /usr/bin/php /var/www/iworks/bluecart/cron/notify_retry.php >> /var/log/bluecart-notify.log 2>&1
 *
 * (위 전각 별표는 PHP 주석이 닫히지 않도록 표기만 바꾼 것입니다. 실제
 *  crontab 에는 일반 별표를 쓰세요.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI 전용');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BC_ROOT . '/includes/notify/MailChannel.php';
require_once BC_ROOT . '/includes/notify/SlackChannel.php';

const MAX_RETRY = 5;

$rows = bc_fetch_all(
    'SELECT * FROM bc_notify_log
      WHERE status = "FAILED" AND retry_count < ?
      ORDER BY id ASC LIMIT 100',
    [MAX_RETRY]
);

$ok = 0;
$ng = 0;
foreach ($rows as $log) {
    try {
        match ($log['channel']) {
            'EMAIL'                        => MailChannel::send($log['recipient'], (string)$log['subject'], (string)$log['body']),
            'SLACK_DM', 'SLACK_CHANNEL'    => SlackChannel::postMessage($log['recipient'], (string)$log['body']),
            default                        => throw new RuntimeException('알 수 없는 채널: ' . $log['channel']),
        };
        Notifier::markSent((int)$log['id']);
        $ok++;
    } catch (Throwable $e) {
        Notifier::markFailed((int)$log['id'], $e->getMessage());
        $ng++;
    }
}

printf("[%s] 재발송 성공 %d건, 실패 %d건%s", date('Y-m-d H:i:s'), $ok, $ng, PHP_EOL);
