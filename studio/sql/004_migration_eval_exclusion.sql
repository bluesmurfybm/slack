-- =====================================================================
-- BlueStudio 마이그레이션 v2 → v3
--   - 구성원 평가 제외 옵션 (bs_member.is_evaluable 외 3개 컬럼)
--
-- 이미 설치된 환경에만 실행하세요.
-- 새로 설치하는 경우 001_schema.sql 에 이미 반영되어 있어 실행할 필요가 없습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/004_migration_eval_exclusion.sql
--
-- 이 스크립트는 한 번만 실행해야 합니다. 두 번 실행하면 ALTER TABLE 단계에서
-- "Duplicate column name" 오류가 나고 중단됩니다(데이터는 손상되지 않습니다).
-- =====================================================================
--
-- ---------------------------------------------------------------------
-- 왜 is_assignable 을 재활용하지 않는가
-- ---------------------------------------------------------------------
-- 둘은 다른 개념입니다.
--
--   is_assignable = 0   이 사람을 **배정 후보로 올리지 않는다**
--                       (휴직·퇴사·타 프로젝트 전담 등)
--   is_evaluable  = 0   이 사람의 **역량 점수를 내지 않는다**
--                       (슬랙 취합 시스템에 업무가 남지 않는 직무 등)
--
-- 둘은 독립입니다. 기획 담당자는 슬랙 취합 데이터가 없어 점수를 낼 수 없지만,
-- 기획 과업에는 당연히 배정되어야 합니다 — is_evaluable=0, is_assignable=1.
-- 한 컬럼으로 합치면 "평가를 못 한다" 는 이유로 배정에서까지 빠집니다.
--
-- ---------------------------------------------------------------------
-- 평가 제외 vs 표본 부족 — 화면에서 구분해야 합니다
-- ---------------------------------------------------------------------
--   insufficient_data = 1   데이터가 모자라다. 쌓이면 언젠가 점수가 나온다
--   is_evaluable      = 0   애초에 이 데이터로 평가하지 않기로 정했다
--
-- 본인이 자기 프로파일을 볼 때 두 경우의 안내 문구가 달라야 합니다(CLAUDE.md).
-- "표본이 부족합니다" 와 "평가 대상이 아닙니다" 는 전혀 다른 말입니다.
--
-- ---------------------------------------------------------------------
-- 근거 (docs/data-quality-report.md, 2026-09 기준 6개월)
-- ---------------------------------------------------------------------
-- 자사 구성원 13명 중 슬랙 취합 시스템 활동:
--   9명  20건 이상 — 점수 산출 가능
--   1명  4건       — 표본 부족(insufficient_data 로 처리)
--   3명  0건       — 직무상 이 시스템을 쓰지 않음 → **이 마이그레이션의 대상**
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

ALTER TABLE `bs_member`
  ADD COLUMN `is_evaluable` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '0이면 역량 점수를 내지 않는다. 배정 가능 여부(is_assignable)와는 별개'
      AFTER `is_assignable`,
  ADD COLUMN `eval_exclude_reason` VARCHAR(200) NULL
      COMMENT '평가 제외 사유. 본인에게 보여줄 문구이므로 반드시 채운다'
      AFTER `is_evaluable`,
  ADD COLUMN `eval_excluded_at` DATETIME NULL
      COMMENT '평가 제외로 바꾼 시각'
      AFTER `eval_exclude_reason`,
  ADD COLUMN `eval_excluded_by` VARCHAR(64) NULL
      COMMENT '평가 제외로 바꾼 사람 iworks 사용자 ID(이메일)'
      AFTER `eval_excluded_at`;

-- 점수 산출 배치가 "평가 대상인 사람" 만 훑는다.
ALTER TABLE `bs_member`
  ADD KEY `ix_bs_member_evaluable` (`is_evaluable`, `role_label`);
