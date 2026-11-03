-- =====================================================================
-- 033 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/033_rollback_idea_source.sql
--
-- 아이디어마다 **첫 링크 하나만** bs_idea.url 로 돌려놓는다. 나머지 링크와
-- 올린 파일 목록은 사라진다(디스크의 파일은 남으니 필요하면 손으로 치운다).
-- 먼저 떠 두라:
--
--   SELECT * FROM bs_idea_source;
-- =====================================================================

UPDATE `bs_idea` i
  JOIN (SELECT idea_id, MIN(id) AS id FROM `bs_idea_source`
         WHERE url IS NOT NULL GROUP BY idea_id) f ON f.idea_id = i.id
  JOIN `bs_idea_source` s ON s.id = f.id
   SET i.url = s.url
 WHERE i.url IS NULL;

DROP TABLE IF EXISTS `bs_idea_source`;
