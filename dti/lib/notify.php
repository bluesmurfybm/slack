<?php
/** 슬랙 웹훅 알림. */

function dti_webhook_post(string $url, array $payload): void {
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
}

/** 선점·배정으로 발표자가 정해졌을 때만 보낸다 */
function dti_notify_new_presenter(?string $webhookUrl, ?callable $post, string $title,
                                  string $presenter, string $plannedDate): void {
    if (!$webhookUrl) return;

    $text = implode("\n", [
        ':studio_microphone: *DTI 발표자 등록*',
        "• 아티클: {$title}",
        "• 발표자: {$presenter}",
        '• 예정일: ' . ($plannedDate !== '' ? $plannedDate : '미정'),
    ]);

    $post = $post ?: 'dti_webhook_post';
    $post($webhookUrl, ['text' => $text]);
}
