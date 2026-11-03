<?php
/** 슬랙 웹훅·웹 푸시 알림. */

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

/** 웹 푸시(core/push.php). $push 는 시험이 바꿔 끼우는 자리다. 실패해도 던지지 않는다 */
function dti_push(?callable $push, array $opts): void {
    try {
        if (!$push) {
            require_once __DIR__ . '/../../core/push.php';
            $push = 'push_send';
        }
        $push($opts);
    } catch (Throwable $e) {
        error_log('[dti] 웹 푸시 실패: ' . $e->getMessage());
    }
}

function dti_push_url(int $tid): string {
    return "dti/index.php?topic={$tid}";
}

/** 발표자가 정해졌다 — 정한 사람과 발표자를 뺀 전원. 발표자 본인에게는 배정일 때 dti.mine 이 간다 */
function dti_push_new_presenter(?callable $push, int $tid, string $title, string $presenter,
                                string $plannedDate, string $actorEmail, string $presenterEmail): void {
    dti_push($push, [
        'kind' => 'dti.presenter',
        'title' => 'DTI 발표자 등록',
        'body' => "{$title} — {$presenter}" . ($plannedDate !== '' ? " ({$plannedDate})" : ''),
        'url' => dti_push_url($tid),
        'tag' => "dti-topic-{$tid}",
        'except' => [$actorEmail, $presenterEmail],
    ]);
}

/** 아티클 등록·자료 추가처럼 모두가 볼 소식 — 한 사람을 뺀 전원 */
function dti_push_topic(?callable $push, int $tid, string $title, string $body, string $actorEmail): void {
    dti_push($push, [
        'kind' => 'dti.topic',
        'title' => $title,
        'body' => $body,
        'url' => dti_push_url($tid),
        'tag' => "dti-topic-{$tid}",
        'except' => [$actorEmail],
    ]);
}

function dti_push_round_issued(?callable $push, array $round, int $count, string $actorEmail): void {
    if ($count <= 0) return;
    $name = $round['no'] . '회차' . ($round['title'] !== '' ? ' ' . $round['title'] : '');
    dti_push($push, [
        'kind' => 'dti.topic',
        'title' => "{$name} 발행",
        'body' => "아티클 {$count}건이 실렸습니다.",
        'url' => 'dti/index.php',
        'tag' => 'dti-round-' . $round['id'],
        'except' => [$actorEmail],
    ]);
}

/** 내 발표를 다른 사람이 바꿨다 — 발표자에게만 */
function dti_push_mine(?callable $push, int $tid, string $title, string $what, string $presenterEmail,
                       array $actor): void {
    if ($presenterEmail === '' || strcasecmp($presenterEmail, (string)$actor['email']) === 0) return;
    dti_push($push, [
        'kind' => 'dti.mine',
        'title' => "{$what} · {$title}",
        'body' => (string)($actor['name'] ?? ''),
        'url' => dti_push_url($tid),
        'tag' => "dti-topic-{$tid}",
        'to' => [$presenterEmail],
    ]);
}

/** 그 주소의 dti 알림을 읽음으로. $seen 은 시험이 바꿔 끼우는 자리다 */
function dti_push_seen(?callable $seen, string $email, string $url): void {
    try {
        if (!$seen) {
            require_once __DIR__ . '/../../core/push.php';
            $seen = static function (string $email, string $url): void {
                $st = portal_db()->prepare("SELECT id FROM portal_users WHERE LOWER(email) = LOWER(?)");
                $st->execute([$email]);
                $uid = (int)$st->fetchColumn();
                if ($uid) push_inbox_mark_read_url($uid, 'dti.', $url);
            };
        }
        $seen($email, $url);
    } catch (Throwable $e) {
        error_log('[dti] 알림 읽음 실패: ' . $e->getMessage());
    }
}
