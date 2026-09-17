<?php
/**
 * 이관 결과 대조 — SQLite 원본과 MySQL 사본을 값 단위로 비교한다. CLI 전용, 일회성.
 *
 *   php learn/tools/verify_migration.php [--db=경로] [--uploads=경로]
 *
 * migrate_from_learning.php 를 돌린 뒤 **지우기 전에** 이걸 돌린다. 여기서 불일치 0 이
 * 나와야 원본(learning/)을 지워도 되는 상태다. 종료코드는 불일치가 있으면 1 이라
 * 스크립트에서 그대로 조건으로 쓸 수 있다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI 에서만 실행한다\n");
}

require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/storage.php';

$opt     = getopt('', ['db::', 'uploads::']);
$SQLITE  = $opt['db']      ?? __DIR__ . '/../../learning/var/learning.db';
$UPLOADS = $opt['uploads'] ?? __DIR__ . '/../../learning/var/uploads';

if (!is_file($SQLITE)) { fwrite(STDERR, "learning.db 를 찾을 수 없다: {$SQLITE}\n"); exit(2); }

$src = new PDO('sqlite:' . $SQLITE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dst = learn_db();

echo '원본: ', realpath($SQLITE), "\n\n";

$diff = 0;
$checked = 0;

/** SQLite 는 정수·실수를 그대로, MySQL(PDO) 은 문자열로 준다 — 값으로 비교한다. */
function same($a, $b) {
    if ($a === null || $b === null) return $a === $b;
    if (is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) < 1e-9;
    return (string)$a === (string)$b;
}

/* ---------- 신청 · 이수증 · 이력 (id 를 유지하므로 id 로 맞춘다) ---------- */

foreach ([['requests', 'learn_requests'],
          ['certs', 'learn_certs'],
          ['histories', 'learn_histories']] as [$s, $d]) {

    $a = $src->query("SELECT * FROM learning_{$s} ORDER BY id")->fetchAll();
    $b = [];
    foreach ($dst->query("SELECT * FROM {$d} ORDER BY id")->fetchAll() as $r) {
        $b[(int)$r['id']] = $r;
    }

    if (count($a) !== count($b)) {
        printf("  건수 불일치 %s: 원본 %d vs 사본 %d\n", $d, count($a), count($b));
        $diff++;
    }
    foreach ($a as $row) {
        $id = (int)$row['id'];
        if (!isset($b[$id])) { echo "  {$d} #{$id} 이 사본에 없다\n"; $diff++; continue; }
        foreach ($row as $col => $v) {
            // catalog_id 는 learn 에서 새로 생긴 컬럼이라 원본에 없다
            if (!array_key_exists($col, $b[$id])) continue;
            $checked++;
            if (!same($v, $b[$id][$col])) {
                printf("  %s #%d.%s: %s != %s\n", $d, $id, $col,
                       var_export($v, true), var_export($b[$id][$col], true));
                $diff++;
            }
        }
    }
    printf("%-18s %d행 대조\n", $d, count($a));
}

/* ---------- 이수증 파일 ---------- */

$destDir = learn_upload_dir();
$noFile  = [];
foreach ($dst->query("SELECT id, path FROM learn_certs ORDER BY id")->fetchAll() as $c) {
    if (!is_file($destDir . '/' . basename((string)$c['path']))) $noFile[] = $c['path'];
}
printf("%-18s %d개 확인", '이수증 파일', (int)$dst->query("SELECT COUNT(*) FROM learn_certs")->fetchColumn());
if ($noFile) {
    printf(", 없음 %d개\n", count($noFile));
    foreach (array_slice($noFile, 0, 10) as $p) echo "    - {$p}\n";
    $diff += count($noFile);
} else {
    echo ", 모두 있음\n";
}

/* ---------- 이름으로 맞추는 것들 — 원본이 사본에 다 들어갔는지만 본다 ---------- */

echo "\n[이름으로 맞춘 표]\n";

$have = [];
foreach ($dst->query("SELECT name FROM learn_sites")->fetchAll(PDO::FETCH_COLUMN) as $n) $have[$n] = 1;
$miss = 0;
foreach ($src->query("SELECT name FROM learning_sites")->fetchAll(PDO::FETCH_COLUMN) as $n) {
    if (!isset($have[$n])) { echo "  플랫폼 누락: {$n}\n"; $miss++; }
}
printf("%-18s 원본 %d개, 누락 %d개\n", '플랫폼',
       (int)$src->query("SELECT COUNT(*) FROM learning_sites")->fetchColumn(), $miss);
$diff += $miss;

$have = [];
foreach ($dst->query("SELECT site, large, medium FROM learn_categories")->fetchAll() as $r) {
    $have[$r['site'] . "\x1f" . $r['large'] . "\x1f" . $r['medium']] = 1;
}
$miss = 0;
foreach ($src->query("SELECT site, large, medium FROM learning_categories")->fetchAll() as $r) {
    $k = $r['site'] . "\x1f" . $r['large'] . "\x1f" . $r['medium'];
    if (!isset($have[$k])) { echo "  분류 누락: {$r['site']} / {$r['large']} / {$r['medium']}\n"; $miss++; }
}
printf("%-18s 원본 %d개, 누락 %d개\n", '분류',
       (int)$src->query("SELECT COUNT(*) FROM learning_categories")->fetchColumn(), $miss);
$diff += $miss;

/* 관리자는 그대로 복제한다 — 양쪽이 정확히 같아야 한다(고정 관리자는 코드가 따로 넣는다) */
$a = $src->query("SELECT LOWER(email) FROM learning_admins")->fetchAll(PDO::FETCH_COLUMN);
$b = $dst->query("SELECT LOWER(email) FROM learn_admins")->fetchAll(PDO::FETCH_COLUMN);
sort($a); sort($b);
if ($a !== $b) {
    echo "  관리자 명단이 다르다\n    원본: " . implode(', ', $a) . "\n    사본: " . implode(', ', $b) . "\n";
    $diff++;
}
printf("%-18s %d명\n", '관리자', count($a));

echo "\n총 {$checked}개 값 비교, 불일치 {$diff}건\n";
echo $diff === 0
    ? "\n이관이 원본과 일치한다. learning/ 을 지워도 된다.\n"
    : "\n불일치가 있다. learning/ 을 지우지 마라.\n";

exit($diff === 0 ? 0 : 1);
