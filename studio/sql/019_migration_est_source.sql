-- =====================================================================
-- 019. 예상공수를 누가 어떻게 매겼는가
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/019_migration_est_source.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/019_migration_est_source.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 난이도와 같은 이유다                                              │
-- │                                                                  │
-- │ 016 에서 bs_task.difficulty_by / difficulty_note 를 둔 이유가     │
-- │ 그대로 공수에도 해당한다. 숫자만 있으면 "왜 3일이냐" 에 답할 수   │
-- │ 없고, **답할 수 없는 숫자는 일정 근거로 못 쓴다.**                │
-- │                                                                  │
-- │ 공수는 난이도보다 더 그렇다. 난이도는 일의 성질이라 틀려도        │
-- │ 토론거리지만, 공수는 **그대로 일정이 된다.** 어디서 나온          │
-- │ 숫자인지 모르면 아무도 그 일정을 책임지지 못한다.                 │
-- │                                                                  │
-- │ est_md_by 는 셋 중 하나다.                                        │
-- │   human — 사람이 넣었다. 자동 판정이 **절대 덮지 않는다**         │
-- │   ai    — 모델이 기획 내용을 보고 봤다                            │
-- │   rule  — 난이도에서 환산했다. 우리 실적이 있으면 그걸로,         │
-- │           없으면 기본표로. 어느 쪽인지 note 에 적는다             │
-- └──────────────────────────────────────────────────────────────────┘
-- =====================================================================

ALTER TABLE `bs_task`
  ADD COLUMN `est_md_by` VARCHAR(20) NULL
      COMMENT '예상공수를 매긴 주체. human=사람 | rule=난이도 환산 | ai=모델'
      AFTER `est_md`,
  ADD COLUMN `est_md_note` VARCHAR(500) NULL
      COMMENT '그렇게 본 근거. 화면에 그대로 보여 준다'
      AFTER `est_md_by`;


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT est_md_by, COUNT(*) FROM bs_task GROUP BY est_md_by;
--   -- 우리 실적 분포. 환산표가 이 숫자에서 나온다
--   SELECT difficulty, COUNT(*) n, ROUND(AVG(est_md),2) avg_md
--     FROM bs_task
--    WHERE est_md IS NOT NULL AND difficulty IS NOT NULL
--      AND COALESCE(est_md_by,'human') = 'human'
--    GROUP BY difficulty ORDER BY difficulty;
-- ---------------------------------------------------------------------
