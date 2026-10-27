-- =====================================================================
-- 031 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/031_rollback_rnd_log_url.sql
--
-- 진행 기록에 붙인 **링크가 모두 사라진다.** 먼저 떠 두라:
--
--   SELECT id, project_id, url FROM bs_rnd_log WHERE url IS NOT NULL;
-- =====================================================================

ALTER TABLE `bs_rnd_log` DROP COLUMN `url`;
