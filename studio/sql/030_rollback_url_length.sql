-- =====================================================================
-- 030 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/030_rollback_url_length.sql
--
-- **500자를 넘는 링크가 이미 있으면 실패한다**(엄격 모드). 먼저 확인하라:
--
--   SELECT id, CHAR_LENGTH(url) FROM bs_rnd_output     WHERE CHAR_LENGTH(url) > 500;
--   SELECT id, CHAR_LENGTH(url) FROM bs_project_source WHERE CHAR_LENGTH(url) > 500;
-- =====================================================================

ALTER TABLE `bs_rnd_output`
  MODIFY COLUMN `url` VARCHAR(500) NULL COMMENT '외부 링크. http:// 또는 https:// 만 받는다';

ALTER TABLE `bs_project_source`
  MODIFY COLUMN `url` VARCHAR(500) NULL COMMENT '피그마/구글드라이브 등 외부 링크';
