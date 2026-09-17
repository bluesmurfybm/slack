<?php
/** 통합 테스트 전용 설정. 배포에 포함하지 않습니다. */
declare(strict_types=1);

return [
    // 테스트 DB 접속 정보. 환경변수로 덮어쓸 수 있습니다.
    //   set BCTEST_DB_USER=root & set BCTEST_DB_PASS=비밀번호
    'db' => [
        'host'     => getenv('BCTEST_DB_HOST') ?: '127.0.0.1',
        'port'     => (int)(getenv('BCTEST_DB_PORT') ?: 3306),
        'database' => getenv('BCTEST_DB_NAME') ?: 'iworks_test',
        'user'     => getenv('BCTEST_DB_USER') ?: 'root',
        'password' => getenv('BCTEST_DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
    ],
    'iworks' => [
        'login_url'    => '/login.php',
        'session_keys' => [
            'id'    => ['ss_mb_id', 'mb_id'],
            'name'  => ['ss_mb_name', 'mb_name'],
            'email' => ['ss_mb_email', 'mb_email'],
        ],
        'member' => [
            'table'        => 'member',
            'col_id'       => 'mb_id',
            'col_name'     => 'mb_name',
            'col_email'    => 'mb_email',
            'col_active'   => 'mb_leave_date',
            'active_where' => "(mb_leave_date IS NULL OR mb_leave_date = '')",
            'col_slack_id' => 'mb_slack_id',
        ],
        'superadmins' => ['admin'],
    ],
    'notify' => [
        'enabled' => false, // 테스트에서는 실제 발송하지 않고 로그만 남긴다
        'mail'  => ['from_name' => 'BlueCart', 'from_address' => 'noreply@example.com',
                    'transport' => 'mail',
                    'smtp' => ['host' => '', 'port' => 587, 'user' => '', 'password' => '', 'encryption' => 'tls']],
        'slack' => ['bot_token' => '', 'webhook_url' => '', 'default_channel' => '#general'],
    ],
    'app' => [
        'base_url'   => 'http://localhost/bluecart',
        'upload_dir' => sys_get_temp_dir() . '/bluecart_test',
        'upload_max' => 10485760,
        'upload_ext' => ['jpg', 'png', 'pdf'],
        'timezone'   => 'Asia/Seoul',
        'debug'      => true,
    ],
];
