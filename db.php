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
            `user_id`    VARCHAR(32) NOT NULL COMMENT '읽은 사용자 Slack ID',
            `request_id` VARCHAR(32) NOT NULL COMMENT 'requests.id',
            `read_at`    DATETIME    NOT NULL,
            PRIMARY KEY (`user_id`, `request_id`),
            KEY `idx_user` (`user_id`)
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
