-- =====================================================================
-- BlueStudio 마이그레이션 v1 → v2
--   - 프로젝트 소프트 삭제 (ba_project.deleted_at / deleted_by / deleted_by_name)
--
-- 이미 001_schema.sql 로 설치한 환경에만 실행하세요.
-- 새로 설치하는 경우 001_schema.sql 에 이미 반영되어 있어 실행할 필요가 없습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/003_migration_soft_delete.sql
--
-- 이 스크립트는 한 번만 실행해야 합니다. 두 번 실행하면 ALTER TABLE 단계에서
-- "Duplicate column name" 오류가 나고 중단됩니다(데이터는 손상되지 않습니다).
-- =====================================================================
--
-- ---------------------------------------------------------------------
-- 왜 소프트 삭제인가
-- ---------------------------------------------------------------------
-- 프로젝트를 진짜로 지우면 FK CASCADE 가 ba_task · ba_project_source ·
-- ba_allocation · ba_allocation_item · ba_progress 까지 통째로 끌고 갑니다.
-- 배정 이력과 진행 기록은 "누가 무엇을 맡았는지" 의 근거라 사라지면 안 됩니다.
-- BlueCart 도 같은 이유로 관리자 영구 삭제 시 bc_request_deleted 에 스냅샷을
-- 남깁니다(01_schema.sql 9번 표 주석).
--
-- 여기서는 한 단계 더 보수적으로, 행을 지우지 않고 감추기만 합니다.
-- 영구 삭제가 필요해지면 그때 BlueCart 처럼 스냅샷 표를 따로 만듭니다.
--
-- ---------------------------------------------------------------------
-- code 는 다시 쓰지 않습니다
-- ---------------------------------------------------------------------
-- uk_ba_project_code 가 그대로 남아 있어, 지운 프로젝트의 code(PRJ-2026-001)는
-- 계속 점유됩니다. 일부러 그렇게 둡니다 — 이미 메일·슬랙으로 나간 코드가
-- 나중에 다른 프로젝트를 가리키면 안 됩니다.
-- 채번(ProjectRepo::nextCode)도 지워진 행을 포함해 최대값을 봅니다.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

ALTER TABLE `ba_project`
  ADD COLUMN `deleted_at`      DATETIME    NULL COMMENT '소프트 삭제 시각. NULL 이면 살아 있는 프로젝트'
      AFTER `owner_name`,
  ADD COLUMN `deleted_by`      VARCHAR(64) NULL COMMENT '지운 사람 iworks 사용자 ID(이메일)'
      AFTER `deleted_at`,
  ADD COLUMN `deleted_by_name` VARCHAR(80) NULL COMMENT '지운 시점 성명 스냅샷'
      AFTER `deleted_by`,
  ADD COLUMN `delete_reason`   VARCHAR(500) NULL COMMENT '삭제 사유'
      AFTER `deleted_by_name`;

-- 목록은 거의 항상 "안 지워진 것" 만 본다. 기존 상태 인덱스 앞에 deleted_at 을
-- 세운 인덱스를 따로 둔다(기존 ix_ba_project_status 는 그대로 남겨 둔다 —
-- 지워진 것까지 포함해 훑는 관리자 조회가 그쪽을 쓴다).
ALTER TABLE `ba_project`
  ADD KEY `ix_ba_project_live` (`deleted_at`, `status`, `dev_start`);
