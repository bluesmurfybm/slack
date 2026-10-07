-- =====================================================================
-- 020. 직접 등록한 점유를 **누가 고쳤는가**
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/020_migration_workload_editor.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/020_migration_workload_editor.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 고치기를 열면서 기록에 구멍을 내면 안 된다                        │
-- │                                                                  │
-- │ bs_workload 한 줄은 **그 사람의 가용도를 그대로 깎는다.** 그래서  │
-- │ 넣을 때 사유(note)를 필수로 받고 created_by 를 남긴다.            │
-- │                                                                  │
-- │ 그런데 화면에 고치기가 없어, 오타 하나를 고치려면 지우고 다시     │
-- │ 넣어야 했다. 그러면 created_by·created_at 이 새로 찍혀 **누가     │
-- │ 언제 처음 넣었는지가 사라진다.** 기록을 지키려고 만든 칸을        │
-- │ 고치기 위해 버리는 셈이었다.                                      │
-- │                                                                  │
-- │ 고치기를 화면에 열면서, 고친 사람도 함께 남긴다. 등록한 사람과    │
-- │ 고친 사람은 **다른 정보**다 — 둘 다 있어야 "이 숫자가 어떻게      │
-- │ 지금 모습이 됐나" 에 답할 수 있다.                                │
-- └──────────────────────────────────────────────────────────────────┘
--
-- updated_at 은 이미 있다(ON UPDATE CURRENT_TIMESTAMP). 누구인지만 없었다.
-- =====================================================================

ALTER TABLE `bs_workload`
  ADD COLUMN `updated_by` VARCHAR(64) NULL
      COMMENT '마지막으로 고친 사람(이메일). 한 번도 안 고쳤으면 NULL'
      AFTER `created_by_name`,
  ADD COLUMN `updated_by_name` VARCHAR(80) NULL
      COMMENT '고친 시점 성명 스냅샷'
      AFTER `updated_by`;


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT id, label, created_by_name, updated_by_name, updated_at
--     FROM bs_workload WHERE kind = 'manual' ORDER BY id DESC LIMIT 5;
--     -- updated_by_name 이 NULL 이면 등록 뒤 한 번도 안 고친 것이다
-- ---------------------------------------------------------------------
