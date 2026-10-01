-- =====================================================================
-- 009. 폐기된 점수 방식이 컬럼 주석에 남아 있던 것 (ba_member, ba_member_skill, ba_member_metric)
--
--   mysql -u root iworks_local     < studio/sql/009_migration_metric_comment.sql
--   mysql -u root blueassign_test  < studio/sql/009_migration_metric_comment.sql
--
-- 여러 번 실행해도 안전합니다. 주석만 바꿉니다 — 타입·NULL 여부·기본값·
-- 데이터는 하나도 건드리지 않습니다.
-- =====================================================================
--
-- 왜 필요한가
--
--   2026-09-30 에 점수 산출 방식을 바꿨습니다. 역할 그룹 안에서 서로
--   줄 세우던 상대 정규화를 버리고, 계열마다 기준값을 두는 절대 기준으로
--   갔습니다. 집단이 계열당 8~10명이라 상대 비교가 망가지기 때문입니다 —
--   21건을 처리하고도 그 안에서 꼴찌라 0점이 되고, 한 명이 퇴사하면 남은
--   사람 점수가 전부 바뀝니다(docs/scoring-design.md §2.2).
--
--   문서와 코드는 그때 맞췄는데 **컬럼 주석을 빠뜨렸습니다.** 주석은
--   DB 안에 들어 있어서 SHOW FULL COLUMNS 나 스키마 뷰어로 그대로 보입니다.
--   지금 상태에서 스키마만 들여다본 사람은 이렇게 읽습니다.
--
--     ba_member.role_label        '... 점수 정규화 그룹의 기준'
--     ba_member_skill.score       '... 역할(role_label) 그룹 내 정규화'
--
--   둘 다 더는 사실이 아닙니다. 역할로 사람을 나누지 않습니다.
--   그대로 두면 폐기한 방식이 되살아납니다.
--
--   같은 김에 산출하지 않기로 한 세 지표에도 표시를 답니다. 컬럼은
--   **지우지 않습니다** — 지난 회차 값이 남아 있고, 나중에 낼 수 있게
--   되면 채우면 됩니다. 다만 지금은 항상 NULL 이고, 읽어서 0 으로 치면
--   그 사람만 부당하게 깎입니다.
--
--     speed_score   난이도로 걸러도 중앙값 1일. 변별력이 없어 삭제
--     comm_score    첫 응답 시간을 못 재고, 남은 신호는 해석이 안 됨
--     rework_rate   재오픈 기록 자체가 없어 계산 불가
--
-- =====================================================================

-- ---------------------------------------------------------------------
-- ba_member — 역할은 더 이상 정규화 그룹이 아니다
-- ---------------------------------------------------------------------
ALTER TABLE `ba_member`
  MODIFY COLUMN `role_label` VARCHAR(50) NULL
    COMMENT '설계·개발 / UI·UX / 인프라 / 기획. 표시용. 점수를 나누는 기준이 아니다';

-- ---------------------------------------------------------------------
-- ba_member_skill — 계열 기준값 대비 절대 점수
-- ---------------------------------------------------------------------
ALTER TABLE `ba_member_skill`
  MODIFY COLUMN `avg_lead_hr` DECIMAL(8,2) NULL
    COMMENT '평균 리드타임(시간). 참고용이며 점수에 들어가지 않는다',
  MODIFY COLUMN `rework_rate` DECIMAL(5,4) NULL
    COMMENT '항상 NULL. 재오픈 기록이 없어 산출하지 않는다(scoring-design.md §0-5)',
  MODIFY COLUMN `score` DECIMAL(5,2) NULL
    COMMENT '0~100. 그 계열 기준값 대비 절대 점수. 사람끼리 비교해 매기지 않는다';

-- ---------------------------------------------------------------------
-- ba_member_metric — 삭제한 지표에 표시를 단다
-- ---------------------------------------------------------------------
ALTER TABLE `ba_member_metric`
  MODIFY COLUMN `cap_score` DECIMAL(5,2) NULL
    COMMENT '난이도 가중 처리량(절대 기준). 화면 표기는 "처리량". 속도·재작업은 들어가지 않는다',
  MODIFY COLUMN `speed_score` DECIMAL(5,2) NULL
    COMMENT '항상 NULL. 산출하지 않는다(scoring-design.md §0-3)',
  MODIFY COLUMN `comm_score` DECIMAL(5,2) NULL
    COMMENT '항상 NULL. 산출하지 않는다(scoring-design.md §0-4)',
  MODIFY COLUMN `breadth_score` DECIMAL(5,2) NULL
    COMMENT '20건 이상 처리한 계열의 비율. 화면 표기는 "경험 범위"';
