-- =====================================================================
-- 022. 프로젝트별 참여 비중 — 산술이 못 담는 판단을 담는 자리
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/022_migration_project_member.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/022_migration_project_member.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 지금까지 "조금 적게" 를 말할 길이 없었다                           │
-- │                                                                  │
-- │ 있던 손잡이는 넷인데 다 안 맞았다 —                               │
-- │   is_assignable   전부 아니면 전무, 게다가 전사 공통              │
-- │   후보에서 빼기    전부 아니면 전무                                │
-- │   bs_workload     연속적이지만 **모든 프로젝트**에 걸리고,        │
-- │                   없는 업무를 지어내야 한다                        │
-- │   manual_adjust   저장·표시는 되는데 **엔진이 안 읽는다**          │
-- │                   (eval_ver 단위라 전 프로젝트에 동시에 걸린다)    │
-- │                                                                  │
-- │ "이 프로젝트에서 이 사람만 절반" 을 적을 자리가 없었다.            │
-- └──────────────────────────────────────────────────────────────────┘
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 점수를 깎지 않는다. **그릇**을 줄인다                              │
-- │                                                                  │
-- │ 적합도에 곱하면 화면의 "적합도 47" 이 실제 47 이 아니게 된다 —    │
-- │ 최소 1건 보장을 정규화로 풀지 않은 바로 그 이유다.                 │
-- │                                                                  │
-- │ 가용 공수에 곱하면 적합도는 실제 값 그대로 두고, 가용도 축과       │
-- │ 과부하 감점이 저절로 반응해 배정량이 준다. 그리고 "이 사람은 이    │
-- │ 프로젝트에 절반만" 이라는 **사람의 말과 1:1로 맞는다.**            │
-- │                                                                  │
-- │ 이미 constraints.capacity_ratio 라는 전역 손잡이가 있다. 이 표는   │
-- │ 그것을 **사람별**로 넓힌 것이다.                                   │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 모양은 bs_rnd_member 를 따랐다 — 프로젝트×사람 + 비율 + 누가 언제.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `bs_project_member` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL
               COMMENT 'bs_project.id',
  `member_id`  INT UNSIGNED NOT NULL
               COMMENT 'bs_member.id',

  -- ★ 0 을 허용하지 않는다(응용에서 0.100 을 바닥으로 자른다).
  --   0 을 두면 "후보에 넣고 0" 과 "후보에서 빼기" 가 같은 결과를 내는
  --   **두 가지 길**이 된다. 안 쓸 거면 후보에서 빼는 것이 맞다.
  `share`      DECIMAL(4,3) NOT NULL DEFAULT 1.000
               COMMENT '이 프로젝트에서의 참여 비중(0.100~1.000). 가용 공수에 곱한다. 1.000 이면 조정 없음',

  -- 사유는 **선택**이다. 회사가 작고 서로 아는 사이라 강제하면 아무 말이나
  -- 적어 넣게 된다. 대신 누가 언제는 반드시 남긴다 — 그건 공짜다.
  `reason`     VARCHAR(500) NULL
               COMMENT '왜 낮췄는가. 비워도 된다',
  `updated_by` VARCHAR(64)  NULL
               COMMENT '마지막으로 바꾼 사람(이메일)',
  `updated_by_name` VARCHAR(80) NULL
               COMMENT '바꾼 시점 성명 스냅샷',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- 한 프로젝트에 한 사람은 한 줄. 두 줄이면 어느 쪽이 참인지 알 수 없다.
  UNIQUE KEY `uk_bs_pm_one` (`project_id`, `member_id`),
  KEY `ix_bs_pm_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='프로젝트별 참여 비중. 비중 1.000 인 사람은 줄을 두지 않는다(표가 안 불어난다)';


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT p.name, m.emp_name, pm.share, pm.reason, pm.updated_by_name
--     FROM bs_project_member pm
--     JOIN bs_project p ON p.id = pm.project_id
--     JOIN bs_member  m ON m.id = pm.member_id;
--     -- 처음에는 0행이 맞다. 사람이 낮춘 줄만 쌓인다.
-- ---------------------------------------------------------------------
