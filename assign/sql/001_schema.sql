-- =====================================================================
-- BlueAssign (iworks `assign` 모듈) — 전체 스키마
-- MySQL 8.0 / utf8mb4 (5.7 호환 범위로 작성)
--
-- 실행:
--   mysql -u <user> -p --default-character-set=utf8mb4 <db> < sql/001_schema.sql
--   그 다음  sql/002_seed_domain.sql
--
-- 재실행 안전 여부:
--   CREATE TABLE IF NOT EXISTS 뿐이라 두 번 실행해도 안전합니다.
--   단, 이미 만들어진 표의 정의를 바꾸지는 않습니다. 정의를 바꿀 때는
--   sql/ 아래에 새 마이그레이션 파일을 추가하고 이 파일에도 반영하십시오.
--
-- 이 스크립트는 ba_ 접두사 표만 만듭니다.
-- 포털 회원 표(portal_users)는 건드리지 않고 조회만 합니다.
-- =====================================================================
--
-- ---------------------------------------------------------------------
-- MySQL 8 전용 문법 사용 여부
-- ---------------------------------------------------------------------
-- 이 파일에는 MySQL 8 전용 문법이 **없습니다.** 전부 5.7 에서도 도는 DDL 입니다.
-- CLAUDE.md 가 "MySQL 8 (5.7 호환 고려)" 를 요구하므로 아래를 일부러 피했습니다.
--
--   * CHECK 제약        — 5.7 은 파싱만 하고 무시합니다. 같은 스크립트가 환경에
--                         따라 다르게 동작하면 안 되므로 쓰지 않았습니다.
--                         범위 검증(difficulty 1~5, progress_pct 0~100,
--                         load_ratio 0.000~1.000, manual_adjust -20~+20)은
--                         BlueCart 가 BC_STATUS 같은 PHP 상수로 하는 방식을 따라
--                         inc/workflow.php 의 BA_* 상수에서 합니다.
--   * 함수 인덱스 / 내림차순 인덱스 / DEFAULT (expression)  — 8.0.13+ 전용
--   * utf8mb4_0900_ai_ci 콜레이션 — 8.0 전용. 기존 표와 같은
--                         utf8mb4_unicode_ci 를 씁니다(조인 시 콜레이션 충돌 방지).
--
-- 조회 쿼리에서 CTE·윈도 함수를 쓰게 되면 그 자리에 주석으로 표시하십시오.
--
-- ---------------------------------------------------------------------
-- 명세서(docs/blueassign-spec.md §3) 대비 조정 내역
--   근거: docs/conventions.md — 기존 모듈(BlueCart)에서 확인된 관례
-- ---------------------------------------------------------------------
--
-- (1) 사용자 참조 컬럼: INT UNSIGNED  →  VARCHAR(64) 이메일 + VARCHAR(80) 이름 스냅샷
--     명세서는 `owner_user_id INT UNSIGNED` 처럼 portal_users.id 를 가리켰습니다.
--     그러나 포털 인증 계층이 넘겨주는 식별자는 **이메일**입니다
--     (bluecart/includes/auth.php 의 'id' => (string)$row['email'],
--      config.iworks.sample.php 의 col_id => 'email').
--     BlueCart 도 requester_id/reviewer_id/assignee_id 를 전부 VARCHAR(64) 이메일로
--     들고 있습니다. 여기에 맞췄습니다.
--     이름(_name)을 함께 저장하는 것도 BlueCart 관례입니다 — 그 시점의 스냅샷이라
--     사람 이름이 바뀌어도 과거 기록이 따라 변하지 않습니다.
--     conventions.md §6.2
--
-- (2) portal_users 로의 FK 는 걸지 않습니다.
--     BlueCart 에 포털 회원 표를 가리키는 FK 가 하나도 없습니다. 같게 둡니다.
--     ba_ 표끼리는 실제 FK 를 겁니다(아래 (3)).
--     conventions.md §6.2
--
-- (3) FK: 논리적 관계가 아니라 **실제 FK 를 겁니다.**
--     BlueCart 가 bc_ 접두사 내부끼리 실제 FK 를 걸고 있습니다
--     (fk_bc_request_category, fk_bc_hist_request, fk_bc_attach_request).
--     삭제 규칙은 관계의 성격에 따라 셋으로 나눴습니다.
--       ON DELETE CASCADE  — 부모가 없으면 뜻이 없는 자식
--                            (프로젝트→출처/태스크, 태스크→진행상황 …)
--       ON DELETE RESTRICT — 지워지면 기록이 깨지는 참조
--                            (사용 중인 분야, 배정된 구성원, 판정 이력 …)
--       ON DELETE SET NULL — 없어도 본체는 성립하는 선택적 참조
--                            (태스크의 출처 문서, 업무이력의 담당자 …)
--     각 제약 옆에 왜 그 규칙인지 적어 두었습니다.
--     conventions.md §6.2 / §6.3
--
-- (4) 공통 컬럼: created_at / updated_at 에 DEFAULT 를 붙였습니다.
--     명세서는 `created_at DATETIME NOT NULL, updated_at DATETIME` 로 DEFAULT 가
--     없었습니다. BlueCart 는 전 표가 아래 형태입니다.
--       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
--       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
--     (포털 core/db.php 의 portal_* 표는 DEFAULT 없이 NULL 허용이라 방식이 다른데,
--      conventions.md §6.2 의 판단대로 BlueCart 쪽을 따릅니다.)
--     명세서에 created_at 이 아예 없던 표(ba_domain, ba_member_skill,
--     ba_member_metric, ba_task_domain, ba_work_item_domain, ba_allocation_item)
--     에도 넣었습니다. 적용 기준:
--       · 사람이 고치는 표          → created_at + updated_at
--       · 한 번 쓰고 안 고치는 표    → created_at 만
--         (판정 스냅샷, 수집 로그, 연결표, 진행상황 기록)
--
-- (5) 인덱스 이름: idx_* / uk  →  ix_* / uk_* / fk_*
--     명세서는 `KEY idx_project`, `UNIQUE KEY uk (...)` 처럼 표 이름이 빠져 있었고
--     같은 이름(`uk`)이 여러 표에 중복됐습니다. BlueCart 형식으로 바꿨습니다.
--       UNIQUE  uk_<표>_<의미>   예) uk_bc_request_no
--       일반    ix_<표>_<의미>   예) ix_bc_request_list
--       FK      fk_<표>_<대상>   예) fk_bc_request_category
--     표 이름이 길면 인덱스명에서 줄입니다(BlueCart 도 bc_request_history →
--     ix_bc_hist_request 로 줄입니다). 이 파일의 줄임말은 아래와 같습니다.
--       ba_project_source    → psrc      ba_member_skill      → mskill
--       ba_task_domain       → tdom      ba_member_metric     → mmetric
--       ba_work_item         → witem     ba_eval_run          → evalrun
--       ba_work_item_domain  → widom     ba_profile_objection → objection
--       ba_allocation        → alloc     ba_allocation_item   → allocitem
--       ba_progress_comment  → pcomment  ba_sync_log          → synclog
--     conventions.md §6.3
--
-- (6) 표 옵션: COLLATE=utf8mb4_unicode_ci 와 한글 COMMENT 를 모두 붙였습니다.
--     명세서는 `DEFAULT CHARSET=utf8mb4` 까지만 적혀 있었습니다.
--     BlueCart 는 예외 없이 아래 형태이고, 모든 표·컬럼에 한글 COMMENT 를 답니다.
--       ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='...'
--     콜레이션을 맞추지 않으면 portal_users 와 조인할 때
--     "Illegal mix of collations" 가 납니다.
--     conventions.md §6.4
--
-- (7) 상태·코드값은 ENUM 이 아니라 VARCHAR + COMMENT 에 가능한 값을 나열합니다.
--     명세서가 이미 VARCHAR 였고 BlueCart 관례와 같아 그대로 뒀습니다. 검증은 PHP 상수.
--     conventions.md §6.4
--
-- (8) 파일 번호가 3자리(001_)입니다.
--     BlueCart 는 2자리(01_schema.sql)입니다. 이번 지시에 맞춰 3자리를 씁니다.
--     conventions.md §9-4 에 미결로 남겨 둔 항목이며, 이 파일로 3자리로 정합니다.
--
-- (9) ba_member.emp_name 을 VARCHAR(50) → VARCHAR(80) 으로 넓혔습니다.
--     BlueCart 의 이름 스냅샷 컬럼이 전부 VARCHAR(80) 입니다. 폭을 맞춰야
--     ba_ 표들 사이에서 이름 컬럼 길이가 갈리지 않습니다.
--
-- (10) 명세서의 `ba_project.owner_user_id` 는 `owner_id` + `owner_name` 으로,
--      `ba_progress_comment.user_id` 는 `user_id` + `user_name` 으로 바꿨습니다.
--      (1) 과 같은 이유이며, `_user_id` 라는 이름이 남아 있으면 정수 PK 로 오해합니다.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';


