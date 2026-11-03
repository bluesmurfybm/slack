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
