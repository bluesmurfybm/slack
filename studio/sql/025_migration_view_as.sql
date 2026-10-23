-- =====================================================================
-- 025. 관리자의 "사용자로 보기" 기록
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/025_migration_view_as.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/025_migration_view_as.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 보기만 한다. 그래도 **누가 누구로 봤는지는 남긴다** (2026-10-12)   │
-- │                                                                  │
-- │ 관리자가 일반 사용자 화면이 어떻게 보이는지 확인하려고 쓴다.       │
-- │ 저장은 bs_begin_write() 가 모두 막는다 — 남의 이름으로 기록이     │
-- │ 남지 않게. 그래도 남의 화면(점유·프로파일 열람 범위)을 보는 일이라 │
-- │ 시작·끝을 이 표에 적는다. BlueStudio 안에서만 쓴다.                │
-- └──────────────────────────────────────────────────────────────────┘
-- =====================================================================

CREATE TABLE IF NOT EXISTS `bs_view_as_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    VARCHAR(64)  NOT NULL COMMENT '실제로 로그인한 관리자(이메일)',
  `admin_name`  VARCHAR(80)  NULL     COMMENT '그 시점 성명 스냅샷',
  `target_id`   VARCHAR(64)  NOT NULL COMMENT '대신 본 사용자(이메일)',
  `target_name` VARCHAR(80)  NULL     COMMENT '그 시점 성명 스냅샷',
  `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '보기 시작',
  `ended_at`    DATETIME     NULL     COMMENT '[돌아가기] 를 누른 때. 세션이 끝나 버렸으면 비어 있다',
  `ip`          VARCHAR(45)  NULL     COMMENT '요청한 곳',
  PRIMARY KEY (`id`),
  KEY `ix_bs_vas_admin`  (`admin_id`, `started_at`),
  KEY `ix_bs_vas_target` (`target_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='관리자의 사용자로 보기(보기 전용) 기록';


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT admin_name, target_name, started_at, ended_at FROM bs_view_as_log ORDER BY id DESC;
-- ---------------------------------------------------------------------
