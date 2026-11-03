<?php
/**
 * book(BlueBooks) 설정과 DB — 포털과 같은 slackapi DB 에 book_requests 테이블 하나로 들어간다.
 *
 * FastAPI/SQLite 시절(app.py 의 requests 테이블)과 컬럼은 같다. 다른 점은 둘뿐이다.
 *  - 날짜는 VARCHAR 그대로다. 화면·API 가 'YYYY-MM-DD' 문자열을 그대로 쓴다(learn·dti 와 같다).
 *  - yes24_url 은 옮기지 않는다. 야스24 → 교보문고로 바뀐 뒤로 어디서도 읽지 않던 컬럼이다.
 * 설정 값을 읽는 곳은 여기 하나뿐이다.
 */

if (is_file(__DIR__ . '/../core/sqlite_compat.php')) require_once __DIR__ . '/../core/sqlite_compat.php';   // 로컬 시험(SQLite)용
require_once __DIR__ . '/../core/db.php';   // add_column_if_missing()

const BOOK_GUBUNS = ['직무관련', '직무무관'];
const BOOK_DEFAULT_GUBUN = '직무무관';
// 완료 처리와 남의 신청 수정·삭제는 여기 적힌 사람만 한다. 명단이 늘면 여기를 고친다(dti 와 같다).
const BOOK_DEFAULT_ADMINS = ['jian@bluesoft.co.kr'];

/* ---------- 설정 ---------- */

function book_config(array $over = []): array {
    $config = $over + [
        'db' => [],
        'admin_emails' => BOOK_DEFAULT_ADMINS,
        'slack_webhook' => null,   // 없으면 신청 알림만 조용히 꺼진다
        'kakao_key' => '',         // 없으면 도서명 자동완성만 꺼진다
        'seed' => __DIR__ . '/seed.json',
        // 화면이 뒤에 /?view=profile 같은 걸 붙여 쓴다. 페이지가 아니라 기준 경로여야 한다
        'portal_url' => '..',
    ];
    $config['admin_emails'] = array_map('strtolower', $config['admin_emails']);
    return $config;
}

function book_config_from_portal(array $over = [], ?array $portal = null): array {
    $portal = $portal ?? require __DIR__ . '/../config.php';

    return book_config($over + [
        'db' => $portal['db'],
        'slack_webhook' => $portal['book_slack_webhook'] ?? null,
        'kakao_key' => (string)($portal['kakao_rest_api_key'] ?? book_kakao_key_from_file()),
    ]);
}

/** 파이썬 시절의 kakao_keys.json 을 그대로 읽는다 — 키를 config.local.php 로 옮기기 전까지 자동완성이 끊기지 않게 */
function book_kakao_key_from_file(): string {
    $file = __DIR__ . '/kakao_keys.json';
    if (!is_file($file)) return '';
    $cfg = json_decode((string)file_get_contents($file), true);
    return is_array($cfg) ? trim((string)($cfg['rest_api_key'] ?? '')) : '';
}

function book_is_admin(array $config, ?string $email): bool {
    return $email !== null && in_array(strtolower($email), $config['admin_emails'], true);
}

/* ---------- 연결 ---------- */

