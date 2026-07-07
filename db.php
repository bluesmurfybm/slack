<?php
/**
 * DB 연결.
 *  - config.php 의 db 설정 사용
 *  - slackapi DB 가 없으면 생성, requests 테이블이 없으면 생성
 */

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $d   = $cfg['db'];
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // 1) DB 미지정으로 접속 → DB 생성
    $dsn = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], $opt);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}`
                CHARACTER SET {$d['charset']} COLLATE {$d['charset']}_unicode_ci");
    $pdo->exec("USE `{$d['name']}`");

    // 2) 테이블 생성 (API 모든 컬럼 + 로컬 관리 컬럼)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `requests` (
            `id`         VARCHAR(32)   NOT NULL COMMENT 'Slack 항목 ID',
            `title`      VARCHAR(500)  NOT NULL DEFAULT '',
            `body`       MEDIUMTEXT    NULL,
            `momo`       VARCHAR(500)  NOT NULL DEFAULT '',
            `lms`        VARCHAR(500)  NOT NULL DEFAULT '',
            `req_id`     VARCHAR(32)   NULL COMMENT '요청자 Slack ID',
            `req`        VARCHAR(120)  NOT NULL DEFAULT '—' COMMENT '요청자 이름',
            `asg_id`     VARCHAR(32)   NULL COMMENT '담당자 Slack ID',
            `asg`        VARCHAR(120)  NOT NULL DEFAULT '—' COMMENT '담당자 이름',
            `status_id`  VARCHAR(32)   NULL,
            `status`     VARCHAR(60)   NOT NULL DEFAULT '',
            `priority_id` VARCHAR(32)  NULL,
            `priority`   VARCHAR(40)   NOT NULL DEFAULT '' COMMENT '우선순위 (일반/긴급)',
            `team_id`    VARCHAR(32)   NULL,
            `team`       VARCHAR(60)   NOT NULL DEFAULT '' COMMENT '개발담당팀',
            `cmt_count`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '댓글 수(주기적 스캔)',
            `eta`        DATE          NULL COMMENT '예상처리완료일(고객안내용)',
            `date`       DATE          NULL COMMENT '요청일',
            `done`       DATE          NULL COMMENT '완료일',
            `created`    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '생성 unixtime',
            `updated`    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '갱신 unixtime',
            `locked`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1=로컬 수정됨, 재동기화 시 보존',
            `edited_by`  VARCHAR(120)  NULL COMMENT '최종 수정자(Slack 이름)',
            `synced_at`  DATETIME      NULL COMMENT '마지막 Slack 동기화 시각',
            `updated_at` DATETIME      NULL COMMENT '마지막 로컬 수정 시각',
            PRIMARY KEY (`id`),
            KEY `idx_status`  (`status`),
            KEY `idx_created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 기존 설치본 마이그레이션: cmt_count 컬럼 없으면 추가
    $dbName = $cfg['db']['name'];
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='requests' AND COLUMN_NAME='cmt_count'");
    $has->execute([$dbName]);
    if (!$has->fetchColumn()) {
        $pdo->exec("ALTER TABLE `requests` ADD COLUMN `cmt_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `team`");
    }
    $hasEta = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA=? AND TABLE_NAME='requests' AND COLUMN_NAME='eta'");
    $hasEta->execute([$dbName]);
    if (!$hasEta->fetchColumn()) {
        $pdo->exec("ALTER TABLE `requests` ADD COLUMN `eta` DATE NULL AFTER `cmt_count`");
    }

    // 난이도 채점 캐시 컬럼 (채점 결과 저장 · 있으면 difficulty.php 에서 규칙기반보다 우선 표시)
    $aiCols = [
        'ai_stars'     => "TINYINT UNSIGNED NULL COMMENT '난이도 별 1~5'",
        'ai_reason'    => "VARCHAR(300) NULL COMMENT '난이도 근거'",
        'ai_conf'      => "VARCHAR(10) NULL COMMENT '신뢰도 high/medium/low'",
        'ai_hash'      => "CHAR(32) NULL COMMENT '채점 시점 제목+본문+팀 해시(변경 감지)'",
        'ai_scored_at' => "DATETIME NULL COMMENT '채점 시각'",
    ];
    // 첨부파일 JSON 캐시 (Slack Lists attachment 필드의 파일 정보: 이름/URL/썸네일 등)
    $aiCols['attachments'] = "MEDIUMTEXT NULL COMMENT '첨부파일 JSON 배열'";
    foreach ($aiCols as $col => $def) {
        $c = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA=? AND TABLE_NAME='requests' AND COLUMN_NAME=?");
        $c->execute([$dbName, $col]);
        if (!$c->fetchColumn()) {
            $pdo->exec("ALTER TABLE `requests` ADD COLUMN `$col` $def");
        }
    }

    // 여러 리스트 통합용: list_id/board/archived 컬럼 + 기존 데이터 블루소프트로 백필
    foreach (['list_id'  => "VARCHAR(32) NULL COMMENT 'Slack 리스트 ID'",
              'board'    => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '리스트 구분(블루소프트/와이오즈)'",
              'archived' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=보관(archived)'"] as $col => $def) {
        $c = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA=? AND TABLE_NAME='requests' AND COLUMN_NAME=?");
        $c->execute([$dbName, $col]);
        if (!$c->fetchColumn()) {
            $pdo->exec("ALTER TABLE `requests` ADD COLUMN `$col` $def");
        }
    }
    // 백필: board 미설정 기존 행 = 블루소프트(현행 리스트)
    $blueId = $cfg['list_id'];
    $pdo->prepare("UPDATE requests SET list_id=?, board='블루소프트' WHERE board='' OR board IS NULL")->execute([$blueId]);

    // 3) 동기화 워터마크 저장 (증분 동기화용)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sync_meta` (
            `k` VARCHAR(64)  NOT NULL,
            `v` TEXT         NULL,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 4) 사용자별 읽음 상태 (행 존재 = 읽음). reads 는 예약어라 user_reads 사용
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_reads` (
            `user_id`    VARCHAR(32)  NOT NULL COMMENT '읽은 사용자 Slack ID',
            `user_name`  VARCHAR(120) NULL COMMENT '읽은 사용자 이름',
            `request_id` VARCHAR(32)  NOT NULL COMMENT 'requests.id',
            `read_at`    DATETIME     NOT NULL,
            PRIMARY KEY (`user_id`, `request_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 기존 user_reads 마이그레이션: user_name 없으면 추가
    $hasUN = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA=? AND TABLE_NAME='user_reads' AND COLUMN_NAME='user_name'");
    $hasUN->execute([$dbName]);
    if (!$hasUN->fetchColumn()) {
        $pdo->exec("ALTER TABLE `user_reads` ADD COLUMN `user_name` VARCHAR(120) NULL COMMENT '읽은 사용자 이름' AFTER `user_id`");
    }

    // 5) 사용자별 고정 상태 (행 존재 = 고정). 고정 항목은 목록 최상단 출력
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_pins` (
            `user_id`    VARCHAR(32) NOT NULL COMMENT '고정한 사용자 Slack ID',
            `request_id` VARCHAR(32) NOT NULL COMMENT 'requests.id',
            `pinned_at`  DATETIME    NOT NULL,
            PRIMARY KEY (`user_id`, `request_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 6) 사용자별 숨김 상태 (행 존재 = 숨김). 기본 목록에서 제외, '숨김 보기'로만 표시
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_hides` (
            `user_id`    VARCHAR(32) NOT NULL COMMENT '숨긴 사용자 Slack ID',
            `request_id` VARCHAR(32) NOT NULL COMMENT 'requests.id',
            `hidden_at`  DATETIME    NOT NULL,
            PRIMARY KEY (`user_id`, `request_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 7) 대학 사이트 목록 (버전별 개발/운영 링크) — 검색·관리용
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schools` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(200) NOT NULL COMMENT '대학(기관)명',
            `ver`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '버전(3.5/3.9/4.5 등)',
            `dev`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '개발 URL',
            `ops`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '운영 URL',
            `active`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=사용,0=미사용',
            `created_at` DATETIME     NULL,
            `updated_at` DATETIME     NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ver`  (`ver`),
            KEY `idx_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 기존 설치본 마이그레이션: active 컬럼 없으면 추가
    $hasActive = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA=? AND TABLE_NAME='schools' AND COLUMN_NAME='active'");
    $hasActive->execute([$dbName]);
    if (!$hasActive->fetchColumn()) {
        $pdo->exec("ALTER TABLE `schools` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ops`");
    }

    // schools 최초 자동 시딩: git clone 후 첫 실행 시 seed 파일로 채움 (DB당 1회만)
    if ($pdo->query("SELECT v FROM sync_meta WHERE k='schools_seeded'")->fetchColumn() === false) {
        $seedFile = __DIR__ . '/schools_seed.json';
        $empty    = ((int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn() === 0);
        if ($empty && is_file($seedFile)) {
            $seed = json_decode((string)file_get_contents($seedFile), true);
            if (is_array($seed) && $seed) {
                $ins = $pdo->prepare("INSERT INTO schools (name, ver, dev, ops, active, created_at, updated_at)
                                      VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $pdo->beginTransaction();
                foreach ($seed as $s) {
                    $ins->execute([
                        (string)($s['name'] ?? ''), (string)($s['ver'] ?? ''),
                        (string)($s['dev'] ?? ''),  (string)($s['ops'] ?? ''),
                        isset($s['active']) ? (int)$s['active'] : 1,
                    ]);
                }
                $pdo->commit();
            }
        }
        // 이미 데이터가 있든 시딩했든 '처리됨'으로 표시 → 재실행/삭제해도 다시 채우지 않음
        $pdo->exec("INSERT INTO sync_meta (k, v) VALUES ('schools_seeded', '1') ON DUPLICATE KEY UPDATE v = v");
    }

    // 8) 로컬 담당자 배정 (Slack 미연동, 사이트 자체 저장). request_id 당 1명.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `local_assignments` (
            `request_id`  VARCHAR(32)  NOT NULL COMMENT 'requests.id',
            `assignee`    VARCHAR(60)  NOT NULL COMMENT '배정 담당자명',
            `assigned_at` DATETIME     NOT NULL,
            `assigned_by` VARCHAR(120) NULL COMMENT '배정 실행자',
            PRIMARY KEY (`request_id`),
            KEY `idx_assignee` (`assignee`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    return $pdo;
}

/** sync_meta 값 읽기 */
function meta_get($key, $default = null) {
    $s = db()->prepare("SELECT v FROM sync_meta WHERE k = ?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : $v;
}

/** sync_meta 값 저장 */
function meta_set($key, $value) {
    $s = db()->prepare("INSERT INTO sync_meta (k, v) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $s->execute([$key, (string)$value]);
}
