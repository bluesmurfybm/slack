-- =====================================================================
-- 로컬 개발용 시드 데이터
--
--   mysql -u root -p --default-character-set=utf8mb4 bluecart_dev < dev/seed_dev.sql
--
-- 01_schema.sql 과 02_seed.sql 을 먼저 실행한 뒤 이 파일을 실행하세요.
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 단독 실행(포털 밖) 전용입니다.                                     │
-- │                                                                  │
-- │ bc_request / bc_role_assign 을 통째로 비우고 샘플로 채웁니다.       │
-- │ 포털 모듈로 쓰는 DB 에는 절대 실행하지 마세요. 실제 요청이 날아갑니다.│
-- │ 포털 안에서는 구성원 명단이 portal_users 이므로 아래 가짜 회원      │
-- │ 테이블(bc_dev_member)도 필요 없습니다.                            │
-- │ dev\setup.bat 은 포털을 감지하면 이 파일을 실행하지 않습니다.       │
-- └──────────────────────────────────────────────────────────────────┘
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. iworks 회원 테이블을 흉내 낸 로컬 전용 테이블
--    운영에서는 iworks 의 실제 회원 테이블을 읽습니다.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `bc_dev_member`;
CREATE TABLE `bc_dev_member` (
  `mb_id`         VARCHAR(64)  NOT NULL,
  `mb_name`       VARCHAR(80)  NOT NULL,
  `mb_email`      VARCHAR(255) NULL,
  `mb_slack_id`   VARCHAR(40)  NULL,
  `mb_leave_date` VARCHAR(10)  NULL,
  PRIMARY KEY (`mb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='로컬 개발용 가짜 회원 테이블';

INSERT INTO `bc_dev_member` (`mb_id`, `mb_name`, `mb_email`, `mb_slack_id`, `mb_leave_date`) VALUES
  ('hoyoung',    '김호영', 'hoyoung@example.com',    'U001', NULL),
  ('jian',       '김지안', 'jian@example.com',       'U002', NULL),
  ('seongcheol', '박성철', 'seongcheol@example.com', 'U003', NULL),
  ('jeongmin',   '안정민', 'jeongmin@example.com',   'U004', NULL),
  ('byeongmun',  '유병문', 'byeongmun@example.com',  'U005', NULL),
  ('sohyeon',    '진소현', 'sohyeon@example.com',    'U006', NULL),
  ('retired',    '퇴사자', 'retired@example.com',    NULL,   '2025-12-31');

-- ---------------------------------------------------------------------
-- 2. 역할 배정
--    hoyoung 은 config 의 superadmins 로도 관리자 권한을 갖습니다.
-- ---------------------------------------------------------------------
DELETE FROM `bc_role_assign`;
INSERT INTO `bc_role_assign` (`role_type`, `user_id`, `user_name`, `is_active`) VALUES
  ('ADMIN',    'hoyoung',    '김호영', 1),
  ('REVIEWER', 'jian',       '김지안', 1),
  ('REVIEWER', 'hoyoung',    '김호영', 1),
  ('BUYER',    'seongcheol', '박성철', 1),
  ('BUYER',    'jian',       '김지안', 1);

-- ---------------------------------------------------------------------
-- 3. 샘플 요청 — 각 상태를 하나씩 만들어 화면을 바로 확인할 수 있게 함
-- ---------------------------------------------------------------------
DELETE FROM `bc_request`;

INSERT INTO `bc_request`
 (`req_year`,`req_seq`,`req_no`,`category_id`,`item_name`,`quantity`,`unit`,
  `est_amount`,`deliver_to`,`note`,`status`,`requester_id`,`requester_name`,`requested_at`)
SELECT 2026, 1, '2026-0001', c.id, '16OZ 아이스 컵', 2, '박스',
       35000, '사무실', '카페용으로 필요합니다', 'REQUESTED',
       'byeongmun', '유병문', DATE_SUB(NOW(), INTERVAL 2 DAY)
  FROM bc_category c WHERE c.code = 'CAFE45CM';

INSERT INTO `bc_request`
 (`req_year`,`req_seq`,`req_no`,`category_id`,`item_name`,`quantity`,`unit`,
  `est_amount`,`deliver_to`,`status`,`requester_id`,`requester_name`,`requested_at`,
  `reviewer_id`,`reviewer_name`,`reviewed_at`,`assignee_id`,`assignee_name`,`assigned_at`,`assigned_by`)
SELECT 2026, 2, '2026-0002', c.id, '무선 마우스', 3, '개',
       45000, '개발팀 자리', 'APPROVED',
       'sohyeon', '진소현', DATE_SUB(NOW(), INTERVAL 3 DAY),
       'jian', '김지안', DATE_SUB(NOW(), INTERVAL 2 DAY),
       'seongcheol', '박성철', DATE_SUB(NOW(), INTERVAL 2 DAY), 'jian'
  FROM bc_category c WHERE c.code = 'BLUESOFT';

INSERT INTO `bc_request`
 (`req_year`,`req_seq`,`req_no`,`category_id`,`item_name`,`quantity`,`unit`,
  `status`,`requester_id`,`requester_name`,`requested_at`,
  `reviewer_id`,`reviewer_name`,`reviewed_at`,`buyer_id`,`buyer_name`,`purchasing_at`,
  `assignee_id`,`assignee_name`,`assigned_at`,`assigned_by`,`purchase_note`)
SELECT 2026, 3, '2026-0003', c.id, 'A4 용지', 5, '박스',
       'PURCHASING',
       'jeongmin', '안정민', DATE_SUB(NOW(), INTERVAL 5 DAY),
       'jian', '김지안', DATE_SUB(NOW(), INTERVAL 4 DAY),
       'jian', '김지안', DATE_SUB(NOW(), INTERVAL 1 DAY),
       'jian', '김지안', DATE_SUB(NOW(), INTERVAL 4 DAY), 'jian',
       '쿠팡 주문 완료, 수요일 도착 예정'
  FROM bc_category c WHERE c.code = 'BLUESOFT';

INSERT INTO `bc_request`
 (`req_year`,`req_seq`,`req_no`,`category_id`,`item_name`,`quantity`,`unit`,
  `status`,`requester_id`,`requester_name`,`requested_at`,
  `reviewer_id`,`reviewer_name`,`reviewed_at`,`buyer_id`,`buyer_name`,
  `assignee_id`,`assignee_name`,`assigned_at`,`assigned_by`,
  `purchasing_at`,`stocked_at`,`actual_amount`)
SELECT 2026, 4, '2026-0004', c.id, '원두(디카페인)', 2, '봉',
       'STOCKED',
       'byeongmun', '유병문', DATE_SUB(NOW(), INTERVAL 14 DAY),
       'jian', '김지안', DATE_SUB(NOW(), INTERVAL 13 DAY),
       'seongcheol', '박성철',
       'seongcheol', '박성철', DATE_SUB(NOW(), INTERVAL 13 DAY), 'jian',
       DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), 24800
  FROM bc_category c WHERE c.code = 'CAFE45CM';

INSERT INTO `bc_request`
 (`req_year`,`req_seq`,`req_no`,`category_id`,`item_name`,`quantity`,`unit`,
  `status`,`requester_id`,`requester_name`,`requested_at`,
  `reviewer_id`,`reviewer_name`,`reviewed_at`,`review_comment`)
SELECT 2026, 5, '2026-0005', c.id, '모니터 받침대', 1, '개',
       'REJECTED',
       'sohyeon', '진소현', DATE_SUB(NOW(), INTERVAL 6 DAY),
       'jian', '김지안', DATE_SUB(NOW(), INTERVAL 5 DAY),
       '창고에 여분이 있습니다. 확인 후 필요하면 다시 요청해 주세요.'
  FROM bc_category c WHERE c.code = 'BLUESOFT';

-- 처리 이력도 최소한 채워 둔다 (상세 화면 확인용)
INSERT INTO `bc_request_history`
 (`request_id`,`event_code`,`from_status`,`to_status`,`actor_id`,`actor_name`,`comment`,`created_at`)
SELECT r.id, 'REQUEST_CREATED', NULL, 'REQUESTED', r.requester_id, r.requester_name, NULL, r.requested_at
  FROM bc_request r;

INSERT INTO `bc_request_history`
 (`request_id`,`event_code`,`from_status`,`to_status`,`actor_id`,`actor_name`,`comment`,`created_at`)
SELECT r.id, 'REVIEW_APPROVED', 'REQUESTED', 'APPROVED', r.reviewer_id, r.reviewer_name, NULL, r.reviewed_at
  FROM bc_request r WHERE r.reviewed_at IS NOT NULL AND r.status <> 'REJECTED';

INSERT INTO `bc_request_history`
 (`request_id`,`event_code`,`from_status`,`to_status`,`actor_id`,`actor_name`,`comment`,`created_at`)
SELECT r.id, 'REVIEW_REJECTED', 'REQUESTED', 'REJECTED', r.reviewer_id, r.reviewer_name,
       r.review_comment, r.reviewed_at
  FROM bc_request r WHERE r.status = 'REJECTED';

SELECT '샘플 데이터 준비 완료' AS msg,
       (SELECT COUNT(*) FROM bc_dev_member) AS 구성원,
       (SELECT COUNT(*) FROM bc_request)    AS 요청,
       (SELECT COUNT(*) FROM bc_role_assign) AS 역할배정;