-- =====================================================================
-- 1. 분야 마스터
--    다른 거의 모든 표가 이걸 가리키므로 가장 먼저 만듭니다.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `ba_domain` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(40)  NOT NULL COMMENT '영문 코드(변경 비권장). 예: attendance, quiz, sso',
  `name`       VARCHAR(80)  NOT NULL COMMENT '화면 표시명. 예: 출석부, 퀴즈·시험',
  -- 코스모스(무들) 컴포넌트 계열. **점수는 분야가 아니라 이 단위로 낸다**
  -- (분야 단위로는 표본이 안 참 — docs/scoring-design.md §1.3).
  `category`   VARCHAR(40)  NULL
               COMMENT 'activity|grading|enrolment|integration|presentation|administration|platform|planning',
  `keywords`   TEXT         NULL COMMENT '매칭용 키워드. 쉼표로 이어 붙인다. 수집기가 업무이력을 이 분야로 가를 때 쓴다',
  `sort_no`    INT          NOT NULL DEFAULT 0 COMMENT '화면 정렬 순서',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0이면 신규 선택 대상에서 제외(기존 기록은 유지)',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_domain_code` (`code`),
  KEY `ix_ba_domain_active` (`is_active`, `sort_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='업무 분야 마스터. 역량·과업·업무이력이 모두 이 축으로 묶인다';


-- =====================================================================
-- 2. 프로젝트 / 과업
-- =====================================================================

-- ---------------------------------------------------------------------
-- 2.1 프로젝트
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_project` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)  NOT NULL COMMENT '표시용 번호. 예: PRJ-2026-001',
  `name`        VARCHAR(200) NOT NULL,
  `summary`     TEXT         NULL COMMENT '프로젝트 개요',
  `client`      VARCHAR(100) NULL COMMENT '고객/기관명',
  `track`       VARCHAR(40)  NULL COMMENT 'lms_b2b|lxp|lxp_hybrid|용역|사내',

  `dev_start`   DATE         NULL COMMENT '개발 시작',
  `dev_end`     DATE         NULL COMMENT '개발 종료',
  `test_start`  DATE         NULL COMMENT '테스트 시작',
  `test_end`    DATE         NULL COMMENT '테스트 종료',
  `deploy_date` DATE         NULL COMMENT '운영서버 배포일',

  `notes`       TEXT         NULL COMMENT '프로젝트 특이점',
  `extra`       TEXT         NULL COMMENT '기타 사항',

  `status`      VARCHAR(20)  NOT NULL DEFAULT 'draft'
                COMMENT 'draft|scoping|allocating|confirmed|running|done|hold',

  -- 조정 (1): 명세서 owner_user_id INT UNSIGNED → 이메일 + 이름 스냅샷
  `owner_id`    VARCHAR(64)  NOT NULL COMMENT 'iworks 사용자 ID(이메일). 프로젝트 담당 PM',
  `owner_name`  VARCHAR(80)  NOT NULL COMMENT '등록 시점 성명 스냅샷',

  -- 소프트 삭제 (003_migration_soft_delete.sql 에서 추가. 새 설치는 여기 이미 들어 있다)
  -- 진짜로 지우면 FK CASCADE 가 태스크·배정·진행기록까지 끌고 가므로 감추기만 한다.
  -- 지워도 code 는 계속 점유된다 — 이미 나간 코드가 다른 프로젝트를 가리키면 안 된다.
  `deleted_at`      DATETIME     NULL COMMENT '소프트 삭제 시각. NULL 이면 살아 있는 프로젝트',
  `deleted_by`      VARCHAR(64)  NULL COMMENT '지운 사람 iworks 사용자 ID(이메일)',
  `deleted_by_name` VARCHAR(80)  NULL COMMENT '지운 시점 성명 스냅샷',
  `delete_reason`   VARCHAR(500) NULL COMMENT '삭제 사유',

  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_project_code` (`code`),
  -- 목록은 거의 항상 "안 지워진 것" 만 본다.
  KEY `ix_ba_project_live`   (`deleted_at`, `status`, `dev_start`),
  -- 지워진 것까지 포함해 훑는 관리자 조회용. 명세서의 idx_status_date 와 같은 축.
  KEY `ix_ba_project_status` (`status`, `dev_start`),
  KEY `ix_ba_project_owner`  (`owner_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='배정 대상 프로젝트';

