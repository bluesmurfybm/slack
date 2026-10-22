<?php
/**
 * 로컬 시험 준비 — MySQL 없이 포털(blue-iWorks) 전체를 이 PC 에서 띄운다. 명령줄 전용.
 *
 *     D:\php82\php.exe dev\local_setup.php
 *
 * 몇 번을 돌려도 된다. 하는 일:
 *  1) 루트 config.php 가 없으면 SQLite 로컬용으로 만든다(db.driver = sqlite). 있으면 손대지 않는다.
 *  2) bluecart/config/config.php 가 없으면 포털 연동 표본(config.iworks.sample.php)을 복사한다.
 *  3) bluecart·studio 의 스키마 SQL 을 core/sqlite_compat.php 번역기로 적용한다.
 *     적용한 파일은 _local_sql_applied 표에 적어 두고 다시 돌리지 않는다.
 *  4) 포털·각 모듈의 DB 함수를 한 번씩 불러 자동 생성 표를 만든다.
 *  5) 리조트 예약 기존 이용내역 16건을 넣는다(비어 있을 때만).
 *
 * 데이터는 var/localdb/*.sqlite 다. 지우면 처음부터 다시 만든다(포털 계정 초기 비번 blue$123).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$say = fn($s) => print($s . PHP_EOL);

/* ---------- 1) config.php ---------- */
$cfgFile = $root . '/config.php';
if (!is_file($cfgFile)) {
    file_put_contents($cfgFile, <<<'PHP'
<?php
/**
 * 로컬 시험용 포털 설정 — dev/local_setup.php 가 만들었다. 운영 서버의 config.php 와 다르다.
 * db.driver = sqlite 이면 core/sqlite_compat.php 가 MySQL 대신 var/localdb/<name>.sqlite 를 연다
 * (php -S 내장 서버·명령줄에서만). 슬랙·Gmail 같은 바깥 연동은 키가 없어 꺼져 있다.
 */
$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    file_put_contents($local, "<?php\nreturn [\n    'key' => '" . base64_encode(random_bytes(32)) . "',\n];\n");
}
$localCfg = require $local;

return [
    'db' => [
        'driver'  => 'sqlite',
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => '',
        'pass'    => '',
        'name'    => 'slack_db',
        'charset' => 'utf8mb4',
    ],
    'key' => $localCfg['key'],
    'list_id'         => '',
    'comment_channel' => '',
    'list_url'        => '',
    'dti_slack_webhook' => null,
    'links' => [
        'book' => $localCfg['book_url'] ?? 'http://127.0.0.1:8000/',
    ],
    'soffice' => 'soffice',
];
PHP);
    $say('config.php 를 만들었습니다 (SQLite 로컬용).');
} else {
    $say('config.php 가 이미 있어 그대로 둡니다.');
}
$cfg = require $cfgFile;
if (($cfg['db']['driver'] ?? '') !== 'sqlite') {
    fwrite(STDERR, "config.php 의 db.driver 가 'sqlite' 가 아닙니다. 운영용 설정 같아 멈춥니다.\n");
    exit(1);
}

/* ---------- 2) bluecart 설정 ---------- */
$bcCfg = $root . '/bluecart/config/config.php';
if (!is_file($bcCfg)) {
    copy($root . '/bluecart/config/config.iworks.sample.php', $bcCfg);
    $say('bluecart/config/config.php 를 포털 연동 표본으로 만들었습니다.');
}

/* ---------- 3) 스키마 SQL ---------- */
require_once $root . '/core/db.php';
$pdo = portal_db();   // 포털 표 + 사내 인원 13명(초기 비번 blue$123)
$pdo->exec("CREATE TABLE IF NOT EXISTS `_local_sql_applied` (`file` VARCHAR(190) NOT NULL, `applied_at` DATETIME NOT NULL, PRIMARY KEY (`file`))");
$done = array_flip($pdo->query("SELECT file FROM _local_sql_applied")->fetchAll(PDO::FETCH_COLUMN));

