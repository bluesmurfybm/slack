-- =====================================================================
-- BlueCart 마이그레이션 v3 → v4
--   - 구매 중단 알림 이벤트(PURCHASE_CANCELED)
--   - 구매 대기 건 철회 시 구매담당자 알림(REQUEST_CANCELED × BUYER)
--
-- 이미 설치된 환경에만 실행하세요. 새로 설치하는 경우 02_seed.sql 에
-- 이미 들어 있어 실행할 필요가 없습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/05_migration_v4.sql
--   또는  php dev/apply_sql.php sql/05_migration_v4.sql
--
-- INSERT IGNORE 라 두 번 실행해도 안전하고, 관리자가 알림 설정에서 이미
-- 바꿔 둔 값은 덮어쓰지 않습니다.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 구매 중단 알림
--   요청자는 기다리던 물품이 안 온다는 것을 알아야 하므로 메일과 DM.
--   검토승인자는 승인했던 건의 결말이라 메일만.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `bc_notify_setting` (`event_code`, `target_role`, `channel`, `is_enabled`) VALUES
  ('PURCHASE_CANCELED', 'REQUESTER', 'EMAIL',         1),
  ('PURCHASE_CANCELED', 'REQUESTER', 'SLACK_DM',      1),
  ('PURCHASE_CANCELED', 'REQUESTER', 'SLACK_CHANNEL', 0),
  ('PURCHASE_CANCELED', 'REVIEWER',  'EMAIL',         1),
  ('PURCHASE_CANCELED', 'REVIEWER',  'SLACK_DM',      0),
  ('PURCHASE_CANCELED', 'REVIEWER',  'SLACK_CHANNEL', 0);

-- ---------------------------------------------------------------------
-- 요청자가 구매 대기 건을 철회하면 사려던 구매담당자가 알아야 한다.
-- 검토 대기·반려 건의 철회에는 가지 않는다(Notifier 가 거른다).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `bc_notify_setting` (`event_code`, `target_role`, `channel`, `is_enabled`) VALUES
  ('REQUEST_CANCELED', 'BUYER', 'EMAIL',         1),
  ('REQUEST_CANCELED', 'BUYER', 'SLACK_DM',      1),
  ('REQUEST_CANCELED', 'BUYER', 'SLACK_CHANNEL', 0);

-- ---------------------------------------------------------------------
-- 확인
-- ---------------------------------------------------------------------
-- SELECT event_code, target_role, channel, is_enabled FROM bc_notify_setting
--  WHERE event_code = 'PURCHASE_CANCELED' OR (event_code = 'REQUEST_CANCELED' AND target_role = 'BUYER');
