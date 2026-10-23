-- =====================================================================
-- 028. 직접 입력 글의 분석 상태 되돌리기 (데이터만 — 스키마 변경 없음)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/028_migration_text_source_status.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/028_migration_text_source_status.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ '전체 다시 분석' 이 직접 입력 글을 ok → skip 으로 덮었다 (2026-10-15)│
-- │                                                                  │
-- │ 글(parsed_text)은 남았지만 WBS 도출·난이도 근거는 parse_status='ok'│
-- │ 만 본다. 그래서 재료에서 조용히 빠졌다. 코드는 WbsExtractor::      │
-- │ parseSource() 에서 고쳤고, 여기서는 이미 덮인 행을 되돌린다.        │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 글이 있는 직접 입력(kind='text')만 고친다. 글이 빈 행은 skip 이 맞다.
-- 여러 번 돌려도 결과가 같다.
--
-- 되돌리기 파일은 두지 않는다. 이 보정 전의 'skip' 은 버그가 만든 값이라
-- 되돌릴 이유가 없다. 걱정되면 먼저 떠 두라:
--
--   SELECT id, project_id, title FROM bs_project_source
--    WHERE kind = 'text' AND parse_status = 'skip'
--      AND parsed_text IS NOT NULL AND parsed_text <> '';
-- =====================================================================

UPDATE `bs_project_source`
   SET `parse_status` = 'ok',
       `parse_error`  = NULL
 WHERE `kind` = 'text'
   AND `parse_status` = 'skip'
   AND `parsed_text` IS NOT NULL
   AND `parsed_text` <> '';

-- 확인 — 0 이어야 한다
--   SELECT COUNT(*) FROM bs_project_source
--    WHERE kind = 'text' AND parse_status <> 'ok' AND parsed_text <> '';
