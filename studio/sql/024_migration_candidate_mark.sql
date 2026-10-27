-- =====================================================================
-- 024. 후보 표의 사람 판단 — ★ 우선 후보와 적합도 가감점
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/024_migration_candidate_mark.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/024_migration_candidate_mark.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 숫자를 덮어쓰지 않는다 (2026-10-12)                                │
-- │                                                                  │
-- │ "적합도를 손으로 고치고 싶다" 는 요청을 받았다. 적합도를 바로     │
-- │ 덮어쓰면 그 87 이 계산값인지 사람이 넣은 값인지 알 수 없게 되고,  │
-- │ 근거(처리 건)로 되짚을 수도 없다 (CLAUDE.md 1항).                  │
-- │                                                                  │
-- │ 그래서 둘로 나눠 담는다 —                                          │
-- │   priority    ★ 우선 후보. 표에서 위로 올린다                      │
-- │   fit_adjust  가감점. 화면은 "87 (+5 수동)" 처럼 **나눠** 적는다    │
-- │                                                                  │
-- │ 계산값(bs_fit_score)은 한 글자도 안 바뀐다. 이 표에 **종합 점수를   │
-- │ 저장하지 않는다** — 조정분만 담는다.                               │
-- │                                                                  │
-- │ 2단계 후보 표에만 쓴다. 3단계 배정 엔진의 적합도에는 넣지 않는다 — │
-- │ 엔진은 기계가 고르는 자리라 사람이 고를 때의 판단을 섞지 않는다.  │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 022 는 "비중 1.000 인 사람은 줄을 두지 않는다" 였다. 이제 비중이 1.000
-- 이어도 ★ 이나 가감점이 있으면 줄이 남는다. 셋 다 기본값이면 지운다.
-- =====================================================================

ALTER TABLE `bs_project_member`
  ADD COLUMN `priority`   TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '★ 우선 후보(1). 2단계 후보 표에서 위로 올린다. 배정 엔진은 읽지 않는다'
      AFTER `reason`,
  ADD COLUMN `fit_adjust` SMALLINT   NULL
      COMMENT '적합도 가감점(-20~+20). 계산값은 그대로 두고 조정분만 담는다. 종합 점수를 저장하지 않는다'
      AFTER `priority`,
  ADD COLUMN `adjust_reason` VARCHAR(500) NULL
      COMMENT '가감점을 준 까닭. 비워도 된다'
      AFTER `fit_adjust`;


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT p.name, m.emp_name, pm.share, pm.priority, pm.fit_adjust, pm.updated_by_name
--     FROM bs_project_member pm
--     JOIN bs_project p ON p.id = pm.project_id
--     JOIN bs_member  m ON m.id = pm.member_id;
-- ---------------------------------------------------------------------
