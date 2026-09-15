<?php
/**
 * magazine(FastAPI/SQLite) → dti(PHP/MySQL) 데이터 이관. CLI 전용, 일회성.
 *
 *   php dti/tools/migrate_from_magazine.php [--db=경로] [--uploads=경로] [--force] [--dry-run]
 *
 * 전환이 끝나면 이 파일과 src/Migration/ 을 지운다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI 에서만 실행한다\n");
}

require __DIR__ . '/../bootstrap.php';

$options = getopt('', ['db::', 'uploads::', 'force', 'dry-run']);
$sqlitePath = $options['db'] ?? __DIR__ . '/../../magazine/var/magazine.db';
$uploadDir = $options['uploads'] ?? __DIR__ . '/../../magazine/var/uploads';
$force = array_key_exists('force', $options);
$dryRun = array_key_exists('dry-run', $options);

if (!is_file($sqlitePath)) {
    fwrite(STDERR, "magazine.db 를 찾을 수 없다: {$sqlitePath}\n");
    exit(1);
}

$source = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$config = dti_config_from_portal();
$target = dti_connect($config);
dti_migrate($target);

echo '원본: ', realpath($sqlitePath), "\n";
echo '대상: MySQL dti_* 테이블', $dryRun ? '  [--dry-run: 쓰지 않는다]' : '', "\n\n";

foreach (dti_migrate_source_counts($source) as $table => $count) {
    printf("  %-24s %d건\n", $table, $count);
}
echo "\n";

$have = dti_migrate_target_topic_count($target);
if ($have && !$force) {
    // --dry-run 은 쓰지 않으므로 막지 않는다. 미리보기까지 막으면 확인할 방법이 없다
    $message = "dti_topics 에 이미 {$have}건이 있다. 덮어쓰려면 --force 를 준다\n"
        . "(--force 는 아티클·발표·반응·연관을 비우고 다시 넣는다. 분야는 이름으로 맞춘다.)\n";
    if (!$dryRun) {
        fwrite(STDERR, $message);
        exit(1);
    }
    echo '※ ', $message, "\n";
}

$report = dti_migrate_run($source, $target, $config, $uploadDir, $dryRun, $force);

echo "옮긴 결과\n";
printf("  아티클  %d건\n", $report['topics']);
printf("  발표    %d건\n", $report['presentations']);
printf("  반응    %d건\n", $report['emotions']);
printf("  분야    %d건 추가\n", $report['fields']);
printf("  파일    %d개\n", $report['files']);
if (!$dryRun) printf("  연관    %d쌍 다시 계산\n", $report['related']);

if ($report['missing']) {
    echo "\n※ 아래는 DB 행만 있고 원본 파일이 없다. 행은 옮겼으므로 목록의 자료 표시는\n";
    echo "   그대로지만, 열기를 누르면 404 가 난다.\n";
    foreach ($report['missing'] as $line) echo '   - ', $line, "\n";
}
