-- =====================================================================
-- 018. 작업이 만들어 낸 결과물을 담을 자리
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/018_migration_job_result.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/018_migration_job_result.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 왜 필요해졌나 — 2026-10-06                                        │
-- │                                                                  │
-- │ [문서에서 WBS 도출] 이 웹 요청 안에서 모델을 불렀다. 문서가       │
-- │ 커지면 100초가 넘어 PHP 가 끊었고, 화면에는 "서버 응답을 읽지     │
-- │ 못했습니다" 만 떴다. 시트 하나로 줄여도 또 끊겼다.                │
-- │                                                                  │
-- │ 링크 읽기·난이도 판정은 같은 이유로 이미 크론 큐에 있다. WBS      │
-- │ 도출만 남아 있었다.                                               │
-- │                                                                  │
-- │ 다만 앞의 둘과 다른 점이 하나 있다. **결과물이 있다.**            │
-- │ 링크 읽기는 bs_source_link 에, 난이도는 bs_task 에 바로 쓰면      │
-- │ 됐지만, WBS 도출은 **초안**이라 사람이 보고 고친 뒤에 저장한다.   │
-- │ 그때까지 어딘가 들고 있어야 한다.                                 │
-- │                                                                  │
-- │ bs_task 에 바로 쓰면 안 된다 — 검토 전에 저장하면 "도출이 곧      │
-- │ 저장" 이 되어 검토 단계가 형식만 남는다(WbsExtractor 주석).       │
-- └──────────────────────────────────────────────────────────────────┘
-- =====================================================================

ALTER TABLE `bs_analysis_job`
  ADD COLUMN `result_json` MEDIUMTEXT NULL
      COMMENT '작업이 만든 결과. WBS 도출의 초안 트리가 여기 담긴다. 사람이 저장하면 비운다'
      AFTER `message`;


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT id, kind, status, done, total,
--          CHAR_LENGTH(COALESCE(result_json,'')) AS result_chars
--     FROM bs_analysis_job ORDER BY id DESC LIMIT 5;
-- ---------------------------------------------------------------------
