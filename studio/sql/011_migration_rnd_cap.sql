-- =====================================================================
-- 011. 전역 설정 표 + R&D 점유 통제 값 (P10-1, 명세서 §9)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/011_migration_rnd_cap.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/011_migration_rnd_cap.sql
--
--   되돌리기: 011_rollback_rnd_cap.sql
--
-- charset 을 지정하지 않으면 한글 COMMENT 가 깨집니다.
-- =====================================================================
--
-- 왜 표로 두는가
--
--   명세서 §9.2 가 상한을 **전역 설정값으로 두고 하드코딩하지 말라**고
--   못 박습니다. 이유가 분명합니다 — 이 숫자들은 운영하면서 반드시 바뀝니다.
--
--     0.30 이 너무 빡빡하면 R&D 가 죽고,
--     너무 느슨하면 배정 회피 통로가 됩니다.
--
--   코드에 박아 두면 그 조정이 배포가 되고, 배포가 필요하면 아무도 안 고치고
--   그냥 승인을 우회합니다. 값을 표에 두면 관리자가 화면에서 바꿉니다.
--
--   BlueCart 의 `bc_setting` 과 같은 모양(키·값)으로 둡니다. 다만 **label 과
--   note 를 함께 둡니다** — 숫자만 있는 설정 표는 반년 뒤에 아무도 못 고칩니다.
--   "0.30 이 무엇의 0.30 인가" 를 표가 스스로 말하게 합니다.
--
-- 기본값은 코드에도 있습니다
--
--   행이 없을 때를 대비해 bs_setting_default() 에 같은 값을 둡니다.
--   그것은 **하드코딩이 아니라 폴백**입니다 — 표의 값이 언제나 이깁니다.
--   설정 행이 지워져도 시스템이 통제 없이 열리지 않게 하는 안전장치입니다.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+09:00';

CREATE TABLE IF NOT EXISTS `bs_setting` (
  `k`          VARCHAR(80)  NOT NULL                COMMENT '설정 키. 코드가 이 이름으로 읽는다',
  `v`          VARCHAR(500) NULL                    COMMENT '값. 숫자도 문자열로 담는다(형 변환은 읽는 쪽에서)',
  `label`      VARCHAR(200) NOT NULL                COMMENT '화면에 보일 이름',
  `note`       VARCHAR(500) NULL                    COMMENT '이 값을 바꾸면 무엇이 달라지는가. 비워 두지 말 것',
  `updated_by` VARCHAR(64)  NULL                    COMMENT '마지막으로 바꾼 사람 iworks 사용자 ID(이메일)',
  `updated_by_name` VARCHAR(80) NULL                COMMENT '바꾼 시점 성명 스냅샷',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='전역 설정. 운영하면서 바뀌는 값은 코드가 아니라 여기에 둔다';

-- ---------------------------------------------------------------------
-- R&D 점유 통제 (명세서 §9.2)
--
-- 이미 있으면 **덮어쓰지 않는다.** 운영에서 조정한 값을 마이그레이션이
-- 되돌리면 안 된다.
-- ---------------------------------------------------------------------
INSERT INTO `bs_setting` (`k`, `v`, `label`, `note`) VALUES
  ('rnd_total_cap', '0.30',
   'R&D 점유 상한 — 1인 합계',
   '한 사람이 모든 R&D 과제에 쓸 수 있는 점유율의 합. 넘으면 합류 승인이 막힌다. 0.30 = 업무 시간의 30%'),

  ('rnd_per_project_cap', '0.20',
   'R&D 점유 상한 — 과제 하나당',
   '한 과제에 걸 수 있는 1인 점유율. 한 과제가 사람을 통째로 가져가지 못하게 한다'),

  ('rnd_concurrent_max', '2',
   'R&D 동시 참여 과제 수',
   '한 사람이 동시에 참여할 수 있는 과제 수. 신청 중인 것은 세지 않고 승인된 것만 센다'),

  ('rnd_stale_weeks', '4',
   'R&D 정체 판정 기간(주)',
   '진행 기록이 이 기간 넘게 없으면 보드에 "조용함" 으로 표시하고, 나중에 점유 신뢰도를 낮추는 근거가 된다')
ON DUPLICATE KEY UPDATE `k` = `k`;
