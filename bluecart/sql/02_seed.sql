-- =====================================================================
-- BlueCart 초기 데이터
-- 실행: mysql -u <user> -p <database> < sql/02_seed.sql
-- =====================================================================
SET NAMES utf8mb4;

-- 사용처: 기존 엑셀 양식의 드롭다운 값 기준
INSERT INTO `bc_category` (`code`, `name`, `sort_order`, `is_active`) VALUES
  ('BLUESOFT', 'BLUESOFT', 10, 1),
  ('CAFE45CM', 'CAFE45CM', 20, 1),
  ('ETC',      '그 외 기타', 90, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 알림 기본 매트릭스
--   REQUEST_CREATED     요청 등록        → 검토승인자
--   REVIEW_APPROVED     승인             → 구매담당자, 요청자
--   REVIEW_REJECTED     반려             → 요청자
--   REQUEST_RESUBMITTED 반려 후 재요청   → 검토승인자
--   REQUEST_CANCELED    요청 철회        → 검토승인자
--   PURCHASE_ASSIGNED   구매 담당 지정   → 지정된 구매담당자
--   PURCHASE_STARTED    구매 진행 전환   → 요청자
--   PURCHASE_DONE       구비 완료        → 요청자, 검토승인자
INSERT INTO `bc_notify_setting` (`event_code`, `target_role`, `channel`, `is_enabled`) VALUES
  ('REQUEST_CREATED',     'REVIEWER',  'EMAIL',         1),
  ('REQUEST_CREATED',     'REVIEWER',  'SLACK_DM',      1),
  ('REQUEST_CREATED',     'REVIEWER',  'SLACK_CHANNEL', 0),

  ('REVIEW_APPROVED',     'BUYER',     'EMAIL',         1),
  ('REVIEW_APPROVED',     'BUYER',     'SLACK_DM',      1),
  ('REVIEW_APPROVED',     'BUYER',     'SLACK_CHANNEL', 0),
  ('REVIEW_APPROVED',     'REQUESTER', 'EMAIL',         1),
  ('REVIEW_APPROVED',     'REQUESTER', 'SLACK_DM',      0),
  ('REVIEW_APPROVED',     'REQUESTER', 'SLACK_CHANNEL', 0),

  ('REVIEW_REJECTED',     'REQUESTER', 'EMAIL',         1),
  ('REVIEW_REJECTED',     'REQUESTER', 'SLACK_DM',      1),
  ('REVIEW_REJECTED',     'REQUESTER', 'SLACK_CHANNEL', 0),

  ('REQUEST_RESUBMITTED', 'REVIEWER',  'EMAIL',         1),
  ('REQUEST_RESUBMITTED', 'REVIEWER',  'SLACK_DM',      1),
  ('REQUEST_RESUBMITTED', 'REVIEWER',  'SLACK_CHANNEL', 0),

  ('REQUEST_CANCELED',    'REVIEWER',  'EMAIL',         0),
  ('REQUEST_CANCELED',    'REVIEWER',  'SLACK_DM',      0),
  ('REQUEST_CANCELED',    'REVIEWER',  'SLACK_CHANNEL', 0),

  ('PURCHASE_ASSIGNED',   'BUYER',     'EMAIL',         1),
  ('PURCHASE_ASSIGNED',   'BUYER',     'SLACK_DM',      1),
  ('PURCHASE_ASSIGNED',   'BUYER',     'SLACK_CHANNEL', 0),

  ('PURCHASE_STARTED',    'REQUESTER', 'EMAIL',         1),
  ('PURCHASE_STARTED',    'REQUESTER', 'SLACK_DM',      1),
  ('PURCHASE_STARTED',    'REQUESTER', 'SLACK_CHANNEL', 0),

  ('PURCHASE_DONE',       'REQUESTER', 'EMAIL',         1),
  ('PURCHASE_DONE',       'REQUESTER', 'SLACK_DM',      1),
  ('PURCHASE_DONE',       'REQUESTER', 'SLACK_CHANNEL', 1),
  ('PURCHASE_DONE',       'REVIEWER',  'EMAIL',         1),
  ('PURCHASE_DONE',       'REVIEWER',  'SLACK_DM',      0),
  ('PURCHASE_DONE',       'REVIEWER',  'SLACK_CHANNEL', 0)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

INSERT INTO `bc_setting` (`k`, `v`) VALUES
  ('slack_default_channel', '#general'),
  ('reject_auto_close_days', '14')
ON DUPLICATE KEY UPDATE `v` = `v`;
