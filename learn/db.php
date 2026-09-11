<?php
/**
 * learn(BlueLearn) 모듈 DB — 포털과 같은 slackapi DB 에 learn_* 테이블로 들어간다.
 *
 *  FastAPI/SQLModel 버전(learning/)의 스키마를 그대로 옮긴 것이다. 두 가지만 다르다.
 *   - 사이트·분류를 FK 가 아니라 이름 문자열로 들고 있는 건 그대로다. 사이트나 분류가
 *     지워지거나 이름이 바뀌어도 지난 신청 건의 표시가 깨지면 안 된다.
 *   - 날짜·시각은 DATETIME 이 아니라 문자열로 둔다. 요청상태를 타임스탬프에서 파생하고
 *     그 비교가 문자열 비교로 성립해야 하기 때문 — 화면·API 도 이 형식을 그대로 쓴다.
 */

require_once __DIR__ . '/../db.php';   // 포털 연결 + 나중에 컬럼을 붙일 때 쓸 add_column_if_missing()

const POLICY_ID = 1;

const DEFAULT_SITES = [
    ['인프런', 'https://www.inflearn.com'],
    ['패스트캠퍼스', 'https://fastcampus.co.kr'],
];

// 두 플랫폼의 카테고리 페이지에서 그대로 옮긴 값(2026-08-30 기준).
// 구분 기호는 일반 가운뎃점(U+00B7) 양쪽에 공백이다 — 다른 문자로 바꾸면 시드 값과
// 화면에서 고른 값이 서로 다른 문자열이 된다.
const DEFAULT_CATEGORIES = [
    '인프런' => [
        'AI 기술' => ['AI에이전트 개발', '딥러닝 · 머신러닝', '컴퓨터 비전', '자연어 처리', '인공지능 기타'],
        'AI 활용(AX)' => ['AI 시작하기', 'AI 개발 활용', 'AI 실무 활용', 'AI 크리에이티브'],
        '개발 · 프로그래밍' => ['웹 개발', 'AI 코딩', '프론트엔드', '백엔드', '풀스택', '모바일 앱 개발', '프로그래밍 언어', '알고리즘 · 자료구조', '데이터베이스', '데브옵스 · 인프라', '소프트웨어 테스트', '개발 도구', '웹 퍼블리싱', '데스크톱 앱 개발', 'VR/AR', '개발 · 프로그래밍 자격증', '개발 · 프로그래밍 기타'],
        '게임 개발' => ['게임 프로그래밍', '게임 기획', '게임 아트 · 그래픽', '게임 개발 기타'],
        '데이터 사이언스' => ['데이터 분석', '데이터 엔지니어링', '데이터 사이언스 자격증', '데이터 사이언스 기타'],
        '보안 · 네트워크' => ['보안', '네트워크', '시스템 · 운영체제', '클라우드', '블록체인', '보안 · 네트워크 자격증', '보안 · 네트워크 기타'],
        '하드웨어' => ['컴퓨터 구조', '임베디드 · IoT', '반도체', '로봇공학', '모빌리티', '하드웨어 자격증', '하드웨어 기타'],
        '디자인 · 아트' => ['CAD · 3D 모델링', 'UX/UI', '그래픽 디자인', '웹툰 · 이모티콘', '사진 · 영상', '사운드', '디자인 자격증', '디자인 기타'],
        '기획 · 경영 · 마케팅' => ['기획 · PM · PO', '마케팅', '경영 · 전략', '기획 · 경영 · 마케팅 자격증', '기획 · 경영 · 마케팅 기타'],
        '외국어' => ['영어', '일본어', '중국어', '스페인어', '독일어'],
        '업무 생산성' => ['업무 자동화', '오피스', '생산성 도구', '업무 생산성 기타'],
        '커리어 · 자기계발' => ['취업 · 이직', '창업 · 부업', '개인 브랜딩', '취미', '금융 · 재테크', '교양', '커리어 · 자기계발 기타'],
        '대학 교육' => ['수학', '공학', '상경', '자연과학', '교육학', '대학 교육 기타'],
    ],
    '패스트캠퍼스' => [
        'AI TECH' => ['LLM', 'RAG & AI Agent', '딥러닝/머신러닝', '컴퓨터 비전', '자율주행/로봇'],
        'AI CREATIVE' => ['2D/3D 이미지 생성', '영상 생성'],
        'AI/업무생산성' => ['AI 생산성', '마케팅', '데이터분석'],
        '개발/데이터' => ['프론트엔드 개발', '백엔드 개발', '모바일 앱 개발', '게임 개발', '데이터 엔지니어링', 'DevOps/Infra', '컴퓨터 공학/SW 엔지니어링', '반도체'],
        '디자인' => ['UX/UI/BX', '그래픽/타이포/브랜딩'],
        '영상/3D' => ['영상/사진', '모션그래픽', '3D', '블렌더', '버튜버'],
        '금융/투자' => ['재무/회계/세무', '재테크/주식', '금융 투자 실무', '부동산'],
        '드로잉/일러스트' => ['드로잉/이모티콘', '캐릭터일러스트', '웹툰/웹소설', '원화/컨셉아트'],
        '비즈니스/기획' => ['PM/PO', '기획/경영/리더십', '부업/창업', '글쓰기'],
    ],
];


