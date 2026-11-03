-- =====================================================================
-- 032 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/032_rollback_idea.sql
--
-- 아이디어·평가·피드백이 **모두 사라진다.** 과제·프로젝트는 남지만
-- "출발 아이디어" 연결이 끊긴다. 먼저 떠 두라:
--
--   sh studio/sql/dump.sh <db> ...      (또는)
--   SELECT * FROM bs_idea;
-- =====================================================================

ALTER TABLE `bs_project`
  DROP FOREIGN KEY `fk_bs_project_origin_idea`,
  DROP FOREIGN KEY `fk_bs_project_origin_prj`;
ALTER TABLE `bs_project`
  DROP KEY `ix_bs_project_origin_idea`,
  DROP KEY `ix_bs_project_origin_prj`,
  DROP COLUMN `origin_idea_id`,
  DROP COLUMN `origin_project_id`;

DROP TABLE IF EXISTS `bs_idea_comment`;
DROP TABLE IF EXISTS `bs_idea_rating`;
DROP TABLE IF EXISTS `bs_idea_domain`;
DROP TABLE IF EXISTS `bs_idea`;
