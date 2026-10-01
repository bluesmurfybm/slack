-- =====================================================================
-- BlueAssign 마이그레이션 v3 → v4
--   - ba_domain.category 를 코스모스(무들) 컴포넌트 계열로 교체
--   - 화상강의 분야 신설
--   - 미분류를 줄이기 위한 keywords 보강
--
-- 이미 설치된 환경에만 실행하세요.
-- 새로 설치하는 경우 001/002 에 이미 반영되어 있어 실행할 필요가 없습니다.
--
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/005_migration_domain_category.sql
--
-- 재실행 안전: UPDATE 와 INSERT ... ON DUPLICATE KEY 뿐이라 여러 번 돌려도 됩니다.
-- =====================================================================
--
-- ---------------------------------------------------------------------
-- 왜 바꾸는가
-- ---------------------------------------------------------------------
-- 기존 category 는 backend / frontend / infra / integration / planning 이라는
-- **기술 축**이었습니다. 두 가지 문제가 있었습니다.
--
--   1. 코스모스가 실제로 어떻게 구성돼 있는지와 무관합니다.
--      출석부 수정과 학사연동 배치는 둘 다 backend 지만 손대는 코드가 다릅니다.
--   2. 배정에 쓸 수 없습니다. "이 과업은 backend 다" 로는 누구를 붙일지 못 정합니다.
--
-- 코스모스는 UBION 무들 배포판 계열입니다(로컬 무들 DB 에서 local_ubion,
-- block_ub_* 확인). 무들 컴포넌트 체계를 축으로 쓰면 "어느 플러그인 계열을
-- 다뤘는가" 가 바로 드러나고, 그대로 배정 근거가 됩니다.
--
-- ---------------------------------------------------------------------
-- 실측 근거 (docs/data-quality-report.md, 6개월 / 평가대상 10명)
-- ---------------------------------------------------------------------
--   분야 21개       : (사람×단위) 181칸 중 20건 이상 34칸 (18.8%)
--   기술축 5개      : 49칸 중 27칸 (55.1%)
--   코스모스 계열 8개 : 76칸 중 38칸 (50.0%)  ← 쓸 수 있는 칸의 **절대 수**가 가장 많다
--
-- 비율은 기술축이 약간 높지만, 칸이 많다는 것은 배정을 더 세밀하게 맞출 수
-- 있다는 뜻입니다. 해석 가능성까지 보면 계열 쪽이 낫습니다.
--
-- ---------------------------------------------------------------------
-- category 코드 (영문 코드 + 한글 표시명은 PHP 상수 BA_DOMAIN_CATEGORY 에)
-- ---------------------------------------------------------------------
--   activity       학습활동        mod_*
--   grading        평가·이수       grade*, completion
--   enrolment      사용자·수강     auth_*, enrol_*, user
--   integration    연동·알림       webservice, message_*, local_*
--   presentation   화면·테마       theme_*, block_*
--   administration 운영·관리       admin, tool_*, report_*
--   platform       플랫폼·인프라   서버·DB·배포
--   planning       기획·QA         ← 점수 산출 대상 아님(표본 없음)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- ---------------------------------------------------------------------
-- 1. category 재분류
-- ---------------------------------------------------------------------

UPDATE `ba_domain` SET `category` = 'activity'
 WHERE `code` IN ('attendance', 'assignment', 'quiz', 'ibt', 'board', 'syllabus', 'media');

UPDATE `ba_domain` SET `category` = 'grading'
 WHERE `code` IN ('gradebook', 'certificate');

UPDATE `ba_domain` SET `category` = 'enrolment'
 WHERE `code` IN ('sso', 'academic_sync', 'shared_univ');

UPDATE `ba_domain` SET `category` = 'integration'
 WHERE `code` IN ('external_api', 'notify');

UPDATE `ba_domain` SET `category` = 'presentation'
 WHERE `code` IN ('frontend_ui');

UPDATE `ba_domain` SET `category` = 'administration'
 WHERE `code` IN ('admin', 'analytics');

UPDATE `ba_domain` SET `category` = 'platform'
 WHERE `code` IN ('infra', 'migration', 'security');

UPDATE `ba_domain` SET `category` = 'planning'
 WHERE `code` IN ('planning');

-- ---------------------------------------------------------------------
-- 2. 화상강의 신설
--
-- 미분류 건 표본에서 ZOOM·webex 가 반복해서 나왔는데 어느 분야에도 없었습니다.
-- 무들에도 mod_zoom / mod_webexactivity 가 별도 활동 모듈로 있어
-- '동영상·콘텐츠'(녹화물)와 구분하는 편이 맞습니다.
--   동영상·콘텐츠 = 녹화된 강의, 업로드 콘텐츠, 인코딩
--   화상강의      = 실시간 수업, ZOOM/Webex 연동
-- ---------------------------------------------------------------------
INSERT INTO `ba_domain` (`code`, `name`, `category`, `sort_no`, `is_active`, `keywords`) VALUES
('webconf', '화상강의', 'activity', 65, 1,
 '화상강의,화상수업,실시간강의,실시간수업,비대면강의,라이브강의,원격수업,화상회의,zoom,webex,웹엑스,구글미트,화상연동,수업참여코드')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `category` = VALUES(`category`), `sort_no` = VALUES(`sort_no`);

-- ---------------------------------------------------------------------
-- 3. keywords 보강
--
-- 미분류 10.7% 의 제목을 훑어 확인된 누락입니다.
-- 002_seed_domain.sql 은 keywords 를 덮어쓰지 않으므로(운영 중 튜닝 보존)
-- 여기서 명시적으로 갱신합니다.
-- ---------------------------------------------------------------------

-- '표절률' 이 안 걸렸습니다. 키워드가 '표절검사' 로만 있어서입니다.
UPDATE `ba_domain`
   SET `keywords` = CONCAT(`keywords`, ',표절,표절률,유사도검사')
 WHERE `code` = 'assignment' AND `keywords` NOT LIKE '%,표절,%';

-- 설문 관련 (무들 mod_feedback / mod_survey / mod_choice)
UPDATE `ba_domain`
   SET `keywords` = CONCAT(`keywords`, ',설문,설문조사,투표,만족도조사,피드백조사')
 WHERE `code` = 'board' AND `keywords` NOT LIKE '%,설문,%';

-- 자율강좌·강좌분류 (코스 관리 계열)
UPDATE `ba_domain`
   SET `keywords` = CONCAT(`keywords`, ',자율강좌,강좌분류,카테고리추가,강좌검색,강좌복사,강좌초기화')
 WHERE `code` = 'admin' AND `keywords` NOT LIKE '%,자율강좌,%';

-- 언어팩·다국어 (화면 문구)
UPDATE `ba_domain`
   SET `keywords` = CONCAT(`keywords`, ',언어팩,다국어,영문화,번역,문구수정,텍스트수정,라벨')
 WHERE `code` = 'frontend_ui' AND `keywords` NOT LIKE '%,언어팩,%';

-- ---------------------------------------------------------------------
-- 4. 확인
-- ---------------------------------------------------------------------
-- 아래로 결과를 확인하십시오. category 가 8종이어야 하고,
-- 분야는 22개(기존 21 + 화상강의)여야 합니다.
--
--   SELECT category, COUNT(*) FROM ba_domain WHERE is_active=1 GROUP BY category;
--   SELECT COUNT(*) FROM ba_domain WHERE is_active=1;
