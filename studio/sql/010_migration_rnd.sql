-- =====================================================================
-- 010. R&D 과제 확장 (P9)  — bs_project 컬럼 + bs_rnd_* 표 4개
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/010_migration_rnd.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/010_migration_rnd.sql
--
--   되돌리기: 010_rollback_rnd.sql
--
-- charset 을 지정하지 않으면 아래 한글 COMMENT 가 깨진 채로 들어갑니다.
-- 스키마는 멀쩡해 보이는데 주석만 ???? 가 되므로 눈치채기 어렵습니다.
--
-- 여러 번 실행하면 ALTER 가 'Duplicate column' 으로 멈춥니다. 컬럼 존재
-- 여부를 조건으로 거는 문법이 MySQL 5.7 에 없습니다(003·008 과 같은 사정).
-- 이미 적용했다면 롤백을 먼저 돌리십시오.
-- =====================================================================
--
-- 왜 필요한가
--
--   지금까지 bs_project 는 "사업·유지보수에서 내려온 지시형 과업" 하나만
--   담았습니다. P9 에서 **구성원이 스스로 발의하는 R&D 과제**가 들어옵니다.
--   둘은 생명주기가 다릅니다.
--
--     프로젝트  등록 → 참여가능 개발자 도출 → 배정안 산출·확정 → 대시보드
--     R&D 과제  발의 → 승인 → 보드 공개 → 합류 → 산출물 축적 → 가용도 반영
--
--   표를 나누지 않고 bs_project 에 얹는 이유는, 둘 다 '기간을 가진 일감'
--   이고 가용도·점유·진행상황을 같은 방식으로 다루기 때문입니다. 표를
--   쪼개면 bs_workload·bs_progress 가 두 갈래를 따로 알아야 합니다.
--
-- 기존 행은 건드리지 않습니다
--
--   project_type DEFAULT 'project', visibility DEFAULT 'private' 이므로
--   **데이터 보정 스크립트가 필요 없습니다.** ALTER 가 기존 행에 기본값을
--   채워 넣고, 그 값이 곧 지금까지의 의미입니다.
--
-- !! 적용 뒤에 반드시 할 일 !!
--
--   bs_project 를 조회하는 **기존 쿼리에 project_type='project' 필터가
--   필요해집니다.** 빠뜨리면 R&D 과제가 프로젝트 목록과 배정 대상에
--   섞입니다. 이 마이그레이션만으로는 끝나지 않습니다.
--   손봐야 할 지점은 docs/rnd-query-impact.md 에 정리해 두었습니다.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- ---------------------------------------------------------------------
-- 1) bs_project — R&D 과제용 칸
--
-- 사람을 가리키는 칸은 **이메일 + 이름 스냅샷**입니다. owner_id/owner_name,
-- deleted_by/deleted_by_name 과 같은 방식입니다. 명세서 3.1 은 이 자리를
-- INT UNSIGNED 로 적어 두었으나, 실제 표는 1차 구현 때부터 이메일을 쓰고
-- 있고 portal_users 에는 FK 를 걸지 않습니다(CLAUDE.md). 명세서 쪽이
-- 낡았습니다.
-- ---------------------------------------------------------------------
ALTER TABLE `bs_project`
  ADD COLUMN `project_type`   VARCHAR(20)  NOT NULL DEFAULT 'project'
      COMMENT '과업 유형. project=지시형 프로젝트 | rnd=자발형 R&D 과제'
      AFTER `code`,
  ADD COLUMN `visibility`     VARCHAR(20)  NOT NULL DEFAULT 'private'
      COMMENT '공개 범위. private=참여자만 | open=사내 열람+합류 가능 | public=사내 전체 열람'
      AFTER `project_type`,
  ADD COLUMN `rnd_category`   VARCHAR(30)  NULL
      COMMENT 'R&D 과제 갈래. poc|enhance|new_module|research. project 에서는 NULL'
      AFTER `track`,
  ADD COLUMN `proposer_id`    VARCHAR(64)  NULL
      COMMENT '발의자 iworks 사용자 ID(이메일). project 에서는 NULL'
      AFTER `owner_name`,
  ADD COLUMN `proposer_name`  VARCHAR(80)  NULL
      COMMENT '발의 시점 성명 스냅샷'
      AFTER `proposer_id`,
  ADD COLUMN `approved_by`    VARCHAR(64)  NULL
      COMMENT '승인한 사람 iworks 사용자 ID(이메일). 승인 전에는 NULL'
      AFTER `proposer_name`,
  ADD COLUMN `approved_by_name` VARCHAR(80) NULL
      COMMENT '승인 시점 성명 스냅샷'
      AFTER `approved_by`,
  ADD COLUMN `approved_at`    DATETIME     NULL
      COMMENT '승인 시각. NULL 이면 미승인 — 점유를 bs_workload 에 올리지 않는다',
  ADD COLUMN `load_cap`       DECIMAL(4,3) NULL
      COMMENT '이 과제 하나가 가져갈 수 있는 1인 점유 상한(0.000~1.000). NULL 이면 전역 설정값',
  ADD COLUMN `recruiting`     TINYINT(1)   NOT NULL DEFAULT 0
      COMMENT '1 이면 보드에서 팀원을 모집 중. 0 이면 합류 신청을 받지 않는다';

