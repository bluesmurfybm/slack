-- =====================================================================
-- BlueCart 마이그레이션 v2 → v3
--   - 관리자 영구 삭제 + 삭제 기록
--
-- 이미 설치된 환경에만 실행하세요. 새로 설치하는 경우 01_schema.sql 에
-- 이미 들어 있어 실행할 필요가 없습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/04_migration_v3.sql
--   또는  php dev/apply_sql.php sql/04_migration_v3.sql
--
-- CREATE TABLE IF NOT EXISTS 뿐이라 두 번 실행해도 안전합니다.
-- 실행 전 백업을 권장합니다:
--   mysqldump -u <user> -p <db> > backup_$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 삭제 기록
--
-- 관리자가 요청을 지우면 bc_request 행이 사라지고 이력·첨부도 함께
-- 지워집니다(ON DELETE CASCADE). 지웠다는 사실까지 없어지면 안 되므로
-- 지운 시점의 요청 본문과 처리 이력을 snapshot 에 통째로 담아 둡니다.
--
-- req_year/req_seq 를 남기는 이유는 하나 더 있습니다. 채번은
-- MAX(req_seq)+1 인데, 마지막 건을 지우면 다음 요청이 같은 번호를 다시
-- 받습니다. 이미 메일·슬랙으로 나간 번호가 다른 건을 가리키게 되므로,
-- 채번은 이 표의 번호까지 함께 보고 건너뜁니다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_request_deleted` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`      BIGINT UNSIGNED NOT NULL COMMENT '지워진 bc_request.id',
  `req_year`        SMALLINT     NOT NULL,
  `req_seq`         INT          NOT NULL,
  `req_no`          VARCHAR(20)  NOT NULL,
  `item_name`       VARCHAR(200) NOT NULL,
  `status`          VARCHAR(20)  NOT NULL COMMENT '지울 때의 처리 상태',
  `requester_id`    VARCHAR(64)  NOT NULL,
  `requester_name`  VARCHAR(80)  NOT NULL,
  `snapshot`        LONGTEXT     NULL COMMENT '요청 본문 + 처리 이력 + 첨부 목록(JSON)',
  `reason`          VARCHAR(500) NULL COMMENT '삭제 사유',
  `deleted_by`      VARCHAR(64)  NOT NULL,
  `deleted_by_name` VARCHAR(80)  NOT NULL,
  `deleted_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bc_reqdel_seq` (`req_year`, `req_seq`),
  KEY `ix_bc_reqdel_no`  (`req_no`),
  KEY `ix_bc_reqdel_at`  (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='삭제된 구매 요청 기록';

-- ---------------------------------------------------------------------
-- 확인
-- ---------------------------------------------------------------------
-- SHOW CREATE TABLE bc_request_deleted;
-- SELECT req_no, item_name, deleted_by_name, deleted_at FROM bc_request_deleted ORDER BY id DESC;
