<?php
/**
 * 실행 환경 점검.
 *
 *   php dev/check.php
 *   php dev/check.php --host=192.168.0.252 --user=bluecart --pass=... --db=bluecart_dev
 *
 * config/config.php 가 있으면 그 값을 쓰고, 없으면 인자로 받은 값을 씁니다.
 *
 * ┌────────────────────────────────────────────────────────────────┐
 * │ 이 파일만은 PHP 5.6 문법으로 작성되어 있습니다.                  │
 * │ "당신의 PHP 가 너무 낮습니다" 를 알려주려면 낮은 PHP 에서도      │
 * │ 실행이 되어야 하기 때문입니다. 나머지 코드는 PHP 8 기준입니다.   │
 * └────────────────────────────────────────────────────────────────┘
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('CLI 전용입니다.');
}

$PASS = 0;
$WARN = 0;
$FAIL = 0;

function line($s) { echo $s . PHP_EOL; }
function head($s) { line(''); line('=== ' . $s . ' ==='); }

function ok_($what, $detail) {
    global $PASS; $PASS++;
    line('  [정상] ' . $what . ($detail !== '' ? '  ' . $detail : ''));
}
function warn_($what, $detail) {
    global $WARN; $WARN++;
    line('  [주의] ' . $what . ($detail !== '' ? '  ' . $detail : ''));
}
function fail_($what, $detail) {
    global $FAIL; $FAIL++;
    line('  [오류] ' . $what . ($detail !== '' ? '  ' . $detail : ''));
}

// ---------------------------------------------------------------------
line('');
line('BlueCart 환경 점검');
line('  목표 환경: PHP 8.0+ / MySQL 5.7+ (운영은 PHP 8 + MySQL 8)');
line(str_repeat('-', 60));

// ---------------------------------------------------------------------
head('PHP');

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
if ($phpOk) {
    ok_('PHP ' . PHP_VERSION, '(8.0 이상 필요)');
} else {
    fail_('PHP ' . PHP_VERSION . ' — 8.0 이상이 필요합니다', '');
    line('');
    line('         이 프로그램은 PHP 8 문법(match 식, str_starts_with 등)을 씁니다.');
    line('         7.x 에서는 문법 오류로 아예 뜨지 않습니다.');
    line('');
    line('         Laragon: Menu > PHP > Version 에서 8.x 선택');
    line('         또는 windows.php.net 에서 8.3 Thread Safe 을 받아');
    line('         C:\\php83 에 풀고 PATH 를 바꾸세요.');
}

line('  실행 파일: ' . (defined('PHP_BINARY') ? PHP_BINARY : '?'));
$ini = php_ini_loaded_file();
line('  php.ini:   ' . ($ini ? $ini : '(없음)'));

// ---------------------------------------------------------------------
head('PHP 확장 모듈');

$need = array(
    'pdo_mysql' => 'DB 접속',
    'mbstring'  => '한글 문자열 처리',
    'curl'      => '슬랙 발송',
    'fileinfo'  => '첨부파일 내용 검사',
    'zlib'      => '엑셀 생성',
    'json'      => 'API 응답',
);
$missing = array();
foreach ($need as $ext => $why) {
    if (extension_loaded($ext)) {
        ok_($ext, '— ' . $why);
    } else {
        fail_($ext . ' 없음', '— ' . $why);
        $missing[] = $ext;
    }
}
if ($missing) {
    line('');
    line('         php.ini 에서 아래 줄 앞의 세미콜론(;)을 지우고 저장하세요.');
    foreach ($missing as $m) {
        line('           extension=' . $m);
    }
    line('         고친 뒤 이 점검을 다시 실행하세요.');
}

// ---------------------------------------------------------------------
head('업로드 설정');

$umf = ini_get('upload_max_filesize');
$pms = ini_get('post_max_size');
line('  upload_max_filesize = ' . $umf);
line('  post_max_size       = ' . $pms);
line('  max_file_uploads    = ' . ini_get('max_file_uploads'));

function to_bytes($v) {
    $v = trim((string)$v);
    if ($v === '') { return 0; }
    $last = strtolower(substr($v, -1));
    $n = (float)$v;
    if ($last === 'g') { $n *= 1024 * 1024 * 1024; }
    elseif ($last === 'm') { $n *= 1024 * 1024; }
    elseif ($last === 'k') { $n *= 1024; }
    return (int)$n;
}
if (to_bytes($pms) <= to_bytes($umf)) {
    warn_('post_max_size 가 upload_max_filesize 보다 크지 않습니다',
          '— 여러 개를 한 번에 올리면 실패합니다');
} else {
    ok_('업로드 크기 설정', '');
}

// ---------------------------------------------------------------------
head('iworks 포털 연동');

$root      = dirname(__DIR__);
$portalDir = dirname($root);
$portalHas = array(
    'auth.php'        => is_file($portalDir . '/auth.php'),
    'worksystems.php' => is_file($portalDir . '/worksystems.php'),
    'config.php'      => is_file($portalDir . '/config.php'),
    'db.php'          => is_file($portalDir . '/db.php'),
    'styles/topbar.css' => is_file($portalDir . '/styles/topbar.css'),
);
$inPortal = $portalHas['auth.php'] && $portalHas['worksystems.php'] && $portalHas['config.php'];

line('  모듈 폴더: ' . basename($root));
line('  상위 폴더: ' . $portalDir);

if ($inPortal) {
    ok_('포털 모듈로 동작합니다', '');
    foreach ($portalHas as $f => $yes) {
        if (!$yes) { warn_('포털 파일 없음: ' . $f, ''); }
    }
    // worksystems.json 등록 여부
    $wsPath = $portalDir . '/worksystems.json';
    if (is_file($wsPath)) {
        $ws = json_decode(file_get_contents($wsPath), true);
        $keys = array();
        if (is_array($ws)) {
            foreach ($ws as $sysRow) { $keys[] = isset($sysRow['key']) ? $sysRow['key'] : ''; }
        }
        if (in_array('bluecart', $keys, true)) {
            ok_('worksystems.json 에 등록됨', '');
        } else {
            warn_('worksystems.json 에 등록되지 않았습니다', '— 상단바 메뉴에 안 나옵니다');
            line('         등록할 항목:');
            line('           { "key": "bluecart", "emoji": "ð", "label": "BlueCart", "path": "bluecart/index.php" }');
        }
    }
} else {
    warn_('단독 실행 모드입니다', '— 포털 파일을 찾지 못했습니다');
    foreach ($portalHas as $f => $yes) {
        line('    ' . ($yes ? '있음' : '없음') . '  ../' . $f);
    }
    line('         포털 모듈로 쓰려면 iworks 저장소 안에 두세요.');
    line('         config.php 는 .gitignore 대상이라 클론 직후에는 없습니다.');
}

// ---------------------------------------------------------------------
head('설정 파일');

$configPath = $root . '/config/config.php';
$cfg        = null;

if (is_file($configPath)) {
    ok_('config/config.php 있음', '');
    if ($phpOk) {
        $cfg = include $configPath;
    } else {
        // 낮은 PHP 에서도 DB 정보만 긁어내 접속 확인은 해 본다.
        $raw = file_get_contents($configPath);
        $cfg = array('db' => array(
            'host'     => preg_match("/'host'\s*=>\s*'([^']*)'/", $raw, $m) ? $m[1] : '127.0.0.1',
            'port'     => preg_match("/'port'\s*=>\s*(\d+)/", $raw, $m) ? (int)$m[1] : 3306,
            'database' => preg_match("/'database'\s*=>\s*'([^']*)'/", $raw, $m) ? $m[1] : '',
            'user'     => preg_match("/'user'\s*=>\s*'([^']*)'/", $raw, $m) ? $m[1] : '',
            'password' => preg_match("/'password'\s*=>\s*'([^']*)'/", $raw, $m) ? $m[1] : '',
        ));
        line('  (PHP 가 낮아 설정을 대충 읽었습니다. DB 접속만 확인합니다.)');
    }
} else {
    warn_('config/config.php 없음', '— dev\\setup.bat 을 먼저 실행하세요');
}

// 명령행 인자가 있으면 그쪽을 우선
$args = array();
foreach ($argv as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) { $args[$m[1]] = $m[2]; }
}
if ($args) {
    $cfg = array('db' => array(
        'host'     => isset($args['host']) ? $args['host'] : '127.0.0.1',
        'port'     => isset($args['port']) ? (int)$args['port'] : 3306,
        'database' => isset($args['db'])   ? $args['db']   : '',
        'user'     => isset($args['user']) ? $args['user'] : '',
        'password' => isset($args['pass']) ? $args['pass'] : '',
    ));
    line('  명령행 인자의 접속 정보를 씁니다.');
}

// ---------------------------------------------------------------------
head('데이터베이스');

if ($cfg === null || empty($cfg['db']['host'])) {
    warn_('접속 정보가 없어 건너뜁니다', '');
} elseif (!extension_loaded('pdo_mysql')) {
    fail_('pdo_mysql 이 없어 접속할 수 없습니다', '');
} else {
    $db = $cfg['db'];
    line('  대상: ' . $db['user'] . '@' . $db['host'] . ':' . $db['port']
         . ' / ' . $db['database']);

    // 먼저 포트가 열려 있는지
    $errno = 0; $errstr = '';
    $sock = @fsockopen($db['host'], (int)$db['port'], $errno, $errstr, 5);
    if ($sock) {
        fclose($sock);
        ok_('포트 연결', '');
    } else {
        fail_('포트에 닿지 않습니다', '(' . $errstr . ')');
        line('         방화벽이나 네트워크 경로를 확인하세요.');
        line('         원격이라면 MySQL 의 bind-address 도 봐야 합니다.');
    }

    try {
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4';
        if (!empty($db['database'])) { $dsn .= ';dbname=' . $db['database']; }
        $pdo = new PDO($dsn, $db['user'], $db['password'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ));
        ok_('접속 성공', '');

        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        line('  서버 버전: ' . $ver);

        $isMaria = stripos($ver, 'mariadb') !== false;
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $ver, $m)) {
            $major = (int)$m[1]; $minor = (int)$m[2];
        } else {
            $major = 0; $minor = 0;
        }

        // 운영 목표는 MySQL 8 입니다. 로컬에서 5.x 를 쓰는 것은 괜찮지만
        // 두 곳의 버전이 다르다는 점은 알고 있어야 합니다.
        if ($isMaria) {
            ok_('MariaDB — 호환됩니다', '(운영은 MySQL 8 기준)');
        } elseif ($major >= 8) {
            ok_('MySQL ' . $major . '.' . $minor . ' — 운영 환경과 같습니다', '');
        } elseif ($major == 5 && $minor >= 7) {
            ok_('MySQL 5.7 — 동작합니다', '');
            line('         운영은 MySQL 8 입니다. 스키마와 질의는 양쪽 모두에서');
            line('         확인했지만, 배포 전 운영 DB 에서도 점검을 한 번 돌리세요.');
        } elseif ($major == 5 && $minor == 6) {
            warn_('MySQL 5.6 — 동작하지만 오래된 버전입니다', '(2021년 지원 종료)');
            line('         운영은 MySQL 8 입니다. 배포 전 운영 DB 에서 점검을 돌리세요.');
        } elseif ($major == 5 && $minor <= 5) {
            fail_('MySQL ' . $major . '.' . $minor . ' 은 쓸 수 없습니다', '');
            line('         DATETIME 컬럼의 기본값(CURRENT_TIMESTAMP)을 5.5 는 못 씁니다.');
            line('         5.6 이상 서버를 쓰거나, 로컬에 MySQL 을 따로 설치하세요.');
            line('         Laragon 을 쓰신다면 이미 MySQL 8 이 들어 있습니다.');
        } else {
            warn_('버전을 판정하지 못했습니다', $ver);
        }

        // 문자셋
        $rows = $pdo->query("SHOW VARIABLES LIKE 'character_set_server'")->fetch(PDO::FETCH_ASSOC);
        $csServer = isset($rows['Value']) ? $rows['Value'] : '?';
        line('  서버 기본 문자셋: ' . $csServer);

        $conn = $pdo->query("SHOW VARIABLES LIKE 'character_set_client'")->fetch(PDO::FETCH_ASSOC);
        if (isset($conn['Value']) && $conn['Value'] === 'utf8mb4') {
            ok_('연결 문자셋 utf8mb4', '');
        } else {
            warn_('연결 문자셋이 utf8mb4 가 아닙니다',
                  '(' . (isset($conn['Value']) ? $conn['Value'] : '?') . ')');
        }

        // utf8mb4 인덱스 길이 (5.6 계열에서 문제)
        if (!$isMaria && $major == 5 && $minor == 6) {
            $lp = $pdo->query("SHOW VARIABLES LIKE 'innodb_large_prefix'")->fetch(PDO::FETCH_ASSOC);
            if (isset($lp['Value']) && strtolower($lp['Value']) !== 'on') {
                warn_('innodb_large_prefix 가 꺼져 있습니다',
                      '— 인덱스 길이 제한에 걸릴 수 있습니다');
            }
        }

        // 권한
        try {
            $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
            $canCreate = false;
            foreach ($grants as $g) {
                if (stripos($g, 'ALL PRIVILEGES') !== false || stripos($g, 'CREATE') !== false) {
                    $canCreate = true;
                }
            }
            if ($canCreate) {
                ok_('테이블 생성 권한 있음', '');
            } else {
                warn_('CREATE 권한을 확인하지 못했습니다', '— 스키마 생성이 막힐 수 있습니다');
            }
        } catch (Exception $e) {
            warn_('권한 확인 실패', $e->getMessage());
        }

        // 테이블 존재 여부
        if (!empty($db['database'])) {
            try {
                $n = $pdo->query("SHOW TABLES LIKE 'bc_%'")->rowCount();
                if ($n > 0) {
                    ok_('BlueCart 테이블 ' . $n . '개 있음', '');
                } else {
                    warn_('BlueCart 테이블이 없습니다', '— sql/01_schema.sql 을 실행하세요');
                }
            } catch (Exception $e) {
                warn_('테이블 확인 실패', $e->getMessage());
            }
        }

        // 시간대
        $tz = $pdo->query("SELECT @@global.time_zone, @@session.time_zone")->fetch(PDO::FETCH_NUM);
        line('  시간대: global=' . $tz[0] . ' session=' . $tz[1]);

    } catch (PDOException $e) {
        fail_('접속 실패', $e->getMessage());
        $msg = $e->getMessage();
        line('');
        if (strpos($msg, '1045') !== false) {
            line('         계정이나 비밀번호가 틀렸습니다.');
        } elseif (strpos($msg, '1044') !== false || strpos($msg, '1049') !== false) {
            line('         데이터베이스가 없거나 접근 권한이 없습니다.');
        } elseif (strpos($msg, '2002') !== false || strpos($msg, '2003') !== false) {
            line('         서버에 닿지 않습니다. 주소·포트·방화벽을 확인하세요.');
            line('         원격 접속을 허용하려면 MySQL 쪽에서:');
            line("           CREATE USER 'bluecart'@'%' IDENTIFIED BY '...';");
            line("           GRANT ALL ON bluecart_dev.* TO 'bluecart'@'%';");
        } elseif (strpos($msg, '1130') !== false) {
            line('         이 PC 의 IP 에서 접속이 허용되어 있지 않습니다.');
            line('         MySQL 계정의 host 부분을 확인하세요.');
        }
    }
}

// ---------------------------------------------------------------------
head('첨부파일 저장소');

$uploadDir = null;
if (is_array($cfg) && isset($cfg['app']['upload_dir'])) {
    $uploadDir = $cfg['app']['upload_dir'];
} elseif (is_file($configPath)) {
    $raw = file_get_contents($configPath);
    if (preg_match("/'upload_dir'\s*=>\s*'([^']*)'/", $raw, $m)) { $uploadDir = $m[1]; }
}

if ($uploadDir === null) {
    warn_('경로를 확인하지 못했습니다', '');
} else {
    line('  경로: ' . $uploadDir);
    if (!is_dir($uploadDir)) {
        fail_('폴더가 없습니다', '');
        line('         만들어 주세요: mkdir "' . $uploadDir . '"');
    } elseif (!is_writable($uploadDir)) {
        fail_('쓰기 권한이 없습니다', '');
    } else {
        ok_('쓰기 가능', '');
    }

    // 웹 루트 안에 있으면 위험
    // 프로그램 폴더 "안" 인지 판정한다.
    // 단순 문자열 접두사로 비교하면 .../bluecart-data 가 .../bluecart 로
    // 시작한다고 잘못 잡힌다. 구분자를 붙여 디렉터리 경계를 맞춘다.
    $realRoot = realpath($root);
    $realUp   = realpath($uploadDir);
    if ($realRoot && $realUp) {
        $normRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $normUp   = rtrim(str_replace('\\', '/', $realUp), '/') . '/';
        // Windows 는 경로 대소문자를 구분하지 않는다
        if (DIRECTORY_SEPARATOR === '\\') {
            $normRoot = strtolower($normRoot);
            $normUp   = strtolower($normUp);
        }
        $inside = (strpos($normUp, $normRoot) === 0);
    } else {
        $inside = false;
    }

    if ($inside) {
        fail_('저장소가 프로그램 폴더 안에 있습니다', '');
        line('         웹에서 직접 열릴 수 있습니다. 바깥으로 옮기세요.');
    }
}

// ---------------------------------------------------------------------
line('');
line(str_repeat('-', 60));
line(sprintf('정상 %d / 주의 %d / 오류 %d', $PASS, $WARN, $FAIL));
line(str_repeat('-', 60));
if ($FAIL > 0) {
    line('오류를 먼저 해결하세요. 위의 안내를 참고하시면 됩니다.');
} elseif ($WARN > 0) {
    line('실행에는 지장이 없지만 주의 항목을 확인해 보세요.');
} else {
    line('모두 준비되었습니다. dev\\serve.bat 을 실행하세요.');
}
line('');

exit($FAIL > 0 ? 1 : 0);
