<?php
/** 신청 1건을 Slack 웹훅으로 알린다. 웹훅이 없거나 실패해도 조용히 넘어간다 — 신청은 이미 저장됐다. */

function book_slack_text(array $rec): string {
    $price = $rec['price'] !== null ? number_format((int)$rec['price']) . '원' : '-';
    $lines = [
        ':books: *새 도서 신청*',
        "• 신청자: {$rec['applicant']}",
        "• 도서: {$rec['title']}" . ($rec['author'] !== '' ? " / {$rec['author']}" : ''),
        "• 구분: {$rec['gubun']}  ·  단가: {$price}",
        "• 신청일: {$rec['date']}",
    ];
    if ($rec['note'] !== '') $lines[] = "• 비고: {$rec['note']}";
    if (!empty($rec['kyobo_url'])) $lines[] = "• 교보문고: {$rec['kyobo_url']}";
    return implode("\n", $lines);
}

function book_notify_slack(?string $webhook, array $rec): void {
    if (!$webhook || !function_exists('curl_init')) return;
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode(['text' => book_slack_text($rec)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

const BOOK_FIELD_LABELS = ['title' => '도서명', 'author' => '저자', 'publisher' => '출판사', 'gubun' => '구분', 'price' => '도서단가',
    'note' => '비고', 'kyobo_url' => '교보문고 링크', 'thumbnail' => '표지'];

function book_push(array $opts): void {
    try {
        require_once __DIR__ . '/../../core/push.php';
        push_send($opts);
    } catch (Throwable $e) {
        error_log('[book] 알림 실패: ' . $e->getMessage());
    }
}

function book_push_url(int $id): string {
    return 'book/index.php?id=' . $id;
}

function book_notify_created(array $rec): void {
    book_push([
        'kind' => 'book.request',
        'title' => '새 도서 신청 · ' . mb_strimwidth($rec['title'], 0, 60, '…'),
        'body' => trim($rec['applicant'] . ' · ' . $rec['gubun']),
        'url' => book_push_url((int)$rec['id']),
        'tag' => 'book-' . (int)$rec['id'],
        'except' => [$rec['applicant_email']],
    ]);
}

/** 남이 내 신청을 바꿨을 때만 신청자에게 — $what 은 '구비 완료'·'수정(도서명)'·'삭제' 같은 짧은 말 */
function book_notify_mine(array $row, array $actor, string $what): void {
    $owner = (string)$row['applicant_email'];
    if ($owner === '' || strcasecmp($owner, (string)$actor['email']) === 0) return;
    book_push([
        'kind' => 'book.mine',
        'title' => $what . ' · ' . mb_strimwidth((string)$row['title'], 0, 60, '…'),
        'body' => trim((string)$actor['name']) . '님이 내 도서 신청을 ' . ($what === '삭제' ? '지웠습니다.' : '바꿨습니다.'),
        'url' => $what === '삭제' ? 'book/index.php' : book_push_url((int)$row['id']),
        'tag' => 'book-' . (int)$row['id'],
        'to' => [$owner],
    ]);
}

/** 고친 칸 → 신청자에게 보낼 짧은 말. 바뀐 게 없으면 '' */
function book_change_phrase(array $existing, array $changes): string {
    $out = [];
    if (array_key_exists('done', $changes) && (int)$changes['done'] !== (int)$existing['done']) {
        $out[] = (int)$changes['done'] ? '구비 완료' : '미처리로 되돌림';
    }
    $fields = [];
    foreach (BOOK_FIELD_LABELS as $k => $label) {
        if (array_key_exists($k, $changes) && (string)($changes[$k] ?? '') !== (string)($existing[$k] ?? '')) $fields[] = $label;
    }
    if ($fields) $out[] = '수정(' . implode(', ', $fields) . ')';
    return implode(' · ', $out);
}

/** 목록 한 장이 전부라 화면을 열면 book 알림을 모두 읽음으로 */
function book_notice_seen(int $uid): void {
    if (!$uid) return;
    try {
        require_once __DIR__ . '/../../core/push.php';
        foreach (array_keys(push_inbox_unread_urls($uid, 'book.')) as $url) push_inbox_mark_read_url($uid, 'book.', $url);
    } catch (Throwable $e) {
        error_log('[book] 알림 읽음: ' . $e->getMessage());
    }
}
