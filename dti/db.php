<?php
/**
 * dti 설정과 DB. 설정 값을 읽는 곳은 여기 하나뿐이고, 테스트는 이 배열만 갈아끼운다.
 */

const DTI_TEAMS = ['APP', 'SQUARE', 'LAB'];
const DTI_MAGAZINES = ['DI', 'MIT TR', 'Etc'];
const DTI_EMOTIONS = ['like', 'apply', 'easy', 'new'];
const DTI_DEFAULT_FIELDS = ['UI/UX', 'Marketing', 'Trend', 'AX', 'Etc'];
const DTI_DEFAULT_ADMINS = ['jian@bluesoft.co.kr', 'kimhy@bluesoft.co.kr', 'jun0@bluesoft.co.kr'];

// 분야는 DB 에서 동적으로 관리하지만 팀은 코드에 둔다. 입·퇴사 때 여기를 고친다.
const DTI_DEFAULT_TEAMS = [
    'kimhy@bluesoft.co.kr' => ['APP', 'SQUARE', 'LAB'],
    'jian@bluesoft.co.kr' => ['SQUARE'],
    'scpark@bluesoft.co.kr' => ['APP'],
    'pink@bluesoft.co.kr' => ['LAB'],
    'venus@bluesoft.co.kr' => ['APP'],
    'akddd@bluesoft.co.kr' => ['APP'],
    'lenda83@bluesoft.co.kr' => ['APP', 'LAB'],
    'amitoa@bluesoft.co.kr' => ['APP'],
    'phr@bluesoft.co.kr' => ['APP'],
    'bnmmnbhj@bluesoft.co.kr' => ['APP'],
    'siyu@bluesoft.co.kr' => ['APP'],
    'hjlee@bluesoft.co.kr' => ['APP'],
    'jun0@bluesoft.co.kr' => ['APP'],
];

/* ---------- 설정 ---------- */

function dti_config(array $over = []): array {
    $config = $over + [
        'db' => [],
        'upload_dir' => __DIR__ . '/var/uploads',
        'max_upload_mb' => 50,
        'admin_emails' => DTI_DEFAULT_ADMINS,
        'teams_by_email' => DTI_DEFAULT_TEAMS,
        'slack_webhook' => null,
        // 화면이 뒤에 /?view=profile 같은 걸 붙여 쓴다. 페이지가 아니라 기준 경로여야 한다
        'portal_url' => '..',
        'slack_url' => '../slack/lists.php',
    ];
    $config['admin_emails'] = array_map('strtolower', $config['admin_emails']);
    dti_config_check($config);
    return $config;
}

function dti_config_from_portal(array $over = []): array {
    $portal = require __DIR__ . '/../config.php';

    return dti_config($over + [
        'db' => $portal['db'],
        'slack_webhook' => $portal['dti_slack_webhook'] ?? null,
    ]);
}

function dti_config_check(array $config): void {
    foreach ($config['teams_by_email'] as $email => $teams) {
        $unknown = array_diff($teams, DTI_TEAMS);
        if ($unknown) {
            throw new InvalidArgumentException(
                '없는 팀입니다: ' . implode(', ', $unknown) . " ({$email})");
        }
    }
}

function dti_is_admin(array $config, ?string $email): bool {
    return $email !== null && in_array(strtolower($email), $config['admin_emails'], true);
}

function dti_teams_of(array $config, ?string $email): array {
    return $config['teams_by_email'][strtolower((string)$email)] ?? [];
}

function dti_max_upload_bytes(array $config): int {
    return $config['max_upload_mb'] * 1024 * 1024;
}

/* ---------- 연결 ---------- */

