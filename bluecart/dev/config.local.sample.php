<?php
/**
 * 로컬 개발용 설정 예시 (Windows).
 *
 * 보통은 dev\setup.bat 이 config\config.php 를 자동으로 만들어 줍니다.
 * 이 파일은 손으로 고쳐 쓰고 싶을 때의 본보기입니다.
 *
 *   copy dev\config.local.sample.php config\config.php
 *
 * 운영 설정은 config/config.sample.php 를 쓰세요.
 */

declare(strict_types=1);

return [

    'db' => [
        // 로컬 MySQL 이면 127.0.0.1, 원격이면 그 서버 주소.
        // 예) '192.168.0.252'
        'host'     => '127.0.0.1',
        'port'     => 3306,
        // 개발용 DB 를 따로 만드세요. 운영 DB 를 그대로 쓰면 안 됩니다.
        'database' => 'bluecart_dev',
        'user'     => 'root',
        // Laragon 은 기본 비밀번호가 없고, XAMPP 도 대개 비어 있습니다.
        // 원격 서버라면 그 계정의 비밀번호를 넣으세요.
        'password' => '',
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
        // 로컬 전용 가짜 회원 테이블 (dev/seed_dev.sql 이 만듭니다)
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
        // 로컬에서는 실제로 보내지 않습니다. bc_notify_log 에 기록만 남습니다.
        // 발송 내용을 확인하려면:
        //   SELECT event_code, target_role, channel, recipient, subject
        //     FROM bc_notify_log ORDER BY id DESC LIMIT 20;
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
        // 웹 루트 바깥. 프로젝트 폴더와 나란히 두었습니다.
        'upload_dir' => 'J:/kimhy/private_project/bluecart-data',
        'upload_max' => 10 * 1024 * 1024,
        'upload_ext' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xlsx', 'xls', 'hwp', 'docx'],
        'timezone'   => 'Asia/Seoul',
        // 로컬에서는 오류를 화면에 그대로 띄웁니다.
        'debug'      => true,
    ],
];