function learn_db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/../config.php';
    $d   = $cfg['db'];
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $dsn = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], $opt);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}`
                CHARACTER SET {$d['charset']} COLLATE {$d['charset']}_unicode_ci");
    $pdo->exec("USE `{$d['name']}`");

    // 요청상태(수강승인요청 → … → 환급완료)는 컬럼으로 두지 않는다 — 승인·청구·환급이 각각
    // 별도 시점이라 한 컬럼으로 들고 있으면 계속 어긋난다. 아래 *_at 들에서 파생한다
    // (lib/status.php 의 learn_derive_status).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_requests` (
            `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `site`                  VARCHAR(100)  NOT NULL DEFAULT ''         COMMENT '교육 플랫폼 이름(문자열 복사)',
            `category_large`        VARCHAR(150)  NOT NULL DEFAULT ''         COMMENT '대분류 이름',
            `category_medium`       VARCHAR(150)  NOT NULL DEFAULT ''         COMMENT '중분류 이름',
            `level`                 VARCHAR(20)   NOT NULL DEFAULT ''         COMMENT '초급/중급/고급',
            `title`                 VARCHAR(500)  NOT NULL                    COMMENT '강의/교육명',
            `url`                   VARCHAR(1000) NOT NULL DEFAULT ''         COMMENT '수강주소',
            `account_type`          VARCHAR(20)   NOT NULL DEFAULT '개인계정' COMMENT '회사계정/개인계정',
            `applicant`             VARCHAR(60)   NOT NULL DEFAULT ''         COMMENT '신청자 이름',
            `applicant_email`       VARCHAR(190)  NOT NULL DEFAULT ''         COMMENT '신원의 출처. 세션에서만 채운다',
            `duration_min`          INT           NOT NULL DEFAULT 0          COMMENT '총 강의시간(분)',
            `is_free`               TINYINT(1)    NOT NULL DEFAULT 0          COMMENT '1=무료 강의. price=0 과 다른 상태다',
            `price`                 INT           NOT NULL DEFAULT 0          COMMENT '수강료',
            `start_date`            VARCHAR(10)   NOT NULL DEFAULT ''         COMMENT '강의 시작일 YYYY-MM-DD',
            `end_date`              VARCHAR(10)   NOT NULL DEFAULT ''         COMMENT '강의 종료일',
            `progress`              VARCHAR(20)   NOT NULL DEFAULT '시작전'   COMMENT '시작전/진행중/완료',
            `progress_at`           VARCHAR(19)   NOT NULL DEFAULT ''         COMMENT '진행상태를 마지막으로 바꾼 시각',
            `approved_at`           VARCHAR(19)   NOT NULL DEFAULT '',
            `rejected_at`           VARCHAR(19)   NOT NULL DEFAULT '',
            `reject_reason`         VARCHAR(1000) NOT NULL DEFAULT '',
            `claimed_at`            VARCHAR(19)   NOT NULL DEFAULT '',
            `claim_approved_at`     VARCHAR(19)   NOT NULL DEFAULT '',
            `claim_rejected_at`     VARCHAR(19)   NOT NULL DEFAULT '',
            `claim_reject_reason`   VARCHAR(1000) NOT NULL DEFAULT '',
            `refunded_at`           VARCHAR(19)   NOT NULL DEFAULT ''         COMMENT '입금까지 끝난 시각 = 환급완료',
            `refund_cap_at_request` INT           NOT NULL DEFAULT 0          COMMENT '신청 시점 건당 상한 스냅샷. 0=상한 없음',
            `refund_amount`         INT           NOT NULL DEFAULT 0          COMMENT '청구승인에서 확정한 환급액',
            `rating`                DOUBLE            NULL                    COMMENT '강의평가 0.5~5.0. NULL=미입력(0 점이 아니다)',
            `recommend`             DOUBLE            NULL                    COMMENT '추천도 0.5~5.0',
            `review_note`           VARCHAR(2000) NOT NULL DEFAULT '',
            `active`                TINYINT(1)    NOT NULL DEFAULT 1,
            `archived`              TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`            VARCHAR(190)  NOT NULL DEFAULT '',
            `created_at`            VARCHAR(19)   NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_applicant` (`applicant_email`),
            KEY `idx_created`   (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_sites` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(100) NOT NULL COMMENT '인프런, 패스트캠퍼스, ...',
            `url`        VARCHAR(500) NOT NULL DEFAULT '',
            `sort_order` INT          NOT NULL DEFAULT 0,
            `active`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0=비활성. 하드 삭제하지 않는다',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 이수증. 자료 칸이 하나뿐인 magazine 과 달리 한 신청에 여러 장이 붙는다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_certs` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_id`  INT UNSIGNED NOT NULL,
            `name`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '원본 파일명',
            `path`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '서버가 만든 저장 파일명',
            `uploaded_by` VARCHAR(190) NOT NULL DEFAULT '',
            `created_at`  VARCHAR(19)  NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_request` (`request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 상태 변경 이력. 돈이 오가므로 누가 언제 승인했는지는 남긴다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_histories` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `request_id`  INT UNSIGNED  NOT NULL,
            `status`      VARCHAR(50)   NOT NULL DEFAULT '' COMMENT '전이 후 상태',
            `memo`        VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '반려 사유·환급액 등',
            `actor`       VARCHAR(60)   NOT NULL DEFAULT '',
            `actor_email` VARCHAR(190)  NOT NULL DEFAULT '',
            `created_at`  VARCHAR(19)   NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_request` (`request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 강의 분류. medium 이 빈 문자열이면 대분류 행이다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_categories` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `site`        VARCHAR(100) NOT NULL DEFAULT '',
            `large`       VARCHAR(150) NOT NULL DEFAULT '' COMMENT '대분류',
            `medium`      VARCHAR(150) NOT NULL DEFAULT '' COMMENT '중분류. 빈 문자열이면 대분류 행',
            `sort_order`  INT          NOT NULL DEFAULT 0,
            `recommended` TINYINT(1)   NOT NULL DEFAULT 0,
            `active`      TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_site` (`site`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 관리자 명단. 화면에서 바꾸므로 코드가 아니라 DB 가 원본이다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_admins` (
            `email`      VARCHAR(190) NOT NULL,
            `added_by`   VARCHAR(190) NOT NULL DEFAULT '',
            `created_at` VARCHAR(19)  NOT NULL DEFAULT '',
            PRIMARY KEY (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 환급 정책. id=1 한 행만 쓴다. 각 기능은 '사용 여부 + 값' 쌍이고 기본은 전부 꺼짐.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `learn_policy` (
            `id`                     TINYINT UNSIGNED NOT NULL,
            `partial_enabled`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '부분환급(건당 상한) 사용',
            `partial_cap`            INT          NOT NULL DEFAULT 0,
            `annual_amount_enabled`  TINYINT(1)   NOT NULL DEFAULT 0,
            `annual_amount_limit`    INT          NOT NULL DEFAULT 0,
            `annual_count_enabled`   TINYINT(1)   NOT NULL DEFAULT 0,
            `annual_count_limit`     INT          NOT NULL DEFAULT 0,
            `claim_deadline_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
            `claim_deadline_days`    INT          NOT NULL DEFAULT 0,
            `updated_by`             VARCHAR(190) NOT NULL DEFAULT '',
            `updated_at`             VARCHAR(19)  NOT NULL DEFAULT '',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    learn_seed($pdo);
    return $pdo;
}

/** 초기 데이터는 각 테이블이 비어 있을 때만 넣는다 */
function learn_seed(PDO $pdo) {
    $now = date('Y-m-d H:i:s');

    if (!(int)$pdo->query("SELECT COUNT(*) FROM learn_sites")->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO learn_sites (name, url, sort_order) VALUES (?,?,?)");
        foreach (DEFAULT_SITES as $i => $s) $ins->execute([$s[0], $s[1], $i]);
    }

    if (!(int)$pdo->query("SELECT COUNT(*) FROM learn_categories")->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO learn_categories (site, large, medium, sort_order)
                              VALUES (?,?,?,?)");
        $order = 0;
        foreach (DEFAULT_CATEGORIES as $site => $larges) {
            foreach ($larges as $large => $mediums) {
                $ins->execute([$site, $large, '', $order++]);
                foreach ($mediums as $medium) $ins->execute([$site, $large, $medium, $order++]);
            }
        }
    }

    if (!(int)$pdo->query("SELECT COUNT(*) FROM learn_admins")->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO learn_admins (email, added_by, created_at)
                              VALUES (?,'seed',?)");
        foreach (SEED_ADMINS as $email) $ins->execute([strtolower($email), $now]);
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM learn_policy WHERE id=?");
    $st->execute([POLICY_ID]);
    if (!(int)$st->fetchColumn()) {
        $pdo->prepare("INSERT INTO learn_policy (id, updated_at) VALUES (?,?)")
            ->execute([POLICY_ID, $now]);
    }
}
