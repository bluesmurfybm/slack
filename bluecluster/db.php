<?php

if (is_file(__DIR__ . '/../core/sqlite_compat.php')) require_once __DIR__ . '/../core/sqlite_compat.php';

/**
 * @param array $db host, port, charset, user, pass, name
 * @return PDO
 */
function bcl_connect($db)
{
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
 * 블루 클러스터 DB 연결을 얻는다.
 * @return PDO
 */
function bcl_db()
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $portal = require __DIR__ . '/../config.php';
    $pdo = bcl_connect($portal['db']);
    bcl_migrate($pdo);

    return $pdo;
}

/**
 * @param PDO $pdo
 */
function bcl_migrate($pdo)
{
    foreach (bcl_schema() as $sql) {
        $pdo->exec($sql);
    }
}

/**
 * @return string[]
 */
function bcl_schema()
{
    return [
        "CREATE TABLE IF NOT EXISTS `bcl_jobs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_key` VARCHAR(20) COLLATE utf8mb4_bin NULL COMMENT '작업 번호(job-N). 등록 직후 id 로 채운다',
            `title` VARCHAR(200) NOT NULL COMMENT '작업 제목',
            `submitter` VARCHAR(190) NOT NULL COMMENT '등록한 사람(포털 이메일)',
            `repos` TEXT NOT NULL COMMENT '대상 저장소 목록(JSON)',
            `instructions` MEDIUMTEXT NOT NULL COMMENT '작업 지시문',
            `state` VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open|done',
            `leader` VARCHAR(80) COLLATE utf8mb4_bin NULL COMMENT '리더 노드 node_id',
            `epoch` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '리더가 바뀔 때마다 1 증가',
            `lease_until` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '리더 리스 끝 시각(unix)',
            `summary` MEDIUMTEXT NULL COMMENT '리더가 끝낼 때 남긴 요약',
            `created_at` INT UNSIGNED NOT NULL COMMENT '등록 시각(unix)',
            `finished_at` INT UNSIGNED NULL COMMENT '끝난 시각(unix)',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_job_key` (`job_key`),
            KEY `ix_state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='블루 클러스터 작업'",
        "CREATE TABLE IF NOT EXISTS `bcl_pieces` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_id` INT UNSIGNED NOT NULL COMMENT 'bcl_jobs.id',
            `piece_key` VARCHAR(80) COLLATE utf8mb4_bin NOT NULL COMMENT '리더가 정한 조각 이름',
            `repo` VARCHAR(190) NOT NULL COMMENT '조각이 다루는 저장소',
            `piece_desc` TEXT NOT NULL COMMENT '조각 설명',
            `state` VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending|running|done',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_job_piece` (`job_id`, `piece_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='작업을 나눈 조각'",
        "CREATE TABLE IF NOT EXISTS `bcl_attempts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `piece_id` INT UNSIGNED NOT NULL COMMENT 'bcl_pieces.id',
            `attempt_no` INT UNSIGNED NOT NULL COMMENT '조각별 1부터',
            `node_id` VARCHAR(80) COLLATE utf8mb4_bin NOT NULL COMMENT '시도한 노드',
            `account` VARCHAR(80) NOT NULL COMMENT '노드가 쓴 Claude 계정',
            `token_user` VARCHAR(190) NOT NULL DEFAULT '' COMMENT '요청에 쓴 토큰 주인(포털 이메일)',
            `status` VARCHAR(20) NOT NULL DEFAULT 'running' COMMENT 'running|done|stopped_by_owner|lost',
            `started` INT UNSIGNED NOT NULL COMMENT '시작 시각(unix)',
            `ended` INT UNSIGNED NULL COMMENT '끝난 시각(unix)',
            `result_path` VARCHAR(500) NULL COMMENT '결과 파일 경로',
            `patch_path` VARCHAR(500) NULL COMMENT '패치 파일 경로',
            `log_path` VARCHAR(500) NULL COMMENT '로그 파일 경로',
            `redactions` TEXT NULL COMMENT '가린 횟수(JSON, 종류별)',
            `closed_by` VARCHAR(80) COLLATE utf8mb4_bin NULL COMMENT '리더가 닫았을 때 그 노드',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_piece_attempt` (`piece_id`, `attempt_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='조각 시도 기록'",
        "CREATE TABLE IF NOT EXISTS `bcl_nodes` (
            `node_id` VARCHAR(80) COLLATE utf8mb4_bin NOT NULL COMMENT '노드 이름',
            `account` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'Claude 계정',
            `token_user` VARCHAR(190) NOT NULL DEFAULT '' COMMENT '요청에 쓴 토큰 주인(포털 이메일)',
            `addr` VARCHAR(190) NOT NULL DEFAULT '' COMMENT '노드 주소',
            `free_cpu` DOUBLE NOT NULL DEFAULT 0 COMMENT '남은 CPU',
            `free_mem` BIGINT NOT NULL DEFAULT 0 COMMENT '남은 메모리',
            `running` INT NOT NULL DEFAULT 0 COMMENT '실행 중인 시도 수',
            `attempts` TEXT NOT NULL COMMENT '실행 중인 시도 목록(JSON, job/piece/attempt_no)',
            `last_seen` INT UNSIGNED NOT NULL COMMENT '마지막 하트비트 시각(unix)',
            PRIMARY KEY (`node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='노드 마지막 하트비트'",
    ];
}