$files = [];
foreach (glob($root . '/bluecart/sql/*.sql') as $f) $files[] = $f;
// studio 는 apply.sh 의 FILES 목록이 원본이다(003·004 는 001 에 이미 들어 있어 빠져 있다)
$apply = (string)file_get_contents($root . '/studio/sql/apply.sh');
if (preg_match('/^FILES="(.*?)"/ms', $apply, $m)) {
    foreach (preg_split('/\s+/', trim($m[1])) as $f) $files[] = $root . '/studio/sql/' . $f;
}

foreach ($files as $f) {
    $key = substr(str_replace('\\', '/', $f), strlen(str_replace('\\', '/', $root)) + 1);   // bluecart/sql/01_schema.sql
    if (isset($done[$key])) continue;
    $n = 0;
    foreach (local_sql_statements((string)file_get_contents($f)) as $stmt) {
        try {
            $pdo->exec($stmt);
            $n++;
        } catch (PDOException $e) {
            // 이미 있는 컬럼(42S21)은 운영 apply.sh 가 건너뛰는 것과 같다
            if ($e->getCode() === '42S21') continue;
            fwrite(STDERR, "  {$key} 에서 실패: " . $e->getMessage() . "\n    " . mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 300) . "\n");
            exit(1);
        }
    }
    $pdo->prepare("INSERT INTO _local_sql_applied (file, applied_at) VALUES (?, NOW())")->execute([$key]);
    $say("  적용: {$key} ({$n}문장)");
}

/* ---------- 4) 모듈별 자동 생성 표 ---------- */
$touch = [
    'slack'   => fn() => (require_once $root . '/slack/db.php') && db(),
    'access'  => fn() => (require_once $root . '/access/db.php') && access_db(),
    // learn·dti·slackai·blueledger 는 상수·설정을 guard 에서 받아 화면을 처음 열 때 만든다
];
foreach ($touch as $name => $fn) {
    try {
        $fn();
        $say("  {$name} 표 확인");
    } catch (Throwable $e) {
        $say("  {$name} 표 만들기 실패 — 화면을 열 때 다시 시도합니다: " . $e->getMessage());
    }
}

/* ---------- 5) 리조트 기존 이용내역 ----------
 * 리조트 예약은 엑셀에서 옮긴 16건이 있어야 화면이 비지 않는다. 이미 있으면 도구가 건너뛴다.
 * 별도 프로세스로 돌린다 — 포털 세션·상수를 이 스크립트와 섞지 않기 위해서다.
 */
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/blueledger/resort/tools/import_seed.php'), $rc);
if ($rc !== 0) $say('  리조트 기존 이용내역 넣기 실패 — 나중에 blueledger\\resort\\tools\\import_seed.php 를 직접 돌리세요.');

$say('');
$say('준비 끝. 로컬_실행.bat 을 더블클릭하거나:');
$say('  D:\\php82\\php.exe -S 127.0.0.1:8780 -t . dev\\local_router.php');
$say('  → http://127.0.0.1:8780/  (포털 계정 이메일 + 초기 비번 blue$123)');

/** SQL 파일 → 문장 목록. -- 주석·/* *\/ 주석을 걷어 내고 문자열 바깥의 ; 로 자른다. */
function local_sql_statements(string $sql): array {
    $out = [];
    $cur = '';
    $n = strlen($sql);
    $q = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q) {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $n) { $cur .= $sql[++$i]; continue; }
            if ($c === $q) $q = null;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
        if ($c === '-' && substr($sql, $i, 2) === '--') { $e = strpos($sql, "\n", $i); $i = $e === false ? $n : $e; $cur .= "\n"; continue; }
        if ($c === '#') { $e = strpos($sql, "\n", $i); $i = $e === false ? $n : $e; $cur .= "\n"; continue; }
        if ($c === '/' && substr($sql, $i, 2) === '/*') { $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $n : $e + 1; continue; }
        if ($c === ';') { if (trim($cur) !== '') $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}
