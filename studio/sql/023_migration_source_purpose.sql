-- =====================================================================
-- 023. 출처 문서의 용도 — 범위 분석용과 참고용을 가른다
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/023_migration_source_purpose.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/023_migration_source_purpose.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 참고 자료는 WBS 초안의 재료가 아니다 (2026-10-12)                  │
-- │                                                                  │
-- │ 지난 사업의 산출물, 학교 소개서, 비슷한 시스템 설명서 같은 것을    │
-- │ 같은 칸에 넣으면 AI 가 그 안의 내용까지 **할 일로 뽑는다.**        │
-- │ 그래서 따로 둘 자리를 만들고, WBS 도출(parsedSources)에서 뺀다.     │
-- │                                                                  │
-- │ 난이도·공수의 근거 찾기(LinkAnalyzer)는 참고 자료도 계속 읽는다 —  │
-- │ 거기서는 할 일을 만들지 않고 근거만 찾으므로 많을수록 낫다.        │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 기존 행은 전부 'scope' 가 된다. 지금까지는 모두 범위 분석용으로 올렸다.
-- =====================================================================

ALTER TABLE `bs_project_source`
  ADD COLUMN `purpose` VARCHAR(20) NOT NULL DEFAULT 'scope'
      COMMENT '용도 scope(업무 내용 및 범위 — WBS 도출 입력)|reference(참고 자료 — WBS 도출에서 뺀다)'
      AFTER `project_id`;


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT purpose, COUNT(*) FROM bs_project_source GROUP BY purpose;
--     -- 처음에는 scope 만 있는 것이 맞다.
-- ---------------------------------------------------------------------
