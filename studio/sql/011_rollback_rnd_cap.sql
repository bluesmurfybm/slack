-- =====================================================================
-- 011 되돌리기 — 전역 설정 표
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/011_rollback_rnd_cap.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/011_rollback_rnd_cap.sql
--
-- =====================================================================
-- !! 운영에서 조정한 값이 사라집니다 !!
--
--   상한을 운영하면서 바꿨다면 그 값이 없어집니다. 되돌리기 전에 먼저
--   적어 두십시오.
--
--     SELECT k, v FROM bs_setting;
--
--   표가 없어지면 코드는 bs_setting_default() 의 폴백 값으로 돌아갑니다.
--   **통제가 사라지는 것이 아닙니다** — 상한은 그대로 걸리고, 다만 값을
--   화면에서 바꿀 수 없게 됩니다.
-- =====================================================================
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `bs_setting`;
