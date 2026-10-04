-- =====================================================================
-- 017. 바깥 호출의 예산 관리 — 켜고 끄기 · 쉬는 시각 · 사용량 기록
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/017_migration_api_budget.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/017_migration_api_budget.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 왜 필요해졌나 — 2026-10-04 에 실제로 겪은 일                       │
-- │                                                                  │
-- │ 피그마 링크를 하나씩 읽는 워커가 하룻밤 돌았다. 결과는 300건 중   │
-- │ 읽음 3건. 원인은 피그마가 느려서가 아니었다.                      │
-- │                                                                  │
-- │   1) 429 를 받고도 **다음 링크로 계속 넘어갔다.** 1초에 수십 번을 │
-- │      더 두드렸다.                                                 │
-- │   2) 피그마가 응답 헤더로 `retry-after: 224862` (2일 14시간) 를   │
-- │      보내고 있었는데 **코드가 그 헤더를 아예 안 봤다.**           │
-- │   3) 그래서 며칠치 예산을 하룻밤에 태웠다.                        │
-- │                                                                  │
-- │ 피그마의 제한은 분당 호출 수가 아니라 **며칠 단위 비용 예산**이다 │
-- │ (x-figma-rate-limit-type: low). 한 번 태우면 되돌릴 길이 없다.    │
-- │                                                                  │
-- │ 그래서 세 가지를 DB 에 둔다.                                      │
-- │   · enabled        — 관리자가 연동을 통째로 끈다                  │
-- │   · cooldown_until — 상대가 "언제까지 쉬어라" 한 시각을 지킨다    │
-- │   · bs_api_usage   — 얼마나 썼는지 눈으로 본다                    │
-- └──────────────────────────────────────────────────────────────────┘
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 연동마다 켜고 끄기 · 쉬는 시각 · 하루 상한
--
-- 자격 정보(bs_integration)와 같은 줄에 둔다. "이 연동을 지금 쓸 수
-- 있는가" 를 한 번에 읽어야 하는데, 표를 나누면 매번 조인해야 한다.
-- ---------------------------------------------------------------------
ALTER TABLE `bs_integration`
  -- 토큰을 지우지 않고 **쓰지만 않는다.** 지금 피그마처럼 제한에 걸린
  -- 동안 꺼 두었다가, 풀리면 다시 켜는 쓰임이다. 연결을 끊으면 토큰이
  -- 날아가 관리자가 다시 발급받아야 한다 — 그건 너무 무거운 조치다.
  ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '관리자가 이 연동을 쓰는가. 0=제외(토큰은 그대로 둔 채 호출만 안 한다)'
      AFTER `provider`,

  -- 상대가 429 와 함께 Retry-After 를 주면 **그 시각을 그대로 적는다.**
  -- 이 시각 전에는 호출을 아예 만들지 않는다. 추측하지 않는다 —
  -- 상대가 말해 준 값을 쓴다.
  ADD COLUMN `cooldown_until` DATETIME NULL
      COMMENT '이 시각 전에는 부르지 않는다. 429 의 Retry-After 를 그대로 담는다'
      AFTER `last_ok_at`,
  ADD COLUMN `cooldown_reason` VARCHAR(200) NULL
      COMMENT '왜 쉬는가. 화면에 그대로 보여 준다'
      AFTER `cooldown_until`,

  -- 상대가 막기 **전에 우리가 먼저 멈춘다.** 0 이면 상한 없음.
  ADD COLUMN `daily_cap` INT UNSIGNED NOT NULL DEFAULT 0
      COMMENT '하루에 걸 수 있는 호출 수. 0=제한 없음'
      AFTER `cooldown_reason`;


-- ---------------------------------------------------------------------
-- 2. 호출 기록
--
-- 바깥으로 나가는 호출은 **한 줄도 빠짐없이** 여기에 남는다. 구글·피그마
-- 뿐 아니라 앞으로 붙일 Claude 도 같은 표를 쓴다 — 쓰임이 같기 때문이다.
--
--   "왜 분석이 안 되지?"  → 호출이 있었나, 몇 번 실패했나
--   "비용이 얼마나 드나?"  → 토큰과 추정 비용
--   "한도에 가까운가?"     → 오늘 몇 번 썼나
--
-- 이 표가 없으면 사람이 서버 로그를 뒤져야 하고, 실제로 그렇게 하루를
-- 날렸다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_api_usage` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `provider`   VARCHAR(20) NOT NULL
               COMMENT '어디를 불렀나. google | figma | claude',
  `act`        VARCHAR(40) NOT NULL
               COMMENT '무엇을 했나. figma_nodes | drive_export | difficulty 등',

  `ok`         TINYINT(1)       NOT NULL DEFAULT 0,
  `http_code`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  -- 묶어 받기의 효과를 보려면 이게 있어야 한다. 호출 1번에 40건을
  -- 처리했다는 사실이 "호출 수가 적다" 보다 정확하다.
  `items`      INT UNSIGNED NOT NULL DEFAULT 0
               COMMENT '이 호출 한 번으로 처리한 건수. 묶어 받기면 여러 건',

  -- AI 용. 구글·피그마는 0 으로 남는다.
  `tokens_in`  INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_micro` INT UNSIGNED NOT NULL DEFAULT 0
               COMMENT '추정 비용. **백만분의 1 달러** 단위 (소수점을 쓰지 않으려고)',

  `ms`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '걸린 시간(밀리초)',
  `note`       VARCHAR(200) NULL COMMENT '실패 사유 등. 사람이 읽을 한 줄',

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- 화면이 늘 "최근 14일, 이 연동" 으로 묻는다. 그 모양 그대로 건다.
  KEY `ix_bs_usage_day` (`provider`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='바깥 API 호출 기록. 사용량 그래프와 한도 판정이 읽는다';


-- ---------------------------------------------------------------------
-- 3. 쌓이는 표다 — 워커가 90일 지난 줄을 지운다
--
-- bs_notification 에 보관 규칙을 안 둬서 계속 쌓이고 있다. 같은 실수를
-- 반복하지 않으려고 여기서는 처음부터 정해 둔다. 지우는 일은
-- cron/analyze.php 가 가끔 한다 — 따로 크론을 늘리지 않는다.
-- ---------------------------------------------------------------------


-- ---------------------------------------------------------------------
-- 4. 확인
--
--   SELECT provider, enabled, cooldown_until, daily_cap FROM bs_integration;
--   SELECT provider, DATE(created_at) d, COUNT(*) calls, SUM(ok) ok, SUM(items) items
--     FROM bs_api_usage GROUP BY provider, d ORDER BY d DESC LIMIT 10;
-- ---------------------------------------------------------------------
