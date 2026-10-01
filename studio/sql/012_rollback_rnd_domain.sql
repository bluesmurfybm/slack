-- =====================================================================
-- 012 되돌리기 — R&D 분야 태그
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/012_rollback_rnd_domain.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/012_rollback_rnd_domain.sql
--
-- =====================================================================
-- !! 태그가 사라집니다 !!
--
--   과제마다 지정한 분야가 없어집니다. 되돌린 뒤 다시 역량을 산출하면
--   R&D 건은 계열을 알 수 없어 반영되지 않습니다.
--
--   **이미 적재된 bs_work_item(source='rnd') 은 지우지 않습니다.**
--   지난 판정 회차가 그 행을 근거로 삼고 있어서, 지우면 확정된 배정의
--   근거가 사라집니다. 함께 치우려면 아래를 따로 돌리십시오.
--
--     DELETE FROM bs_work_item WHERE source = 'rnd';
-- =====================================================================
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `bs_rnd_domain`;

ALTER TABLE `bs_work_item`
  MODIFY COLUMN `source` VARCHAR(10) NOT NULL COMMENT '업무 이력의 출처. slack';
