-- =====================================================================
-- 020 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/020_rollback_workload_editor.sql
--
-- 점유 기록 자체는 남는다. **누가 고쳤는지만 사라진다** — 등록한 사람
-- (created_by)은 그대로다.
-- =====================================================================

ALTER TABLE `bs_workload`
  DROP COLUMN `updated_by`,
  DROP COLUMN `updated_by_name`;
