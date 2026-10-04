-- =====================================================================
-- 017 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/017_rollback_api_budget.sql
--
-- 호출 기록이 사라진다. 자격 정보(토큰)는 그대로 남는다 — 되돌린다고
-- 관리자가 다시 연결해야 하는 일이 생기면 안 된다.
-- =====================================================================

DROP TABLE IF EXISTS `bs_api_usage`;

ALTER TABLE `bs_integration`
  DROP COLUMN `enabled`,
  DROP COLUMN `cooldown_until`,
  DROP COLUMN `cooldown_reason`,
  DROP COLUMN `daily_cap`;
