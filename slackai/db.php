<?php
/**
 * slackai DB 연결.
 *  - config.php 의 db 접속 정보를 쓰되, 데이터베이스 이름만 config.php 'slackai'.'db_name'(기본 slackai_db) 으로 바꾼다.
 *    → slack/ 과 같은 Slack 리스트를 보지만 데이터는 완전히 분리된 테스트베드. 인증(portal_users)은 그대로 slack_db.
 *  - slack/db.php 의 8개 테이블 + AI 테이블(ai_*) 을 최초 접속 시 생성/마이그레이션한다.
 *  - AI 테이블 DDL 은 워커(slackai/worker/core/store.py)에도 같은 내용이 있다. **컬럼을 바꾸면 둘 다 고친다.**
 *  - 최초 데이터 복제는 slackai/tools/clone_db.php (slack_db → slackai_db).
 */

/**
 * ALTER TABLE ADD COLUMN 을 안전하게 실행한다.
 *  한 페이지 로드에서 여러 AJAX 가 동시에 db()를 부르면 "확인 후 ALTER" 는 경쟁이 생기므로,
 *  이미 존재해서 나는 에러(42S21/1060)만 조용히 무시해 멱등하게 만든다. (readme "DB" 절 규칙)
 */
if (!function_exists('add_column_if_missing')) {
    function add_column_if_missing($pdo, $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') throw $e;
        }
    }
}

