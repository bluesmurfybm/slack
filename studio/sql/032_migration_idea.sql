-- =====================================================================
-- 032. 아이디어·제안
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/032_migration_idea.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/032_migration_idea.sql
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 과제가 되기 전의 생각을 담는 자리 (2026-11-02 사용자 요청)        │
-- │                                                                  │
-- │ R&D 과제는 발의하는 순간 기간·점유·승인을 묻는다. "이런 게 있으면 │
-- │ 좋겠다" 수준의 생각은 그 문턱을 못 넘고 슬랙에서 흩어진다.       │
-- │ 아이디어는 문턱 없이 올리고, 누구나 평가·피드백하고, 쓸 만한 것만 │
-- │ 과제(생략 가능) → 프로젝트로 올라간다.                           │
-- └──────────────────────────────────────────────────────────────────┘
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 평가는 아이디어에 매기는 것이지 사람에게 매기는 것이 아니다       │
-- │                                                                  │
-- │ CLAUDE.md §1 — 제안자별 평균·순위를 만들지 않고, 역량 점수에도    │
-- │ 넣지 않는다. 그래서 평가 표에 **제안자 칸이 없다.** 집계는 언제나 │
-- │ 아이디어 단위다. 개별 점수는 본인만 본다(평균·인원만 공개).       │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 사람은 portal 이메일(user_id)로 적는다. bs_member 가 아니라서 배정
-- 대상이 아닌 사람도 올리고 평가할 수 있다 — 진행상황 댓글과 같은 방식.
--
-- 표 넷 + bs_project 칸 둘.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- ---------------------------------------------------------------------
-- 1) bs_idea — 아이디어 본문
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_idea` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `code`             VARCHAR(20)   NOT NULL                COMMENT '화면 번호. IDEA-YYYY-NNN. 지워도 다시 쓰지 않는다',
  `title`            VARCHAR(200)  NOT NULL                COMMENT '한 줄 제목',
  `problem`          TEXT          NULL                    COMMENT '무엇이 불편한가·왜 필요한가',
  `proposal`         TEXT          NULL                    COMMENT '어떻게 하자는가',
  `effect`           TEXT          NULL                    COMMENT '기대 효과. 비워도 된다',
  `url`              VARCHAR(2000) NULL                    COMMENT '참고 링크. http(s) 만. 최대 2000자(BS_URL_MAX)',
  `rnd_category`     VARCHAR(20)   NULL                    COMMENT 'R&D 과제와 같은 갈래(BS_RND_CATEGORY). 과제로 넘길 때 그대로 따라간다',

  `author_id`        VARCHAR(64)   NOT NULL                COMMENT '올린 사람 portal 이메일. 익명이어도 채운다(관리자만 본다)',
  `author_name`      VARCHAR(80)   NULL                    COMMENT '올린 시점 성명 스냅샷',
  `is_anonymous`     TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '1=익명. 화면에는 작성자 대신 "익명" — 관리자와 본인만 이름을 본다',

  `status`           VARCHAR(20)   NOT NULL DEFAULT 'open' COMMENT 'open(제안됨)|reviewing(검토 중)|adopted(채택)|hold(보류)|rejected(반려)|promoted(과제·프로젝트로 넘어감)',
  `review_note`      VARCHAR(500)  NULL                    COMMENT '검토 의견. 채택·보류·반려 사유',
  `reviewed_by`      VARCHAR(64)   NULL,
  `reviewed_by_name` VARCHAR(80)   NULL,
  `reviewed_at`      DATETIME      NULL,

  -- 넘어간 곳. 과제를 거쳐 프로젝트가 되면 과제 쪽 origin 으로 이어진다.
  `promoted_project_id` INT UNSIGNED NULL                  COMMENT '넘어간 bs_project.id (rnd 또는 project)',
  `promoted_by`      VARCHAR(64)   NULL,
  `promoted_by_name` VARCHAR(80)   NULL,
  `promoted_at`      DATETIME      NULL,

  `duplicate_of`     INT UNSIGNED  NULL                    COMMENT '비슷한 아이디어로 묶은 대상 bs_idea.id. 평가·피드백은 그쪽으로 모은다',

  `deleted_at`       DATETIME      NULL                    COMMENT '소프트 삭제. 피드백과 평가가 딸려 있어 행은 남긴다',
  `deleted_by`       VARCHAR(64)   NULL,
  `deleted_by_name`  VARCHAR(80)   NULL,

  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bs_idea_code` (`code`),
  -- 보드가 (살아 있는 것, 상태, 최신순) 으로 긁는다.
  KEY `ix_bs_idea_board`  (`deleted_at`, `status`, `created_at`),
  KEY `ix_bs_idea_author` (`author_id`, `created_at`),
  KEY `ix_bs_idea_promoted` (`promoted_project_id`),
  CONSTRAINT `fk_bs_idea_project` FOREIGN KEY (`promoted_project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_idea_dup` FOREIGN KEY (`duplicate_of`)
    REFERENCES `bs_idea` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='아이디어·제안. 과제가 되기 전의 생각';

-- ---------------------------------------------------------------------
-- 2) bs_idea_domain — 분야 태그 (bs_rnd_domain 과 같은 모양)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_idea_domain` (
  `idea_id`    INT UNSIGNED NOT NULL COMMENT 'bs_idea.id',
  `domain_id`  INT UNSIGNED NOT NULL COMMENT 'bs_domain.id',
  PRIMARY KEY (`idea_id`, `domain_id`),
  KEY `ix_bs_ideadom_domain` (`domain_id`),
  CONSTRAINT `fk_bs_ideadom_idea` FOREIGN KEY (`idea_id`)
    REFERENCES `bs_idea` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_ideadom_domain` FOREIGN KEY (`domain_id`)
    REFERENCES `bs_domain` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='아이디어 분야 태그. 과제로 넘길 때 bs_rnd_domain 으로 옮긴다';

