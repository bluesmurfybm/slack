-- =====================================================================
-- 018 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/018_rollback_job_result.sql
--
-- 저장되지 않은 WBS 초안이 사라진다. 이미 [저장] 한 WBS 는 bs_task 에
-- 있으므로 영향이 없다.
-- =====================================================================

ALTER TABLE `bs_analysis_job` DROP COLUMN `result_json`;
