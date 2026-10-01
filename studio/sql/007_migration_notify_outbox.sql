-- =====================================================================
-- 007. 알림 적재함 (bs_notification)
--
--   mysql -u root iworks_local < studio/sql/007_migration_notify_outbox.sql
--
-- 여러 번 실행해도 안전합니다(CREATE TABLE IF NOT EXISTS 뿐).
-- =====================================================================
--
-- 왜 표를 두는가
--
--   명세서 §7.1 Step3 의 마지막은 "[최종 배정 완료] → 담당자에게 Slack DM
--   + 메일 발송" 입니다. 그런데 확정하는 순간 바로 보내면 두 가지가 깨집니다.
--
--   1. 슬랙이 느리거나 죽어 있으면 **확정 자체가 실패**합니다.
--      배정 확정은 DB 안에서 끝나야 하는 일인데 바깥 사정에 묶입니다.
--   2. 보냈는지 못 보냈는지 아무 데도 안 남습니다. 못 받았다는 사람이
--      나왔을 때 확인할 방법이 없습니다.
--
--   그래서 확정은 "보낼 것" 만 여기에 적고 끝냅니다. 실제 발송은 이 표를
--   읽어 가는 쪽이 합니다(아직 없음 — 아래 참고).
--
-- 지금 상태
--
--   보내는 경로가 아직 없습니다. slack/slack_lib.php 의 slackPost() 로
--   chat.postMessage 를 부를 수는 있으나, 토큰이 운영 config.php 의 AES 키로
--   암호화돼 있어 이 모듈에서 풀 수 없습니다. 메일도 slack/gmail 쪽은
--   **읽기** 전용이라 보내는 함수가 없습니다.
--
--   그래서 status='queued' 로만 쌓입니다. 화면은 "보낼 예정" 이라고
--   정직하게 말하고, 보냈다고 하지 않습니다.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `bs_notification` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`     VARCHAR(10)  NOT NULL COMMENT 'slack|email',
  `ref_type`    VARCHAR(20)  NOT NULL COMMENT '무엇 때문에 보내는가. allocation 등',
  `ref_id`      BIGINT UNSIGNED NULL COMMENT '참조 대상 id. FK 아님(대상 표가 가변)',
  `member_id`   INT UNSIGNED NULL COMMENT '받는 구성원. 구성원이 지워져도 기록은 남긴다',
  `to_addr`     VARCHAR(150) NOT NULL COMMENT '보낼 당시의 슬랙 핸들 또는 메일 주소(스냅샷)',
  `subject`     VARCHAR(200) NULL,
  `body`        TEXT         NOT NULL,
  `status`      VARCHAR(10)  NOT NULL DEFAULT 'queued' COMMENT 'queued|sent|failed|skipped',
  -- 왜 못 보냈는지. status=skipped 면 "주소가 없음" 같은 이유가 들어간다.
  `error`       VARCHAR(300) NULL,
  `attempts`    TINYINT      NOT NULL DEFAULT 0,
  `sent_at`     DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 보낼 것을 집어 가는 조회.
  KEY `ix_bs_notify_pending` (`status`, `id`),
  -- "이 배정안으로 누구에게 무엇이 나갔나" 를 되짚는 조회.
  KEY `ix_bs_notify_ref`     (`ref_type`, `ref_id`),
  KEY `ix_bs_notify_member`  (`member_id`),
  -- 구성원을 지워도 알림 기록은 남긴다. 누구에게 보냈는지는 to_addr 스냅샷에 있다.
  CONSTRAINT `fk_bs_notify_member` FOREIGN KEY (`member_id`)
    REFERENCES `bs_member` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='보낼 알림 적재함. 실제 발송은 별도 경로가 읽어 간다';
