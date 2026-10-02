-- =====================================================================
-- 016 되돌리기 — 링크 분석
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/016_rollback_analysis.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/016_rollback_analysis.sql
--
-- **읽어 둔 링크 내용이 사라진다.** 다시 읽으려면 분석을 다시 돌려야 하고,
-- 상대 서비스를 그만큼 다시 부른다. 지우기 전에 세어 보라:
--
--   SELECT COUNT(*) FROM bs_source_link WHERE status = 'ok';
--
-- 태스크의 난이도 숫자(bs_task.difficulty)는 남는다. 누가 매겼는지와
-- 그 근거만 사라진다.
-- =====================================================================

DROP TABLE IF EXISTS `bs_analysis_job`;
DROP TABLE IF EXISTS `bs_source_link`;

ALTER TABLE `bs_task`
  DROP COLUMN `difficulty_note`,
  DROP COLUMN `difficulty_by`;
