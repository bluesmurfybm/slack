<?php
declare(strict_types=1);

require_once __DIR__ . '/MailChannel.php';
require_once __DIR__ . '/SlackChannel.php';

/**
 * 이벤트 발생 → (이벤트 × 수신역할 × 채널) 설정을 읽어 발송.
 *
 * 발송은 bc_notify_log 에 먼저 PENDING 으로 적재한 뒤 즉시 시도한다.
 * 실패 건은 FAILED 로 남고 cron/notify_retry.php 가 재시도한다.
 * 알림 실패가 업무 처리 자체를 막지 않도록 예외는 삼킨다.
 */
final class Notifier
{
    public static function dispatch(string $event, array $request, array $actor, string $comment = ''): void
    {
        if (!isset(BC_EVENT[$event])) {
            return;
        }
        $targets = BC_EVENT_TARGETS[$event] ?? [];

        foreach ($targets as $role) {
            $channels = Setting::enabledChannels($event, $role);
            if (!$channels) {
                continue;
            }
            $recipients = RoleAssign::resolveRecipients($role, $request);
            if (!$recipients) {
                self::log($request['id'], $event, $role, 'EMAIL', '-', null, null,
                          'SKIPPED', '수신 대상이 배정되어 있지 않습니다.');
                continue;
            }

            $msg = self::compose($event, $request, $actor, $comment, $role);

            foreach ($channels as $channel) {
                try {
                    match ($channel) {
                        'EMAIL'         => self::sendEmail($recipients, $msg, $request, $event, $role),
                        'SLACK_DM'      => self::sendSlackDm($recipients, $msg, $request, $event, $role),
                        'SLACK_CHANNEL' => self::sendSlackChannel($msg, $request, $event, $role),
                        default         => null,
                    };
                } catch (Throwable $e) {
                    error_log('[BlueCart] notify failed: ' . $e->getMessage());
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // 메시지 작성
    // -----------------------------------------------------------------

    /** @return array{subject:string,text:string} */
    private static function compose(
        string $event, array $req, array $actor, string $comment, string $role
    ): array {
        $label  = BC_EVENT[$event];
        $item   = sprintf('%s %d%s', $req['item_name'], (int)$req['quantity'], $req['unit']);
        $url    = rtrim((string)bc_config('app.base_url'), '/') . '/?id=' . (int)$req['id'];

        $subject = sprintf('[물품구매 %s] %s - %s', $req['req_no'], $label, $req['item_name']);

        $lines = [
            $label,
            '',
            '요청번호: ' . $req['req_no'],
            '사용처: '   . $req['category_name'],
            '물품: '     . $item,
            '요청자: '   . $req['requester_name'] . ' (' . bc_date($req['requested_at'], 'Y-m-d H:i') . ')',
            '현재 상태: ' . bc_status_label($req['status']),
        ];

        if ($req['deliver_to']) {
            $lines[] = '수령 장소: ' . $req['deliver_to'];
        }
        if ($req['need_by']) {
            $lines[] = '희망 수령일: ' . bc_date($req['need_by']);
        }
        if ($req['ref_url']) {
            $lines[] = '참고 링크: ' . $req['ref_url'];
        }
        if ($req['note']) {
            $lines[] = '비고: ' . $req['note'];
        }
        if (!empty($req['assignee_name'])) {
            $lines[] = '구매 담당: ' . $req['assignee_name'];
        }

        $lines[] = '';
        $lines[] = '처리자: ' . $actor['name'];

        if ($comment !== '') {
            $lines[] = ($event === 'REVIEW_REJECTED' ? '반려 사유: ' : '의견: ') . $comment;
        }

        // 수신자가 다음에 무엇을 해야 하는지 한 줄로 알려준다.
        $next = self::nextStep($event, $role);
        if ($next) {
            $lines[] = '';
            $lines[] = $next;
        }

        $lines[] = '';
        $lines[] = $url;

        return ['subject' => $subject, 'text' => implode("\n", $lines)];
    }

    private static function nextStep(string $event, string $role): ?string
    {
        return match (true) {
            $event === 'REQUEST_CREATED'     && $role === 'REVIEWER'  => '관리자 탭 > 신청 물품 관리에서 승인 또는 반려를 처리하세요.',
            $event === 'REQUEST_RESUBMITTED' && $role === 'REVIEWER'  => '반려된 요청이 수정되어 다시 올라왔습니다. 재검토가 필요합니다.',
            $event === 'REVIEW_APPROVED'     && $role === 'BUYER'     => '관리자 탭 > 신청 물품 관리에서 구매 진행으로 변경하세요.',
            $event === 'PURCHASE_ASSIGNED'   && $role === 'BUYER'     => '이 건의 구매 담당으로 지정되었습니다. 관리자 탭 > 신청 물품 관리에서 확인하세요.',
            $event === 'REVIEW_REJECTED'     && $role === 'REQUESTER' => '내용을 수정해 재요청하거나 요청을 철회할 수 있습니다.',
            $event === 'PURCHASE_DONE'       && $role === 'REQUESTER' => '물품이 입고되었습니다. 수령해 주세요.',
            default => null,
        };
    }

    // -----------------------------------------------------------------
    // 채널별 발송
    // -----------------------------------------------------------------

    private static function sendEmail(array $recipients, array $msg, array $req, string $event, string $role): void
    {
        foreach ($recipients as $r) {
            if (empty($r['email'])) {
                self::log($req['id'], $event, $role, 'EMAIL', $r['id'], $msg['subject'], $msg['text'],
                          'SKIPPED', '이메일 주소가 없습니다.');
                continue;
            }
            $logId = self::log($req['id'], $event, $role, 'EMAIL', $r['email'],
                               $msg['subject'], $msg['text'], 'PENDING', null);
            try {
                MailChannel::send($r['email'], $msg['subject'], $msg['text']);
                self::markSent($logId);
            } catch (Throwable $e) {
                self::markFailed($logId, $e->getMessage());
            }
        }
    }

    private static function sendSlackDm(array $recipients, array $msg, array $req, string $event, string $role): void
    {
        foreach ($recipients as $r) {
            $slackId = $r['slack_id'] ?: null;
            if (!$slackId && !empty($r['email'])) {
                $slackId = SlackChannel::lookupUserByEmail($r['email']);
            }
            if (!$slackId) {
                self::log($req['id'], $event, $role, 'SLACK_DM', $r['id'], $msg['subject'], $msg['text'],
                          'SKIPPED', '슬랙 사용자를 찾지 못했습니다.');
                continue;
            }
            $logId = self::log($req['id'], $event, $role, 'SLACK_DM', $slackId,
                               $msg['subject'], $msg['text'], 'PENDING', null);
            try {
                SlackChannel::postMessage($slackId, $msg['text']);
                self::markSent($logId);
            } catch (Throwable $e) {
                self::markFailed($logId, $e->getMessage());
            }
        }
    }

    private static function sendSlackChannel(array $msg, array $req, string $event, string $role): void
    {
        $channel = Setting::get('slack_default_channel')
                ?: (string)bc_config('notify.slack.default_channel', '');
        if ($channel === '') {
            self::log($req['id'], $event, $role, 'SLACK_CHANNEL', '-', $msg['subject'], $msg['text'],
                      'SKIPPED', '기본 슬랙 채널이 설정되어 있지 않습니다.');
            return;
        }
        $logId = self::log($req['id'], $event, $role, 'SLACK_CHANNEL', $channel,
                           $msg['subject'], $msg['text'], 'PENDING', null);
        try {
            SlackChannel::postMessage($channel, $msg['text']);
            self::markSent($logId);
        } catch (Throwable $e) {
            self::markFailed($logId, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // 로그
    // -----------------------------------------------------------------

    public static function log(
        ?int $requestId, string $event, string $role, string $channel, string $recipient,
        ?string $subject, ?string $body, string $status, ?string $error
    ): int {
        bc_query(
            'INSERT INTO bc_notify_log
               (request_id, event_code, target_role, channel, recipient, subject, body, status, error_msg, sent_at)
             VALUES (?,?,?,?,?,?,?,?,?, CASE WHEN ? = "SENT" THEN NOW() ELSE NULL END)',
            [$requestId, $event, $role, $channel, $recipient, $subject, $body, $status, $error, $status]
        );
        return (int)bc_db()->lastInsertId();
    }

    public static function markSent(int $logId): void
    {
        bc_query('UPDATE bc_notify_log SET status = "SENT", sent_at = NOW(), error_msg = NULL WHERE id = ?', [$logId]);
    }

    public static function markFailed(int $logId, string $error): void
    {
        bc_query(
            'UPDATE bc_notify_log SET status = "FAILED", error_msg = ?, retry_count = retry_count + 1 WHERE id = ?',
            [mb_substr($error, 0, 500), $logId]
        );
    }
}
