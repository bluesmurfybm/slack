-- OnWatch(수시 감시) 스키마.
-- 화면(inc/db.php)이 처음 DB 에 붙을 때 아직 적용하지 않은 sql/NNN_*.sql 을 차례로 적용한다.
-- 한 번 적용된 파일은 고치지 않는다. 바꿀 것은 다음 번호의 새 파일로 더한다.
-- 문장은 ';' 로 끝나는 줄에서 자른다 — COMMENT 안에 세미콜론을 쓰지 않는다.
-- MySQL 5.7 호환(CTE·윈도 함수 없음).

CREATE TABLE IF NOT EXISTS `ow_watch` (
    `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`              VARCHAR(200)  NOT NULL COMMENT '감시 이름',
    `prompt`             MEDIUMTEXT    NOT NULL COMMENT '무엇을 어떻게 살필지 — 사용자가 쓴 지시문 원문',
    `baseline`           TEXT          NULL     COMMENT '기준 상태. 이미 알고 있는 것 — 이것과 달라진 것만 소식으로 본다',
    `baseline_updated_at` DATETIME     NULL     COMMENT '기준 상태를 마지막으로 바꾼 때',
    `owner_id`           INT UNSIGNED  NOT NULL COMMENT '등록한 사람 portal_users.id',
    `status`             VARCHAR(12)   NOT NULL DEFAULT 'active' COMMENT 'active 감시 중 / paused 일시정지 / ended 종료',
    `starts_at`          DATETIME      NOT NULL COMMENT '감시 시작',
    `ends_at`            DATETIME      NOT NULL COMMENT '감시 종료. 지나면 배치가 ended 로 바꾼다',
    `interval_min`       INT UNSIGNED  NOT NULL COMMENT '주기(분) 60·180·360·720·1440·10080',
    `run_time`           CHAR(5)       NULL     COMMENT '하루 이상 주기의 실행 시각 HH:MM',
    `weekday`            TINYINT       NULL     COMMENT '매주 주기의 요일 1(월)~7(일)',
    `weekdays_only`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1이면 토·일은 건너뛴다',
    `hour_from`          TINYINT       NOT NULL DEFAULT 0  COMMENT '하루 안 주기의 실행 시간대 시작(시)',
    `hour_to`            TINYINT       NOT NULL DEFAULT 24 COMMENT '하루 안 주기의 실행 시간대 끝(시, 미포함)',
    `notify_mode`        VARCHAR(12)   NOT NULL DEFAULT 'change' COMMENT 'change 새 소식이 있을 때만 / always 매번 결과를 알림',
    `min_importance`     VARCHAR(8)    NOT NULL DEFAULT 'low' COMMENT '알릴 최소 중요도 low / medium / high. 낮은 것도 기록은 한다',
    `notify_slack`       TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '슬랙으로도 알릴지',
    `slack_webhook_enc`  TEXT          NULL     COMMENT '이 감시 전용 슬랙 웹훅(암호화). 비면 기본 채널',
    `prefer_domains`     TEXT          NULL     COMMENT '먼저 볼 출처 도메인. 줄바꿈 구분',
    `block_domains`      TEXT          NULL     COMMENT '보지 않을 도메인. 줄바꿈 구분 — 검색 도구에 그대로 넘긴다',
    `max_searches`       SMALLINT UNSIGNED NOT NULL DEFAULT 15 COMMENT '한 번 실행의 웹 검색 상한',
    `monthly_budget_usd` DECIMAL(8,2)  NOT NULL DEFAULT 20.00 COMMENT '이 감시의 한 달 비용 상한(USD)',
    `next_run_at`        DATETIME      NULL     COMMENT '다음 정기 실행 시각. NULL 이면 남은 실행 없음',
    `last_run_at`        DATETIME      NULL,
    `last_change_at`     DATETIME      NULL     COMMENT '마지막으로 새 소식을 찾은 때',
    `run_requested_at`   DATETIME      NULL     COMMENT '지금 실행 요청. 배치가 집어 가면 비운다',
    `run_requested_by`   INT UNSIGNED  NULL,
    `locked_until`       DATETIME      NULL     COMMENT '배치가 실행 중이면 이때까지 다른 배치가 손대지 않는다',
    `locked_by`          VARCHAR(80)   NULL     COMMENT '실행 중인 배치 host:pid',
    `fail_streak`        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '연달아 실패한 횟수',
    `end_notice_sent`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '종료 임박 알림을 보냈는지',
    `created_at`         DATETIME      NOT NULL,
    `updated_at`         DATETIME      NULL,
    `ended_at`           DATETIME      NULL,
    PRIMARY KEY (`id`),
    KEY `ix_ow_watch_due` (`status`, `next_run_at`),
    KEY `ix_ow_watch_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='수시 감시 한 건 — 무엇을 언제까지 얼마나 자주 볼지';

CREATE TABLE IF NOT EXISTS `ow_subscriber` (
    `watch_id`   INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL COMMENT 'portal_users.id',
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`watch_id`, `user_id`),
    KEY `ix_ow_sub_user` (`user_id`),
    CONSTRAINT `fk_ow_sub_watch` FOREIGN KEY (`watch_id`) REFERENCES `ow_watch` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='감시 소식을 받는 사람. 등록자는 처음부터 들어 있다';

CREATE TABLE IF NOT EXISTS `ow_run` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `watch_id`       INT UNSIGNED  NOT NULL,
    `trigger`        VARCHAR(12)   NOT NULL COMMENT 'schedule 정기 / manual 지금 실행 / cli 명령줄',
    `requested_by`   INT UNSIGNED  NULL     COMMENT '지금 실행을 누른 사람',
    `started_at`     DATETIME      NOT NULL,
    `finished_at`    DATETIME      NULL,
    `status`         VARCHAR(12)   NOT NULL DEFAULT 'running' COMMENT 'running / changed 새 소식 / quiet 변화 없음 / failed / budget 예산 초과로 건너뜀',
    `engine`         VARCHAR(12)   NULL     COMMENT 'anthropic / cli',
    `model`          VARCHAR(80)   NULL,
    `searches`       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '웹 검색 횟수',
    `input_tokens`   INT UNSIGNED  NOT NULL DEFAULT 0,
    `output_tokens`  INT UNSIGNED  NOT NULL DEFAULT 0,
    `cost_usd`       DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT '추정 비용(USD)',
    `new_findings`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `dup_findings`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '이미 알린 것이라 버린 수',
    `summary`        VARCHAR(500)  NULL     COMMENT '한 줄 요약',
    `research_md`    MEDIUMTEXT    NULL     COMMENT '조사 노트 원문 — 무엇을 보고 그렇게 판단했는지',
    `baseline_hint`  TEXT          NULL     COMMENT '기준 상태를 이렇게 바꾸자는 제안. 사람이 반영해야 바뀐다',
    `notified`       TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '알림을 보냈는지',
    `error`          TEXT          NULL,
    PRIMARY KEY (`id`),
    KEY `ix_ow_run_watch` (`watch_id`, `id`),
    KEY `ix_ow_run_month` (`started_at`),
    CONSTRAINT `fk_ow_run_watch` FOREIGN KEY (`watch_id`) REFERENCES `ow_watch` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='감시 실행 이력 — 한 번 돌 때마다 한 줄';

CREATE TABLE IF NOT EXISTS `ow_finding` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `watch_id`      INT UNSIGNED  NOT NULL,
    `run_id`        INT UNSIGNED  NOT NULL,
    `fingerprint`   CHAR(40)      NOT NULL COMMENT 'sha1(대상 키·변화 종류·구별 표식) — 같은 소식을 두 번 알리지 않는다',
    `subject_key`   VARCHAR(300)  NOT NULL COMMENT '무엇에 대한 소식인지 가리키는 안정된 키',
    `marker`        VARCHAR(200)  NOT NULL DEFAULT '' COMMENT '이번 변화를 구별하는 표식(릴리스 번호, 날짜 등)',
    `title`         VARCHAR(300)  NOT NULL,
    `detail_md`     TEXT          NULL,
    `change_type`   VARCHAR(12)   NOT NULL COMMENT 'new 새로 나옴 / updated 바뀜 / retracted 회수 / defect 결함 / other',
    `importance`    VARCHAR(8)    NOT NULL COMMENT 'high / medium / low',
    `confidence`    VARCHAR(12)   NOT NULL COMMENT 'confirmed 원문 확인 / reported 신뢰할 만한 보도 / unverified 미확인',
    `sources_json`  TEXT          NULL     COMMENT '[{url,title,published,seen}] seen 은 조사 중 실제로 열어 본 주소인지',
    `verification`  TEXT          NULL     COMMENT '받는 사람이 따로 확인해야 할 것',
    `published`     VARCHAR(40)   NULL     COMMENT '원문 발표 시점(원문 표기 그대로)',
    `alerted`       TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '알림 대상이었는지(최소 중요도 이상)',
    `feedback`      VARCHAR(12)   NULL     COMMENT 'useful 유용 / duplicate 중복 / wrong 틀림 — 다음 실행에 반영',
    `feedback_by`   INT UNSIGNED  NULL,
    `feedback_at`   DATETIME      NULL,
    `created_at`    DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ow_finding_fp` (`watch_id`, `fingerprint`),
    KEY `ix_ow_finding_run` (`run_id`),
    CONSTRAINT `fk_ow_finding_watch` FOREIGN KEY (`watch_id`) REFERENCES `ow_watch` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='찾아낸 소식. 이미 있는 fingerprint 는 다시 넣지 않는다';

CREATE TABLE IF NOT EXISTS `ow_event` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `watch_id`   INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NULL     COMMENT '한 사람. 배치가 한 일이면 NULL',
    `action`     VARCHAR(20)  NOT NULL COMMENT 'create / edit / pause / resume / end / extend / run / baseline / subscribe / unsubscribe',
    `detail`     VARCHAR(500) NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_ow_event_watch` (`watch_id`, `id`),
    CONSTRAINT `fk_ow_event_watch` FOREIGN KEY (`watch_id`) REFERENCES `ow_watch` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='감시 설정 변경 기록 — 누구나 보는 감시라 누가 무엇을 바꿨는지 남긴다';
