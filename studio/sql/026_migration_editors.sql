-- =====================================================================
-- 026. 참여자에게 수정 권한 — 주도자·PM 이 사람마다 부여한다
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/026_migration_editors.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/026_migration_editors.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 고칠 수 있는 사람이 너무 적었다 (2026-10-14)                        │
-- │                                                                  │
-- │ R&D 과제   발의자(승인 전까지만) · 관리자                           │
-- │ 프로젝트   담당 PM · 관리자                                         │
-- │                                                                  │
-- │ 함께 일하는 사람이 설명 한 줄을 고치려 해도 주인에게 부탁해야 했다. │
-- │ 일괄로 열지 않고 **주도자·PM 이 사람마다** 켠다 — 누가 켰는지 남는다.│
-- └──────────────────────────────────────────────────────────────────┘
--
-- R&D 는 이미 참여자 표(bs_rnd_member)가 있어 칸만 더한다. 승인된 참여자만
-- 받을 수 있다(응용에서 막는다). 승인 뒤에도 기간·점유율까지 고칠 수 있다 —
-- 다만 사람별 점유는 여전히 **승인된 참여율**로만 올라가므로(§9.1) 승인
-- 통제는 그대로다. 기간이 바뀌면 응용이 점유 기간을 다시 맞춘다.
--
-- 프로젝트는 참여자 표가 따로 없다(배정은 차수마다 바뀐다). 그래서 편집자
-- 표를 둔다. 편집자는 프로젝트 정보·WBS·배정안 산출까지 하고, **배정 확정은
-- 하지 않는다** — 확정은 점유를 만드는 결정이라 PM 의 몫으로 남긴다.
-- =====================================================================

ALTER TABLE `bs_rnd_member`
  ADD COLUMN `can_edit`        TINYINT(1)  NOT NULL DEFAULT 0
      COMMENT '과제 수정 권한(1). 주도자·관리자가 승인된 참여자에게 켠다. 주도자는 이 칸과 무관하게 고칠 수 있다',
  ADD COLUMN `edit_granted_by` VARCHAR(64) NULL
      COMMENT '수정 권한을 마지막으로 켜거나 끈 사람(이메일)',
  ADD COLUMN `edit_granted_at` DATETIME    NULL
      COMMENT '그 시각';

CREATE TABLE IF NOT EXISTS `bs_project_editor` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`      INT UNSIGNED NOT NULL COMMENT 'bs_project.id (project_type=project)',
  `user_id`         VARCHAR(64)  NOT NULL COMMENT '편집자(이메일). portal_users 와 FK 를 걸지 않는다(관례)',
  `user_name`       VARCHAR(80)  NULL     COMMENT '부여 시점 성명 스냅샷',
  `granted_by`      VARCHAR(64)  NULL     COMMENT '부여한 사람(이메일)',
  `granted_by_name` VARCHAR(80)  NULL     COMMENT '부여한 사람 성명 스냅샷',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bs_peditor` (`project_id`, `user_id`),
  KEY `ix_bs_peditor_user` (`user_id`),
  CONSTRAINT `fk_bs_peditor_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='프로젝트 공동 편집자. 정보·WBS·배정안 산출까지. 배정 확정은 PM·관리자만';


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT project_id, member_id, can_edit, edit_granted_by FROM bs_rnd_member WHERE can_edit = 1;
--   SELECT * FROM bs_project_editor;
-- ---------------------------------------------------------------------
