<?php

namespace Dti\Service;

final class CurlWebhook implements Webhook
{
    public function post(string $url, array $payload): void
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
        ]);

        if (curl_exec($curl) === false) {
            // 알림이 안 갔다고 예약까지 실패시키지는 않는다
            error_log('[dti] 슬랙 알림 전송 실패: ' . curl_error($curl));
        }
        curl_close($curl);
    }
}
