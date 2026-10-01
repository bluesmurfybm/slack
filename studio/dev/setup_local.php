<?php
/** 로컬 개발 환경 준비 — 테스트 DB 생성, 스키마 적재, 포털 config.php 생성. 운영에서 실행 금지. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 이 파일은 로컬 개발 전용입니다.                                    │
   │ DB 를 만들고 설정 파일을 쓰므로 CLI 에서만 돌게 막아 둡니다.       │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('dev/setup_local.php 는 명령줄에서만 실행할 수 있습니다.');
}

$MODULE = dirname(__DIR__);          // <포털>/studio
$PORTAL = dirname($MODULE);          // <포털>

// ---------------------------------------------------------------------
// 설정값 — 로컬 WAMP 기준. 다르면 여기만 고친다.
// ---------------------------------------------------------------------
$cfg = [
    'host'    => getenv('BA_DB_HOST') ?: '127.0.0.1',
    'port'    => (int)(getenv('BA_DB_PORT') ?: 3306),
    'name'    => getenv('BA_DB_NAME') ?: 'iworks_local',
    'user'    => getenv('BA_DB_USER') ?: 'root',
    'pass'    => getenv('BA_DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];

function say(string $s): void { echo $s . PHP_EOL; }
function fail(string $s): never { fwrite(STDERR, "\n[실패] $s\n"); exit(1); }

say('');
say('BlueAssign 로컬 개발 환경 준비');
say(str_repeat('=', 60));
say(sprintf('  DB  %s@%s:%d/%s', $cfg['user'], $cfg['host'], $cfg['port'], $cfg['name']));
say('');

// ---------------------------------------------------------------------
// 1. DB 생성
// ---------------------------------------------------------------------
say('[1/4] 데이터베이스');
try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fail('MySQL 에 붙지 못했습니다: ' . $e->getMessage()
       . "\n       WAMP 가 떠 있는지, 접속 정보가 맞는지 확인하세요."
       . "\n       다른 값을 쓰려면 환경변수 BA_DB_HOST/PORT/NAME/USER/PASS 를 주세요.");
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$cfg['name']}`");
$pdo->exec("SET time_zone = '+09:00'");
say("      OK  `{$cfg['name']}` 준비됨");

// ---------------------------------------------------------------------
// 2. 포털 config.php
//
// 없을 때만 만든다. 이미 있으면 절대 덮어쓰지 않는다 — 운영 설정을
// 실수로 날리는 사고가 가장 비싸다.
// ---------------------------------------------------------------------
say('[2/4] 포털 config.php');
$configPath = $PORTAL . '/config.php';

if (is_file($configPath)) {
    say('      건너뜀  이미 있습니다. 덮어쓰지 않습니다.');
    say('              ' . $configPath);
} else {
    // AES-256-GCM 키. core/auth.php 의 enc_token()/dec_token() 이 쓴다.
    // **로컬 전용 더미**다. 운영 키와 아무 관계가 없고, 알 필요도 없다.
    // 이 키로 암호화한 값은 이 로컬 DB 안에서만 의미가 있다.
    $devKey = base64_encode(random_bytes(32));

    $php = <<<PHP
<?php
/**
 * 포털 로컬 개발 설정 — studio/dev/setup_local.php 가 만들었습니다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 이 파일은 로컬 전용입니다. git 에 올리지 마세요.                   │
 * │ .gitignore 가 config.php 를 막고 있습니다. 그 줄을 지우지 마세요.  │
 * │                                                                  │
 * │ 아래 'key' 는 이 컴퓨터에서만 쓰는 더미 값입니다.                  │
 * │ 운영 키가 아니며, 운영 DB 의 암호화된 값을 풀지 못합니다.          │
 * └──────────────────────────────────────────────────────────────────┘
 */

