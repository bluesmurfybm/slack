-- =====================================================================
-- 010 되돌리기 — R&D 과제 확장 (P9)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/010_rollback_rnd.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/010_rollback_rnd.sql
--
-- =====================================================================
-- !! 데이터가 사라집니다 !!
--
--   bs_rnd_member / bs_rnd_output / bs_rnd_log / bs_rnd_interest 를
--   **통째로 지웁니다.** 발의된 과제의 참여자·산출물·진행 기록이 전부
--   없어지며 되살릴 수 없습니다.
--
--   bs_project 에서도 R&D 전용 칸을 지웁니다. project_type='rnd' 인 행은
--   **표에 남지만 유형을 잃고 일반 프로젝트와 구분할 수 없게 됩니다.**
--   되돌리기 전에 먼저 확인하십시오.
--
--     SELECT id, code, name, status FROM bs_project WHERE project_type = 'rnd';
--
--   한 건이라도 나오면, 지울지 일반 프로젝트로 둘지 **사람이 먼저 정해야
--   합니다.** 이 스크립트는 묻지 않습니다.
--
--   R&D 과제가 만든 점유도 따로 남습니다. 함께 지우려면 아래를 먼저
--   돌리십시오(이 스크립트에는 넣지 않았습니다 — 되돌리기가 가용도를
--   조용히 바꾸면 안 됩니다).
--
--     DELETE FROM bs_workload WHERE ref_type = 'rnd';
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- 자식부터 지웁니다. FK 가 걸려 있어 순서를 바꾸면 실패합니다.
DROP TABLE IF EXISTS `bs_rnd_interest`;
DROP TABLE IF EXISTS `bs_rnd_log`;
DROP TABLE IF EXISTS `bs_rnd_output`;
DROP TABLE IF EXISTS `bs_rnd_member`;

-- 인덱스를 먼저 떼고 컬럼을 지웁니다.
-- (project_type 이 인덱스의 첫 칸이라 컬럼부터 지우면 MySQL 이
--  인덱스를 알아서 손보는데, 명시하는 편이 읽기 쉽습니다.)
ALTER TABLE `bs_project`
  DROP KEY `ix_bs_project_type_status`;

ALTER TABLE `bs_project`
  DROP COLUMN `recruiting`,
  DROP COLUMN `load_cap`,
  DROP COLUMN `approved_at`,
  DROP COLUMN `approved_by_name`,
  DROP COLUMN `approved_by`,
  DROP COLUMN `proposer_name`,
  DROP COLUMN `proposer_id`,
  DROP COLUMN `rnd_category`,
  DROP COLUMN `visibility`,
  DROP COLUMN `project_type`;