/** 이 모듈이 쓰는 DB 이름 (config.php slackai.db_name, 기본 slackai_db) */
function slackai_db_name() {
    static $name = null;
    if ($name !== null) return $name;
    $cfg  = require __DIR__ . '/../config.php';
    $name = $cfg['slackai']['db_name'] ?? 'slackai_db';
    return $name;
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/../config.php';
    $d   = $cfg['db'];
    $dbName = slackai_db_name();
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // 1) DB 미지정으로 접속 → DB 생성 → 선택. 세션 시간대는 KST 고정(워커·PHP 가 같은 기준으로 DATETIME 을 비교한다)
    $dsn = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], $opt);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName`
                CHARACTER SET {$d['charset']} COLLATE {$d['charset']}_unicode_ci");
    $pdo->exec("USE `$dbName`");
    $pdo->exec("SET time_zone = '+09:00'");

    // 2) 요청 테이블 (slack/db.php 와 동일)
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
            `cmt_count`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '댓글 수',
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
    add_column_if_missing($pdo, "ALTER TABLE `requests` ADD COLUMN `cmt_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `team`");
    add_column_if_missing($pdo, "ALTER TABLE `requests` ADD COLUMN `eta` DATE NULL AFTER `cmt_count`");

    // 난이도 채점 캐시 컬럼 — slack/ 에서는 선언만 되어 있었고, slackai 에서는 워커 triage 가 채운다.
    $aiCols = [
        'ai_stars'     => "TINYINT UNSIGNED NULL COMMENT '난이도 별 1~5 (AI triage)'",
        'ai_reason'    => "VARCHAR(300) NULL COMMENT '난이도 근거'",
        'ai_conf'      => "VARCHAR(10) NULL COMMENT '신뢰도 high/medium/low'",
        'ai_hash'      => "CHAR(32) NULL COMMENT '채점 시점 제목+본문 해시(변경 감지)'",
        'ai_scored_at' => "DATETIME NULL COMMENT '채점 시각'",
        'attachments'  => "MEDIUMTEXT NULL COMMENT '첨부파일 JSON 배열'",
    ];
    foreach ($aiCols as $col => $def) {
        add_column_if_missing($pdo, "ALTER TABLE `requests` ADD COLUMN `$col` $def");
    }
    foreach (['list_id'  => "VARCHAR(32) NULL COMMENT 'Slack 리스트 ID'",
              'board'    => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '리스트 구분(블루소프트/와이오즈)'",
              'archived' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=보관(archived)'"] as $col => $def) {
        add_column_if_missing($pdo, "ALTER TABLE `requests` ADD COLUMN `$col` $def");
    }
    $blueId = $cfg['list_id'];
    $pdo->prepare("UPDATE requests SET list_id=?, board='블루소프트' WHERE board='' OR board IS NULL")->execute([$blueId]);

    // 3) 동기화 워터마크·메타 (k/v)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sync_meta` (
            `k` VARCHAR(64)  NOT NULL,
            `v` TEXT         NULL,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 4~6) 사용자별 읽음/고정/숨김 (행 존재 = 상태)
    foreach ([['user_reads', 'read_at', '읽은'], ['user_pins', 'pinned_at', '고정한'], ['user_hides', 'hidden_at', '숨긴']] as [$t, $tsCol, $who]) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `$t` (
                `user_id`    VARCHAR(32)  NOT NULL COMMENT '$who 사용자 Slack ID',
                `request_id` VARCHAR(32)  NOT NULL COMMENT 'requests.id',
                `$tsCol`     DATETIME     NOT NULL,
                PRIMARY KEY (`user_id`, `request_id`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // 6-1) 사용자별 설정(key-value)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_prefs` (
            `user_id`    VARCHAR(32)  NOT NULL COMMENT '사용자 Slack ID',
            `pref_key`   VARCHAR(64)  NOT NULL COMMENT '설정 키 (예: filter_presets)',
            `pref_value` MEDIUMTEXT   NULL     COMMENT 'JSON 값',
            `updated_at` DATETIME     NOT NULL,
            PRIMARY KEY (`user_id`, `pref_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 7) 대학 사이트 목록
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schools` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(200) NOT NULL COMMENT '대학(기관)명',
            `ver`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '버전(3.5/3.9/4.5 등)',
            `dev`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '개발 URL',
            `ops`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '운영 URL',
            `log`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '로그 관리 URL',
            `active`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=사용,0=미사용',
            `created_at` DATETIME     NULL,
            `updated_at` DATETIME     NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ver`  (`ver`),
            KEY `idx_name` (`name`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($pdo, "ALTER TABLE `schools` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ops`");
    add_column_if_missing($pdo, "ALTER TABLE `schools` ADD COLUMN `log` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '로그 관리 URL' AFTER `ops`");

    // schools 최초 자동 시딩 (slack/db.php 는 seed 경로가 잘못되어 무동작이었음 → schools/ 폴더 경로로 수정)
    if ($pdo->query("SELECT v FROM sync_meta WHERE k='schools_seeded'")->fetchColumn() === false) {
        $seedFile = __DIR__ . '/schools/schools_seed.json';
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
        $pdo->exec("INSERT INTO sync_meta (k, v) VALUES ('schools_seeded', '1') ON DUPLICATE KEY UPDATE v = v");
    }

    // 8) 로컬 담당자 배정
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

    // 9) AI 테이블 + 시드
    ai_schema($pdo);
    ai_seed_tags($pdo);
    ai_seed_settings($pdo);

    return $pdo;
}

/**
 * AI 파이프라인 테이블. 워커 store.py 의 DDL 과 1:1 — 컬럼을 바꾸면 둘 다 고친다.
 *  InnoDB utf8mb4_unicode_ci, FK 없음(저장소 관례), 이후 컬럼 추가는 add_column_if_missing().
 */
function ai_schema(PDO $pdo) {
    $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // 작업 큐. PHP 가 넣고 워커가 claim 한다.
    //  dedupe_key = kind:request_id:ref_id, open_key = 열려 있으면 1 / 끝나면 NULL → UNIQUE(dedupe_key, open_key) 로 "열린 같은 잡" 중복 방지
    //  (MySQL 은 UNIQUE 안의 NULL 을 서로 다른 값으로 보므로 끝난 잡은 몇 개든 남을 수 있다)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_jobs` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind`          VARCHAR(20)  NOT NULL COMMENT 'ingest|triage|plan|execute|commit|review|learn|distill|check_repo|revert|resync',
            `request_id`    VARCHAR(32)  NULL COMMENT 'requests.id (없을 수 있음: distill/check_repo)',
            `ref_id`        BIGINT UNSIGNED NULL COMMENT 'plan/execution/commit/repo id',
            `repo_id`       INT UNSIGNED NULL COMMENT '레포별 직렬화 키(plan/execute/commit/revert)',
            `dedupe_key`    VARCHAR(90)  NOT NULL COMMENT 'kind:request_id:ref_id',
            `params`        MEDIUMTEXT   NULL COMMENT 'JSON 입력',
            `status`        VARCHAR(12)  NOT NULL DEFAULT 'queued' COMMENT 'queued|running|done|failed|cancelled',
            `open_key`      TINYINT      NULL COMMENT 'queued/running=1, 종료 시 NULL',
            `priority`      TINYINT      NOT NULL DEFAULT 0 COMMENT '높을수록 먼저',
            `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `run_after`     DATETIME     NULL COMMENT '재시도 백오프',
            `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
            `worker`        VARCHAR(60)  NULL COMMENT 'host:pid',
            `progress`      VARCHAR(200) NULL,
            `heartbeat_at`  DATETIME     NULL,
            `started_at`    DATETIME     NULL,
            `finished_at`   DATETIME     NULL,
            `error`         TEXT         NULL,
            `result`        MEDIUMTEXT   NULL COMMENT 'JSON 결과 요약',
            `model`         VARCHAR(60)  NULL,
            `tokens_in`     INT UNSIGNED NULL,
            `tokens_out`    INT UNSIGNED NULL,
            `cost_usd`      DECIMAL(10,4) NULL,
            `requested_by`  VARCHAR(190) NULL COMMENT '포털 이메일 | sync:* | worker:* | slack:*',
            `created_at`    DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_open` (`dedupe_key`, `open_key`),
            KEY `idx_status_kind` (`status`, `kind`),
            KEY `idx_req_status`  (`request_id`, `status`),
            KEY `idx_created`     (`created_at`)
        ) $E
    ");

    // 접수 분석 결과 (요청당 1행, 재분석 시 덮어쓰고 version+1)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_triage` (
            `request_id`      VARCHAR(32)  NOT NULL,
            `version`         INT UNSIGNED NOT NULL DEFAULT 1,
            `summary_md`      TEXT         NULL COMMENT '요약 3~5줄',
            `summary_short`   VARCHAR(300) NULL COMMENT '한 줄 요약(목록 배지)',
            `problem_type`    VARCHAR(12)  NULL COMMENT 'bug|feature|question|data|ops|other',
            `urgency`         VARCHAR(10)  NULL COMMENT 'low|normal|high|critical',
            `affected_area`   VARCHAR(200) NULL,
            `difficulty`      TINYINT UNSIGNED NULL COMMENT '1~5',
            `questions`       TEXT         NULL COMMENT 'JSON [] 추가 확인 필요 사항',
            `suggested_new_tags` TEXT      NULL COMMENT 'JSON [] ai_tags 에 없는 제안',
            `repo_id`         INT UNSIGNED NULL COMMENT '확정 레포 (NULL=사용자 선택 필요)',
            `repo_confidence` DECIMAL(4,3) NULL,
            `repo_reason`     VARCHAR(30)  NULL COMMENT 'lms_url|url_pattern|title_customer|llm|user',
            `repo_candidates` TEXT         NULL COMMENT 'JSON [{id,score,reason}]',
            `content_hash`    CHAR(32)     NULL COMMENT 'md5(title+body) 변경 감지',
            `model`           VARCHAR(60)  NULL,
            `cost_usd`        DECIMAL(10,4) NULL,
            `job_id`          BIGINT UNSIGNED NULL,
            `created_at`      DATETIME     NOT NULL,
            `updated_at`      DATETIME     NOT NULL,
            PRIMARY KEY (`request_id`),
            KEY `idx_repo` (`repo_id`)
        ) $E
    ");

    // 태그(카테고리) — 사용자 CRUD
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_tags` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(60)  NOT NULL,
            `slug`        VARCHAR(60)  NOT NULL,
            `parent_id`   INT UNSIGNED NULL,
            `color`       VARCHAR(7)   NOT NULL DEFAULT '#9aa0a6',
            `keywords`    TEXT         NULL COMMENT '콤마 구분 힌트 키워드',
            `description` VARCHAR(300) NULL,
            `sort_order`  INT          NOT NULL DEFAULT 0,
            `active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME     NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_name` (`name`),
            KEY `idx_parent` (`parent_id`)
        ) $E
    ");

    // 요청 ↔ 태그 (source: ai 가 붙임 / user 가 고침 / rule 키워드)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `request_tags` (
            `request_id` VARCHAR(32)  NOT NULL,
            `tag_id`     INT UNSIGNED NOT NULL,
            `source`     VARCHAR(8)   NOT NULL DEFAULT 'ai' COMMENT 'ai|user|rule',
            `confidence` DECIMAL(4,3) NULL,
            `set_by`     VARCHAR(190) NULL,
            `created_at` DATETIME     NOT NULL,
            PRIMARY KEY (`request_id`, `tag_id`),
            KEY `idx_tag` (`tag_id`)
        ) $E
    ");

    // 유사 과거 문의 (TF-IDF 후보 20건 rank 0 + LLM 재정렬 상위 5건 rank 1~5)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_similar` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_id`  VARCHAR(32)  NOT NULL,
            `similar_id`  VARCHAR(32)  NOT NULL,
            `score_tfidf` DECIMAL(5,3) NULL,
            `score_llm`   DECIMAL(5,3) NULL,
            `rank`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=후보, 1~5=LLM 상위',
            `why_similar` VARCHAR(300) NULL,
            `resolution`  TEXT         NULL COMMENT '과거 처리 내용 요약(스레드·커밋 기반)',
            `job_id`      BIGINT UNSIGNED NULL,
            `created_at`  DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pair` (`request_id`, `similar_id`)
        ) $E
    ");

    // 댓글 스레드 평문 캐시 (cmt_count 가 바뀌면 워커가 다시 받는다)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_comment_cache` (
            `request_id` VARCHAR(32)  NOT NULL,
            `thread_ts`  VARCHAR(24)  NOT NULL DEFAULT '',
            `text`       MEDIUMTEXT   NULL COMMENT '\"이름: 내용\" 줄바꿈 평문',
            `cmt_count`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '조회 시점 requests.cmt_count',
            `fetched_at` DATETIME     NOT NULL,
            PRIMARY KEY (`request_id`)
        ) $E
    ");

    // 작업 계획 (요청당 여러 버전)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_plans` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_id`    VARCHAR(32)  NOT NULL,
            `version`       INT UNSIGNED NOT NULL DEFAULT 1,
            `status`        VARCHAR(12)  NOT NULL DEFAULT 'draft' COMMENT 'draft|approved|executing|executed|committed|reviewed|rejected|superseded|failed',
            `repo_id`       INT UNSIGNED NULL,
            `title`         VARCHAR(300) NULL,
            `plan_md`       MEDIUMTEXT   NULL,
            `plan_json`     MEDIUMTEXT   NULL COMMENT '{understanding_md,approach_md,files:[{path,action,why}],steps:[{n,text,files}],risks,test_plan,questions,estimated_minutes,confidence,needs_human}',
            `budget_usd`    DECIMAL(8,2) NULL COMMENT '실행 예산 상한(승인 시 확정)',
            `est_minutes`   INT UNSIGNED NULL,
            `user_hint`     TEXT         NULL COMMENT '재생성 시 사용자가 준 힌트',
            `prompt_md`     MEDIUMTEXT   NULL COMMENT '워커가 자동 조립한 플랜 프롬프트(시스템+사용자) — 화면 확인용',
            `claude_session_id` CHAR(36) NULL COMMENT '플랜 세션 → 실행 시 --resume',
            `base_revision` VARCHAR(64)  NULL COMMENT '플랜 시점 작업사본 리비전',
            `budget_hit`    TINYINT(1)   NOT NULL DEFAULT 0,
            `num_turns`     INT UNSIGNED NULL,
            `duration_ms`   INT UNSIGNED NULL,
            `model`         VARCHAR(60)  NULL,
            `cost_usd`      DECIMAL(10,4) NULL,
            `job_id`        BIGINT UNSIGNED NULL,
            `created_by`    VARCHAR(190) NULL,
            `approved_by`   VARCHAR(190) NULL,
            `approved_at`   DATETIME     NULL,
            `executed_at`   DATETIME     NULL,
            `rejected_by`   VARCHAR(190) NULL,
            `rejected_at`   DATETIME     NULL,
            `reject_reason` TEXT         NULL,
            `metrics_json`  TEXT         NULL COMMENT 'learn 결과: {jaccard,precision,recall,missed,unexpected,est_vs_actual,verdict}',
            `created_at`    DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_req_ver` (`request_id`, `version`),
            KEY `idx_status` (`status`)
        ) $E
    ");

    // 플랜 실행 (Claude 가 실제 수정한 결과)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_executions` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plan_id`        BIGINT UNSIGNED NOT NULL,
            `request_id`     VARCHAR(32)  NOT NULL,
            `status`         VARCHAR(12)  NOT NULL DEFAULT 'queued' COMMENT 'queued|running|done|failed|cancelled|reverted',
            `resumed`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0=플랜 세션 재개 실패 → 새 세션',
            `preexisting_json` TEXT       NULL COMMENT '실행 전 untracked 파일 목록(svn add 제외)',
            `branch`         VARCHAR(120) NULL,
            `diff`           MEDIUMTEXT   NULL COMMENT 'unified diff (2MB 캡)',
            `diff_stat`      TEXT         NULL COMMENT 'JSON [{path,add,del,status}]',
            `files_changed`  INT UNSIGNED NULL,
            `lines_added`    INT UNSIGNED NULL,
            `lines_deleted`  INT UNSIGNED NULL,
            `lint_ok`        TINYINT(1)   NULL,
            `lint_output`    TEXT         NULL,
            `result_md`      MEDIUMTEXT   NULL COMMENT 'Claude 완료 보고',
            `log`            MEDIUMTEXT   NULL,
            `budget_hit`     TINYINT(1)   NOT NULL DEFAULT 0,
            `model`          VARCHAR(60)  NULL,
            `cost_usd`       DECIMAL(10,4) NULL,
            `job_id`         BIGINT UNSIGNED NULL,
            `approved_by`    VARCHAR(190) NULL,
            `started_at`     DATETIME     NULL,
            `finished_at`    DATETIME     NULL,
            `created_at`     DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_plan` (`plan_id`),
            KEY `idx_req`  (`request_id`)
        ) $E
    ");

    // 커밋
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_commits` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `execution_id` BIGINT UNSIGNED NOT NULL,
            `request_id`   VARCHAR(32)  NOT NULL,
            `repo_id`      INT UNSIGNED NULL,
            `vcs`          VARCHAR(5)   NULL COMMENT 'svn|git',
            `revision`     VARCHAR(80)  NULL,
            `branch`       VARCHAR(120) NULL,
            `message`      TEXT         NOT NULL,
            `status`       VARCHAR(12)  NOT NULL DEFAULT 'pending' COMMENT 'pending|committed|pushed|failed',
            `log`          TEXT         NULL,
            `job_id`       BIGINT UNSIGNED NULL,
            `committed_by` VARCHAR(190) NULL,
            `committed_at` DATETIME     NULL,
            `created_at`   DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_exec` (`execution_id`),
            KEY `idx_req`  (`request_id`)
        ) $E
    ");

    // 타 AI 검토
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_reviews` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `commit_id`     BIGINT UNSIGNED NOT NULL,
            `request_id`    VARCHAR(32)  NOT NULL,
            `reviewer`      VARCHAR(40)  NOT NULL COMMENT 'codex|openai|gemini|claude|human',
            `verdict`       VARCHAR(16)  NULL COMMENT 'pass|warn|fail|error',
            `addresses_inquiry` TINYINT(1) NULL,
            `report_md`     MEDIUMTEXT   NULL,
            `findings_json` TEXT         NULL COMMENT '[{severity,file,line,text,suggestion}]',
            `model`         VARCHAR(60)  NULL,
            `cost_usd`      DECIMAL(10,4) NULL,
            `job_id`        BIGINT UNSIGNED NULL,
            `requested_by`  VARCHAR(190) NULL,
            `created_at`    DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_commit` (`commit_id`),
            KEY `idx_req`    (`request_id`)
        ) $E
    ");

    // 학습 노트(교훈) — 승인된 것만 다음 플랜 프롬프트에 들어간다
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_lessons` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_id`     VARCHAR(32)  NULL,
            `plan_id`        BIGINT UNSIGNED NULL,
            `commit_id`      BIGINT UNSIGNED NULL,
            `kind`           VARCHAR(20)  NOT NULL COMMENT 'file_miss|approach|style|test|estimate|tag_correction|review_finding|manual|other',
            `scope`          VARCHAR(8)   NOT NULL DEFAULT 'repo' COMMENT 'repo|tag|global',
            `repo_id`        INT UNSIGNED NULL,
            `tag_id`         INT UNSIGNED NULL,
            `title`          VARCHAR(300) NOT NULL,
            `lesson_md`      MEDIUMTEXT   NULL COMMENT '지시형 1~2문장',
            `evidence_md`    MEDIUMTEXT   NULL,
            `weight`         DECIMAL(4,3) NOT NULL DEFAULT 0.500,
            `evidence_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `used_count`     INT UNSIGNED NOT NULL DEFAULT 0,
            `status`         VARCHAR(10)  NOT NULL DEFAULT 'proposed' COMMENT 'proposed|approved|rejected',
            `approved_by`    VARCHAR(190) NULL,
            `approved_at`    DATETIME     NULL,
            `job_id`         BIGINT UNSIGNED NULL,
            `created_at`     DATETIME     NOT NULL,
            `updated_at`     DATETIME     NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status_kind` (`status`, `kind`),
            KEY `idx_repo`  (`repo_id`),
            KEY `idx_req`   (`request_id`)
        ) $E
    ");

    // 감사 로그 (버튼 클릭·승인·워커 이벤트)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_events` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_id` VARCHAR(32)  NULL,
            `actor`      VARCHAR(190) NOT NULL COMMENT '포털 이메일 | worker:* | sync:* | slack:*',
            `action`     VARCHAR(40)  NOT NULL,
            `ref_table`  VARCHAR(30)  NULL,
            `ref_id`     BIGINT UNSIGNED NULL,
            `detail`     TEXT         NULL COMMENT 'JSON',
            `ip`         VARCHAR(45)  NULL,
            `created_at` DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_req`    (`request_id`, `id`),
            KEY `idx_action` (`action`, `created_at`)
        ) $E
    ");

    // 설정 (k/v) — 화면 토글이 워커 동작을 바꾼다
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_settings` (
            `k`          VARCHAR(64)  NOT NULL,
            `v`          TEXT         NULL,
            `updated_by` VARCHAR(190) NULL,
            `updated_at` DATETIME     NULL,
            PRIMARY KEY (`k`)
        ) $E
    ");

    // 고객사 → 로컬 작업 사본 매핑
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_repos` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(120) NOT NULL,
            `school_id`      INT UNSIGNED NULL COMMENT 'schools.id',
            `vcs`            VARCHAR(5)   NOT NULL DEFAULT 'svn' COMMENT 'svn|git',
            `remote_url`     VARCHAR(500) NULL,
            `local_path`     VARCHAR(500) NOT NULL COMMENT '워커 호스트의 작업 사본 경로',
            `default_branch` VARCHAR(80)  NULL,
            `version`        VARCHAR(20)  NULL COMMENT 'LMS 버전 3.5/3.9/4.5',
            `php_bin`        VARCHAR(300) NULL COMMENT '이 레포용 php 실행 파일(없으면 워커 PHP_BIN)',
            `match_rules`    TEXT         NULL COMMENT 'JSON {lms_patterns:[],title_keywords:[]}',
            `notes`          TEXT         NULL COMMENT '플랜 시스템 프롬프트에 붙는 저장소 메모',
            `active`         TINYINT(1)   NOT NULL DEFAULT 1,
            `last_checked`   DATETIME     NULL,
            `check_status`   VARCHAR(12)  NULL COMMENT 'ok|fail|unchecked',
            `check_message`  VARCHAR(300) NULL,
            `head_revision`  VARCHAR(80)  NULL,
            `created_at`     DATETIME     NOT NULL,
            `updated_at`     DATETIME     NULL,
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`)
        ) $E
    ");

    // 이후 컬럼 추가는 전부 여기서 add_column_if_missing() 으로. (예)
    add_column_if_missing($pdo, "ALTER TABLE `ai_plans` ADD COLUMN `prompt_md` MEDIUMTEXT NULL COMMENT '워커가 자동 조립한 플랜 프롬프트(시스템+사용자) — 화면 확인용' AFTER `user_hint`");
}

