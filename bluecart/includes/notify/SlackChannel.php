<?php
declare(strict_types=1);

/**
 * 슬랙 발송.
 *
 * 개인 DM 은 Bot Token 이 있어야 합니다 (chat:write, users:read, users:read.email).
 * 채널 발송만 쓸 경우에는 Incoming Webhook 만으로도 동작합니다.
 * Bot Token 이 있으면 항상 Web API 를 우선 사용합니다.
 */
final class SlackChannel
{
    private const API = 'https://slack.com/api/';

    public static function postMessage(string $channel, string $text): void
    {
        if (!bc_config('notify.enabled', true)) {
            return;
        }

        $token = (string)bc_config('notify.slack.bot_token', '');
        if ($token !== '') {
            self::api('chat.postMessage', [
                'channel' => $channel,
                'text'    => $text,
                'blocks'  => json_encode(self::blocks($text), JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }

        $webhook = (string)bc_config('notify.slack.webhook_url', '');
        if ($webhook === '') {
            throw new RuntimeException('슬랙 봇 토큰 또는 Webhook URL 이 설정되어 있지 않습니다.');
        }
        if (str_starts_with($channel, 'U')) {
            throw new RuntimeException('개인 DM 은 봇 토큰이 필요합니다. Webhook 으로는 보낼 수 없습니다.');
        }
        self::httpPostJson($webhook, ['text' => $text, 'blocks' => self::blocks($text)]);
    }

    /** 이메일로 슬랙 사용자 ID 조회. 실패하면 null. */
    public static function lookupUserByEmail(string $email): ?string
    {
        static $cache = [];
        if (array_key_exists($email, $cache)) {
            return $cache[$email];
        }
        if ((string)bc_config('notify.slack.bot_token', '') === '') {
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

    /** 첫 줄은 제목, 나머지는 본문으로 나눈 단순 블록. */
    private static function blocks(string $text): array
    {
        $lines = explode("\n", $text);
        $title = array_shift($lines) ?: 'BlueCart';
        $rest  = trim(implode("\n", $lines));

        $blocks = [[
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => mb_substr($title, 0, 150), 'emoji' => true],
        ]];
        if ($rest !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => mb_substr($rest, 0, 2900)],
            ];
        }
        return $blocks;
    }

    private static function api(string $method, array $params): array
    {
        $token = (string)bc_config('notify.slack.bot_token', '');
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