function dti_connect(array $config): PDO {
    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}`
                CHARACTER SET {$db['charset']} COLLATE {$db['charset']}_unicode_ci");
    $pdo->exec("USE `{$db['name']}`");

    return $pdo;
}

function dti_migrate(PDO $pdo): void {
    foreach (dti_schema_tables() as $sql) {
        $pdo->exec($sql);
    }
    // 컬럼 추가는 저장소 공통 헬퍼로만 한다 — 동시 요청에서 "확인 후 ALTER" 는 레이스가 난다
    require_once __DIR__ . '/../db.php';
    foreach (dti_schema_alters() as $sql) {
        add_column_if_missing($pdo, $sql);
    }
    dti_seed_fields($pdo);
}

function dti_seed_fields(PDO $pdo): void {
    if ((int)$pdo->query("SELECT COUNT(*) FROM dti_fields")->fetchColumn() > 0) return;

    $insert = $pdo->prepare("INSERT INTO dti_fields (name) VALUES (?)");
    foreach (DTI_DEFAULT_FIELDS as $name) {
        $insert->execute([$name]);
    }
}

/* ---------- 스키마 ---------- */

/**
 * dti_* 테이블 정의. 파이썬 magazine 의 core/db.py 모델을 그대로 옮긴 것이다.
 * topics 의 presenter·planned_date·material_* 는 발표 분리 뒤로 쓰지 않지만,
 * 이관 정확성을 "응답이 같다"로 검증하므로 정리하지 않고 그대로 만든다.
 */
function dti_schema_tables(): array {
    return [
        'dti_topics' => "
            CREATE TABLE IF NOT EXISTS `dti_topics` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(500) NOT NULL,
                `field` VARCHAR(150) NOT NULL DEFAULT '',
                `keywords` VARCHAR(1000) NOT NULL DEFAULT '',
                `magazine` VARCHAR(50) NOT NULL DEFAULT '',
                `volume` VARCHAR(50) NOT NULL DEFAULT '',
                `page` VARCHAR(50) NOT NULL DEFAULT '',
                `year` INT NULL,
                `requirement` VARCHAR(20) NOT NULL DEFAULT 'recommended' COMMENT 'required|recommended|normal',
                `team` VARCHAR(20) NOT NULL DEFAULT '',
                `presenter` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '발표 분리 전 컬럼. 값만 남아 있다',
                `presenter_email` VARCHAR(190) NOT NULL DEFAULT '',
                `planned_date` VARCHAR(10) NOT NULL DEFAULT '',
                `done_date` VARCHAR(10) NOT NULL DEFAULT '',
                `note` VARCHAR(2000) NOT NULL DEFAULT '',
                `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '구성원 화면 노출',
                `archived` TINYINT(1) NOT NULL DEFAULT 0,
                `material_kind` VARCHAR(10) NULL COMMENT 'link|file. NULL 이 없음이다',
                `material_name` VARCHAR(500) NULL,
                `material_url` VARCHAR(1000) NULL,
                `material_path` VARCHAR(255) NULL,
                `scan_kind` VARCHAR(10) NULL,
                `scan_name` VARCHAR(500) NULL,
                `scan_url` VARCHAR(1000) NULL,
                `scan_path` VARCHAR(255) NULL,
                `created_by` VARCHAR(190) NOT NULL DEFAULT '',
                `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dti_presentations' => "
            CREATE TABLE IF NOT EXISTS `dti_presentations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `topic_id` INT UNSIGNED NOT NULL,
                `presenter` VARCHAR(60) NOT NULL DEFAULT '',
                `presenter_email` VARCHAR(190) NOT NULL DEFAULT '',
                `planned_date` VARCHAR(10) NOT NULL DEFAULT '',
                `done_date` VARCHAR(10) NOT NULL DEFAULT '',
                `material_kind` VARCHAR(10) NULL,
                `material_name` VARCHAR(500) NULL,
                `material_url` VARCHAR(1000) NULL,
                `material_path` VARCHAR(255) NULL,
                `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_topic` (`topic_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dti_materials' => "
            CREATE TABLE IF NOT EXISTS `dti_materials` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `topic_id` INT UNSIGNED NOT NULL,
                `slot` VARCHAR(10) NOT NULL COMMENT 'material|scan',
                `kind` VARCHAR(10) NOT NULL COMMENT 'link|file',
                `name` VARCHAR(500) NOT NULL DEFAULT '',
                `url` VARCHAR(1000) NULL,
                `path` VARCHAR(255) NULL COMMENT '저장 파일명. link 면 NULL',
                `created_by` VARCHAR(190) NOT NULL DEFAULT '',
                `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `idx_topic_slot` (`topic_id`, `slot`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dti_emotions' => "
            CREATE TABLE IF NOT EXISTS `dti_emotions` (
                `presentation_id` INT UNSIGNED NOT NULL,
                `email` VARCHAR(190) NOT NULL,
                `kind` VARCHAR(20) NOT NULL COMMENT 'like|apply|easy|new',
                `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                PRIMARY KEY (`presentation_id`, `email`, `kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dti_fields' => "
            CREATE TABLE IF NOT EXISTS `dti_fields` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dti_related' => "
            CREATE TABLE IF NOT EXISTS `dti_related` (
                `topic_id` INT UNSIGNED NOT NULL,
                `related_id` INT UNSIGNED NOT NULL,
                `score` INT NOT NULL,
                PRIMARY KEY (`topic_id`, `related_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** 이미 만들어진 설치본에 컬럼을 붙일 때 여기에 ALTER 문을 더한다 */
function dti_schema_alters(): array {
    return [
        "ALTER TABLE `dti_topics` ADD COLUMN `note` VARCHAR(2000) NOT NULL DEFAULT '' AFTER `done_date`",
        "ALTER TABLE `dti_topics` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `note`",
        "ALTER TABLE `dti_topics` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active`",
    ];
}
