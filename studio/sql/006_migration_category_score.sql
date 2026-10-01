-- =====================================================================
-- BlueStudio 마이그레이션 v4 → v5
--   - 계열(category) 단위 역량 점수 표 신설
--
-- 이미 설치된 환경에만 실행하세요.
-- 새로 설치하는 경우 001_schema.sql 에 이미 반영되어 있습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/006_migration_category_score.sql
--
-- 재실행 안전: CREATE TABLE IF NOT EXISTS 뿐입니다.
-- =====================================================================
--
-- ---------------------------------------------------------------------
-- 왜 표를 새로 만드는가
-- ---------------------------------------------------------------------
-- `bs_member_skill` 은 (구성원 × **분야**) 단위입니다. 그런데 점수는
-- **계열(category) 단위**로 냅니다(docs/scoring-design.md §1).
-- 분야 22개로는 (사람×분야) 칸의 82%가 표본 부족으로 버려지기 때문입니다.
--
-- 계열 점수를 넣을 자리가 없어 표를 하나 더 둡니다. 역할이 다릅니다.
--
--   bs_member_skill     분야별 처리 실적  → **근거 표시용**. 점수는 내지 않는다
--   bs_member_category  계열별 역량 점수  → **레이더 차트 + 배정 엔진**
--
-- ---------------------------------------------------------------------
-- 절대 기준 점수
-- ---------------------------------------------------------------------
--   score = min(100, weighted_qty / baseline * 100)
--
-- baseline 은 그 계열에서 "충분히 숙련" 으로 볼 난이도 가중 처리량이며,
-- 판정 회차마다 데이터에서 뽑습니다(상위 25% 지점). 회차별로 달라지므로
-- **그때 쓴 값을 행에 같이 저장**합니다. 나중에 "왜 이 점수였나" 를
-- 되짚으려면 분모를 알아야 합니다.
--
-- 사람끼리 상대 비교를 하지 않습니다. 한 명이 빠져도 남의 점수가 안 바뀝니다.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

CREATE TABLE IF NOT EXISTS `bs_member_category` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`    INT UNSIGNED NOT NULL,
  `category`     VARCHAR(40)  NOT NULL COMMENT 'bs_domain.category. BS_DOMAIN_CATEGORY 의 열쇠',
  `eval_ver`     INT UNSIGNED NOT NULL COMMENT 'bs_eval_run.id',

  `case_count`   INT          NOT NULL DEFAULT 0 COMMENT '그 계열 처리 건수',
  `weighted_qty` DECIMAL(8,2) NULL COMMENT '난이도 가중 처리량 = Σ(건별 난이도)',
  `baseline`     DECIMAL(8,2) NULL COMMENT '그때 쓴 기준값(상위 25% 지점). 점수의 분모',
  `score`        DECIMAL(5,2) NULL COMMENT '0~100. min(100, weighted_qty/baseline*100)',

  -- 표본이 얼마나 되는지. 점수를 자르는 것이 아니라 화면 표시를 가른다.
  --   full    20건 이상 — 그대로 보여준다
  --   partial 10~19건   — 흐리게, '참고' 표시
  --   none    10건 미만 — 점수를 숨기고 '표본 부족' 으로만
  `confidence`   VARCHAR(10)  NOT NULL DEFAULT 'none' COMMENT 'full|partial|none',
  `insufficient_data` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1이면 점수를 그대로 노출하지 않는다',

  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bs_mcat_ver` (`member_id`, `category`, `eval_ver`),
  -- "이 계열을 잘하는 사람" 조회 — 후보 리스트의 주 경로
  KEY `ix_bs_mcat_cat`  (`category`, `eval_ver`, `score`),
  KEY `ix_bs_mcat_eval` (`eval_ver`),
  CONSTRAINT `fk_bs_mcat_member` FOREIGN KEY (`member_id`)
    REFERENCES `bs_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  -- 판정 회차를 지우면 점수의 근거가 사라진다. 막는다.
  CONSTRAINT `fk_bs_mcat_eval` FOREIGN KEY (`eval_ver`)
    REFERENCES `bs_eval_run` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='구성원 계열별 역량 점수 스냅샷. 판정 회차마다 보존';

-- ---------------------------------------------------------------------
-- bs_member_skill 은 이제 '근거 표시용' 입니다.
-- score 컬럼은 채우지 않습니다(계열 단위로만 점수를 냅니다).
-- 주석만 바꿔 의도를 남깁니다.
-- ---------------------------------------------------------------------
ALTER TABLE `bs_member_skill`
  MODIFY COLUMN `score` DECIMAL(5,2) NULL
    COMMENT '쓰지 않습니다. 점수는 bs_member_category 에만 있습니다(계열 단위)';
