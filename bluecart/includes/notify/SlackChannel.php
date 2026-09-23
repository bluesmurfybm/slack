<?php
declare(strict_types=1);

/**
 * 슬랙 발송.
 *
 * 개인 DM 은 Bot Token 이 있어야 합니다 (chat:write, users:read, users:read.email).
 * 채널 발송만 쓸 경우에는 Incoming Webhook 만으로도 동작합니다.
 * Bot Token 이 있으면 항상 Web API 를 우선 사용합니다.
 *
 * 토큰과 Webhook URL 은 관리자 화면(알림 설정)에서 넣은 값을 먼저 보고,
 * 없으면 config.php 의 notify.slack 값을 씁니다. 화면에서 넣은 값은
 * 암호화해서 bc_setting 에 들어갑니다.
 */
final class SlackChannel
{
    private const API = 'https://slack.com/api/';

    /** 봇 토큰 — 관리자 화면 저장값이 먼저, 없으면 설정 파일. */
    public static function botToken(): string
    {
        $v = Setting::secret('slack_bot_token');
        return $v !== '' ? $v : trim((string)bc_config('notify.slack.bot_token', ''));
    }

    /** Incoming Webhook URL — 관리자 화면 저장값이 먼저, 없으면 설정 파일. */
    public static function webhookUrl(): string
    {
        $v = Setting::secret('slack_webhook_url');
        return $v !== '' ? $v : trim((string)bc_config('notify.slack.webhook_url', ''));
    }

    public static function postMessage(string $channel, string $text, string $emoji = ''): void
    {
        if (!bc_config('notify.enabled', true)) {
            return;
        }

        if (self::botToken() !== '') {
            self::api('chat.postMessage', [
                'channel' => $channel,
                'text'    => $text,
                'blocks'  => json_encode(self::blocks($text, $emoji), JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }

        $webhook = self::webhookUrl();
        if ($webhook === '') {
            throw new RuntimeException('슬랙 봇 토큰 또는 Webhook URL 이 설정되어 있지 않습니다.');
        }
        if (str_starts_with($channel, 'U')) {
            throw new RuntimeException('개인 DM 은 봇 토큰이 필요합니다. Webhook 으로는 보낼 수 없습니다.');
        }
        self::httpPostJson($webhook, ['text' => $text, 'blocks' => self::blocks($text, $emoji)]);
    }

    /** 이메일로 슬랙 사용자 ID 조회. 실패하면 null. */
    public static function lookupUserByEmail(string $email): ?string
    {
        static $cache = [];
        if (array_key_exists($email, $cache)) {
            return $cache[$email];
        }
        if (self::botToken() === '') {
            return $cache[$email] = null;
        }
        try {
            $res = self::api('users.lookupByEmail', ['email' => $email]);
            return $cache[$email] = $res['user']['id'] ?? null;
        } catch (Throwable $e) {
            error_log('[BlueCart] slack lookup failed: ' . $e->getMessage());
            return $cache[$email] = null;
        }
    }

    /**
     * 첫 줄은 제목, 나머지는 본문.
     *
     * 같은 채널(#blue_inbox)에 DTI·도서 신청 알림도 함께 들어와서 그쪽 모양에
     * 맞췄다. 제목은 '이모지 + 굵게' 한 줄, 항목은 가운뎃점 목록이다.
     *
     * header 블록은 쓰지 않는다. 글자가 크고 이모지가 제목과 따로 놀아서,
     * 다른 알림과 나란히 놓으면 혼자 튄다. 한 채널에 여러 시스템이 들어올
     * 때는 같은 문법(mrkdwn 한 덩어리)으로 맞추는 쪽이 읽기 낫다.
     */
    private static function blocks(string $text, string $emoji = ''): array
    {
        $lines = explode("\n", $text);
        $title = array_shift($lines) ?: 'BlueCart';

        $out = [($emoji !== '' ? $emoji . ' ' : '') . '*' . $title . '*'];
        foreach ($lines as $line) {
            // '항목: 값' 꼴만 목록으로 만든다. 안내 문장과 링크는 그대로 둔다.
            $out[] = preg_match('/^[^:\n]{1,24}: /u', $line) ? '• ' . $line : $line;
        }

        return [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => mb_substr(implode("\n", $out), 0, 2950)],
        ]];
    }

    private static function api(string $method, array $params): array
    {
        $token = self::botToken();
        $ch = curl_init(self::API . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('슬랙 API 호출 실패: ' . $err);
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json) || empty($json['ok'])) {
            throw new RuntimeException('슬랙 API 오류: ' . ($json['error'] ?? 'unknown'));
        }
        return $json;
    }

    private static function httpPostJson(string $url, array $payload): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 300) {
            throw new RuntimeException('슬랙 Webhook 실패: ' . ($err ?: (string)$body));
        }
    }
}
