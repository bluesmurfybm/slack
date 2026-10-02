-- =====================================================================
-- 015 되돌리기 — 외부 연동 자격 정보
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/015_rollback_integration.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/015_rollback_integration.sql
--
-- **저장해 둔 토큰이 사라진다.** 되돌린 뒤 다시 쓰려면 구글 동의를 다시
-- 받고 피그마 토큰을 다시 넣어야 한다. 지우기 전에 한 번 확인하라:
--
--   SELECT provider, account FROM bs_integration;
--
-- 이미 읽어 둔 parsed_text 는 bs_project_source 에 남아 있어 사라지지 않는다.
-- 다시 읽지 못할 뿐이다.
-- =====================================================================

DROP TABLE IF EXISTS `bs_integration`;
