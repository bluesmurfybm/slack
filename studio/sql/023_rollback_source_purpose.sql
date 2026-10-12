-- =====================================================================
-- 023 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/023_rollback_source_purpose.sql
--
-- 참고 자료로 올린 문서가 **범위 분석용으로 섞인다.** 다음 WBS 도출부터
-- 그 문서도 재료가 된다. 되돌리기 전에 참고 자료를 지울지 먼저 정하라:
--
--   SELECT id, project_id, title FROM bs_project_source WHERE purpose = 'reference';
-- =====================================================================

ALTER TABLE `bs_project_source` DROP COLUMN `purpose`;
