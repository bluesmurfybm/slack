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

/**
 * 같은 일을 웹 푸시로도 알린다(core/push.php, 종류 'dti.presenter').
 *
 * 웹훅과 달리 주소 설정이 없어도 보낸다 — 받을지 말지는 사람마다 마이페이지에서
 * 정한다. 알림을 누르면 매거진 목록이 열린다.
 *
 * $push 는 시험이 바꿔 끼우는 자리다. 비어 있으면 그때 core/push.php 를 불러
 * push_send 를 쓴다(dti 는 포털 core 를 함수 안에서 늦게 부르는 방식을 따른다 — guard.php).
 */
function dti_push_new_presenter(?callable $push, int $tid, string $title, string $presenter,
                                string $plannedDate): void {
    if (!$push) {
        require_once __DIR__ . '/../../core/push.php';
        $push = 'push_send';
    }
    $push([
        'kind'  => 'dti.presenter',
        'title' => 'DTI 발표자 등록',
        'body'  => "{$title} — {$presenter}" . ($plannedDate !== '' ? " ({$plannedDate})" : ''),
        'url'   => 'dti/index.php',
        'tag'   => "dti-topic-{$tid}",
    ]);
}
