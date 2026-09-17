-- =====================================================================
-- BlueCart - 사내 물품 구매 요청/처리 시스템
-- MySQL 8.0+ / utf8mb4
-- =====================================================================
-- 실행: mysql -u <user> -p <database> < sql/01_schema.sql
-- 주의: 이 스크립트는 bc_ 접두사 테이블만 생성합니다.
--       iworks 기존 회원 테이블은 건드리지 않고 조회만 합니다.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- ---------------------------------------------------------------------
-- 1. 카테고리(사용처) : 엑셀 양식의 '사용처' 컬럼
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_category` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)  NOT NULL COMMENT '영문 코드(변경 비권장)',
  `name`        VARCHAR(80)  NOT NULL COMMENT '화면 표시명',
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bc_category_code` (`code`),
  KEY `ix_bc_category_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='물품 사용처/카테고리';

-- ---------------------------------------------------------------------
-- 2. 구매 요청
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_request` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `req_year`        SMALLINT     NOT NULL COMMENT '요청 연도(연도별 번호 채번용)',
  `req_seq`         INT          NOT NULL COMMENT '연도 내 일련번호',
  `req_no`          VARCHAR(20)  NOT NULL COMMENT '표시용 번호 예: 2026-0001',

  `category_id`     INT UNSIGNED NOT NULL,
  `item_name`       VARCHAR(200) NOT NULL COMMENT '필요 물품',
  `quantity`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '필요 갯수',
  `unit`            VARCHAR(20)  NOT NULL DEFAULT '개' COMMENT '단위(개/박스/세트 등)',
  `est_amount`      INT UNSIGNED NULL COMMENT '예상 금액(원), 선택',
  `ref_url`         VARCHAR(1000) NULL COMMENT '참고 상품 URL',
  `deliver_to`      VARCHAR(200) NULL COMMENT '배송/수령 희망 장소',
  `need_by`         DATE         NULL COMMENT '희망 수령일',
  `note`            TEXT         NULL COMMENT '비고',

  `status`          VARCHAR(20)  NOT NULL DEFAULT 'REQUESTED'
                    COMMENT 'REQUESTED|APPROVED|REJECTED|PURCHASING|STOCKED|CANCELED',

  `requester_id`    VARCHAR(64)  NOT NULL COMMENT 'iworks 사용자 ID',
  `requester_name`  VARCHAR(80)  NOT NULL COMMENT '요청 시점 성명 스냅샷',
  `requested_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  `reviewer_id`     VARCHAR(64)  NULL,
  `reviewer_name`   VARCHAR(80)  NULL,
  `reviewed_at`     DATETIME     NULL,
  `review_comment`  TEXT         NULL COMMENT '승인 의견 또는 반려 사유',

  `assignee_id`     VARCHAR(64)  NULL COMMENT '이 건을 맡은 구매담당자',
  `assignee_name`   VARCHAR(80)  NULL,
  `assigned_at`     DATETIME     NULL,
  `assigned_by`     VARCHAR(64)  NULL COMMENT '담당을 지정한 사람',

  `buyer_id`        VARCHAR(64)  NULL COMMENT '실제로 처리한 구매담당자',
  `buyer_name`      VARCHAR(80)  NULL,
  `purchasing_at`   DATETIME     NULL COMMENT '구매 진행 전환 시각',
  `stocked_at`      DATETIME     NULL COMMENT '구비 완료 시각',
  `purchase_note`   TEXT         NULL COMMENT '구매 처리 메모(결제수단, 실구매처 등)',
  `actual_amount`   INT UNSIGNED NULL COMMENT '실제 구매 금액(원)',

  `resubmit_count`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '반려 후 재요청 횟수',
  `closed_at`       DATETIME     NULL COMMENT '취소/철회 시각',

  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bc_request_no` (`req_no`),
  UNIQUE KEY `uk_bc_request_year_seq` (`req_year`, `req_seq`),
  KEY `ix_bc_request_list`   (`req_year`, `status`, `requested_at`),
  KEY `ix_bc_request_mine`   (`requester_id`, `requested_at`),
  KEY `ix_bc_request_buyer`  (`buyer_id`, `status`),
  KEY `ix_bc_request_assignee` (`assignee_id`, `status`),
  KEY `ix_bc_request_cat`    (`category_id`),
  CONSTRAINT `fk_bc_request_category` FOREIGN KEY (`category_id`)
    REFERENCES `bc_category` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='물품 구매 요청';

