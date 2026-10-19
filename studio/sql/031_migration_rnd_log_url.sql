-- =====================================================================
-- 031. 진행 기록에도 링크 하나
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/031_migration_rnd_log_url.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/031_migration_rnd_log_url.sql
--
-- 산출물처럼 진행 기록도 자료 영역에서 [파일 첨부] · [링크] 를 받는다
-- (2026-10-19 사용자 요청). 한 건에 링크 하나 — 산출물과 같다.
-- 길이는 030 과 같은 2000자(BS_URL_MAX).
--
-- 031 이 없는 DB 에서는 링크만 꺼진 채로 돈다(RndRepo::hasLogUrl).
-- =====================================================================

ALTER TABLE `bs_rnd_log`
  ADD COLUMN `url` VARCHAR(2000) NULL COMMENT '참고 링크. http:// 또는 https:// 만. 최대 2000자' AFTER `worked_on`;
