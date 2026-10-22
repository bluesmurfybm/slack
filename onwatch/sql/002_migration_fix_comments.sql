-- 001 의 한글 COMMENT 가 깨진 컬럼을 바로잡는다. 데이터·타입은 그대로이고 설명문만 다시 씌운다.
-- 원인: 스키마를 문장으로 자르는 ow_sql_statements() 의 정규식에 /u 가 없어, '입'·'업'·'임' 처럼
-- 0x85 로 끝나는 한글의 끝 바이트를 줄바꿈(NEL)으로 보고 잘랐다. MySQL 은 깨진 바이트를 '?' 로 저장했다.
-- 열 정의는 001 의 해당 줄을 그대로 옮겼다(타입·NULL·DEFAULT 가 바뀌지 않게).

ALTER TABLE `ow_watch` MODIFY COLUMN `status` VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'active 감시 중 / paused 일시정지 / ended 종료';
ALTER TABLE `ow_watch` MODIFY COLUMN `ends_at` DATETIME NOT NULL COMMENT '감시 종료. 지나면 배치가 ended 로 바꾼다';
ALTER TABLE `ow_watch` MODIFY COLUMN `slack_webhook_enc` TEXT NULL COMMENT '이 감시 전용 슬랙 웹훅(암호화). 비면 기본 채널';
ALTER TABLE `ow_watch` MODIFY COLUMN `end_notice_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '종료 임박 알림을 보냈는지';
ALTER TABLE `ow_run` MODIFY COLUMN `trigger` VARCHAR(12) NOT NULL COMMENT 'schedule 정기 / manual 지금 실행 / cli 명령줄';
ALTER TABLE `ow_run` MODIFY COLUMN `research_md` MEDIUMTEXT NULL COMMENT '조사 노트 원문 — 무엇을 보고 그렇게 판단했는지';
ALTER TABLE `ow_finding` MODIFY COLUMN `fingerprint` CHAR(40) NOT NULL COMMENT 'sha1(대상 키·변화 종류·구별 표식) — 같은 소식을 두 번 알리지 않는다';