-- ---------------------------------------------------------------------
-- 2.2 개발 범위 출처 문서
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_project_source` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`       INT UNSIGNED NOT NULL,
  -- image 는 명세서에 없던 값이다. 화면 시안을 사진/캡처로 받는 경우가 있어 P1 에서 추가했다.
  `kind`             VARCHAR(20)  NOT NULL COMMENT 'xlsx|pptx|docx|pdf|image|figma|url|text',
  `title`            VARCHAR(200) NULL,
  `url`              VARCHAR(500) NULL COMMENT '피그마/구글드라이브 등 외부 링크',
  `file_path`        VARCHAR(500) NULL COMMENT '업로드 실경로(웹 루트 바깥)',
  `file_size`        BIGINT UNSIGNED NULL COMMENT '바이트',
  `mime`             VARCHAR(100) NULL,
  `parsed_text`      MEDIUMTEXT   NULL COMMENT '추출 텍스트. WBS 도출 입력',
  `parse_status`     VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|ok|fail|skip',
  `parse_error`      VARCHAR(500) NULL,

  -- 조정 (1)
  `uploaded_by`      VARCHAR(64)  NULL COMMENT 'iworks 사용자 ID(이메일)',
  `uploaded_by_name` VARCHAR(80)  NULL COMMENT '업로드 시점 성명 스냅샷',

  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ba_psrc_project` (`project_id`),
  -- 파싱 대기 건을 배치가 집어 간다.
  KEY `ix_ba_psrc_parse`   (`parse_status`, `id`),
  -- 프로젝트가 지워지면 그 출처 문서도 남을 이유가 없다.
  CONSTRAINT `fk_ba_psrc_project` FOREIGN KEY (`project_id`)
    REFERENCES `ba_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='개발 범위 출처 문서(엑셀·피그마·PPT 등)';

-- ---------------------------------------------------------------------
-- 2.3 태스크 (대/중/소 3계층)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_task` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`   INT UNSIGNED NOT NULL,
  `parent_id`    INT UNSIGNED NULL COMMENT '상위 태스크. depth=1 이면 NULL',
  `depth`        TINYINT      NOT NULL COMMENT '1=대 2=중 3=소',
  `seq`          INT          NOT NULL DEFAULT 0 COMMENT '형제 내 정렬',
  `wbs_no`       VARCHAR(20)  NULL COMMENT '표시용 WBS 번호. 예: 1.2.3',
  `title`        VARCHAR(300) NOT NULL,
  `description`  TEXT         NULL,
  `est_md`       DECIMAL(6,2) NULL COMMENT '추정 공수(M/D)',
  `difficulty`   TINYINT      NULL COMMENT '1~5. 검증은 PHP 상수에서',
  `plan_start`   DATE         NULL,
  `plan_end`     DATE         NULL,
  `source_id`    INT UNSIGNED NULL COMMENT '어느 출처 문서에서 도출됐는지',
  `source_ref`   VARCHAR(200) NULL COMMENT '시트명!셀범위 / 슬라이드 12 / 프레임명',
  `origin`       VARCHAR(10)  NOT NULL DEFAULT 'auto' COMMENT 'auto(LLM 도출)|manual',
  -- CLAUDE.md 의 human-in-the-loop 원칙: confirmed=0 인 태스크는 배정 대상이 될 수 없다.
  -- 이 표에서는 기본값만 보장하고, 실제 차단은 배정 엔진(PHP)에서 한다.
  `confirmed`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '사람이 검토 확정했는지. 0이면 배정 대상 제외',
  `status`       VARCHAR(20)  NOT NULL DEFAULT 'todo'
                 COMMENT 'todo|doing|review|dev_deployed|prod_deployed|done|hold',
  `progress_pct` TINYINT      NOT NULL DEFAULT 0 COMMENT '0~100',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- WBS 트리를 그릴 때의 조회 순서 그대로.
  KEY `ix_ba_task_project` (`project_id`, `depth`, `seq`),
  KEY `ix_ba_task_parent`  (`parent_id`),
  KEY `ix_ba_task_source`  (`source_id`),
  -- 배정 후보를 고를 때 "확정됐고 아직 안 끝난 태스크" 를 자주 센다.
  KEY `ix_ba_task_confirm` (`project_id`, `confirmed`, `status`),

  CONSTRAINT `fk_ba_task_project` FOREIGN KEY (`project_id`)
    REFERENCES `ba_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

  -- 자기 참조. 상위가 지워지면 하위도 지운다.
  --   주의: InnoDB 는 **자기 참조 FK 의 CASCADE 를 여러 단계로 이어서 수행하지 않는다.**
  --   대→중→소 세 단계를 한 번에 지우려면 응용에서 잎(depth=3)부터 지워 올라가야 한다.
  --   프로젝트째로 지우는 경우는 위 fk_ba_task_project 가 전 행을 한 번에 지우므로 문제없다.
  CONSTRAINT `fk_ba_task_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `ba_task` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

  -- 출처 문서를 지워도 이미 도출된 태스크는 살아 있어야 한다(근거 링크만 끊긴다).
  CONSTRAINT `fk_ba_task_source` FOREIGN KEY (`source_id`)
    REFERENCES `ba_project_source` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='WBS 태스크(대/중/소 3계층)';