-- 목록은 거의 항상 "한 유형만" 본다. 유형을 맨 앞에 세운 인덱스를 둔다.
-- 기존 ix_bs_project_live(deleted_at, status, dev_start) 는 그대로 남긴다 —
-- 지워진 것까지 훑는 관리자 조회가 그쪽을 쓴다.
--
-- 이름은 uk_/ix_ 관례를 따랐다. 명세서 3.1 의 `idx_type_status` 는 이
-- 저장소의 다른 인덱스와 접두사가 어긋난다.
ALTER TABLE `bs_project`
  ADD KEY `ix_bs_project_type_status` (`project_type`, `status`, `dev_start`);

-- ---------------------------------------------------------------------
-- 2) bs_rnd_member — 누가 어느 과제에 얼마만큼 참여하는가
--
-- **승인 전에는 가용도에 반영하지 않습니다.** status='approved' 이고
-- approved_at 이 찬 행만 bs_workload 로 올립니다(CLAUDE.md 2항).
-- 자동 승인 경로를 만들지 마십시오.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_rnd_member` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `project_id`     INT UNSIGNED  NOT NULL                COMMENT 'bs_project.id. project_type=rnd 인 행만 온다',
  `member_id`      INT UNSIGNED  NOT NULL                COMMENT 'bs_member.id. 참여하는 구성원',
  `role`           VARCHAR(20)   NOT NULL DEFAULT 'member' COMMENT '과제 내 역할. lead=주도 | member=참여',
  `load_ratio`     DECIMAL(4,3)  NOT NULL                COMMENT '이 과제에 쓰는 점유 비율(0.000~1.000). 승인돼야 가용도에 반영된다',
  `status`         VARCHAR(20)   NOT NULL DEFAULT 'requested'
                   COMMENT '합류 상태. requested=신청 | approved=승인 | rejected=반려 | left=중도이탈 | done=종료',
  `join_reason`    VARCHAR(500)  NULL                    COMMENT '왜 합류하려 하는가. 신청 시 필수(응용에서 강제)',
  `reject_reason`  VARCHAR(500)  NULL                    COMMENT '반려 사유. status=rejected 면 채운다',
  `approved_by`    VARCHAR(64)   NULL                    COMMENT '승인·반려한 사람 iworks 사용자 ID(이메일)',
  `approved_by_name` VARCHAR(80) NULL                    COMMENT '승인 시점 성명 스냅샷',
  `approved_at`    DATETIME      NULL                    COMMENT '승인 시각. NULL 이면 bs_workload 적재 금지',
  `joined_at`      DATETIME      NULL                    COMMENT '실제 합류 시각',
  `left_at`        DATETIME      NULL                    COMMENT '이탈·종료 시각',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bs_rndmember_one` (`project_id`, `member_id`),
  KEY `ix_bs_rndmember_member` (`member_id`, `status`),
  KEY `ix_bs_rndmember_project` (`project_id`, `status`),
  CONSTRAINT `fk_bs_rndmember_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_rndmember_member` FOREIGN KEY (`member_id`)
    REFERENCES `bs_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='R&D 과제 참여자. 승인된 행만 가용도에 반영된다';

-- ---------------------------------------------------------------------
-- 3) bs_rnd_output — 산출물
--
-- 종료 시 **1건 이상 필수**입니다. 없으면 dropped 로 처리하고 역량 지표에
-- 반영하지 않습니다(CLAUDE.md 2항).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_rnd_output` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `project_id`     INT UNSIGNED  NOT NULL                COMMENT 'bs_project.id',
  `kind`           VARCHAR(20)   NOT NULL DEFAULT 'doc'
                   COMMENT '산출물 갈래. doc=문서 | repo=저장소 | demo=시연 | report=보고서 | module=모듈 | slide=발표자료',
  `title`          VARCHAR(300)  NOT NULL                COMMENT '산출물 제목',
  `url`            VARCHAR(500)  NULL                    COMMENT '외부 링크. http:// 또는 https:// 만 받는다',
  `file_path`      VARCHAR(500)  NULL                    COMMENT '업로드 실경로(웹 루트 바깥). bs_project_source 와 같은 방식',
  `summary`        TEXT          NULL                    COMMENT '무엇을 만들었는지 요약',
  `created_by`     VARCHAR(64)   NULL                    COMMENT '등록한 사람 iworks 사용자 ID(이메일)',
  `created_by_name` VARCHAR(80)  NULL                    COMMENT '등록 시점 성명 스냅샷',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bs_rndoutput_project` (`project_id`, `created_at`),
  CONSTRAINT `fk_bs_rndoutput_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='R&D 과제 산출물. 종료하려면 1건 이상 있어야 한다';

