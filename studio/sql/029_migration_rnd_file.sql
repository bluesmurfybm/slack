-- =====================================================================
-- 029. R&D 진행 기록·산출물 첨부 + 고친 기록
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/029_migration_rnd_file.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/029_migration_rnd_file.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 진행 기록·산출물에 파일을 붙이고, 고치고 지울 수 있게 (2026-10-19)  │
-- │                                                                  │
-- │ 산출물에는 file_path 칸이 하나 있었지만 쓰이지 않았고(링크만 받음),│
-- │ 진행 기록에는 붙일 곳이 없었다. 둘이 같이 쓰는 첨부 표를 둔다 —    │
-- │ 한 건에 여러 파일. 파일은 웹 루트 바깥(upload_dir)에 두고          │
-- │ api/rnd_file.php 만 내보낸다. 과제의 공개 범위를 거기서 본다.       │
-- │                                                                  │
-- │ 고치기·지우기는 작성자·주도자·관리자가 언제든 한다(사용자 결정).   │
-- │ 누가 언제 고쳤는지는 남긴다.                                      │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 과제를 영구삭제하면 FK 로 첨부 행도 지워진다. 디스크의 파일은 응용이
-- 지운다(RndRepo::purge). 진행 기록·산출물을 지울 때도 응용이 첨부를 지운다
-- — owner 는 두 표를 가리키므로 FK 를 걸 수 없다.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `bs_rnd_file` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `project_id`       INT UNSIGNED  NOT NULL COMMENT 'bs_project.id (project_type=rnd)',
  `owner_type`       VARCHAR(10)   NOT NULL COMMENT '어디에 붙었나. log=진행 기록 | output=산출물',
  `owner_id`         BIGINT UNSIGNED NOT NULL COMMENT 'bs_rnd_log.id 또는 bs_rnd_output.id',
  `orig_name`        VARCHAR(255)  NOT NULL COMMENT '올린 파일 이름',
  `file_path`        VARCHAR(500)  NOT NULL COMMENT '저장 실경로(웹 루트 바깥). 응답에 싣지 않는다',
  `mime`             VARCHAR(120)  NULL     COMMENT '내용으로 확인한 형식',
  `file_size`        INT UNSIGNED  NULL     COMMENT '바이트',
  `uploaded_by`      VARCHAR(64)   NULL     COMMENT '올린 사람(이메일)',
  `uploaded_by_name` VARCHAR(80)   NULL     COMMENT '올린 사람 성명 스냅샷',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bs_rndfile_owner` (`owner_type`, `owner_id`),
  KEY `ix_bs_rndfile_project` (`project_id`),
  CONSTRAINT `fk_bs_rndfile_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='R&D 진행 기록·산출물 첨부. 한 건에 여러 파일';

ALTER TABLE `bs_rnd_log`
  ADD COLUMN `updated_at`      DATETIME    NULL COMMENT '마지막으로 고친 때. 고친 적 없으면 NULL',
  ADD COLUMN `updated_by_name` VARCHAR(80) NULL COMMENT '마지막으로 고친 사람 성명 스냅샷';

ALTER TABLE `bs_rnd_output`
  ADD COLUMN `updated_at`      DATETIME    NULL COMMENT '마지막으로 고친 때. 고친 적 없으면 NULL',
  ADD COLUMN `updated_by_name` VARCHAR(80) NULL COMMENT '마지막으로 고친 사람 성명 스냅샷';


-- ---------------------------------------------------------------------
-- 확인
--
--   SHOW TABLES LIKE 'bs\_rnd\_file';
--   SHOW COLUMNS FROM bs_rnd_log LIKE 'updated%';
-- ---------------------------------------------------------------------