-- ---------------------------------------------------------------------
-- 2.4 태스크 ↔ 분야
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_task_domain` (
  `task_id`    INT UNSIGNED NOT NULL,
  `domain_id`  INT UNSIGNED NOT NULL,
  `weight`     DECIMAL(4,3) NOT NULL DEFAULT 1.000 COMMENT '이 태스크에서 해당 분야가 차지하는 비중 0.000~1.000',
  -- 연결표라 updated_at 은 두지 않는다(고치는 대신 지우고 다시 넣는다).
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_id`, `domain_id`),
  -- domain_id 로 거꾸로 찾는 조회(이 분야에 걸린 태스크)가 있으므로 따로 건다.
  -- 복합 PK 의 뒷 컬럼은 단독으로 인덱스 구실을 못 한다.
  KEY `ix_ba_tdom_domain` (`domain_id`),
  CONSTRAINT `fk_ba_tdom_task` FOREIGN KEY (`task_id`)
    REFERENCES `ba_task` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  -- 쓰이고 있는 분야는 지우지 못하게 막는다. 쓰지 않으려면 is_active=0 으로 내린다.
  CONSTRAINT `fk_ba_tdom_domain` FOREIGN KEY (`domain_id`)
    REFERENCES `ba_domain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='태스크가 어느 분야에 속하는지(다중)';


-- =====================================================================
-- 3. 구성원 / 역량
-- =====================================================================

-- ---------------------------------------------------------------------
-- 3.1 구성원
--     포털 계정(portal_users)과 1:1. FK 는 걸지 않는다 — 조정 (2).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_member` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- 조정 (1): 명세서 user_id INT UNSIGNED → 이메일
  `user_id`       VARCHAR(64)  NOT NULL COMMENT 'iworks 사용자 ID(이메일). portal_users.email 과 같은 값',
  -- 조정 (9): VARCHAR(50) → VARCHAR(80)
  `emp_name`      VARCHAR(80)  NOT NULL COMMENT '성명',
  `role_label`    VARCHAR(50)  NULL COMMENT '설계·개발 / UI·UX / 인프라 / 기획. 점수 정규화 그룹의 기준',
  `team`          VARCHAR(50)  NULL,
  `career_months` INT          NOT NULL DEFAULT 0 COMMENT '총 경력(개월). HR 입력',
  `join_date`     DATE         NULL,
  `base_capacity` DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT '기본 가용 M/M. 0.50 = 하프',
  `slack_handle`  VARCHAR(80)  NULL,
  `email`         VARCHAR(150) NULL COMMENT '연락용. 로그인 이메일과 다를 수 있다',
  `is_assignable` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0이면 배정 후보에서 제외',

  -- 평가 제외 (004_migration_eval_exclusion.sql 에서 추가. 새 설치는 여기 이미 있다)
  --
  -- is_assignable 과 **다른 개념**이다. 합치지 말 것.
  --   is_assignable = 0  배정 후보로 올리지 않는다 (휴직·퇴사 등)
  --   is_evaluable  = 0  역량 점수를 내지 않는다   (이 데이터로 평가할 수 없는 직무)
  -- 기획 담당자는 슬랙 취합 데이터가 없어 점수를 못 내지만 기획 과업에는
  -- 배정되어야 한다 → is_evaluable=0, is_assignable=1.
  --
  -- 화면에서 '표본 부족(insufficient_data)' 과 반드시 구분해 표시할 것.
  -- "데이터가 모자랍니다" 와 "평가 대상이 아닙니다" 는 다른 말이다(CLAUDE.md).
  `is_evaluable`        TINYINT(1)   NOT NULL DEFAULT 1
                        COMMENT '0이면 역량 점수를 내지 않는다. 배정 가능 여부와는 별개',
  `eval_exclude_reason` VARCHAR(200) NULL
                        COMMENT '평가 제외 사유. 본인에게 보여줄 문구이므로 반드시 채운다',
  `eval_excluded_at`    DATETIME     NULL COMMENT '평가 제외로 바꾼 시각',
  `eval_excluded_by`    VARCHAR(64)  NULL COMMENT '평가 제외로 바꾼 사람(이메일)',

  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_member_user` (`user_id`),
  -- 후보 리스트가 (배정 가능 ∩ 역할) 로 먼저 거른다.
  KEY `ix_ba_member_assignable` (`is_assignable`, `role_label`),
  -- 점수 산출 배치는 (평가 대상 ∩ 역할) 로 훑는다.
  KEY `ix_ba_member_evaluable`  (`is_evaluable`, `role_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='배정 대상 구성원. 포털 계정과 1:1(FK 없음)';