/** ai_tags 최초 시드 (tags/tags_seed.php). 비어 있을 때 1회. */
function ai_seed_tags(PDO $pdo) {
    if ($pdo->query("SELECT v FROM sync_meta WHERE k='ai_tags_seeded'")->fetchColumn() !== false) return;
    $seedFile = __DIR__ . '/tags/tags_seed.php';
    $empty = ((int)$pdo->query("SELECT COUNT(*) FROM ai_tags")->fetchColumn() === 0);
    if ($empty && is_file($seedFile)) {
        $seed = require $seedFile;
        $ins  = $pdo->prepare("INSERT INTO ai_tags (name, slug, parent_id, color, keywords, sort_order, active, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
        $pdo->beginTransaction();
        $i = 0;
        foreach ($seed['cats'] as [$key, $label, $kws]) {
            $color = $seed['colors'][$i % count($seed['colors'])];
            $ins->execute([$label, $key, null, $color, implode(',', $kws), $i * 10]);
            $pid = (int)$pdo->lastInsertId();
            $j = 0;
            foreach (($seed['subs'][$key] ?? []) as [$slabel, $skws]) {
                $ins->execute([$label . '/' . $slabel, $key . '-' . ($j + 1), $pid, $color, implode(',', $skws), $i * 10 + $j + 1]);
                $j++;
            }
            $i++;
        }
        $pdo->commit();
    }
    $pdo->exec("INSERT INTO sync_meta (k, v) VALUES ('ai_tags_seeded', '1') ON DUPLICATE KEY UPDATE v = v");
}

/** ai_settings 기본값 (INSERT IGNORE — 이미 있는 키는 건드리지 않음). 워커 .env 와 같은 기본값. */
function ai_seed_settings(PDO $pdo) {
    // v2: 자동 플랜 모델(기본 haiku — 토큰 비용 절감). 이미 있는 값은 건드리지 않는다.
    $pdo->exec("INSERT IGNORE INTO ai_settings (k, v, updated_by, updated_at) VALUES ('plan_model_auto', 'claude-haiku-4-5', 'seed', NOW())");
    if ($pdo->query("SELECT v FROM sync_meta WHERE k='ai_settings_seeded_v1'")->fetchColumn() !== false) return;
    $defaults = [
        'paused'              => '0',      // 1 이면 워커가 잡을 집지 않음
        'approvers'           => '[]',     // JSON 이메일 배열 (config.php slackai.approvers 와 합집합)
        'auto_triage'         => '1',      // 신규 문의 자동 분석
        'auto_plan'           => '1',      // 분석 후 레포가 확정되면 플랜까지 자동 실행(승인~검토 중인 플랜이 있으면 생략)
        'auto_review'         => '0',      // 커밋 후 자동 검토
        'retriage_on_update'  => '0',      // 본문 갱신 시 재분석
        'enqueue_on_full'     => '0',      // full 동기화에서도 신규 enqueue
        'similar_min'         => '0.15',
        'similar_limit'       => '20',
        'heartbeat_stale_sec' => '120',
        'budget_plan_usd'     => '3.00',
        'budget_execute_usd'  => '10.00',
        'budget_review_usd'   => '2.00',
        'max_daily_usd'       => '30.00',
        'reviewer'            => 'codex,openai,gemini,claude',
        'post_to_slack'       => '0',
        'comment_scan_sec'    => '0',      // 0 = full 동기화에서만 댓글 수 전체 스캔(이벤트 수신이 대신함)
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO ai_settings (k, v, updated_by, updated_at) VALUES (?, ?, 'seed', NOW())");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
    $pdo->exec("INSERT INTO sync_meta (k, v) VALUES ('ai_settings_seeded_v1', '1') ON DUPLICATE KEY UPDATE v = v");
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
