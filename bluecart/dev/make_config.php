<?php
/**
 * setup.bat 이 모은 접속 정보로 config/config.php 를 만든다.
 *
 *   php dev/make_config.php <host> <port> <db> <user> <pass>
 *   php dev/make_config.php --portal          (포털 config.php 에서 DB 물려받기)
 *
 * 값을 var_export 로 써 넣으므로 비밀번호에 따옴표가 들어 있어도 깨지지 않습니다.
 * (배치 파일에서 sed 로 치환하면 이런 값에서 망가집니다.)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI 전용입니다.');
}

$root = dirname(__DIR__);
$out  = $root . '/config/config.php';

if (is_file($out)) {
    fwrite(STDERR, "config/config.php 가 이미 있습니다. 덮어쓰지 않습니다.\n");
    exit(0);
}

// --portal : 포털 config.php 에서 DB 를 물려받는 설정을 만든다.
//            로컬에서 포털 모듈로 띄워 볼 때 쓴다.
if (($argv[1] ?? '') === '--portal') {
    $portalDir = dirname($root);
    if (!is_file($portalDir . '/config.php')) {
        fwrite(STDERR, "포털 config.php 가 없습니다: {$portalDir}/config.php\n");
        exit(1);
    }
    $uploadDir = str_replace('\\', '/', dirname($root)) . '/bluecart-data';
    $tpl = file_get_contents(__DIR__ . '/../config/config.iworks.sample.php');
    $tpl = str_replace(
        "'upload_dir' => '/var/www/iworks-data/bluecart',",
        "'upload_dir' => " . var_export($uploadDir, true) . ',',
        $tpl
    );
    $tpl = str_replace("'debug'      => false,", "'debug'      => true,", $tpl);
    $tpl = str_replace("'enabled' => true,", "'enabled' => false,   // 로컬: 기록만 남기고 실제 발송하지 않음", $tpl);
    $tpl = str_replace(
        " * iworks 포털 모듈로 동작할 때의 설정.",
        " * iworks 포털 모듈 · 로컬 개발용 설정. dev/setup.bat 이 자동으로 만들었습니다.\n * 생성 시각: " . date('Y-m-d H:i:s'),
        $tpl
    );
    if (file_put_contents($out, $tpl) === false) {
        fwrite(STDERR, "config/config.php 를 쓰지 못했습니다.\n");
        exit(1);
    }
    echo "[작업] config/config.php 생성 (포털 DB 물려받음)" . PHP_EOL;
    echo "       첨부 저장소: {$uploadDir}" . PHP_EOL;
    exit(0);
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 3306);
$db   = $argv[3] ?? 'bluecart_dev';
$user = $argv[4] ?? 'root';
$pass = $argv[5] ?? '';

// 첨부 저장소는 프로젝트 폴더 바깥, 나란한 위치에 둔다.
$uploadDir = str_replace('\\', '/', dirname($root)) . '/bluecart-data';

$e = fn(string $v): string => var_export($v, true);

$php = <<<PHP
<?php
/**
 * 로컬 개발용 설정. dev/setup.bat 이 자동으로 만들었습니다.
 * 생성 시각: {date}
 *
 * 이 파일은 .gitignore 에 있어 저장소에 올라가지 않습니다.
 * 운영 설정은 config/config.sample.php 를 보고 따로 작성하세요.
 */

declare(strict_types=1);

return [

    'db' => [
        'host'     => {host},
        'port'     => {port},
        'database' => {db},
        'user'     => {user},
        'password' => {pass},
        'charset'  => 'utf8mb4',
    ],

    'iworks' => [
        // 로컬에서는 dev/login.php 가 세션을 채웁니다.
        'login_url'    => '/dev/login.php',
        'session_keys' => [
            'id'    => ['ss_mb_id'],
            'name'  => ['ss_mb_name'],
            'email' => ['ss_mb_email'],
        ],
        // 로컬 전용 가짜 회원 테이블 (dev/seed_dev.sql 이 만듭니다).
        // 실제 iworks 회원 테이블로 시험하려면 여기를 그 테이블로 바꾸세요.
        'member' => [
            'table'        => 'bc_dev_member',
            'col_id'       => 'mb_id',
            'col_name'     => 'mb_name',
            'col_email'    => 'mb_email',
            'col_active'   => 'mb_leave_date',
            'active_where' => "(mb_leave_date IS NULL OR mb_leave_date = '')",
            'col_slack_id' => 'mb_slack_id',
        ],
        'superadmins' => ['hoyoung'],
    ],

    'notify' => [
        // 로컬에서는 실제로 보내지 않고 bc_notify_log 에 기록만 남깁니다.
        'enabled' => false,
        'mail' => [
            'from_name'    => 'BlueCart (local)',
            'from_address' => 'noreply@localhost',
            'transport'    => 'mail',
            'smtp' => ['host' => '', 'port' => 587, 'user' => '',
                       'password' => '', 'encryption' => 'tls'],
        ],
        'slack' => [
            'bot_token'       => '',
            'webhook_url'     => '',
            'default_channel' => '#test',
        ],
    ],

    'app' => [
        'base_url'   => 'http://127.0.0.1:8080',
        'upload_dir' => {upload},
        'upload_max' => 10 * 1024 * 1024,
        'upload_ext' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xlsx', 'xls', 'hwp', 'docx'],
        'timezone'   => 'Asia/Seoul',
        'debug'      => true,
    ],
];

PHP;

$php = strtr($php, [
    '{date}'   => date('Y-m-d H:i:s'),
    '{host}'   => $e($host),
    '{port}'   => (string)$port,
    '{db}'     => $e($db),
    '{user}'   => $e($user),
    '{pass}'   => $e($pass),
    '{upload}' => $e($uploadDir),
]);

if (file_put_contents($out, $php) === false) {
    fwrite(STDERR, "config/config.php 를 쓰지 못했습니다.\n");
    exit(1);
}

echo "[작업] config/config.php 생성 ({$user}@{$host}:{$port}/{$db})" . PHP_EOL;
echo "       첨부 저장소: {$uploadDir}" . PHP_EOL;