-- ---------------------------------------------------------------------
-- 3.2 판정 실행 이력
--     역량 스냅샷(ba_member_skill / ba_member_metric)이 이걸 가리키므로 먼저 만든다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_eval_run` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '곧 eval_ver. 역량 스냅샷의 버전 번호',
  `started_at`  DATETIME     NULL,
  `finished_at` DATETIME     NULL,
  `period_from` DATE         NULL COMMENT '집계 대상 기간 시작',
  `period_to`   DATE         NULL COMMENT '집계 대상 기간 끝',
  `source_stat` TEXT         NULL COMMENT '원천별 건수(JSON). 예: {"slack":1240,"gmail":880}',
  `formula_ver` VARCHAR(20)  NULL COMMENT '점수식 버전. 예: v1.0',
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'running' COMMENT 'running|ok|fail',
  `note`        VARCHAR(300) NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 화면이 "가장 최근 성공한 판정" 을 자주 찾는다.
  KEY `ix_ba_evalrun_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='역량 재판정 실행 이력. id 가 곧 역량 스냅샷의 버전';

-- ---------------------------------------------------------------------
-- 3.3 구성원 분야별 역량 (판정 회차마다 한 벌씩 쌓인다)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_member_skill` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`    INT UNSIGNED NOT NULL,
  `domain_id`    INT UNSIGNED NOT NULL,
  `eval_ver`     INT UNSIGNED NOT NULL COMMENT 'ba_eval_run.id',
  `case_count`   INT          NOT NULL DEFAULT 0 COMMENT '해당 분야 처리 건수',
  `weighted_qty` DECIMAL(8,2) NULL COMMENT '난이도 가중 처리량',
  `avg_lead_hr`  DECIMAL(8,2) NULL COMMENT '평균 리드타임(시간)',
  `rework_rate`  DECIMAL(5,4) NULL COMMENT '재작업/재오픈 비율 0.0000~1.0000',
  `score`        DECIMAL(5,2) NULL COMMENT '0~100 정규화 분야 역량 점수. 역할(role_label) 그룹 내 정규화',
  `is_primary`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '주요 분야 여부',
  -- CLAUDE.md: 표본이 부족한 구성원은 낮은 점수 대신 이 플래그를 세운다(건수 < 20).
  -- 명세서 §3.2 에는 없던 컬럼이지만 CLAUDE.md 가 강제하는 규칙이라 넣었다.
  -- 이 값이 1이면 화면은 점수 대신 "표본 부족" 으로 표시해야 한다.
  `insufficient_data` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1이면 표본 부족. 점수를 그대로 노출하지 않는다',
  -- 한 번 쓰고 고치지 않는 스냅샷이라 updated_at 없음 — 조정 (4)
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_mskill_ver` (`member_id`, `domain_id`, `eval_ver`),
  -- "이 분야를 잘하는 사람" 을 뽑는 조회. 후보 리스트의 주 경로다.
  KEY `ix_ba_mskill_domain` (`domain_id`, `eval_ver`, `score`),
  KEY `ix_ba_mskill_eval`   (`eval_ver`),
  CONSTRAINT `fk_ba_mskill_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_mskill_domain` FOREIGN KEY (`domain_id`)
    REFERENCES `ba_domain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  -- 판정 회차를 지우면 그 회차 점수의 근거가 사라진다. 지우지 못하게 막는다.
  CONSTRAINT `fk_ba_mskill_eval` FOREIGN KEY (`eval_ver`)
    REFERENCES `ba_eval_run` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='구성원 분야별 역량 스냅샷. 판정 회차(eval_ver)마다 보존';

-- ---------------------------------------------------------------------
-- 3.3-b 구성원 계열별 역량 점수 (006_migration_category_score.sql 에서 추가)
--
-- ba_member_skill 은 (구성원 × **분야**) 인데, 점수는 **계열 단위**로 냅니다.
-- 분야 22개로는 (사람×분야) 칸의 82%가 표본 부족으로 버려지기 때문입니다
-- (docs/scoring-design.md §1.3). 그래서 표를 나눕니다.
--
--   ba_member_skill     분야별 처리 실적 → 근거 표시용. score 는 채우지 않는다
--   ba_member_category  계열별 역량 점수 → 레이더 차트 + 배정 엔진
--
-- 점수는 절대 기준입니다. score = min(100, weighted_qty / baseline * 100)
-- baseline(분모)은 회차마다 달라지므로 그때 쓴 값을 행에 함께 저장합니다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_member_category` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`    INT UNSIGNED NOT NULL,
  `category`     VARCHAR(40)  NOT NULL COMMENT 'ba_domain.category. BA_DOMAIN_CATEGORY 의 열쇠',
  `eval_ver`     INT UNSIGNED NOT NULL COMMENT 'ba_eval_run.id',
  `case_count`   INT          NOT NULL DEFAULT 0 COMMENT '그 계열 처리 건수',
  `weighted_qty` DECIMAL(8,2) NULL COMMENT '난이도 가중 처리량 = Σ(건별 난이도)',
  `baseline`     DECIMAL(8,2) NULL COMMENT '그때 쓴 기준값(상위 25% 지점). 점수의 분모',
  `score`        DECIMAL(5,2) NULL COMMENT '0~100',
  `confidence`   VARCHAR(10)  NOT NULL DEFAULT 'none' COMMENT 'full(20건+)|partial(10~19)|none(<10)',
  `insufficient_data` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1이면 점수를 그대로 노출하지 않는다',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_mcat_ver` (`member_id`, `category`, `eval_ver`),
  KEY `ix_ba_mcat_cat`  (`category`, `eval_ver`, `score`),
  KEY `ix_ba_mcat_eval` (`eval_ver`),
  CONSTRAINT `fk_ba_mcat_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_mcat_eval` FOREIGN KEY (`eval_ver`)
    REFERENCES `ba_eval_run` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='구성원 계열별 역량 점수 스냅샷. 판정 회차마다 보존';