function book_connect(array $config): PDO {
    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}";
    $connect = function_exists('db_connect') ? 'db_connect' : fn($dsn, $u, $p, $o) => new PDO($dsn, $u, $p, $o);
    $pdo = $connect($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ], $db['name']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}`
                CHARACTER SET {$db['charset']} COLLATE {$db['charset']}_unicode_ci");
    $pdo->exec("USE `{$db['name']}`");

    return $pdo;
}

function book_migrate(PDO $pdo, array $config): void {
    $pdo->exec(book_schema());
    // 컬럼 추가는 저장소 공통 헬퍼로만 한다 — 동시 요청에서 "확인 후 ALTER" 는 레이스가 난다
    foreach (book_schema_alters() as $sql) {
        add_column_if_missing($pdo, $sql);
    }
    book_seed($pdo, $config);
}

/* ---------- 스키마 ---------- */

function book_schema(): string {
    return "
        CREATE TABLE IF NOT EXISTS `book_requests` (
            `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `year`            INT           NULL                    COMMENT '신청일 앞 네 자리. 연도 필터용',
            `date`            VARCHAR(10)   NOT NULL DEFAULT ''     COMMENT '신청일 YYYY-MM-DD',
            `applicant`       VARCHAR(60)   NOT NULL DEFAULT ''     COMMENT '신청자 이름(표시용)',
            `applicant_email` VARCHAR(190)  NOT NULL DEFAULT ''     COMMENT '본인 판정 근거. 로그인 연동 전 행은 비어 있을 수 있다',
            `gubun`           VARCHAR(20)   NOT NULL DEFAULT '직무무관' COMMENT '직무관련|직무무관',
            `title`           VARCHAR(500)  NOT NULL,
            `author`          VARCHAR(300)  NOT NULL DEFAULT '',
            `publisher`       VARCHAR(200)  NOT NULL DEFAULT '',
            `price`           INT           NULL                    COMMENT '도서단가(원). 모르면 NULL',
            `done`            TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '처리(구매) 완료',
            `note`            VARCHAR(2000) NOT NULL DEFAULT '',
            `kyobo_url`       VARCHAR(1000) NULL                    COMMENT '교보문고 상품 페이지. 비면 검색으로 연다',
            `thumbnail`       VARCHAR(1000) NULL                    COMMENT '카카오 책 검색이 준 표지',
            `created_at`      VARCHAR(19)   NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_year_date` (`year`, `date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='도서 구매 신청(BlueBooks)'";
}

/** 이미 만들어진 설치본에 컬럼을 붙일 때 여기에 ALTER 문을 더한다 */
function book_schema_alters(): array {
    return [];
}

/* ---------- 시드 ---------- */

/**
 * 표가 비어 있으면 seed.json(원본 엑셀에서 뽑은 127건)을 넣는다.
 * 이름→이메일은 포털 계정(portal_users)으로 맞춘다. 퇴사자처럼 계정이 없는 이름은 비워 둔다 —
 * 본인 판정에는 못 쓰지만 표시는 그대로 된다.
 */
function book_seed(PDO $pdo, array $config): void {
    if ((int)$pdo->query("SELECT COUNT(*) FROM book_requests")->fetchColumn() > 0) return;
    if (!is_file($config['seed'])) return;

    $rows = json_decode((string)file_get_contents($config['seed']), true);
    if (!is_array($rows)) return;

    $emails = book_member_emails();
    $insert = $pdo->prepare("INSERT INTO book_requests
        (`year`, `date`, `applicant`, `applicant_email`, `gubun`, `title`, `author`, `publisher`, `price`, `done`, `note`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $r) {
        $name = trim((string)($r['applicant'] ?? ''));
        $date = (string)($r['date'] ?? '');
        $insert->execute([
            $r['year'] ?? (ctype_digit(substr($date, 0, 4)) ? (int)substr($date, 0, 4) : null),
            $date,
            $name,
            $emails[$name] ?? '',
            in_array($r['gubun'] ?? '', BOOK_GUBUNS, true) ? $r['gubun'] : BOOK_DEFAULT_GUBUN,
            trim((string)($r['title'] ?? '')),
            trim((string)($r['author'] ?? '')),
            trim((string)($r['publisher'] ?? '')),
            isset($r['price']) && is_numeric($r['price']) ? (int)$r['price'] : null,
            !empty($r['done']) ? 1 : 0,
            trim((string)($r['note'] ?? '')),
            $now,
        ]);
    }
    error_log('[book] seed.json ' . count($rows) . '건을 book_requests 에 넣었다');
}

/**
 * 포털 계정 이름 → 이메일. portal_db() 로 읽는다 — 포털 화면을 한 번도 안 연 새 설치본에서
 * book API 가 먼저 불리면 portal_users 가 아직 없다. portal_db() 가 그 표를 만들고 시드까지 넣는다.
 */
function book_member_emails(): array {
    $map = [];
    foreach (portal_db()->query("SELECT name, email FROM portal_users") as $r) {
        $map[trim((string)$r['name'])] = (string)$r['email'];
    }
    return $map;
}
