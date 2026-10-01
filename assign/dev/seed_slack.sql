-- =====================================================================
-- 수집기 시험용 샘플 — 슬랙 취합 시스템 쪽 표 + ba_member
--
--   mysql -u root iworks_local < assign/dev/seed_slack.sql
--
-- 운영 DB 에서 실행하지 마세요. 아래 DELETE 가 실제 업무 이력을 지웁니다.
--
-- 표 정의는 slack/db.php 의 것을 그대로 옮겼습니다(운영에서는 그쪽이 만듭니다).
-- 로컬에는 슬랙 모듈이 돌지 않아 표가 없으므로 여기서 만들어 둡니다.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- slack 쪽 표 (slack/db.php 와 같은 정의)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `requests` (
  `id`         VARCHAR(32)   NOT NULL COMMENT 'Slack 항목 ID',
  `title`      VARCHAR(500)  NOT NULL DEFAULT '',
  `body`       MEDIUMTEXT    NULL,
  `momo`       VARCHAR(500)  NOT NULL DEFAULT '',
  `lms`        VARCHAR(500)  NOT NULL DEFAULT '',
  `req_id`     VARCHAR(32)   NULL,
  `req`        VARCHAR(120)  NOT NULL DEFAULT '—',
  `asg_id`     VARCHAR(32)   NULL,
  `asg`        VARCHAR(120)  NOT NULL DEFAULT '—',
  `status_id`  VARCHAR(32)   NULL,
  `status`     VARCHAR(60)   NOT NULL DEFAULT '',
  `priority_id` VARCHAR(32)  NULL,
  `priority`   VARCHAR(40)   NOT NULL DEFAULT '',
  `team_id`    VARCHAR(32)   NULL,
  `team`       VARCHAR(60)   NOT NULL DEFAULT '',
  `cmt_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `eta`        DATE          NULL,
  `date`       DATE          NULL,
  `done`       DATE          NULL,
  `created`    INT UNSIGNED  NOT NULL DEFAULT 0,
  `updated`    INT UNSIGNED  NOT NULL DEFAULT 0,
  `locked`     TINYINT(1)    NOT NULL DEFAULT 0,
  `edited_by`  VARCHAR(120)  NULL,
  `synced_at`  DATETIME      NULL,
  `updated_at` DATETIME      NULL,
  `ai_stars`   TINYINT UNSIGNED NULL,
  `ai_reason`  VARCHAR(300)  NULL,
  `ai_conf`    VARCHAR(10)   NULL,
  `ai_hash`    CHAR(32)      NULL,
  `ai_scored_at` DATETIME    NULL,
  `attachments` MEDIUMTEXT   NULL,
  `list_id`    VARCHAR(32)   NULL,
  `board`      VARCHAR(40)   NOT NULL DEFAULT '',
  `archived`   TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status`  (`status`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schools` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(200) NOT NULL,
  `ver`        VARCHAR(20)  NOT NULL DEFAULT '',
  `dev`        VARCHAR(500) NOT NULL DEFAULT '',
  `ops`        VARCHAR(500) NOT NULL DEFAULT '',
  `log`        VARCHAR(500) NOT NULL DEFAULT '',
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NULL,
  `updated_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_assignments` (
  `request_id`  VARCHAR(32)  NOT NULL,
  `assignee`    VARCHAR(60)  NOT NULL,
  `assigned_at` DATETIME     NOT NULL,
  `assigned_by` VARCHAR(120) NULL,
  PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 초기화
-- ---------------------------------------------------------------------
DELETE FROM ba_work_item_domain;
DELETE FROM ba_work_item;
DELETE FROM ba_sync_log;
DELETE FROM local_assignments;
DELETE FROM requests;
DELETE FROM schools;

-- ---------------------------------------------------------------------
-- ba_member — 운영에서는 MemberRepo::syncFromPortalUsers() 가 채웁니다(P3).
-- 수집기가 담당자를 붙이려면 이 표가 있어야 해서 여기서 미리 넣습니다.
-- ---------------------------------------------------------------------
INSERT INTO ba_member (user_id, emp_name, role_label, team, career_months, base_capacity, is_assignable)
VALUES
  ('kimhy@bluesoft.co.kr',    '김호영', '설계·개발', '개발1팀', 180, 1.00, 1),
  ('jian@bluesoft.co.kr',     '김지안', '설계·개발', '개발1팀',  96, 1.00, 1),
  ('scpark@bluesoft.co.kr',   '박성철', '인프라',    '인프라팀', 150, 1.00, 1),
  ('pink@bluesoft.co.kr',     '김태주', 'UI·UX',     '디자인팀', 120, 1.00, 1),
  ('venus@bluesoft.co.kr',    '안정민', '설계·개발', '개발2팀',  72, 1.00, 1),
  ('lenda83@bluesoft.co.kr',  '진소현', '기획',      '기획팀',  144, 0.50, 1),
  ('amitoa@bluesoft.co.kr',   '김아랑', '설계·개발', '개발2팀',  60, 1.00, 1)
ON DUPLICATE KEY UPDATE emp_name = VALUES(emp_name);

-- ---------------------------------------------------------------------
-- 평가 제외 예시
--
-- 진소현(기획)은 슬랙 취합 시스템에 업무가 남지 않는 직무라 역량 점수를 낼 수
-- 없습니다. 운영 데이터에서도 6개월간 0건이었습니다(docs/data-quality-report.md).
--
-- **배정은 그대로 가능합니다** — is_assignable 은 1로 둡니다.
-- 평가만 빼는 것이지 사람을 빼는 게 아닙니다.
-- ---------------------------------------------------------------------
UPDATE ba_member
   SET is_evaluable = 0,
       eval_exclude_reason = '기획 직무 — 슬랙 취합 시스템에 업무 기록이 남지 않아 이 데이터로 평가할 수 없습니다.',
       eval_excluded_at = NOW(),
       eval_excluded_by = 'kimhy@bluesoft.co.kr'
 WHERE user_id = 'lenda83@bluesoft.co.kr';

-- ---------------------------------------------------------------------
-- 대학 사이트 목록 — 기관명 표준화가 이 표를 먼저 봅니다.
-- ---------------------------------------------------------------------
INSERT INTO schools (name, ver, dev, ops, active, created_at, updated_at) VALUES
  ('한국기술교육대학교', '4.5', 'https://dev.lms.koreatech.ac.kr', 'https://lms.koreatech.ac.kr', 1, NOW(), NOW()),
  ('충북대학교',        '3.9', 'https://dev.lms.chungbuk.ac.kr',  'https://lms.chungbuk.ac.kr',  1, NOW(), NOW()),
  ('공주대학교',        '3.5', 'https://dev.lms.kongju.ac.kr',    'https://lms.kongju.ac.kr',    1, NOW(), NOW()),
  ('순천향대학교',      '4.5', 'https://dev.lms.sch.ac.kr',       'https://lms.sch.ac.kr',       1, NOW(), NOW());

-- ---------------------------------------------------------------------
-- 업무 이력 샘플
--
-- 일부러 아래를 섞어 두었습니다(수집기가 제대로 가르는지 보려고).
--   · 상태 전 구간 (등록 / 진행중 / 확인요청(개발서버반영) / 확인요청(운영서버반영)
--                   / 운영배포요청 / 완료 / 보류 / 처리불가)
--   · 기관을 LMS 링크로만 알 수 있는 건 / 제목에만 있는 건 / 아예 모르는 건
--   · ba_member 에 없는 담당자 (박모름, 외주업체) → 미매칭으로 빠져야 함
--   · 담당자 미지정 '—'
--   · ai_stars 있는 건 / 없는 건 (규칙 기반으로 계산되어야 함)
--   · archived=1 / 제목 빈 건 (skip_empty_title 확인용)
--   · 와이오즈 보드 (기본 설정에서는 수집 대상 아님)
--   · date 가 비어 created 로만 기간 판정되는 건
-- created/updated 는 UNIX_TIMESTAMP 로 계산해 넣습니다.
-- ---------------------------------------------------------------------
INSERT INTO requests
 (id, title, body, lms, req, asg, asg_id, status, priority, team, cmt_count,
  `date`, done, created, updated, ai_stars, board, archived) VALUES

-- 출석부 + 성적부 (여러 분야에 걸리는 건)
('R001', '[한기대] 온라인 출석부 이수 기준 변경 및 성적부 반영',
 '온라인 출석 인정 기준을 주차별 80%로 변경하고, 변경분이 성적부 총점에 자동 반영되도록 수정 요청드립니다. 기존 데이터도 재계산 필요합니다.',
 'https://lms.koreatech.ac.kr/course/view.php?id=1', '고객사', '김호영', NULL,
 '완료', '일반', '시스템개발', 7,
 '2026-09-01', '2026-09-12', UNIX_TIMESTAMP('2026-09-01 09:10:00'), UNIX_TIMESTAMP('2026-09-12 17:40:00'), NULL, '블루소프트', 0),

-- 기관을 LMS 링크로만 알 수 있음 (제목에 학교명 없음)
('R002', '퀴즈 응시 중 새로고침하면 답안이 초기화되는 문제',
 '시험 응시 도중 브라우저 새로고침 시 이미 선택한 답안이 사라집니다. 세션 처리 확인 부탁드립니다.',
 'https://lms.chungbuk.ac.kr/mod/quiz/view.php?id=22', '고객사', '김지안', NULL,
 '확인요청(개발서버반영)', '긴급', '시스템개발', 12,
 '2026-09-03', NULL, UNIX_TIMESTAMP('2026-09-03 11:00:00'), UNIX_TIMESTAMP('2026-09-10 14:20:00'), 4, '블루소프트', 0),

-- SSO / 인증 — 난이도 높은 쪽
('R003', '[공주대] 통합인증 SSO 연동 오류 — 로그인 후 세션 끊김',
 'SSO 인증 후 LMS 로 넘어올 때 간헐적으로 세션이 유지되지 않습니다. CAS 티켓 검증 로직과 세션 저장소 확인이 필요합니다. 암호화 키 교체 이후 발생한 것으로 보입니다.',
 'https://lms.kongju.ac.kr', '고객사', '박성철', NULL,
 '확인요청(운영서버반영)', '긴급', '시스템개발', 18,
 '2026-09-05', NULL, UNIX_TIMESTAMP('2026-09-05 08:30:00'), UNIX_TIMESTAMP('2026-09-18 19:05:00'), 5, '블루소프트', 0),

-- UI 퍼블리싱 — 난이도 낮은 쪽 (ai_stars 없음 → 규칙 기반으로 낮게 나와야 함)
('R004', '[순천향대] 강의실 목록 화면 정렬 깨짐 및 오탈자 수정',
 '모바일에서 카드 정렬이 깨집니다. 그리고 안내 문구에 오타가 있어 함께 수정 부탁드립니다.',
 'https://lms.sch.ac.kr/my/', '고객사', '김태주', NULL,
 '완료', '일반', '블루소프트', 3,
 '2026-09-08', '2026-09-09', UNIX_TIMESTAMP('2026-09-08 13:00:00'), UNIX_TIMESTAMP('2026-09-09 10:10:00'), NULL, '블루소프트', 0),

-- 마이그레이션 — 가장 어려운 축
('R005', '[한기대] 무들 3.9 → 4.5 업그레이드 데이터 이관 정합성 검증',
 '버전 업그레이드 후 기존 강좌/수강생/성적 데이터 이관 정합성 검증이 필요합니다. 스키마 변경분 확인과 백업 복구 절차도 함께 점검해 주세요. 이관 대상 강좌가 12,000건 규모입니다.',
 'https://dev.lms.koreatech.ac.kr', '고객사', '김호영', NULL,
 '진행중', '긴급', '시스템개발', 24,
 '2026-09-10', NULL, UNIX_TIMESTAMP('2026-09-10 09:00:00'), UNIX_TIMESTAMP('2026-09-25 18:00:00'), NULL, '블루소프트', 0),

-- 담당자가 ba_member 에 없음 → 미매칭으로 빠져야 함
('R006', '[충북대] 수료증 발급 양식 변경',
 '수료증 하단 직인 이미지와 발급 문구를 변경해 주세요.',
 'https://lms.chungbuk.ac.kr', '고객사', '박모름', NULL,
 '완료', '일반', '블루소프트', 2,
 '2026-09-11', '2026-09-15', UNIX_TIMESTAMP('2026-09-11 10:00:00'), UNIX_TIMESTAMP('2026-09-15 16:00:00'), NULL, '블루소프트', 0),

-- 담당자 미지정
('R007', '학사연동 배치 실패 — 수강신청 데이터 누락',
 '야간 학사 연동 배치가 실패해 당일 수강신청 정보가 반영되지 않았습니다. 인터페이스 로그 확인 요청드립니다.',
 '', '고객사', '—', NULL,
 '등록', '긴급', '미지정', 1,
 '2026-09-12', NULL, UNIX_TIMESTAMP('2026-09-12 07:30:00'), UNIX_TIMESTAMP('2026-09-12 07:30:00'), NULL, '블루소프트', 0),

-- 기관 식별 불가 (링크도 제목도 단서 없음)
('R008', '동영상 이어보기 지점이 저장되지 않음',
 '영상 시청 중 이탈 후 재접속하면 처음부터 재생됩니다. 플레이어 시청기록 저장 로직 확인 바랍니다.',
 '', '고객사', '안정민', NULL,
 '운영배포요청', '일반', '시스템개발', 6,
 '2026-09-14', NULL, UNIX_TIMESTAMP('2026-09-14 15:20:00'), UNIX_TIMESTAMP('2026-09-22 11:00:00'), 3, '블루소프트', 0),

-- 보류 / 처리불가
('R009', '[한국기술교육대] 공유대학 학점교류 연계 화면 추가',
 '혁신융합 컨소시엄 학점교류 신청 화면을 추가해 주세요. 타대학 연계 규격 협의가 필요합니다.',
 'https://lms.koreatech.ac.kr', '고객사', '진소현', NULL,
 '보류', '일반', '기획', 9,
 '2026-09-15', NULL, UNIX_TIMESTAMP('2026-09-15 09:00:00'), UNIX_TIMESTAMP('2026-09-20 14:00:00'), NULL, '블루소프트', 0),

('R010', '알림톡 발송 실패 건 재발송 요청',
 'SMS 발송 실패 이력이 있는 건들을 재발송 처리해 주세요.',
 '', '고객사', '김아랑', NULL,
 '처리불가', '일반', '블루소프트', 4,
 '2026-09-16', NULL, UNIX_TIMESTAMP('2026-09-16 10:00:00'), UNIX_TIMESTAMP('2026-09-17 09:00:00'), NULL, '블루소프트', 0),

-- date 가 비어 created 로만 기간 판정되어야 하는 건
('R011', '[공주대] 과제 제출 파일 용량 제한 상향',
 '과제 첨부 파일 용량을 100MB 로 올려 주세요.',
 'https://lms.kongju.ac.kr', '고객사', '김지안', NULL,
 '진행중', '일반', '블루소프트', 1,
 NULL, NULL, UNIX_TIMESTAMP('2026-09-18 11:00:00'), UNIX_TIMESTAMP('2026-09-19 09:00:00'), NULL, '블루소프트', 0),

-- 보관 항목 (include_archived=true 면 들어와야 함)
('R012', '[충북대] 게시판 첨부파일 다운로드 권한 오류',
 '수강생이 자료실 첨부파일을 내려받지 못합니다. 권한 확인 부탁드립니다.',
 'https://lms.chungbuk.ac.kr', '고객사', '김호영', NULL,
 '완료', '일반', '블루소프트', 5,
 '2026-09-02', '2026-09-04', UNIX_TIMESTAMP('2026-09-02 09:00:00'), UNIX_TIMESTAMP('2026-09-04 17:00:00'), 2, '블루소프트', 1),

-- 제목 없음 (skip_empty_title=true 면 빠져야 함)
('R013', '', '슬랙에서 만들다 만 행입니다.', '', '고객사', '김호영', NULL,
 '등록', '일반', '미지정', 0,
 '2026-09-19', NULL, UNIX_TIMESTAMP('2026-09-19 09:00:00'), UNIX_TIMESTAMP('2026-09-19 09:00:00'), NULL, '블루소프트', 0),

-- 와이오즈 보드 (기본 설정에서는 수집 대상 아님)
('R014', '[외부] 와이오즈 리스트 항목 — 수집 대상 아님',
 '협력사 보드입니다. boards 설정에 넣지 않으면 들어오면 안 됩니다.',
 '', '고객사', '외주업체', NULL,
 '진행중', '일반', '와이오즈', 2,
 '2026-09-13', NULL, UNIX_TIMESTAMP('2026-09-13 09:00:00'), UNIX_TIMESTAMP('2026-09-14 09:00:00'), NULL, '와이오즈', 0),

-- 기간 밖 (2026-06) — --from/--to 로 걸러져야 함
('R015', '[순천향대] 지난 분기 통계 화면 개선',
 '이용 현황 대시보드에 월별 추이 차트를 추가해 주세요.',
 'https://lms.sch.ac.kr', '고객사', '안정민', NULL,
 '완료', '일반', '블루소프트', 3,
 '2026-06-10', '2026-06-20', UNIX_TIMESTAMP('2026-06-10 09:00:00'), UNIX_TIMESTAMP('2026-06-20 17:00:00'), NULL, '블루소프트', 0);

-- ---------------------------------------------------------------------
-- 사이트에서 직접 배정한 담당자 — 슬랙의 asg 보다 이쪽이 우선입니다.
-- R007 은 슬랙에서 '—'(미지정)이지만 여기서 배정된 상태입니다.
-- ---------------------------------------------------------------------
INSERT INTO local_assignments (request_id, assignee, assigned_at, assigned_by) VALUES
  ('R007', '박성철', NOW(), '김호영');