-- ---------------------------------------------------------------------
-- 3.4 구성원 종합 지표
--     CLAUDE.md: 이 값들로 **전체 랭킹 화면을 만들지 않는다.**
--     특정 과업 기준 적합도 계산의 입력으로만 쓴다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_member_metric` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`     INT UNSIGNED NOT NULL,
  `eval_ver`      INT UNSIGNED NOT NULL COMMENT 'ba_eval_run.id',
  `period_from`   DATE         NULL,
  `period_to`     DATE         NULL,
  `total_cases`   INT          NOT NULL DEFAULT 0,
  `cap_score`     DECIMAL(5,2) NULL COMMENT '개발 역량(난이도·처리량·속도·재작업)',
  `speed_score`   DECIMAL(5,2) NULL COMMENT '처리 속도',
  `comm_score`    DECIMAL(5,2) NULL COMMENT '소통·분석 역량',
  `breadth_score` DECIMAL(5,2) NULL COMMENT '분야 커버리지',
  `career_score`  DECIMAL(5,2) NULL COMMENT '경력 환산',
  `manual_adjust` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '관리자 보정치 -20.00~+20.00. 범위 검증은 PHP',
  `adjust_reason` VARCHAR(300) NULL COMMENT '보정 사유. 보정했으면 반드시 채운다',
  `insufficient_data` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1이면 표본 부족(건수 < 20)',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_mmetric_ver` (`member_id`, `eval_ver`),
  KEY `ix_ba_mmetric_eval` (`eval_ver`),
  CONSTRAINT `fk_ba_mmetric_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_mmetric_eval` FOREIGN KEY (`eval_ver`)
    REFERENCES `ba_eval_run` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='구성원 종합 지표 스냅샷. 전체 랭킹 화면 용도로 쓰지 않는다';


-- =====================================================================
-- 4. 원천 업무 이력 (수집기가 적재)
-- =====================================================================

-- ---------------------------------------------------------------------
-- 4.1 업무 이력
--     CLAUDE.md: 모든 점수는 이 표의 행으로 역추적 가능해야 한다.
--     source_url 을 비우지 말 것 — 근거 링크가 없으면 이의 제기에 답할 수 없다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_work_item` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`           VARCHAR(10)  NOT NULL COMMENT 'slack|gmail',
  `source_key`       VARCHAR(120) NOT NULL COMMENT '원천 고유키. 중복 적재 방지용',
  `source_url`       VARCHAR(500) NULL COMMENT '원천 링크. 점수 역추적의 근거',
  `title`            VARCHAR(400) NULL,
  `body_excerpt`     TEXT         NULL,
  `org_name`         VARCHAR(100) NULL COMMENT '기관/대학명',
  `member_id`        INT UNSIGNED NULL COMMENT '처리 담당자. 매칭 실패 시 NULL',
  `requested_at`     DATETIME     NULL,
  `first_reply_at`   DATETIME     NULL,
  `dev_deployed_at`  DATETIME     NULL,
  `prod_deployed_at` DATETIME     NULL,
  `closed_at`        DATETIME     NULL,
  `status_raw`       VARCHAR(60)  NULL COMMENT '원문 상태. 예: 확인요청(개발서버반영)',
  `reopen_count`     TINYINT      NOT NULL DEFAULT 0,
  `msg_count`        INT          NOT NULL DEFAULT 0 COMMENT '스레드 왕복 수',
  `body_len`         INT          NOT NULL DEFAULT 0,
  `difficulty`       TINYINT      NULL COMMENT '1~5',
  `difficulty_by`    VARCHAR(10)  NULL COMMENT 'rule|llm|manual',
  `collected_at`     DATETIME     NULL COMMENT '수집기가 넣은 시각',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 수집기가 ON DUPLICATE KEY UPDATE 로 재적재하는 기준.
  UNIQUE KEY `uk_ba_witem_source` (`source`, `source_key`),
  -- 구성원별 기간 집계(역량 판정의 주 경로).
  KEY `ix_ba_witem_member` (`member_id`, `closed_at`),
  -- 구성원 없이 기간 전체를 훑는 집계도 있다(§4 정규화 모집단).
  KEY `ix_ba_witem_closed` (`closed_at`),
  -- 담당자를 못 찾은 건을 나중에 손으로 이어 붙인다.
  KEY `ix_ba_witem_org`    (`org_name`),
  -- 구성원을 지워도 수집된 사실 자체는 남긴다(근거 보존). 담당자만 끊는다.
  CONSTRAINT `fk_ba_witem_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='슬랙/지메일에서 수집한 원천 업무 이력. 모든 점수의 근거';

-- ---------------------------------------------------------------------
-- 4.2 업무 이력 ↔ 분야
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_work_item_domain` (
  `work_item_id` BIGINT UNSIGNED NOT NULL,
  `domain_id`    INT UNSIGNED    NOT NULL,
  `confidence`   DECIMAL(4,3)    NOT NULL DEFAULT 1.000 COMMENT '키워드 매칭 신뢰도 0.000~1.000',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`work_item_id`, `domain_id`),
  KEY `ix_ba_widom_domain` (`domain_id`),
  CONSTRAINT `fk_ba_widom_item` FOREIGN KEY (`work_item_id`)
    REFERENCES `ba_work_item` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_widom_domain` FOREIGN KEY (`domain_id`)
    REFERENCES `ba_domain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='업무 이력이 어느 분야인지(다중). ba_domain.keywords 매칭 결과';

