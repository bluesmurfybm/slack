<?php
/**
 * iworks 포털 모듈로 동작할 때의 설정.
 *
 *   cp config/config.iworks.sample.php config/config.php
 *
 * DB 접속 정보는 포털 config.php 를 그대로 가져다 씁니다.
 * 접속 정보가 두 군데 적혀 있으면 한쪽만 바뀌었을 때 원인을 찾기 어렵습니다.
 *
 * 포털 config.php 의 형식(core/db.php 참고):
 *   ['db' => ['host','port','name','user','pass','charset'], ...]
 */

declare(strict_types=1);

$portal = require dirname(__DIR__, 2) . '/config.php';
$pdb    = $portal['db'];

return [

    // -----------------------------------------------------------------
    // 데이터베이스 — 포털과 같은 DB. bc_ 접두사 테이블만 씁니다.
    // -----------------------------------------------------------------
    'db' => [
        'host'     => $pdb['host'],
        'port'     => (int)($pdb['port'] ?? 3306),
        'database' => $pdb['name'],
        'user'     => $pdb['user'],
        'password' => $pdb['pass'],
        'charset'  => $pdb['charset'] ?? 'utf8mb4',
    ],

    // -----------------------------------------------------------------
    // 포털 연동
    // -----------------------------------------------------------------
    'iworks' => [
        // 포털 core/auth.php 로 세션과 로그인 사용자를 가져온다.
        'use_portal_auth' => true,

        // worksystems.json 에 등록한 key. 상단바에서 현재 위치 표시와
        // 미로그인 안내(?need_login=)에 쓰인다.
        'module_key' => 'bluecart',

        // 포털이 있으면 쓰이지 않지만, 단독 실행 대비로 남겨 둔다.
        'login_url'    => '../index.php',
        'session_keys' => [
            'id'    => ['portal_uid'],
            'name'  => [],
            'email' => [],
        ],

        // 구성원 명단 = 포털 계정. learn/dti 와 같은 원본을 본다.
        // 식별자로 이메일을 쓰므로 col_id 도 email 이다.
        'member' => [
            'table'      => 'portal_users',
            'col_id'     => 'email',
            'col_name'   => 'name',
            'col_email'  => 'email',
            // portal_users 에는 퇴사 구분 컬럼이 없다. 조건을 비워 전원을 본다.
            'col_active'   => null,
            'active_where' => '',
            // slack_token_enc 는 개인 토큰이라 사용자 ID 가 아니다.
            // 슬랙 DM 은 이메일로 users.lookupByEmail 조회해서 찾는다.
            'col_slack_id' => null,
        ],

        // 최초 관리자. 역할 배정 화면에 들어갈 사람을 이메일로 적는다.
        // 화면에서 배정하고 나면 비워도 된다.
        'superadmins' => ['kimhy@bluesoft.co.kr'],
    ],

    // -----------------------------------------------------------------
    // 알림
    // -----------------------------------------------------------------
    'notify' => [
        'enabled' => true,

        'mail' => [
            'from_name'    => 'BlueCart',
            'from_address' => getenv('BLUECART_MAIL_FROM') ?: 'noreply@bluesoft.co.kr',
            'transport'    => 'mail',
            'smtp' => [
                'host'       => getenv('BLUECART_SMTP_HOST') ?: '',
                'port'       => (int)(getenv('BLUECART_SMTP_PORT') ?: 587),
                'user'       => getenv('BLUECART_SMTP_USER') ?: '',
                'password'   => getenv('BLUECART_SMTP_PASS') ?: '',
                'encryption' => 'tls',
            ],
        ],

        'slack' => [
            // 아래 두 값은 관리자 탭 > 알림 설정 화면에서 넣는 쪽이 편하다.
            // 화면에서 넣은 값이 우선이고, 포털 config.php 의 key 로 암호화해
            // bc_setting 에 들어간다. 여기(또는 환경변수)는 비워 둬도 된다.
            // 필요 스코프: chat:write, users:read, users:read.email
            'bot_token'       => getenv('BLUECART_SLACK_BOT_TOKEN') ?: '',
            'webhook_url'     => getenv('BLUECART_SLACK_WEBHOOK') ?: '',
            'default_channel' => '#general',
        ],
    ],

    // -----------------------------------------------------------------
    // 기타
    // -----------------------------------------------------------------
    'app' => [
        'base_url' => 'http://iworks.bizblue.co.kr/bluecart',
        // 웹 루트 바깥. 포털의 다른 모듈도 var/ 를 쓰지만 그쪽은 .htaccess 로
        // 막아 두는 방식이다. 여기서는 트리 바깥에 두는 쪽을 택했다.
        'upload_dir' => '/var/www/iworks-data/bluecart',
        'upload_max' => 10 * 1024 * 1024,
        'upload_ext' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xlsx', 'xls', 'hwp', 'docx'],
        'timezone'   => 'Asia/Seoul',
        'debug'      => false,
    ],
];
