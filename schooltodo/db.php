<?php

if (is_file(__DIR__ . '/../core/sqlite_compat.php')) require_once __DIR__ . '/../core/sqlite_compat.php';

const SCHOOLTODO_STATUS_TODO = '미조치';
const SCHOOLTODO_STATUS_DOING = '진행중';
const SCHOOLTODO_STATUS_DEV = '개발반영';
const SCHOOLTODO_STATUS_OPS = '운영반영';
const SCHOOLTODO_STATUS_EXCLUDED = '해당없음';
const SCHOOLTODO_STATUSES = [
    SCHOOLTODO_STATUS_TODO,
    SCHOOLTODO_STATUS_DOING,
    SCHOOLTODO_STATUS_DEV,
    SCHOOLTODO_STATUS_OPS,
    SCHOOLTODO_STATUS_EXCLUDED,
];

/**
 * @param array $over
 * @return array
 */
function schooltodo_config($over = []) {
    return $over + [
        'db' => [],
        'portal_url' => '..',
    ];
}

/**
 * @return array
 */
function schooltodo_config_from_portal() {
    $portal = require __DIR__ . '/../config.php';

    return schooltodo_config(['db' => $portal['db']]);
}

/**
 * @param array $config
 * @return PDO
 */
function schooltodo_connect($config) {
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

/**
 * @param PDO $pdo
 */
function schooltodo_migrate($pdo) {
    foreach (schooltodo_schema() as $sql) {
        $pdo->exec($sql);
    }
    // 보관(archived_at)은 나중에 붙은 열 — 이미 있으면 42S21(중복 열) 로 넘어간다
    try {
        $pdo->exec("ALTER TABLE `schooltodo_sheets` ADD COLUMN `archived_at` VARCHAR(19) NOT NULL DEFAULT ''
                    COMMENT '보관한 시각. 비어 있으면 탭에 보인다' AFTER `created_at`");
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S21') throw $e;
    }
}

/**
 * @return string[]
 */
function schooltodo_schema() {
    return [
        "CREATE TABLE IF NOT EXISTS `schooltodo_sheets` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(200) NOT NULL COMMENT '할일 이름',
            `description` VARCHAR(2000) NOT NULL DEFAULT '',
            `created_by` VARCHAR(60) NOT NULL DEFAULT '',
            `created_at` VARCHAR(19) NOT NULL DEFAULT '',
            `archived_at` VARCHAR(19) NOT NULL DEFAULT '' COMMENT '보관한 시각. 비어 있으면 탭에 보인다',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_title` (`title`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='대학 일괄 할일 시트'",
        "CREATE TABLE IF NOT EXISTS `schooltodo_cells` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sheet_id` INT UNSIGNED NOT NULL,
            `school_id` INT UNSIGNED NOT NULL COMMENT 'schools.id',
            `status` VARCHAR(20) NOT NULL DEFAULT '미조치' COMMENT '미조치|진행중|개발반영|운영반영|해당없음',
            `note` VARCHAR(2000) NOT NULL DEFAULT '',
            `updated_by` VARCHAR(60) NOT NULL DEFAULT '',
            `updated_at` VARCHAR(19) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sheet_school` (`sheet_id`, `school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='시트의 대학별 상태. 행이 없으면 미조치'",
    ];
}
