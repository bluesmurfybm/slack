-- =====================================================================
-- 019 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/019_rollback_est_source.sql
--
-- est_md 값 자체는 남는다. **누가 매겼는지만 사라진다** — 되돌린 뒤에는
-- 사람이 넣은 공수와 자동 판정을 구분할 수 없으니, 다시 올리기 전에는
-- 자동 판정을 돌리지 말 것.
-- =====================================================================

ALTER TABLE `bs_task`
  DROP COLUMN `est_md_by`,
  DROP COLUMN `est_md_note`;
