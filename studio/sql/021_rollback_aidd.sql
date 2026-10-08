-- =====================================================================
-- 021 되돌리기.
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local < studio/sql/021_rollback_aidd.sql
--
-- 계수가 사라지면 공수 보정과 가용도 할인이 전부 멈춘다 — 보정 없는
-- 상태로 돌아간다. **이미 매긴 공수는 되돌아가지 않는다.** 태스크에
-- 저장된 값이라 [난이도 매기기] 를 다시 돌려야 한다.
--
-- 지난 배정안의 params_json 에 복사된 aidd 값은 그대로 남는다. 그 차수가
-- 어떤 설정으로 나왔는지는 계속 설명할 수 있다 — 그게 복사해 둔 이유다.
-- =====================================================================

ALTER TABLE `bs_project`
  DROP COLUMN `aidd_enabled`,
  DROP COLUMN `aidd_effort`,
  DROP COLUMN `aidd_load`;
