-- =====================================================================
-- 030. 링크 칸을 500 → 2000자로
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/030_migration_url_length.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/030_migration_url_length.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 실제로 쓰는 문서 링크가 500자를 넘었다 (2026-10-19)                │
-- │                                                                  │
-- │ 웍스모바일 문서 뷰어 주소는 원본 주소를 통째로 품고 있어 700자를   │
-- │ 넘는다. 산출물 링크는 길이 검사가 없어 DB 가 거절했고(엄격 모드)   │
-- │ 화면에는 "처리 중 오류" 만 떴다. 참고 자료 링크는 "500자 이내" 로  │
-- │ 막혔다. 둘 다 2000자로 넓히고, 응용이 BS_URL_MAX 로 먼저 본다.     │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 두 칸 모두 색인이 없다 — 넓혀도 색인 길이 제한에 걸리지 않는다.
-- bs_source_link.url(문서에서 찾은 링크)은 (project_id, url) 유일 색인이
-- 있어 넓히지 않는다. 그쪽은 응용이 500자로 잘라 담는다(LinkAnalyzer::scan).
-- =====================================================================

ALTER TABLE `bs_rnd_output`
  MODIFY COLUMN `url` VARCHAR(2000) NULL COMMENT '외부 링크. http:// 또는 https:// 만 받는다. 최대 2000자(030)';

ALTER TABLE `bs_project_source`
  MODIFY COLUMN `url` VARCHAR(2000) NULL COMMENT '피그마/구글드라이브 등 외부 링크. 최대 2000자(030)';

-- 확인
--   SELECT table_name, column_type FROM information_schema.columns
--    WHERE column_name = 'url' AND table_name IN ('bs_rnd_output', 'bs_project_source');