-- ---------------------------------------------------------------------
-- 3) bs_idea_rating — 평가 (한 사람 한 건, 고칠 수 있다)
--
--    두 축이다. 하나로 합치면 "좋은데 어렵다" 와 "쉽지만 작다" 가 같은
--    점수가 된다. 합친 점수는 **저장하지 않는다** — 화면이 그때 계산한다.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_idea_rating` (
  `idea_id`      INT UNSIGNED NOT NULL COMMENT 'bs_idea.id',
  `user_id`      VARCHAR(64)  NOT NULL COMMENT '평가한 사람 portal 이메일. 본인 것만 화면에 보인다',
  `user_name`    VARCHAR(80)  NULL     COMMENT '평가 시점 성명 스냅샷. 한줄 의견에만 드러난다',
  `value_score`  TINYINT      NOT NULL COMMENT '가치 1~5 — 되면 얼마나 좋은가',
  `feasibility_score` TINYINT NOT NULL COMMENT '실현 가능성 1~5 — 우리가 해낼 수 있는가',
  `opinion`      VARCHAR(300) NULL     COMMENT '한줄 의견. 비워도 된다. 적으면 이름과 함께 보인다',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`idea_id`, `user_id`),
  KEY `ix_bs_idearate_user` (`user_id`),
  CONSTRAINT `fk_bs_idearate_idea` FOREIGN KEY (`idea_id`)
    REFERENCES `bs_idea` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='아이디어 평가. 아이디어 단위로만 집계한다 — 제안자 칸이 없는 이유';

-- ---------------------------------------------------------------------
-- 4) bs_idea_comment — 피드백 (답글 한 단계)
--
--    kind=status 는 사람이 쓰지 않는다. 검토·승격 때 기록이 남는 자리다 —
--    피드백 흐름 안에서 "언제 왜 채택됐나" 가 보이게.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bs_idea_comment` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `idea_id`     INT UNSIGNED    NOT NULL COMMENT 'bs_idea.id',
  `parent_id`   BIGINT UNSIGNED NULL     COMMENT '답글이면 원 피드백 id. 한 단계만',
  `kind`        VARCHAR(20)     NOT NULL DEFAULT 'comment' COMMENT 'comment(피드백)|status(상태 바뀜 기록)',
  `user_id`     VARCHAR(64)     NOT NULL COMMENT '쓴 사람 portal 이메일',
  `user_name`   VARCHAR(80)     NULL     COMMENT '쓴 시점 성명 스냅샷',
  `content`     TEXT            NOT NULL,
  `deleted_at`  DATETIME        NULL     COMMENT '지워도 답글이 매달려 있으면 "지운 피드백입니다" 로 자리만 남긴다',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bs_ideacmt_idea` (`idea_id`, `created_at`),
  KEY `ix_bs_ideacmt_parent` (`parent_id`),
  CONSTRAINT `fk_bs_ideacmt_idea` FOREIGN KEY (`idea_id`)
    REFERENCES `bs_idea` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_ideacmt_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `bs_idea_comment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='아이디어 피드백과 상태 기록';

-- ---------------------------------------------------------------------
-- 5) bs_project — 어디서 왔는가
--
--    아이디어 → 과제, 아이디어 → 프로젝트, 과제 → 프로젝트. 거꾸로
--    (과제·프로젝트 화면에서 "출발 아이디어") 도 보여야 해서 이쪽에도 둔다.
--    bs_idea.promoted_project_id 와 짝이다.
-- ---------------------------------------------------------------------
ALTER TABLE `bs_project`
  ADD COLUMN `origin_idea_id` INT UNSIGNED NULL
      COMMENT '출발 아이디어 bs_idea.id' AFTER `recruiting`,
  ADD COLUMN `origin_project_id` INT UNSIGNED NULL
      COMMENT '출발 R&D 과제 bs_project.id (과제 → 프로젝트)' AFTER `origin_idea_id`,
  ADD KEY `ix_bs_project_origin_idea` (`origin_idea_id`),
  ADD KEY `ix_bs_project_origin_prj`  (`origin_project_id`),
  ADD CONSTRAINT `fk_bs_project_origin_idea` FOREIGN KEY (`origin_idea_id`)
    REFERENCES `bs_idea` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bs_project_origin_prj` FOREIGN KEY (`origin_project_id`)
    REFERENCES `bs_project` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