-- ---------------------------------------------------------------------
-- 4.3 수집 실행 로그
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_sync_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`      VARCHAR(10)  NOT NULL COMMENT 'slack|gmail',
  `started_at`  DATETIME     NULL,
  `finished_at` DATETIME     NULL,
  `fetched`     INT          NOT NULL DEFAULT 0,
  `inserted`    INT          NOT NULL DEFAULT 0,
  `updated`     INT          NOT NULL DEFAULT 0,
  `skipped`     INT          NOT NULL DEFAULT 0,
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'running' COMMENT 'running|ok|fail',
  `message`     TEXT         NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- "이 원천을 마지막으로 언제 어디까지 긁었나" 조회.
  KEY `ix_ba_synclog_src` (`source`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='수집 배치 실행 로그';

-- ---------------------------------------------------------------------
-- 4.4 프로파일 이의 제기
--     CLAUDE.md: 본인은 자기 프로파일을 항상 열람할 수 있고 이의를 제기할 수 있다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_profile_objection` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`        INT UNSIGNED NOT NULL COMMENT '이의를 낸 본인',
  `eval_ver`         INT UNSIGNED NULL COMMENT '어느 판정 회차에 대한 이의인지',
  `domain_id`        INT UNSIGNED NULL COMMENT '특정 분야에 대한 이의면 채운다. 종합이면 NULL',
  `content`          TEXT         NOT NULL,
  `status`           VARCHAR(20)  NOT NULL DEFAULT 'open' COMMENT 'open|reviewed|applied|rejected',

  -- 조정 (1)
  `reviewed_by`      VARCHAR(64)  NULL COMMENT 'iworks 사용자 ID(이메일)',
  `reviewed_by_name` VARCHAR(80)  NULL COMMENT '검토 시점 성명 스냅샷',
  `reviewed_at`      DATETIME     NULL,
  `review_note`      TEXT         NULL,

  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ba_objection_member` (`member_id`, `id`),
  -- 관리자 화면이 미처리(open) 건을 먼저 본다.
  KEY `ix_ba_objection_status` (`status`, `id`),
  KEY `ix_ba_objection_domain` (`domain_id`),
  KEY `ix_ba_objection_eval`   (`eval_ver`),
  CONSTRAINT `fk_ba_objection_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  -- 판정 회차나 분야가 정리돼도 이의 제기 기록 자체는 남긴다.
  CONSTRAINT `fk_ba_objection_eval` FOREIGN KEY (`eval_ver`)
    REFERENCES `ba_eval_run` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_objection_domain` FOREIGN KEY (`domain_id`)
    REFERENCES `ba_domain` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='구성원 프로파일 이의 제기';


-- =====================================================================
-- 5. 배정 / 진행
-- =====================================================================

