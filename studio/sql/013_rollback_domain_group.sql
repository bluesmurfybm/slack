-- =====================================================================
-- 013 되돌리기 — 분야 묶음
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/013_rollback_domain_group.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/013_rollback_domain_group.sql
--
-- **일반 분야 6개를 지운다.** 그 분야로 태그된 기록이 있으면 함께 사라진다 —
-- bs_rnd_domain · bs_task_domain · bs_work_item_domain · bs_member_skill 이
-- 전부 domain_id 로 물려 있고 ON DELETE CASCADE 다.
--
-- 이미 과제가 태그를 달았다면 지우지 말고 is_active=0 으로 내리는 쪽을
-- 생각하라. 기존 기록은 남고 새로 고르지만 못하게 된다:
--
--   UPDATE bs_domain SET is_active = 0 WHERE domain_group = 'general';
-- =====================================================================

DELETE FROM `bs_domain`
 WHERE `code` IN ('inhouse_product', 'productivity', 'ai_infra',
                  'ai_apply', 'algorithm', 'tech_poc');

ALTER TABLE `bs_domain`
  DROP KEY `ix_bs_domain_group`,
  DROP COLUMN `domain_group`;
