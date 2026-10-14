-- =====================================================================
-- 026 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/026_rollback_editors.sql
--
-- 부여해 둔 수정 권한이 **모두 사라진다.** 다시 주도자·발의자·PM·관리자만
-- 고칠 수 있게 된다. 지우기 전에 떠 두라:
--
--   SELECT * FROM bs_project_editor;
--   SELECT project_id, member_id, edit_granted_by FROM bs_rnd_member WHERE can_edit = 1;
-- =====================================================================

DROP TABLE IF EXISTS `bs_project_editor`;

ALTER TABLE `bs_rnd_member`
  DROP COLUMN `edit_granted_at`,
  DROP COLUMN `edit_granted_by`,
  DROP COLUMN `can_edit`;
