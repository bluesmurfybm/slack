-- =====================================================================
-- 014 되돌리기 — 사업 유형
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/014_rollback_project_track.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/014_rollback_project_track.sql
--
-- 'lxp_campus' 를 'lxp' 로 되돌린다.
--
-- **'lxp_open'(개방형)은 되돌릴 자리가 없다.** 014 전에는 없던 값이라
-- 어디로 보내야 할지 알 수 없다. 그런 행이 있으면 사람이 정해야 하므로
-- 손대지 않고 둔다 — 화면에는 'lxp_open' 이라는 코드로 그대로 보인다.
-- 되돌리기 전에 먼저 세어 보라:
--
--   SELECT COUNT(*) FROM bs_project WHERE track = 'lxp_open';
-- =====================================================================

UPDATE `bs_project` SET `track` = 'lxp' WHERE `track` = 'lxp_campus';

ALTER TABLE `bs_project`
  MODIFY COLUMN `track` VARCHAR(40) NULL
  COMMENT 'lms_b2b|lxp|lxp_hybrid|용역|사내';
