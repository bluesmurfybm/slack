-- =====================================================================
-- 024 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/024_rollback_candidate_mark.sql
--
-- ★ 과 가감점이 **통째로 사라진다.** 비중이 1.000 이면서 ★·가감점만 있던
-- 줄은 022 의 규칙(비중 1.000 은 줄을 두지 않는다)에 맞게 함께 지운다.
-- =====================================================================

DELETE FROM `bs_project_member` WHERE `share` >= 1.000;

ALTER TABLE `bs_project_member`
  DROP COLUMN `adjust_reason`,
  DROP COLUMN `fit_adjust`,
  DROP COLUMN `priority`;
