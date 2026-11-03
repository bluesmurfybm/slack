-- =====================================================================
-- 033. 아이디어 참고 자료 (파일 · 링크)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/033_migration_idea_source.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/033_migration_idea_source.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 아이디어에도 자료를 붙인다 (2026-11-03 사용자 요청)               │
-- │                                                                  │
-- │ 032 는 참고 링크 칸 하나뿐이었다. 화면 캡처·조사 문서·참고 서비스 │
-- │ 주소를 여러 개 붙이려면 표가 따로 있어야 한다. 모양은 프로젝트·   │
-- │ R&D 과제의 참고 자료(bs_project_source)와 같게 두고, 파일은 같은  │
-- │ upload_dir 아래에 같은 검사(SourceUploader::saveFile)로 저장한다. │
-- │ 내보내기는 api/idea_file.php 만 한다.                             │
-- │                                                                  │
-- │ bs_project_source 에 넣지 않는 이유 — 그 표는 project_id 가 필수다.│
-- │ 아이디어는 과업이 아니다.                                         │
-- │                                                                  │
-- │ 과제·프로젝트로 전환하면 자료는 **복사**되어 그쪽 참고 자료가 된다│
-- │ (파일도 따로 복사). 한쪽을 지워도 다른 쪽은 남는다.               │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 032 의 bs_idea.url(링크 하나)은 이 표로 옮기고 비운다. 칸은 남긴다 —
-- 되돌리기가 다시 채울 자리다.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

CREATE TABLE IF NOT EXISTS `bs_idea_source` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `idea_id`          INT UNSIGNED  NOT NULL COMMENT 'bs_idea.id',
  `kind`             VARCHAR(10)   NOT NULL COMMENT '종류. xlsx|pptx|docx|pdf|image(파일) / url|figma(링크) — bs_project_source.kind 와 같은 값',
  `title`            VARCHAR(200)  NOT NULL COMMENT '표시 이름. 파일은 올린 파일 이름',
  `url`              VARCHAR(2000) NULL     COMMENT '링크 주소. 파일이면 NULL',
  `file_path`        VARCHAR(500)  NULL     COMMENT '저장 실경로(웹 루트 바깥). 응답에 싣지 않는다. 링크면 NULL',
  `mime`             VARCHAR(120)  NULL     COMMENT '내용으로 확인한 형식',
  `file_size`        INT UNSIGNED  NULL     COMMENT '바이트',
  `uploaded_by`      VARCHAR(64)   NULL     COMMENT '올린 사람(이메일)',
  `uploaded_by_name` VARCHAR(80)   NULL     COMMENT '올린 사람 성명 스냅샷',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bs_ideasrc_idea` (`idea_id`),
  CONSTRAINT `fk_bs_ideasrc_idea` FOREIGN KEY (`idea_id`)
    REFERENCES `bs_idea` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='아이디어 참고 자료 — 파일·링크. 전환하면 과제·프로젝트 참고 자료로 복사된다';

-- 032 의 링크 하나를 옮긴다. 표시 이름은 주소 그대로.
INSERT INTO `bs_idea_source` (idea_id, kind, title, url, uploaded_by, uploaded_by_name, created_at)
SELECT id,
       IF(LOWER(url) LIKE '%figma.com%', 'figma', 'url'),
       LEFT(url, 200), url, author_id, author_name, created_at
  FROM `bs_idea`
 WHERE url IS NOT NULL AND url <> '';

UPDATE `bs_idea` SET url = NULL WHERE url IS NOT NULL;


-- ---------------------------------------------------------------------
-- 확인
--
--   SHOW TABLES LIKE 'bs\_idea\_source';
--   SELECT COUNT(*) FROM bs_idea WHERE url IS NOT NULL;   -- 0
-- ---------------------------------------------------------------------
