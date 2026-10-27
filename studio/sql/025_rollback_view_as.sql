-- =====================================================================
-- 025 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/025_rollback_view_as.sql
--
-- 누가 누구로 봤는지의 기록이 **사라진다.** 지우기 전에 떠 두라:
--
--   SELECT * FROM bs_view_as_log;
--
-- 코드는 표가 없으면 기록만 건너뛰고 보기 기능은 그대로 돈다.
-- =====================================================================

DROP TABLE IF EXISTS `bs_view_as_log`;
