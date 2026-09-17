<?php
/**
 * BlueCart 환경설정 샘플.
 *
 *   cp config/config.sample.php config/config.php
 *
 * config.php 는 .gitignore 에 등록되어 있습니다. 실제 계정 정보는 절대
 * 저장소에 커밋하지 마십시오. 서버 환경변수를 쓰면 파일에 평문으로
 * 남기지 않을 수 있습니다.
 */

declare(strict_types=1);

return [

    // -----------------------------------------------------------------
    // 데이터베이스
    // -----------------------------------------------------------------
    'db' => [
        'host'     => getenv('BLUECART_DB_HOST') ?: '127.0.0.1',
        'port'     => (int)(getenv('BLUECART_DB_PORT') ?: 3306),
        'database' => getenv('BLUECART_DB_NAME') ?: 'iworks',
        'user'     => getenv('BLUECART_DB_USER') ?: 'CHANGE_ME',
        'password' => getenv('BLUECART_DB_PASS') ?: 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // -----------------------------------------------------------------
    // iworks 연동
    //   BlueCart 는 자체 로그인을 두지 않고 iworks 세션을 그대로 씁니다.
    //   아래 값은 iworks 실제 구현에 맞춰 반드시 수정해야 합니다.
    //   → includes/auth.php 주석 참고
    // -----------------------------------------------------------------
    'iworks' => [
        // 로그인하지 않은 사용자를 보낼 곳
        'login_url'    => '/login.php',

        // 세션에서 사용자 식별자를 담고 있는 키 (후보를 순서대로 탐색)
        'session_keys' => [
            'id'    => ['ss_mb_id', 'mb_id', 'user_id', 'uid'],
            'name'  => ['ss_mb_name', 'mb_name', 'user_name'],
            'email' => ['ss_mb_email', 'mb_email', 'user_email'],
        ],

        // 구성원 목록 조회용 매핑 (역할 배정 화면에서 사용)
        'member' => [
            'table'      => 'member',          // iworks 회원 테이블
            'col_id'     => 'mb_id',
            'col_name'   => 'mb_name',
            'col_email'  => 'mb_email',
            'col_active' => 'mb_leave_date',   // 퇴사/탈퇴 판정 컬럼 (없으면 null)
            // 재직자만 뽑는 조건. 컬럼 구조에 맞게 수정하세요.
            'active_where' => "(mb_leave_date IS NULL OR mb_leave_date = '')",
            // 슬랙 사용자 ID 컬럼이 회원 테이블에 있으면 지정 (없으면 null)
            'col_slack_id' => null,
        ],

        // 시스템 관리자로 간주할 사용자 ID 목록 (부트스트랩용)
        // 최초 1회 역할 배정을 위해 필요합니다. 이후 bc_role_assign 으로 관리.
        'superadmins' => ['admin'],
    ],

    // -----------------------------------------------------------------
    // 알림
    // -----------------------------------------------------------------
    'notify' => [
        // 전체 발송 스위치. false 면 로그만 남기고 실제 발송하지 않음(테스트용)
        'enabled' => true,

        'mail' => [
            'from_name'    => 'BlueCart',
            'from_address' => getenv('BLUECART_MAIL_FROM') ?: 'noreply@bizblue.co.kr',
            // 'mail'(PHP mail()) 또는 'smtp'
            'transport'    => 'mail',
            'smtp' => [
                'host'       => getenv('BLUECART_SMTP_HOST') ?: '',
                'port'       => (int)(getenv('BLUECART_SMTP_PORT') ?: 587),
                'user'       => getenv('BLUECART_SMTP_USER') ?: '',
                'password'   => getenv('BLUECART_SMTP_PASS') ?: '',
                'encryption' => 'tls', // tls | ssl | none
            ],
        ],

        'slack' => [
            // Bot User OAuth Token (xoxb-...). DM 발송에 필요합니다.
            // 필요 스코프: chat:write, users:read, users:read.email
            'bot_token'       => getenv('BLUECART_SLACK_BOT_TOKEN') ?: '',
            // 채널 발송만 쓸 경우 Incoming Webhook 도 가능
            'webhook_url'     => getenv('BLUECART_SLACK_WEBHOOK') ?: '',
            'default_channel' => '#general',
        ],
    ],

    // -----------------------------------------------------------------
    // 기타
    // -----------------------------------------------------------------
    'app' => [
        // 웹에서 접근하는 BlueCart 기본 경로 (링크 생성용)
        'base_url'    => 'http://iworks.bizblue.co.kr/bluecart',
        // 첨부파일 저장 경로 (웹 루트 바깥을 권장)
        // 첨부파일 저장 경로. 반드시 웹 루트 바깥에 두세요.
        // 웹에서 직접 열리는 위치에 있으면 올라온 파일이 실행될 수 있습니다.
        'upload_dir'  => '/var/www/iworks-data/bluecart',
        'upload_max'  => 10 * 1024 * 1024, // 파일 하나당 10MB
        // 확장자 화이트리스트. 여기에 없는 형식은 거부됩니다.
        // 내용이 확장자와 맞는지도 함께 검사하므로(includes/model/Attachment.php)
        // 새 형식을 추가하면 MIME_BY_EXT 에도 대응표를 넣어야 합니다.
        'upload_ext'  => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xlsx', 'xls', 'hwp', 'docx'],
        'timezone'    => 'Asia/Seoul',
        'debug'       => false,
    ],
];
