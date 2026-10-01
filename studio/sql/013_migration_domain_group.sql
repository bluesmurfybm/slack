-- =====================================================================
-- 013. 분야 묶음 — 코스모스 LXP / 일반 (R&D 과제 발의용)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/013_migration_domain_group.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/013_migration_domain_group.sql
--
-- 왜 — 분야 22개가 전부 코스모스(무들) 컴포넌트 기준이라, 사내 제품이나
-- AI 실험 같은 R&D 과제를 발의할 때 붙일 태그가 없었다. 발의 화면이 코스모스
-- 기능 목록만 보여 준다.
--
-- 묶음은 둘뿐이다. 세부 분야는 코스모스 쪽이 맞고, 일반은 큰 분류만 있으면
-- 된다는 판단이다. 그래서 기존 22개는 전부 cosmos 로 두고 일반 6개를 새로
-- 넣는다.
--
-- **새 분야 6개 중 4개는 category 를 비운다.** 점수는 분야가 아니라 계열
-- 단위로 내는데(scoring-design.md §1.3), score.py 는 계열을 고를 때
-- `WHERE category IS NOT NULL AND category <> ''` 로 거른다. 비워 두면
-- 점수 계산에 아예 들어가지 않으므로 **기존 구성원 점수가 흔들리지 않는다.**
-- 분야별 근거(bs_member_skill)에는 그대로 남아 무엇을 했는지는 보인다.
--
-- 계열을 새로 만들지 않은 것도 같은 이유다. 계열을 8→9 로 늘리면
-- breadth_score 의 분모가 바뀌어 전원 점수가 한 번 흔들린다.
--
-- ai_infra 와 ai_apply 만 계열을 채운다. 둘은 실제로 인프라 일이고 연동
-- 일이라 기존 계열에 자연스럽게 들어가고, 역량으로 쌓이는 것이 맞다.
--
-- keywords 는 비운다. 수집기가 자동으로 고를 수 없는 분야들이다 — 슬랙
-- 취합 시스템에는 고객 요청만 들어오고, "사내 제품"인지 "알고리즘 실험"인지
-- 낱말로 가를 수 없다. 발의할 때 사람이 직접 고르는 태그로만 쓴다.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 묶음 칸
--
-- 기본값 'cosmos' 로 두면 기존 22행이 그대로 코스모스 묶음이 된다.
-- ENUM 대신 VARCHAR + COMMENT (docs/conventions.md).
-- ---------------------------------------------------------------------
ALTER TABLE `bs_domain`
  ADD COLUMN `domain_group` VARCHAR(20) NOT NULL DEFAULT 'cosmos'
      COMMENT '분야 묶음. cosmos=코스모스 LXP 기능 | general=일반(사내·연구)'
      AFTER `name`,
  ADD KEY `ix_bs_domain_group` (`domain_group`, `sort_no`);


-- ---------------------------------------------------------------------
-- 2. 일반 분야
--
-- sort_no 는 300 부터 — 기존이 210 까지라 사이에 끼어들지 않는다.
-- 다시 돌려도 안전하도록 code 유일키에 걸어 ON DUPLICATE 로 받는다.
-- ---------------------------------------------------------------------
INSERT INTO `bs_domain` (`code`, `name`, `domain_group`, `category`, `keywords`, `sort_no`, `is_active`)
VALUES
  ('inhouse_product', '사내 제품·서비스',   'general', NULL,          NULL, 300, 1),
  ('productivity',    '업무생산성 도구',    'general', NULL,          NULL, 310, 1),
  ('ai_infra',        'AI 환경 구축·운영',  'general', 'platform',    NULL, 320, 1),
  ('ai_apply',        'AI 연계·응용',       'general', 'integration', NULL, 330, 1),
  ('algorithm',       '알고리즘·기법 검증', 'general', NULL,          NULL, 340, 1),
  ('tech_poc',        '신기술 검토·PoC',    'general', NULL,          NULL, 350, 1)
ON DUPLICATE KEY UPDATE
  `name`         = VALUES(`name`),
  `domain_group` = VALUES(`domain_group`),
  `category`     = VALUES(`category`),
  `sort_no`      = VALUES(`sort_no`),
  `is_active`    = VALUES(`is_active`);


-- ---------------------------------------------------------------------
-- 3. 확인
--
--   SELECT domain_group, COUNT(*) FROM bs_domain GROUP BY domain_group;
--     cosmos 22 / general 6
--
--   SELECT COUNT(*) FROM bs_domain WHERE category IS NULL;
--     4  (점수 계산에 들어가지 않는 분야)
-- ---------------------------------------------------------------------