-- ---------------------------------------------------------------------
-- 5.1 기간별 점유 (가용도 산출 입력)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_workload` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`  INT UNSIGNED NOT NULL,
  `kind`       VARCHAR(10)  NOT NULL COMMENT 'assigned(모듈 내 확정)|inferred(슬랙·메일 추정)|manual(휴가·교육 등)',
  -- ref_type/ref_id 는 가리키는 표가 그때그때 달라(ba_allocation_item, ba_work_item …)
  -- FK 를 걸 수 없다. 참조 무결성은 응용에서 지킨다. 명세서와 같음.
  `ref_type`   VARCHAR(20)  NULL COMMENT '참조 대상 종류. allocation_item|work_item 등',
  `ref_id`     BIGINT UNSIGNED NULL COMMENT '참조 대상 id. FK 아님(대상 표가 가변)',
  `label`      VARCHAR(200) NULL COMMENT '화면 표시용. 예: OO대 LXP 고도화 / 하계휴가',
  `start_date` DATE         NOT NULL,
  `end_date`   DATE         NOT NULL,
  `load_ratio` DECIMAL(4,3) NOT NULL COMMENT '점유율 0.000~1.000',
  `confidence` DECIMAL(4,3) NOT NULL DEFAULT 1.000 COMMENT 'kind=inferred 일 때의 신뢰도',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 가용도 계산이 (사람, 기간 겹침) 으로만 조회한다.
  KEY `ix_ba_workload_period` (`member_id`, `start_date`, `end_date`),
  KEY `ix_ba_workload_ref`    (`ref_type`, `ref_id`),
  -- 점유 기록은 배정·수집에서 다시 만들어낼 수 있는 파생 데이터라 같이 지운다.
  CONSTRAINT `fk_ba_workload_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='구성원 기간별 점유. 참여 가능도 산출 입력';

-- ---------------------------------------------------------------------
-- 5.2 배정안 (제안 → 조정 → 확정)
--     CLAUDE.md: 배정안은 항상 "제안" 이다. status='confirmed' 로 가는 단계를
--     코드에서 건너뛸 수 없어야 한다. 이 표는 confirmed_by/confirmed_at 이
--     같이 채워지도록 응용에서 강제한다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_allocation` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`        INT UNSIGNED NOT NULL,
  `version`           INT          NOT NULL DEFAULT 1 COMMENT '프로젝트 내 배정안 회차',
  `status`            VARCHAR(20)  NOT NULL DEFAULT 'proposed' COMMENT 'proposed|adjusted|confirmed|archived',
  `engine_ver`        VARCHAR(20)  NULL COMMENT '배정 엔진 버전',
  `eval_ver`          INT UNSIGNED NULL COMMENT '사용된 역량 스냅샷(ba_eval_run.id)',
  `params_json`       TEXT         NULL COMMENT '가중치/제약 조건(JSON)',

  -- 조정 (1)
  `created_by`        VARCHAR(64)  NULL COMMENT 'iworks 사용자 ID(이메일)',
  `created_by_name`   VARCHAR(80)  NULL COMMENT '생성 시점 성명 스냅샷',
  `confirmed_by`      VARCHAR(64)  NULL COMMENT '확정한 사람. status=confirmed 면 반드시 채운다',
  `confirmed_by_name` VARCHAR(80)  NULL,
  `confirmed_at`      DATETIME     NULL,

  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ba_alloc_ver` (`project_id`, `version`),
  -- 대시보드가 프로젝트의 확정 배정안을 찾는다.
  KEY `ix_ba_alloc_status` (`project_id`, `status`),
  KEY `ix_ba_alloc_eval`   (`eval_ver`),
  CONSTRAINT `fk_ba_alloc_project` FOREIGN KEY (`project_id`)
    REFERENCES `ba_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  -- 판정 회차가 정리돼도 배정안 기록은 남긴다(어떤 스냅샷을 썼는지만 흐려진다).
  CONSTRAINT `fk_ba_alloc_eval` FOREIGN KEY (`eval_ver`)
    REFERENCES `ba_eval_run` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='프로젝트 배정안. 버전 관리';

-- ---------------------------------------------------------------------
-- 5.3 배정안 항목
--     CLAUDE.md: 모든 점수는 근거로 역추적 가능해야 한다.
--     reason_json 에 evidence(ba_work_item 링크)를 반드시 담는다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_allocation_item` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `allocation_id` INT UNSIGNED NOT NULL,
  `task_id`       INT UNSIGNED NOT NULL,
  `member_id`     INT UNSIGNED NOT NULL,
  `role`          VARCHAR(20)  NOT NULL DEFAULT 'owner' COMMENT 'owner|support|reviewer',
  `alloc_ratio`   DECIMAL(4,3) NOT NULL DEFAULT 1.000 COMMENT '이 사람이 이 태스크에 쏟는 비율',
  `fit_score`     DECIMAL(5,2) NULL COMMENT '적합도 0~100',
  `reason_json`   TEXT         NULL COMMENT '산출 근거(JSON). {"domain_fit":82,"availability":65,"evidence":[...]}',
  `is_manual`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '사람이 손으로 바꾼 항목',
  `manual_note`   VARCHAR(300) NULL COMMENT '손으로 바꿨으면 사유',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 같은 배정안에서 한 사람이 한 태스크에 같은 역할로 두 번 들어갈 수 없다.
  -- (명세서에 없던 제약. 조정 화면에서 중복 추가를 막지 못하면 공수가 이중 계산된다.)
  UNIQUE KEY `uk_ba_allocitem_one` (`allocation_id`, `task_id`, `member_id`, `role`),
  KEY `ix_ba_allocitem_task`   (`task_id`),
  KEY `ix_ba_allocitem_member` (`member_id`),
  CONSTRAINT `fk_ba_allocitem_alloc` FOREIGN KEY (`allocation_id`)
    REFERENCES `ba_allocation` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_allocitem_task` FOREIGN KEY (`task_id`)
    REFERENCES `ba_task` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  -- 배정받은 사람은 지우지 못하게 막는다. 빼려면 is_assignable=0 으로 내린다.
  CONSTRAINT `fk_ba_allocitem_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='배정안 항목. 태스크 × 구성원';

-- ---------------------------------------------------------------------
-- 5.4 진행상황 (개발자 본인 등록)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_progress` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`      INT UNSIGNED NOT NULL,
  `member_id`    INT UNSIGNED NOT NULL COMMENT '등록한 본인',
  `status`       VARCHAR(20)  NULL COMMENT 'ba_task.status 와 같은 체계',
  `progress_pct` TINYINT      NULL COMMENT '0~100',
  `content`      TEXT         NULL,
  `blocker`      TEXT         NULL COMMENT '이슈/블로커',
  `worked_on`    DATE         NULL COMMENT '작업한 날',
  -- 기록이라 고치지 않는다 — 조정 (4)
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ba_progress_task`   (`task_id`, `created_at`),
  -- 대시보드가 사람별 최근 진행을 모은다.
  KEY `ix_ba_progress_member` (`member_id`, `created_at`),
  CONSTRAINT `fk_ba_progress_task` FOREIGN KEY (`task_id`)
    REFERENCES `ba_task` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_progress_member` FOREIGN KEY (`member_id`)
    REFERENCES `ba_member` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='태스크 진행상황 기록';

-- ---------------------------------------------------------------------
-- 5.5 진행상황 댓글
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ba_progress_comment` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `progress_id` BIGINT UNSIGNED NOT NULL,
  -- 조정 (1)/(10): 구성원이 아닌 사람(PM 등)도 달 수 있어 ba_member 가 아니라 포털 계정을 쓴다.
  `user_id`     VARCHAR(64) NOT NULL COMMENT 'iworks 사용자 ID(이메일)',
  `user_name`   VARCHAR(80) NOT NULL COMMENT '작성 시점 성명 스냅샷',
  `content`     TEXT        NULL,
  `created_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ba_pcomment_progress` (`progress_id`, `id`),
  CONSTRAINT `fk_ba_pcomment_progress` FOREIGN KEY (`progress_id`)
    REFERENCES `ba_progress` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='진행상황 댓글';