return [
    'db' => [
        'host'    => '{$cfg['host']}',
        'port'    => {$cfg['port']},
        'name'    => '{$cfg['name']}',
        'user'    => '{$cfg['user']}',
        'pass'    => '{$cfg['pass']}',
        'charset' => 'utf8mb4',
    ],

    // core/auth.php enc_token()/dec_token() 용. 로컬 더미.
    'key' => '{$devKey}',

    // book 모듈은 별도 프로세스라 절대주소로 덮어쓸 수 있다. 로컬에선 안 띄운다.
    'links' => [
        'book' => null,
    ],
];
PHP;

    if (file_put_contents($configPath, $php) === false) {
        fail('config.php 를 쓰지 못했습니다: ' . $configPath);
    }
    say('      OK  새로 만들었습니다 (로컬 전용 더미 key 포함)');
    say('          ' . $configPath);
}

// ---------------------------------------------------------------------
// 3. 스키마
// ---------------------------------------------------------------------
say('[3/4] BlueAssign 스키마');

/**
 * .sql 파일을 실행한다.
 *
 * 세미콜론으로 자르되 문자열·주석 안의 세미콜론은 건너뛴다.
 * 우리 .sql 에는 트리거나 프로시저가 없어 DELIMITER 는 다루지 않는다.
 */
function runSqlFile(PDO $pdo, string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        fail('읽지 못했습니다: ' . $path);
    }

    $stmts = [];
    $buf = '';
    $inStr = false;
    $strCh = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c    = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if (!$inStr) {
            // 한 줄 주석
            if ($c === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $i += 2;
                while ($i < $len && !($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) { $i++; }
                $i++;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $inStr = true; $strCh = $c;
            } elseif ($c === ';') {
                $stmts[] = $buf; $buf = '';
                continue;
            }
        } else {
            if ($c === '\\') { $buf .= $c . $next; $i++; continue; }
            if ($c === $strCh) { $inStr = false; }
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') { $stmts[] = $buf; }

    $n = 0;
    foreach ($stmts as $s) {
        if (trim($s) === '') { continue; }
        $pdo->exec($s);
        $n++;
    }
    return $n;
}

foreach (['001_schema.sql', '002_seed_domain.sql'] as $file) {
    $path = $MODULE . '/sql/' . $file;
    if (!is_file($path)) {
        fail('없습니다: ' . $path);
    }
    try {
        $n = runSqlFile($pdo, $path);
        say("      OK  $file  ($n 문)");
    } catch (PDOException $e) {
        fail("$file 적재 실패: " . $e->getMessage());
    }
}

$tables  = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = '{$cfg['name']}' AND table_name LIKE 'ba\\_%'"
)->fetchColumn();
$domains = (int)$pdo->query('SELECT COUNT(*) FROM ba_domain')->fetchColumn();
say("      확인  ba_ 표 {$tables}개 / 분야 {$domains}개");

// ---------------------------------------------------------------------
// 4. 포털 표 + 계정
//
// core/db.php 의 portal_db() 가 portal_users 를 만들고 13명을 시드한다.
// 여기서 한 번 불러 두면 로그인할 계정이 생긴다.
// ---------------------------------------------------------------------
say('[4/4] 포털 계정');
// core/auth.php 가 아니라 core/db.php 만 부른다.
// auth.php 는 include 하는 순간 세션을 여는데, CLI 에서 이미 글자를 찍은 뒤라
// "headers already sent" 경고가 줄줄이 난다. 여기에 세션은 필요 없다.
require_once $PORTAL . '/core/db.php';
$ppdo  = portal_db();
$users = (int)$ppdo->query('SELECT COUNT(*) FROM portal_users')->fetchColumn();
say("      OK  portal_users {$users}명 (초기 비밀번호 blue\$123)");

say('');
say(str_repeat('=', 60));
say('준비 끝났습니다.');
say('');
say('  서버 띄우기   assign\\dev\\serve.bat');
say('                또는  php -S 127.0.0.1:8099 -t . studio/dev/router.php');
say('  접속          http://127.0.0.1:8099/studio/');
say('  API 시험      php studio/dev/api_test.php');
say('');
say('  샘플 데이터가 필요하면:');
say('     mysql -u ' . $cfg['user'] . ' ' . $cfg['name'] . ' < studio/dev/seed_dev.sql');
say('');
