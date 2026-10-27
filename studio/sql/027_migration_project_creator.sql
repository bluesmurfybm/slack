-- =====================================================================
-- 027. 프로젝트 등록 권한 — 관리자가 사람마다 켠다
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/027_migration_project_creator.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/027_migration_project_creator.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 관리자만 프로젝트를 등록할 수 있었다 (2026-10-15)                   │
-- │                                                                  │
-- │ 실제 PM 몇 명이 직접 등록하게 한다. 모두에게 열지 않는다 —         │
-- │ 등록한 사람은 그 프로젝트의 PM 이 되고, PM 은                      │
-- │   · 남의 직접 등록 점유를 고칠 수 있고 (bs_can_edit_workload)      │
-- │   · 후보 표에서 후보 전원의 근거를 보고                            │
-- │   · 배정 확정으로 bs_workload 를 만든다                            │
-- │ 빈 프로젝트 하나로 이것이 다 열리므로 **관리자가 사람을 골라** 켠다.│
-- └──────────────────────────────────────────────────────────────────┘
--
-- 끄면 **새로 등록만** 못 한다. 이미 등록한 프로젝트의 PM 권한은 그대로다 —
-- PM 은 bs_project.owner_id 로 정해지고 이 칸과 무관하다. 넘기려면 프로젝트의
-- 담당 PM 을 바꾼다.
-- =====================================================================

ALTER TABLE `bs_member`
  ADD COLUMN `can_create_project` TINYINT(1)  NOT NULL DEFAULT 0
      COMMENT '프로젝트 등록 권한(1). 관리자가 켠다. 관리자는 이 칸과 무관하게 등록할 수 있다',
  ADD COLUMN `create_granted_by`  VARCHAR(64) NULL
      COMMENT '등록 권한을 마지막으로 켜거나 끈 사람(이메일)',
  ADD COLUMN `create_granted_at`  DATETIME    NULL
      COMMENT '그 시각';


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT id, emp_name, user_id, create_granted_by, create_granted_at
--     FROM bs_member WHERE can_create_project = 1;
-- ---------------------------------------------------------------------
