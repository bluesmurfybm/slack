-- =====================================================================
-- 012. R&D 과제의 분야 태그 (P10-3, 명세서 §8.2 · §4.7)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/012_migration_rnd_domain.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/012_migration_rnd_domain.sql
--
--   되돌리기: 012_rollback_rnd_domain.sql
-- =====================================================================
--
-- 왜 필요한가
--
--   명세서 §4.7 은 종료된 R&D 과제를 역량에 반영할 때 **"발의 시 지정한
--   도메인 태그의 계열"** 로 분류하라고 한다. 그런데 P9-2 에서 발의 화면을
--   만들 때 그 칸을 빠뜨렸다 — 명세서 §8.2 의 입력 항목에 "도메인 태그" 가
--   있는데 구현에 들어가지 않았다.
--
--   태그가 없으면 계열을 알 수 없고, 계열을 모르면 경험 범위(breadth)에
--   반영할 수 없다. 반영의 주 목적이 바로 그것이므로 여기서 채운다.
--
-- bs_task_domain 과 같은 모양이다
--
--   태스크가 여러 분야에 걸리듯 과제도 걸린다. 같은 구조를 쓰면 점수
--   산출기가 두 갈래를 따로 다룰 필요가 없다.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+09:00';

CREATE TABLE IF NOT EXISTS `bs_rnd_domain` (
  `project_id` INT UNSIGNED  NOT NULL                COMMENT 'bs_project.id. project_type=rnd 인 행만 온다',
  `domain_id`  INT UNSIGNED  NOT NULL                COMMENT 'bs_domain.id. 이 과제가 다루는 분야',
  `weight`     DECIMAL(4,3)  NOT NULL DEFAULT 1.000  COMMENT '이 과제에서 해당 분야가 차지하는 비중 0.000~1.000',
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`project_id`, `domain_id`),
  KEY `ix_bs_rnddom_domain` (`domain_id`),
  CONSTRAINT `fk_bs_rnddom_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_rnddom_domain` FOREIGN KEY (`domain_id`)
    REFERENCES `bs_domain` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='R&D 과제가 어느 분야에 속하는지(다중). 역량 반영 시 계열의 근거';

-- ---------------------------------------------------------------------
-- 적재된 R&D 업무 이력을 알아보기 위한 표시
--
-- bs_work_item.source 는 지금까지 'slack' 하나뿐이었다. 'rnd' 가 더해진다.
-- 컬럼은 VARCHAR(10) 이라 **스키마 변경이 없다.** 주석만 고쳐 둔다.
-- ---------------------------------------------------------------------
ALTER TABLE `bs_work_item`
  MODIFY COLUMN `source` VARCHAR(10) NOT NULL
  COMMENT '업무 이력의 출처. slack=슬랙 취합 시스템 | rnd=종료된 R&D 과제(P10-3)';
