-- =====================================================================
-- 016. 링크 분석 — 발견한 링크와 그 결과, 그리고 작업 큐
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/016_migration_analysis.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/016_migration_analysis.sql
--
-- IA 시트에는 항목마다 기획 화면(피그마·드라이브) 주소가 걸려 있다. 그 주소를
-- 따라가 내용까지 읽어야 태스크의 성격을 알 수 있다.
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 웹 요청 안에서 할 수 없는 일이다                                  │
-- │                                                                  │
-- │ IA 항목이 100개면 피그마를 100번 부른다. 한 번에 몇 초씩 걸리니   │
-- │ 요청이 타임아웃으로 죽고, 그 사이 화면이 멈춘다. 중간에 끊기면    │
-- │ 어디까지 했는지도 모른다.                                         │
-- │                                                                  │
-- │ 그래서 **큐에 넣고 크론이 하나씩 처리한다**. 화면은 넣기만 하고   │
-- │ 진행률만 본다. bluecart/cron 이 쓰는 방식과 같다.                 │
-- └──────────────────────────────────────────────────────────────────┘
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 발견한 링크
--
-- 출처 문서(IA 시트)의 글자에서 뽑아낸 주소 한 줄이 한 행이다.
-- 읽기 전에도 행이 생긴다 — 무엇을 읽을 예정인지가 먼저 보여야 한다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_source_link` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `source_id`  INT UNSIGNED NULL
               COMMENT '이 링크를 찾아낸 출처 문서. 문서가 지워지면 NULL 로 남는다',

  `url`        VARCHAR(500) NOT NULL COMMENT '찾아낸 주소',
  `provider`   VARCHAR(20)  NOT NULL DEFAULT 'other'
               COMMENT '읽을 수 있는 서비스인가. google | figma | other',

  -- **어느 항목의 링크인가.** 이게 없으면 읽어 온 글이 어느 태스크 것인지
  -- 알 수 없다. 시트에서 그 주소가 있던 줄을 그대로 담는다.
  `context`    VARCHAR(500) NULL COMMENT '그 주소가 있던 줄. 어느 항목인지 알아보는 근거',

  `status`     VARCHAR(20)  NOT NULL DEFAULT 'pending'
               COMMENT 'pending=읽기 전 | ok=읽음 | fail=실패 | skip=읽을 수 없는 서비스',
  `title`      VARCHAR(200) NULL COMMENT '읽어 온 문서의 이름',
  `parsed_text` MEDIUMTEXT  NULL COMMENT '읽어 온 글자. 난이도 판정과 WBS 도출이 쓴다',
  `error`      VARCHAR(300) NULL COMMENT '실패 사유. 사람이 읽을 말로 적는다',

  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fetched_at` DATETIME     NULL COMMENT '마지막으로 읽어 본 시각',

  PRIMARY KEY (`id`),
  -- 같은 주소가 여러 줄에 나와도 한 번만 읽는다. IA 시트에서 같은 기획
  -- 화면을 여러 항목이 가리키는 일이 흔하다.
  UNIQUE KEY `uk_bs_slink_url` (`project_id`, `url`),
  KEY `ix_bs_slink_todo` (`project_id`, `status`),
  CONSTRAINT `fk_bs_slink_project` FOREIGN KEY (`project_id`)
      REFERENCES `bs_project` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='출처 문서에서 찾아낸 링크와 읽어 온 내용';


-- ---------------------------------------------------------------------
-- 2. 작업 큐
--
-- 한 프로젝트에 한 번에 하나만 돈다. 두 사람이 동시에 눌러도 두 번 돌지
-- 않게 하려는 것이다 — 같은 링크를 두 번 읽으면 상대 서비스의 호출 제한에
-- 걸린다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_analysis_job` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,

  `kind`       VARCHAR(20)  NOT NULL
               COMMENT '무슨 일인가. links=링크 읽기 | difficulty=난이도 판정',
  `status`     VARCHAR(20)  NOT NULL DEFAULT 'queued'
               COMMENT 'queued=대기 | running=도는 중 | done=끝 | failed=실패 | canceled=취소',

  `total`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '할 일 수',
  `done`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '끝낸 수(실패 포함)',
  `failed`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '그중 실패한 수',
  `message`    VARCHAR(300) NULL COMMENT '화면에 보여 줄 한 줄',

  -- 워커가 죽어도 큐가 영영 막히지 않게 한다. running 인데 heartbeat 가
  -- 오래 멈춰 있으면 다른 워커가 집어 간다.
  `heartbeat_at` DATETIME   NULL COMMENT '워커가 살아 있다고 찍는 시각',

  `created_by`      VARCHAR(64) NULL,
  `created_by_name` VARCHAR(80) NULL,
  `created_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at`      DATETIME    NULL,
  `finished_at`     DATETIME    NULL,

  PRIMARY KEY (`id`),
  KEY `ix_bs_job_todo` (`status`, `id`),
  KEY `ix_bs_job_project` (`project_id`, `id`),
  CONSTRAINT `fk_bs_job_project` FOREIGN KEY (`project_id`)
      REFERENCES `bs_project` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='오래 걸리는 분석 작업. 크론 워커가 하나씩 집어 간다';


-- ---------------------------------------------------------------------
-- 3. 태스크 난이도의 근거
--
-- 난이도를 누가 어떻게 매겼는지 남긴다. 숫자만 있으면 "왜 4점이냐" 에
-- 답할 수 없고, 답할 수 없는 숫자는 배정 근거로 못 쓴다.
-- ---------------------------------------------------------------------
ALTER TABLE `bs_task`
  ADD COLUMN `difficulty_by` VARCHAR(20) NULL
      COMMENT '난이도를 매긴 주체. human=사람 | rule=규칙 | ai=모델'
      AFTER `difficulty`,
  ADD COLUMN `difficulty_note` VARCHAR(500) NULL
      COMMENT '그렇게 본 근거. 화면에 그대로 보여 준다'
      AFTER `difficulty_by`;


-- ---------------------------------------------------------------------
-- 4. 확인
--
--   SELECT status, COUNT(*) FROM bs_source_link GROUP BY status;
--   SELECT id, kind, status, done, total FROM bs_analysis_job ORDER BY id DESC LIMIT 5;
-- ---------------------------------------------------------------------
