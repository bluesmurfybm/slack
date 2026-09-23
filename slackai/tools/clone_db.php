<?php
/**
 * slack_db → slackai_db 최초 복제 (CLI 전용, 멱등).
 *  php slackai/tools/clone_db.php [--force] [--tables=requests,schools] [--dry-run]
 *  - 대상 DB(config.php slackai.db_name)를 만들고, slack 8개 테이블을 CREATE TABLE … LIKE + INSERT … SELECT 로 복제한다.
 *  - 대상 테이블에 이미 행이 있으면 --force 없이는 아무것도 건드리지 않고 종료한다(1). --force 는 DROP 후 재복제.
 *  - portal_* 는 복제하지 않는다(인증은 계속 slack_db).
 *  - 복제 후 sync_meta 의 last_synced_at/data_changed_at/cmt_scan_at 만 지운다(워터마크 list_updated_max_* 는 유지 → 첫 동기화가 증분).
 *  - 마지막에 slackai/db.php 의 db() 를 불러 ai_* 테이블과 태그 시드를 만든다.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$opts   = getopt('', ['force', 'tables:', 'dry-run']);
$force  = isset($opts['force']);
$dry    = isset($opts['dry-run']);
$cfg    = require __DIR__ . '/../../config.php';
$d      = $cfg['db'];
$src    = $d['name'];
$dst    = $cfg['slackai']['db_name'] ?? 'slackai_db';
$tables = ['requests', 'sync_meta', 'user_reads', 'user_pins', 'user_hides', 'user_prefs', 'schools', 'local_assignments'];
if (!empty($opts['tables'])) $tables = array_values(array_filter(array_map('trim', explode(',', $opts['tables']))));

if ($src === $dst) { fwrite(STDERR, "원본과 대상 DB 이름이 같습니다($src). config.php slackai.db_name 을 확인하세요.\n"); exit(1); }

$pdo = new PDO("mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}", $d['user'], $d['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "원본 $src → 대상 $dst" . ($dry ? " (dry-run)" : "") . "\n";

// 원본 테이블 존재 확인
$have = $pdo->query("SHOW TABLES FROM `$src`")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (!in_array($t, $have, true)) { fwrite(STDERR, "원본에 테이블이 없습니다: $src.$t\n"); exit(1); }
}

// 대상 DB 생성 + 선행 점검(행이 있는 테이블이 하나라도 있으면 --force 없이는 중단)
if (!$dry) $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dst` CHARACTER SET {$d['charset']} COLLATE {$d['charset']}_unicode_ci");
$dstHave = $dry ? [] : $pdo->query("SHOW TABLES FROM `$dst`")->fetchAll(PDO::FETCH_COLUMN);
$nonEmpty = [];
foreach ($tables as $t) {
    if (in_array($t, $dstHave, true)) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$dst`.`$t`")->fetchColumn();
        if ($n > 0) $nonEmpty[$t] = $n;
    }
}
if ($nonEmpty && !$force) {
    fwrite(STDERR, "대상에 이미 데이터가 있습니다: " . json_encode($nonEmpty) . "\n--force 를 주면 DROP 후 다시 복제합니다.\n");
    exit(1);
}

foreach ($tables as $t) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM `$src`.`$t`")->fetchColumn();
    if ($dry) { echo sprintf("%-18s %6d 행 (복제 예정)\n", $t, $n); continue; }
    if ($force) $pdo->exec("DROP TABLE IF EXISTS `$dst`.`$t`");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$dst`.`$t` LIKE `$src`.`$t`");
    $pdo->beginTransaction();
    $copied = $pdo->exec("INSERT INTO `$dst`.`$t` SELECT * FROM `$src`.`$t`");
    $pdo->commit();
    echo sprintf("%-18s %6d 행 복제\n", $t, $copied);
}

if (!$dry) {
    if (in_array('sync_meta', $tables, true)) {
        $pdo->exec("DELETE FROM `$dst`.`sync_meta` WHERE k IN ('last_synced_at','data_changed_at','cmt_scan_at')");
    }
    // ai_* 테이블 + 시드 생성 (db() 는 config 의 slackai.db_name 을 쓴다)
    require __DIR__ . '/../db.php';
    db();
    $aiTables = array_filter($pdo->query("SHOW TABLES FROM `$dst`")->fetchAll(PDO::FETCH_COLUMN), fn($x) => str_starts_with($x, 'ai_') || $x === 'request_tags');
    echo "AI 테이블 " . count($aiTables) . "개 준비: " . implode(', ', $aiTables) . "\n";
    echo "태그 " . (int)$pdo->query("SELECT COUNT(*) FROM `$dst`.ai_tags")->fetchColumn() . "개 시드\n";
}
echo "완료\n";
