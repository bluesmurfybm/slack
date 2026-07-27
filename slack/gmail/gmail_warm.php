<?php
/**
 * Gmail 캐시 예열 (CLI 전용): 열람 속도 개선용 일회성/수시 실행 도구.
 *  1) 대화 ID(thrid) 전체 백필 — 2000건/커맨드
 *  2) 최근 메일 본문 프리페치 — 기본 300건 (인자로 조정: php gmail_warm.php 1000)
 */
if (php_sapi_name() !== 'cli') exit('CLI 전용');
require_once __DIR__ . '/gmail_lib.php';

$bodies = max(0, (int)($argv[1] ?? 300));
gmail_ensure_table();
$pdo = db();
$acct = gmail_owner();

// ---- 1) thrid 백필 ----
$raw = new GmailRaw();
$done = 0;
while (true) {
    $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND thrid IS NULL ORDER BY udate DESC LIMIT 2000");
    $q->execute([$acct]);
    $uids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$uids) break;
    $map = $raw->thridMap(implode(',', $uids));
    $st = $pdo->prepare("UPDATE gmail_mails SET thrid = ? WHERE account = ? AND uid = ?");
    $pdo->beginTransaction();
    foreach ($uids as $u) $st->execute([$map[$u] ?? '0', $acct, $u]);   // 0=응답 없음 — 재조회 방지
    $pdo->commit();
    $done += count($uids);
    echo "thrid: {$done}건 처리\n";
}
// ---- 1.5) 라벨 백필 (필터 기능용) ----
$ldone = 0;
while (true) {
    $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND labels IS NULL ORDER BY udate DESC LIMIT 2000");
    $q->execute([$acct]);
    $uids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$uids) break;
    $map = $raw->labelsMap(implode(',', $uids));
    $st = $pdo->prepare("UPDATE gmail_mails SET labels = ? WHERE account = ? AND uid = ?");
    $pdo->beginTransaction();
    foreach ($uids as $u) $st->execute([gmail_labels_pack($map[$u] ?? []), $acct, $u]);
    $pdo->commit();
    $ldone += count($uids);
    echo "labels: {$ldone}건 처리\n";
}
gmeta_set('gmail_labels', json_encode($raw->allLabels(), JSON_UNESCAPED_UNICODE));   // 필터 드롭다운용
$raw->close();

// ---- 2) 본문 프리페치 (최근 N건) ----
$n = 0;
if ($bodies > 0) {
    [$im, $err] = gmail_open();
    if (!$im) { echo "IMAP 실패: $err\n"; exit(1); }
    $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND body_html IS NULL ORDER BY udate DESC LIMIT " . $bodies);
    $q->execute([$acct]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) {
        if (gmail_fill_body($im, $pdo, $acct, $u)) $n++;
        if ($n % 25 === 0 && $n > 0) echo "body: {$n}건\n";
    }
    imap_close($im);
}
echo "완료: thrid {$done}건, labels {$ldone}건, body {$n}건\n";
