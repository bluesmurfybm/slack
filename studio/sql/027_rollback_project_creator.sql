-- =====================================================================
-- 027 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/027_rollback_project_creator.sql
--
-- 켜 둔 등록 권한이 **모두 사라진다.** 다시 관리자만 등록할 수 있다.
-- 이미 등록된 프로젝트와 그 PM 은 그대로다. 지우기 전에 떠 두라:
--
--   SELECT id, emp_name, user_id, create_granted_by FROM bs_member WHERE can_create_project = 1;
-- =====================================================================

ALTER TABLE `bs_member`
  DROP COLUMN `create_granted_at`,
  DROP COLUMN `create_granted_by`,
  DROP COLUMN `can_create_project`;
