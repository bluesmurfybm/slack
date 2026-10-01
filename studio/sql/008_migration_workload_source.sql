-- =====================================================================
-- 008. 직접 등록한 점유의 출처와 작성자 (bs_workload)
--
--   mysql -u root iworks_local < studio/sql/008_migration_workload_source.sql
--
-- 여러 번 실행해도 안전합니다. 이미 있으면 ALTER 가 실패하는데, 그때는
-- 아래 안내대로 건너뛰면 됩니다(컬럼 존재 여부를 조건으로 거는 문법이
-- MySQL 5.7 에 없습니다 — 003 마이그레이션과 같은 사정입니다).
-- =====================================================================
--
-- 왜 필요한가
--
--   슬랙·메일에 안 잡히는 업무를 사람이 직접 등록할 수 있게 열면,
--   그 값이 **가용도를 그대로 깎습니다.** 점유 80% 를 한 줄 넣으면
--   그 사람은 모든 프로젝트의 후보 목록에서 사실상 사라집니다.
--
--   그런데 지금 bs_workload 에는 **누가 언제 무슨 근거로 넣었는지 적을
--   칸이 없습니다.** 그대로 열면 "이 사람 가용도가 왜 이렇지" 를 물었을 때
--   아무도 답할 수 없습니다. 악의가 없어도 착오 한 건이 조용히 남습니다.
--
--   그래서 세 가지를 같이 받습니다.
--     · 누가 넣었나      created_by / created_by_name
--     · 무슨 근거인가    source (슬랙·메일·회의 등) + source_url
--     · 왜 넣었나        note
--
--   kind='assigned'(배정 확정이 만든 것)와 kind='inferred'(수집기 추정)는
--   출처가 이미 ref_type/ref_id 로 남으므로 이 칸들이 비어 있어도 됩니다.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `bs_workload`
  ADD COLUMN `source`          VARCHAR(20)  NULL
      COMMENT '직접 등록 시 근거의 출처. slack|email|meeting|doc|etc'
      AFTER `ref_id`,
  ADD COLUMN `source_url`      VARCHAR(500) NULL
      COMMENT '근거 링크(슬랙 스레드·메일 등). 있으면 화면이 걸어 준다'
      AFTER `source`,
  ADD COLUMN `note`            VARCHAR(500) NULL
      COMMENT '왜 이만큼 점유하는지. 직접 등록에서는 필수(응용에서 강제)'
      AFTER `confidence`,
  ADD COLUMN `created_by`      VARCHAR(64)  NULL
      COMMENT '넣은 사람의 iworks 계정(이메일). 직접 등록에서만 채운다'
      AFTER `note`,
  ADD COLUMN `created_by_name` VARCHAR(80)  NULL
      COMMENT '넣은 시점 성명 스냅샷'
      AFTER `created_by`;

-- 직접 등록만 따로 훑는 조회가 생깁니다(구성원 화면의 '내가 등록한 것').
ALTER TABLE `bs_workload`
  ADD KEY `ix_bs_workload_manual` (`kind`, `member_id`, `start_date`);
