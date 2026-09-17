-- iworks 회원 테이블을 흉내 낸 테스트 픽스처
DROP TABLE IF EXISTS member;
CREATE TABLE member (
  mb_id VARCHAR(64) PRIMARY KEY,
  mb_name VARCHAR(80) NOT NULL,
  mb_email VARCHAR(255) NULL,
  mb_slack_id VARCHAR(40) NULL,
  mb_leave_date VARCHAR(10) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO member VALUES
 ('admin','관리자','admin@example.com',NULL,NULL),
 ('hoyoung','김호영','hoyoung@example.com','U001',NULL),
 ('jian','김지안','jian@example.com','U002',NULL),
 ('seongcheol','박성철','seongcheol@example.com','U003',NULL),
 ('byeongmun','유병문','byeongmun@example.com',NULL,NULL),
 ('retired','퇴사자','retired@example.com',NULL,'2025-12-31');
