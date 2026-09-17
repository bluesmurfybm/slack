-- =====================================================================
-- BlueCart 마이그레이션 v1 → v2
--   - 건별 구매담당자 지정
--   - 구매 담당 지정 알림 이벤트
--
-- 이미 01_schema.sql 로 설치한 환경에만 실행하세요.
-- 새로 설치하는 경우 01_schema.sql 에 이미 반영되어 있어 실행할 필요가 없습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/03_migration_v2.sql
--
-- 이 스크립트는 한 번만 실행해야 합니다. 두 번 실행하면 ALTER TABLE 단계에서
-- "Duplicate column name" 오류가 나고 중단됩니다(데이터는 손상되지 않습니다).
-- 이미 적용했는지 확인하려면:
--   SHOW COLUMNS FROM bc_request LIKE 'assignee_id';
--
-- 실행 전 백업을 권장합니다:
--   mysqldump -u <user> -p <db> bc_request bc_notify_setting > backup_$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. 건별 구매담당자 컬럼
--    assignee_* : 이 건을 맡기로 지정된 사람
--    buyer_*    : 실제로 상태를 바꾼 사람 (기존 컬럼, 그대로 유지)
-- ---------------------------------------------------------------------
ALTER TABLE `bc_request`
  ADD COLUMN `assignee_id`   VARCHAR(64) NULL COMMENT '이 건을 맡은 구매담당자' AFTER `review_comment`,
  ADD COLUMN `assignee_name` VARCHAR(80) NULL AFTER `assignee_id`,
  ADD COLUMN `assigned_at`   DATETIME    NULL AFTER `assignee_name`,
  ADD COLUMN `assigned_by`   VARCHAR(64) NULL COMMENT '담당을 지정한 사람' AFTER `assigned_at`,
  ADD KEY `ix_bc_request_assignee` (`assignee_id`, `status`);

-- 이미 진행 중이거나 완료된 건은 처리한 사람을 담당자로 채워 둔다.
-- 담당자가 비어 있으면 '아무 구매담당자나 처리 가능'으로 해석되기 때문에,
-- 과거 건이 갑자기 미배정으로 보이지 않게 하려는 처리.
UPDATE `bc_request`
   SET `assignee_id`   = `buyer_id`,
       `assignee_name` = `buyer_name`,
       `assigned_at`   = COALESCE(`purchasing_at`, `stocked_at`, `reviewed_at`)
 WHERE `buyer_id` IS NOT NULL
   AND `assignee_id` IS NULL;

-- ---------------------------------------------------------------------
-- 2. 구매 담당 지정 알림
-- ---------------------------------------------------------------------
INSERT INTO `bc_notify_setting` (`event_code`, `target_role`, `channel`, `is_enabled`) VALUES
  ('PURCHASE_ASSIGNED', 'BUYER', 'EMAIL',         1),
  ('PURCHASE_ASSIGNED', 'BUYER', 'SLACK_DM',      1),
  ('PURCHASE_ASSIGNED', 'BUYER', 'SLACK_CHANNEL', 0)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- ---------------------------------------------------------------------
-- 3. 확인
-- ---------------------------------------------------------------------
-- SELECT COUNT(*) AS 담당지정됨 FROM bc_request WHERE assignee_id IS NOT NULL;
-- SELECT event_code, COUNT(*) FROM bc_notify_setting GROUP BY event_code;