-- ---------------------------------------------------------------------
-- 3. 처리 이력 (상태 전이 감사 로그)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_request_history` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`   BIGINT UNSIGNED NOT NULL,
  `event_code`   VARCHAR(30)  NOT NULL COMMENT 'REQUEST_CREATED 등',
  `from_status`  VARCHAR(20)  NULL,
  `to_status`    VARCHAR(20)  NULL,
  `actor_id`     VARCHAR(64)  NOT NULL,
  `actor_name`   VARCHAR(80)  NOT NULL,
  `comment`      TEXT         NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bc_hist_request` (`request_id`, `id`),
  CONSTRAINT `fk_bc_hist_request` FOREIGN KEY (`request_id`)
    REFERENCES `bc_request` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='요청 처리 이력';

-- ---------------------------------------------------------------------
-- 4. 첨부파일 (견적서/사진 등, 선택 기능)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_attachment` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`   BIGINT UNSIGNED NOT NULL,
  `orig_name`    VARCHAR(255) NOT NULL,
  `stored_path`  VARCHAR(500) NOT NULL COMMENT '웹 루트 외부 경로',
  `mime_type`    VARCHAR(100) NOT NULL,
  `file_size`    INT UNSIGNED NOT NULL,
  `uploader_id`  VARCHAR(64)  NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bc_attach_request` (`request_id`),
  CONSTRAINT `fk_bc_attach_request` FOREIGN KEY (`request_id`)
    REFERENCES `bc_request` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='요청 첨부파일';

-- ---------------------------------------------------------------------
-- 5. 처리 역할 배정 (검토승인자 / 구매담당자, 각각 다중)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_role_assign` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_type`   VARCHAR(20)  NOT NULL COMMENT 'REVIEWER|BUYER|ADMIN',
  `user_id`     VARCHAR(64)  NOT NULL COMMENT 'iworks 사용자 ID',
  `user_name`   VARCHAR(80)  NOT NULL COMMENT '배정 시점 성명 스냅샷',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `assigned_by` VARCHAR(64)  NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bc_role` (`role_type`, `user_id`),
  KEY `ix_bc_role_user` (`user_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='처리 역할 배정';

-- ---------------------------------------------------------------------
-- 6. 프로세스별 알림 설정
--    (이벤트 × 수신 역할) 당 채널을 다중 선택
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_notify_setting` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_code`   VARCHAR(30) NOT NULL COMMENT 'REQUEST_CREATED|REVIEW_APPROVED|...',
  `target_role`  VARCHAR(20) NOT NULL COMMENT 'REQUESTER|REVIEWER|BUYER',
  `channel`      VARCHAR(20) NOT NULL COMMENT 'EMAIL|SLACK_CHANNEL|SLACK_DM',
  `is_enabled`   TINYINT(1)  NOT NULL DEFAULT 1,
  `updated_by`   VARCHAR(64) NULL,
  `updated_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bc_notify` (`event_code`, `target_role`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='프로세스별 알림 대상/방법';

-- ---------------------------------------------------------------------
-- 7. 발송 로그 (재시도 큐 겸용)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_notify_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`   BIGINT UNSIGNED NULL,
  `event_code`   VARCHAR(30)  NOT NULL,
  `target_role`  VARCHAR(20)  NOT NULL,
  `channel`      VARCHAR(20)  NOT NULL,
  `recipient`    VARCHAR(255) NOT NULL COMMENT '이메일 주소 / 슬랙 채널 / 슬랙 사용자',
  `subject`      VARCHAR(255) NULL,
  `body`         TEXT         NULL,
  `status`       VARCHAR(20)  NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|SENT|FAILED|SKIPPED',
  `error_msg`    VARCHAR(500) NULL,
  `retry_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_at`      DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bc_nlog_retry`   (`status`, `retry_count`, `id`),
  KEY `ix_bc_nlog_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='알림 발송 로그';

-- ---------------------------------------------------------------------
-- 8. 키-값 환경설정 (슬랙 토큰 등 운영 값은 config.php 우선)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_setting` (
  `k`          VARCHAR(80)  NOT NULL,
  `v`          TEXT         NULL,
  `updated_by` VARCHAR(64)  NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='운영 설정';
