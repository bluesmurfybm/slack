-- =====================================================================
-- 029 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/029_rollback_rnd_file.sql
--
-- 진행 기록·산출물의 **첨부 기록이 모두 사라진다.** 디스크의 파일은 남으므로
-- 따로 치워야 한다. 지우기 전에 떠 두라:
--
--   SELECT * FROM bs_rnd_file;
-- =====================================================================

DROP TABLE IF EXISTS `bs_rnd_file`;

ALTER TABLE `bs_rnd_log`
  DROP COLUMN `updated_by_name`,
  DROP COLUMN `updated_at`;

ALTER TABLE `bs_rnd_output`
  DROP COLUMN `updated_by_name`,
  DROP COLUMN `updated_at`;