-- ---------------------------------------------------------------------
-- 4) bs_rnd_log — 진행 기록
--
-- **4주 공백이면 정체로 표시하고 workload confidence 를 0.5 로 내립니다**
-- (CLAUDE.md 2항). 그 판정이 이 표의 created_at 을 봅니다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_rnd_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`     INT UNSIGNED  NOT NULL                COMMENT 'bs_project.id',
  `member_id`      INT UNSIGNED  NOT NULL                COMMENT 'bs_member.id. 기록을 남긴 사람',
  `content`        TEXT          NOT NULL                COMMENT '무엇을 했는가',
  `finding`        TEXT          NULL                    COMMENT '알아낸 것. 과제의 값어치는 대개 여기에 쌓인다',
  `worked_on`      DATE          NULL                    COMMENT '실제 작업한 날. 기록일과 다를 수 있다',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bs_rndlog_project_time` (`project_id`, `created_at`),
  KEY `ix_bs_rndlog_member` (`member_id`, `created_at`),
  CONSTRAINT `fk_bs_rndlog_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_rndlog_member` FOREIGN KEY (`member_id`)
    REFERENCES `bs_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='R&D 과제 진행 기록. 공백이 길면 정체로 본다';

-- ---------------------------------------------------------------------
-- 5) bs_rnd_interest — 관심 표시
--
-- 합류(bs_rnd_member)와 다릅니다. 관심은 **점유를 만들지 않습니다.**
-- 보드에서 "이런 과제가 있으면 끼고 싶다" 를 가볍게 남기는 자리입니다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_rnd_interest` (
  `project_id`     INT UNSIGNED  NOT NULL                COMMENT 'bs_project.id',
  `member_id`      INT UNSIGNED  NOT NULL                COMMENT 'bs_member.id. 관심을 표시한 구성원',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`project_id`, `member_id`),
  KEY `ix_bs_rndinterest_member` (`member_id`, `created_at`),
  CONSTRAINT `fk_bs_rndinterest_project` FOREIGN KEY (`project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_rndinterest_member` FOREIGN KEY (`member_id`)
    REFERENCES `bs_member` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='R&D 과제 관심 표시. 합류와 달리 점유를 만들지 않는다';

-- =====================================================================
-- 스키마 변경이 없는 것
--
--   bs_workload.ref_type 에 'rnd' 값이 추가됩니다. VARCHAR 라 스키마를
--   바꿀 필요가 없습니다(명세서 3.3). 응용에서 BS_WORKLOAD_SOURCE 류
--   상수에 값을 더하면 됩니다.
-- =====================================================================
