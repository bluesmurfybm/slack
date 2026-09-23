"""AI 테이블 DDL(PHP 쌍둥이) + 도메인 접근자.

PHP twin: slackai/db.php — ai_schema() 의 DDL 과 1:1 로 같아야 한다. **컬럼을 바꾸면 둘 다 고친다.**
InnoDB utf8mb4_unicode_ci, FK 없음, 이후 컬럼 추가는 MIGRATIONS(ADD COLUMN, 1060 무시).
DATETIME 은 모두 KST 문자열(now_str) 로 쓴다(세션 time_zone=+09:00 이라 NOW() 와 같다).
"""

import json
import logging

from core.clock import now_str

logger = logging.getLogger(__name__)

_E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"

AI_TABLES = ["ai_jobs", "ai_triage", "ai_tags", "request_tags", "ai_similar", "ai_comment_cache",
             "ai_plans", "ai_executions", "ai_commits", "ai_reviews", "ai_lessons", "ai_events",
             "ai_settings", "ai_repos"]
BASE_TABLES = ["requests", "sync_meta", "user_reads", "user_pins", "user_hides", "user_prefs",
               "schools", "local_assignments"]

DDL = [
    # 작업 큐. PHP 가 넣고 워커가 claim 한다.
    #  dedupe_key = kind:request_id:ref_id, open_key = 열려 있으면 1 / 끝나면 NULL → UNIQUE(dedupe_key, open_key)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_jobs` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `kind`          VARCHAR(20)  NOT NULL COMMENT 'ingest|triage|plan|execute|commit|review|learn|distill|check_repo|revert|resync',
        `request_id`    VARCHAR(32)  NULL COMMENT 'requests.id (없을 수 있음: distill/check_repo)',
        `ref_id`        BIGINT UNSIGNED NULL COMMENT 'plan/execution/commit/repo id',
        `repo_id`       INT UNSIGNED NULL COMMENT '레포별 직렬화 키(plan/execute/commit/revert)',
        `dedupe_key`    VARCHAR(90)  NOT NULL COMMENT 'kind:request_id:ref_id',
        `params`        MEDIUMTEXT   NULL COMMENT 'JSON 입력',
        `status`        VARCHAR(12)  NOT NULL DEFAULT 'queued' COMMENT 'queued|running|done|failed|cancelled',
        `open_key`      TINYINT      NULL COMMENT 'queued/running=1, 종료 시 NULL',
        `priority`      TINYINT      NOT NULL DEFAULT 0 COMMENT '높을수록 먼저',
        `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `max_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `run_after`     DATETIME     NULL COMMENT '재시도 백오프',
        `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
        `worker`        VARCHAR(60)  NULL COMMENT 'host:pid',
        `progress`      VARCHAR(200) NULL,
        `heartbeat_at`  DATETIME     NULL,
        `started_at`    DATETIME     NULL,
        `finished_at`   DATETIME     NULL,
        `error`         TEXT         NULL,
        `result`        MEDIUMTEXT   NULL COMMENT 'JSON 결과 요약',
        `model`         VARCHAR(60)  NULL,
        `tokens_in`     INT UNSIGNED NULL,
        `tokens_out`    INT UNSIGNED NULL,
        `cost_usd`      DECIMAL(10,4) NULL,
        `requested_by`  VARCHAR(190) NULL COMMENT '포털 이메일 | sync:* | worker:* | slack:*',
        `created_at`    DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_open` (`dedupe_key`, `open_key`),
        KEY `idx_status_kind` (`status`, `kind`),
        KEY `idx_req_status`  (`request_id`, `status`),
        KEY `idx_created`     (`created_at`)
    ) {_E}
    """,
    # 접수 분석 결과 (요청당 1행, 재분석 시 덮어쓰고 version+1)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_triage` (
        `request_id`      VARCHAR(32)  NOT NULL,
        `version`         INT UNSIGNED NOT NULL DEFAULT 1,
        `summary_md`      TEXT         NULL COMMENT '요약 3~5줄',
        `summary_short`   VARCHAR(300) NULL COMMENT '한 줄 요약(목록 배지)',
        `problem_type`    VARCHAR(12)  NULL COMMENT 'bug|feature|question|data|ops|other',
        `urgency`         VARCHAR(10)  NULL COMMENT 'low|normal|high|critical',
        `affected_area`   VARCHAR(200) NULL,
        `difficulty`      TINYINT UNSIGNED NULL COMMENT '1~5',
        `questions`       TEXT         NULL COMMENT 'JSON [] 추가 확인 필요 사항',
        `suggested_new_tags` TEXT      NULL COMMENT 'JSON [] ai_tags 에 없는 제안',
        `repo_id`         INT UNSIGNED NULL COMMENT '확정 레포 (NULL=사용자 선택 필요)',
        `repo_confidence` DECIMAL(4,3) NULL,
        `repo_reason`     VARCHAR(30)  NULL COMMENT 'lms_url|url_pattern|title_customer|llm|user',
        `repo_candidates` TEXT         NULL COMMENT 'JSON [{{id,score,reason}}]',
        `content_hash`    CHAR(32)     NULL COMMENT 'md5(title+body) 변경 감지',
        `model`           VARCHAR(60)  NULL,
        `cost_usd`        DECIMAL(10,4) NULL,
        `job_id`          BIGINT UNSIGNED NULL,
        `created_at`      DATETIME     NOT NULL,
        `updated_at`      DATETIME     NOT NULL,
        PRIMARY KEY (`request_id`),
        KEY `idx_repo` (`repo_id`)
    ) {_E}
    """,
    # 태그(카테고리) — 사용자 CRUD
    f"""
    CREATE TABLE IF NOT EXISTS `ai_tags` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(60)  NOT NULL,
        `slug`        VARCHAR(60)  NOT NULL,
        `parent_id`   INT UNSIGNED NULL,
        `color`       VARCHAR(7)   NOT NULL DEFAULT '#9aa0a6',
        `keywords`    TEXT         NULL COMMENT '콤마 구분 힌트 키워드',
        `description` VARCHAR(300) NULL,
        `sort_order`  INT          NOT NULL DEFAULT 0,
        `active`      TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`  DATETIME     NOT NULL,
        `updated_at`  DATETIME     NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_name` (`name`),
        KEY `idx_parent` (`parent_id`)
    ) {_E}
    """,
    # 요청 ↔ 태그 (source: ai 가 붙임 / user 가 고침 / rule 키워드)
    f"""
    CREATE TABLE IF NOT EXISTS `request_tags` (
        `request_id` VARCHAR(32)  NOT NULL,
        `tag_id`     INT UNSIGNED NOT NULL,
        `source`     VARCHAR(8)   NOT NULL DEFAULT 'ai' COMMENT 'ai|user|rule',
        `confidence` DECIMAL(4,3) NULL,
        `set_by`     VARCHAR(190) NULL,
        `created_at` DATETIME     NOT NULL,
        PRIMARY KEY (`request_id`, `tag_id`),
        KEY `idx_tag` (`tag_id`)
    ) {_E}
    """,
    # 유사 과거 문의 (TF-IDF 후보 20건 rank 0 + LLM 재정렬 상위 5건 rank 1~5)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_similar` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_id`  VARCHAR(32)  NOT NULL,
        `similar_id`  VARCHAR(32)  NOT NULL,
        `score_tfidf` DECIMAL(5,3) NULL,
        `score_llm`   DECIMAL(5,3) NULL,
        `rank`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=후보, 1~5=LLM 상위',
        `why_similar` VARCHAR(300) NULL,
        `resolution`  TEXT         NULL COMMENT '과거 처리 내용 요약(스레드·커밋 기반)',
        `job_id`      BIGINT UNSIGNED NULL,
        `created_at`  DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_pair` (`request_id`, `similar_id`)
    ) {_E}
    """,
    # 댓글 스레드 평문 캐시 (cmt_count 가 바뀌면 워커가 다시 받는다)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_comment_cache` (
        `request_id` VARCHAR(32)  NOT NULL,
        `thread_ts`  VARCHAR(24)  NOT NULL DEFAULT '',
        `text`       MEDIUMTEXT   NULL COMMENT '"이름: 내용" 줄바꿈 평문',
        `cmt_count`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '조회 시점 requests.cmt_count',
        `fetched_at` DATETIME     NOT NULL,
        PRIMARY KEY (`request_id`)
    ) {_E}
    """,
    # 작업 계획 (요청당 여러 버전)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_plans` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_id`    VARCHAR(32)  NOT NULL,
        `version`       INT UNSIGNED NOT NULL DEFAULT 1,
        `status`        VARCHAR(12)  NOT NULL DEFAULT 'draft' COMMENT 'draft|approved|executing|executed|committed|reviewed|rejected|superseded|failed',
        `repo_id`       INT UNSIGNED NULL,
        `title`         VARCHAR(300) NULL,
        `plan_md`       MEDIUMTEXT   NULL,
        `plan_json`     MEDIUMTEXT   NULL COMMENT '{{understanding_md,approach_md,files:[{{path,action,why}}],steps:[{{n,text,files}}],risks,test_plan,questions,estimated_minutes,confidence,needs_human}}',
        `budget_usd`    DECIMAL(8,2) NULL COMMENT '실행 예산 상한(승인 시 확정)',
        `est_minutes`   INT UNSIGNED NULL,
        `user_hint`     TEXT         NULL COMMENT '재생성 시 사용자가 준 힌트',
        `prompt_md`     MEDIUMTEXT   NULL COMMENT '워커가 자동 조립한 플랜 프롬프트(시스템+사용자) — 화면 확인용',
        `claude_session_id` CHAR(36) NULL COMMENT '플랜 세션 → 실행 시 --resume',
        `base_revision` VARCHAR(64)  NULL COMMENT '플랜 시점 작업사본 리비전',
        `budget_hit`    TINYINT(1)   NOT NULL DEFAULT 0,
        `num_turns`     INT UNSIGNED NULL,
        `duration_ms`   INT UNSIGNED NULL,
        `model`         VARCHAR(60)  NULL,
        `cost_usd`      DECIMAL(10,4) NULL,
        `job_id`        BIGINT UNSIGNED NULL,
        `created_by`    VARCHAR(190) NULL,
        `approved_by`   VARCHAR(190) NULL,
        `approved_at`   DATETIME     NULL,
        `executed_at`   DATETIME     NULL,
        `rejected_by`   VARCHAR(190) NULL,
        `rejected_at`   DATETIME     NULL,
        `reject_reason` TEXT         NULL,
        `metrics_json`  TEXT         NULL COMMENT 'learn 결과: {{jaccard,precision,recall,missed,unexpected,est_vs_actual,verdict}}',
        `created_at`    DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_req_ver` (`request_id`, `version`),
        KEY `idx_status` (`status`)
    ) {_E}
    """,
    # 플랜 실행 (Claude 가 실제 수정한 결과)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_executions` (
        `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `plan_id`        BIGINT UNSIGNED NOT NULL,
        `request_id`     VARCHAR(32)  NOT NULL,
        `status`         VARCHAR(12)  NOT NULL DEFAULT 'queued' COMMENT 'queued|running|done|failed|cancelled|reverted',
        `resumed`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0=플랜 세션 재개 실패 → 새 세션',
        `preexisting_json` TEXT       NULL COMMENT '실행 전 untracked 파일 목록(svn add 제외)',
        `branch`         VARCHAR(120) NULL,
        `diff`           MEDIUMTEXT   NULL COMMENT 'unified diff (2MB 캡)',
        `diff_stat`      TEXT         NULL COMMENT 'JSON [{{path,add,del,status}}]',
        `files_changed`  INT UNSIGNED NULL,
        `lines_added`    INT UNSIGNED NULL,
        `lines_deleted`  INT UNSIGNED NULL,
        `lint_ok`        TINYINT(1)   NULL,
        `lint_output`    TEXT         NULL,
        `result_md`      MEDIUMTEXT   NULL COMMENT 'Claude 완료 보고',
        `log`            MEDIUMTEXT   NULL,
        `budget_hit`     TINYINT(1)   NOT NULL DEFAULT 0,
        `model`          VARCHAR(60)  NULL,
        `cost_usd`       DECIMAL(10,4) NULL,
        `job_id`         BIGINT UNSIGNED NULL,
        `approved_by`    VARCHAR(190) NULL,
        `started_at`     DATETIME     NULL,
        `finished_at`    DATETIME     NULL,
        `created_at`     DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_plan` (`plan_id`),
        KEY `idx_req`  (`request_id`)
    ) {_E}
    """,
    # 커밋
    f"""
    CREATE TABLE IF NOT EXISTS `ai_commits` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `execution_id` BIGINT UNSIGNED NOT NULL,
        `request_id`   VARCHAR(32)  NOT NULL,
        `repo_id`      INT UNSIGNED NULL,
        `vcs`          VARCHAR(5)   NULL COMMENT 'svn|git',
        `revision`     VARCHAR(80)  NULL,
        `branch`       VARCHAR(120) NULL,
        `message`      TEXT         NOT NULL,
        `status`       VARCHAR(12)  NOT NULL DEFAULT 'pending' COMMENT 'pending|committed|pushed|failed',
        `log`          TEXT         NULL,
        `job_id`       BIGINT UNSIGNED NULL,
        `committed_by` VARCHAR(190) NULL,
        `committed_at` DATETIME     NULL,
        `created_at`   DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_exec` (`execution_id`),
        KEY `idx_req`  (`request_id`)
    ) {_E}
    """,
    # 타 AI 검토
    f"""
    CREATE TABLE IF NOT EXISTS `ai_reviews` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `commit_id`     BIGINT UNSIGNED NOT NULL,
        `request_id`    VARCHAR(32)  NOT NULL,
        `reviewer`      VARCHAR(40)  NOT NULL COMMENT 'codex|openai|gemini|claude|human',
        `verdict`       VARCHAR(16)  NULL COMMENT 'pass|warn|fail|error',
        `addresses_inquiry` TINYINT(1) NULL,
        `report_md`     MEDIUMTEXT   NULL,
        `findings_json` TEXT         NULL COMMENT '[{{severity,file,line,text,suggestion}}]',
        `model`         VARCHAR(60)  NULL,
        `cost_usd`      DECIMAL(10,4) NULL,
        `job_id`        BIGINT UNSIGNED NULL,
        `requested_by`  VARCHAR(190) NULL,
        `created_at`    DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_commit` (`commit_id`),
        KEY `idx_req`    (`request_id`)
    ) {_E}
    """,
    # 학습 노트(교훈) — 승인된 것만 다음 플랜 프롬프트에 들어간다
    f"""
    CREATE TABLE IF NOT EXISTS `ai_lessons` (
        `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_id`     VARCHAR(32)  NULL,
        `plan_id`        BIGINT UNSIGNED NULL,
        `commit_id`      BIGINT UNSIGNED NULL,
        `kind`           VARCHAR(20)  NOT NULL COMMENT 'file_miss|approach|style|test|estimate|tag_correction|review_finding|manual|other',
        `scope`          VARCHAR(8)   NOT NULL DEFAULT 'repo' COMMENT 'repo|tag|global',
        `repo_id`        INT UNSIGNED NULL,
        `tag_id`         INT UNSIGNED NULL,
        `title`          VARCHAR(300) NOT NULL,
        `lesson_md`      MEDIUMTEXT   NULL COMMENT '지시형 1~2문장',
        `evidence_md`    MEDIUMTEXT   NULL,
        `weight`         DECIMAL(4,3) NOT NULL DEFAULT 0.500,
        `evidence_count` INT UNSIGNED NOT NULL DEFAULT 1,
        `used_count`     INT UNSIGNED NOT NULL DEFAULT 0,
        `status`         VARCHAR(10)  NOT NULL DEFAULT 'proposed' COMMENT 'proposed|approved|rejected',
        `approved_by`    VARCHAR(190) NULL,
        `approved_at`    DATETIME     NULL,
        `job_id`         BIGINT UNSIGNED NULL,
        `created_at`     DATETIME     NOT NULL,
        `updated_at`     DATETIME     NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status_kind` (`status`, `kind`),
        KEY `idx_repo`  (`repo_id`),
        KEY `idx_req`   (`request_id`)
    ) {_E}
    """,
    # 감사 로그 (버튼 클릭·승인·워커 이벤트)
    f"""
    CREATE TABLE IF NOT EXISTS `ai_events` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_id` VARCHAR(32)  NULL,
        `actor`      VARCHAR(190) NOT NULL COMMENT '포털 이메일 | worker:* | sync:* | slack:*',
        `action`     VARCHAR(40)  NOT NULL,
        `ref_table`  VARCHAR(30)  NULL,
        `ref_id`     BIGINT UNSIGNED NULL,
        `detail`     TEXT         NULL COMMENT 'JSON',
        `ip`         VARCHAR(45)  NULL,
        `created_at` DATETIME     NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_req`    (`request_id`, `id`),
        KEY `idx_action` (`action`, `created_at`)
    ) {_E}
    """,
    # 설정 (k/v) — 화면 토글이 워커 동작을 바꾼다
    f"""
    CREATE TABLE IF NOT EXISTS `ai_settings` (
        `k`          VARCHAR(64)  NOT NULL,
        `v`          TEXT         NULL,
        `updated_by` VARCHAR(190) NULL,
        `updated_at` DATETIME     NULL,
        PRIMARY KEY (`k`)
    ) {_E}
    """,
    # 고객사 → 로컬 작업 사본 매핑
    f"""
    CREATE TABLE IF NOT EXISTS `ai_repos` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`           VARCHAR(120) NOT NULL,
        `school_id`      INT UNSIGNED NULL COMMENT 'schools.id',
        `vcs`            VARCHAR(5)   NOT NULL DEFAULT 'svn' COMMENT 'svn|git',
        `remote_url`     VARCHAR(500) NULL,
        `local_path`     VARCHAR(500) NOT NULL COMMENT '워커 호스트의 작업 사본 경로',
        `default_branch` VARCHAR(80)  NULL,
        `version`        VARCHAR(20)  NULL COMMENT 'LMS 버전 3.5/3.9/4.5',
        `php_bin`        VARCHAR(300) NULL COMMENT '이 레포용 php 실행 파일(없으면 워커 PHP_BIN)',
        `match_rules`    TEXT         NULL COMMENT 'JSON {{lms_patterns:[],title_keywords:[]}}',
        `notes`          TEXT         NULL COMMENT '플랜 시스템 프롬프트에 붙는 저장소 메모',
        `active`         TINYINT(1)   NOT NULL DEFAULT 1,
        `last_checked`   DATETIME     NULL,
        `check_status`   VARCHAR(12)  NULL COMMENT 'ok|fail|unchecked',
        `check_message`  VARCHAR(300) NULL,
        `head_revision`  VARCHAR(80)  NULL,
        `created_at`     DATETIME     NOT NULL,
        `updated_at`     DATETIME     NULL,
        PRIMARY KEY (`id`),
        KEY `idx_school` (`school_id`)
    ) {_E}
    """,
]

# 이후 컬럼 추가는 전부 여기서(PHP 는 add_column_if_missing). 이미 있으면 1060 → 무시.
MIGRATIONS: list[str] = [
    "ALTER TABLE `ai_plans` ADD COLUMN `prompt_md` MEDIUMTEXT NULL COMMENT '워커가 자동 조립한 플랜 프롬프트(시스템+사용자) — 화면 확인용' AFTER `user_hint`",
]
DUPLICATE_COLUMN = 1060


def ensure_schema(db) -> None:
    """ai_* 테이블 생성/마이그레이션. 기본 8개 테이블은 PHP(slackai/db.php) 가 만든다."""
    for ddl in DDL:
        db.exec(ddl)
    for sql in MIGRATIONS:
        try:
            db.exec(sql)
        except Exception as e:
            if not (e.args and e.args[0] == DUPLICATE_COLUMN):
                raise
    db.commit()


def show_tables(db) -> list[str]:
    return [next(iter(r.values())) for r in db.all("SHOW TABLES")]


def missing_tables(db) -> list[str]:
    have = set(show_tables(db))
    return [t for t in AI_TABLES + BASE_TABLES if t not in have]


# ----------------------------------------------------------------- meta / settings / events

def meta_get(db, k: str, default=None):
    v = db.scalar("SELECT v FROM sync_meta WHERE k = %s", (k,))
    return default if v is None else v


def meta_set(db, k: str, v) -> None:
    db.exec("INSERT INTO sync_meta (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            (k, str(v)))


def mark_changed(db) -> None:
    """AI 데이터가 바뀌었음 → 화면 status.php 폴링이 패널을 갱신한다."""
    import time

    meta_set(db, "ai_changed_at", int(time.time()))


def settings_all(db) -> dict[str, str]:
    return {r["k"]: r["v"] for r in db.all("SELECT k, v FROM ai_settings")}


def setting_set(db, k: str, v, by: str = "worker") -> None:
    db.exec("INSERT INTO ai_settings (k, v, updated_by, updated_at) VALUES (%s, %s, %s, %s) "
            "ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by), "
            "updated_at = VALUES(updated_at)", (k, str(v), by, now_str()))


def event(db, request_id: str | None, actor: str, action: str, ref_table: str | None = None,
          ref_id: int | None = None, detail: dict | None = None) -> None:
    db.exec("INSERT INTO ai_events (request_id, actor, action, ref_table, ref_id, detail, ip, created_at) "
            "VALUES (%s, %s, %s, %s, %s, %s, NULL, %s)",
            (request_id or None, actor, action, ref_table, ref_id,
             json.dumps(detail, ensure_ascii=False) if detail else None, now_str()))


def events_for(db, request_id: str, actions: list[str], limit: int = 30) -> list[dict]:
    if not actions:
        return []
    ph = ",".join(["%s"] * len(actions))
    return db.all(f"SELECT id, actor, action, ref_table, ref_id, detail, created_at FROM ai_events "
                  f"WHERE request_id = %s AND action IN ({ph}) ORDER BY id DESC LIMIT {int(limit)}",
                  (request_id, *actions))


# ----------------------------------------------------------------- requests / boards

BOARD_INIT_STATUS = {"블루소프트": "등록", "와이오즈": "시작 전"} # slackai/boards.php init_status
INIT_STATUSES = {"등록", "시작 전", ""}


def request_get(db, request_id: str) -> dict | None:
    return db.one("SELECT id, list_id, board, archived, title, body, momo, lms, req_id, req, asg_id, asg, "
                  "status, priority, team, cmt_count, eta, `date`, done, created, updated, attachments "
                  "FROM requests WHERE id = %s", (request_id,))


def requests_brief(db, ids: list[str]) -> dict[str, dict]:
    if not ids:
        return {}
    ph = ",".join(["%s"] * len(ids))
    rows = db.all(f"SELECT id, title, status, asg, done, cmt_count, board, archived FROM requests "
                  f"WHERE id IN ({ph})", tuple(ids))
    return {r["id"]: r for r in rows}


def request_set_ai(db, request_id: str, stars: int | None, reason: str | None, conf: str | None,
                   h: str) -> None:
    db.exec("UPDATE requests SET ai_stars = %s, ai_reason = %s, ai_conf = %s, ai_hash = %s, "
            "ai_scored_at = %s WHERE id = %s",
            (stars, (reason or "")[:300] or None, (conf or "")[:10] or None, h, now_str(), request_id))


def schools_all(db) -> list[dict]:
    return db.all("SELECT id, name, ver, dev, ops FROM schools WHERE active = 1")


# ----------------------------------------------------------------- tags

def tags_active(db) -> list[dict]:
    return db.all("SELECT id, name, slug, parent_id, keywords, description FROM ai_tags "
                  "WHERE active = 1 ORDER BY sort_order, id")


def request_tags_replace_ai(db, request_id: str, picks: list[tuple[int, float]], by: str) -> None:
    """source='ai' 행만 교체. user 행은 불변(그 태그가 ai 에도 있으면 user 유지)."""
    db.exec("DELETE FROM request_tags WHERE request_id = %s AND source = 'ai'", (request_id,))
    for tag_id, conf in picks:
        db.exec("INSERT IGNORE INTO request_tags (request_id, tag_id, source, confidence, set_by, created_at) "
                "VALUES (%s, %s, 'ai', %s, %s, %s)", (request_id, tag_id, conf, by, now_str()))


def request_tag_ids(db, request_id: str) -> list[int]:
    return [r["tag_id"] for r in db.all("SELECT tag_id FROM request_tags WHERE request_id = %s",
                                        (request_id,))]


# ----------------------------------------------------------------- triage

def triage_get(db, request_id: str) -> dict | None:
    return db.one("SELECT * FROM ai_triage WHERE request_id = %s", (request_id,))


def triage_upsert(db, request_id: str, d: dict) -> None:
    now = now_str()
    db.exec(
        "INSERT INTO ai_triage (request_id, version, summary_md, summary_short, problem_type, urgency, "
        "affected_area, difficulty, questions, suggested_new_tags, repo_id, repo_confidence, repo_reason, "
        "repo_candidates, content_hash, model, cost_usd, job_id, created_at, updated_at) "
        "VALUES (%s, 1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) "
        "ON DUPLICATE KEY UPDATE version = version + 1, summary_md = VALUES(summary_md), "
        "summary_short = VALUES(summary_short), problem_type = VALUES(problem_type), urgency = VALUES(urgency), "
        "affected_area = VALUES(affected_area), difficulty = VALUES(difficulty), questions = VALUES(questions), "
        "suggested_new_tags = VALUES(suggested_new_tags), repo_id = VALUES(repo_id), "
        "repo_confidence = VALUES(repo_confidence), repo_reason = VALUES(repo_reason), "
        "repo_candidates = VALUES(repo_candidates), content_hash = VALUES(content_hash), model = VALUES(model), "
        "cost_usd = VALUES(cost_usd), job_id = VALUES(job_id), updated_at = VALUES(updated_at)",
        (request_id, d.get("summary_md"), d.get("summary_short"), d.get("problem_type"), d.get("urgency"),
         d.get("affected_area"), d.get("difficulty"),
         json.dumps(d.get("questions") or [], ensure_ascii=False),
         json.dumps(d.get("suggested_new_tags") or [], ensure_ascii=False),
         d.get("repo_id"), d.get("repo_confidence"), d.get("repo_reason"),
         json.dumps(d.get("repo_candidates") or [], ensure_ascii=False),
         d.get("content_hash"), d.get("model"), d.get("cost_usd"), d.get("job_id"), now, now))


# ----------------------------------------------------------------- similar / comments

def similar_replace(db, request_id: str, rows: list[dict], job_id: int | None) -> None:
    db.exec("DELETE FROM ai_similar WHERE request_id = %s", (request_id,))
    now = now_str()
    for r in rows:
        db.exec("INSERT INTO ai_similar (request_id, similar_id, score_tfidf, score_llm, `rank`, why_similar, "
                "resolution, job_id, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s) "
                "ON DUPLICATE KEY UPDATE score_tfidf = VALUES(score_tfidf), score_llm = VALUES(score_llm), "
                "`rank` = VALUES(`rank`), why_similar = VALUES(why_similar), resolution = VALUES(resolution), "
                "job_id = VALUES(job_id)",
                (request_id, r["id"], r.get("score_tfidf"), r.get("score_llm"), int(r.get("rank") or 0),
                 (r.get("why_similar") or "")[:300] or None, r.get("resolution"), job_id, now))


def similar_top(db, request_id: str, limit: int = 5) -> list[dict]:
    return db.all("SELECT s.similar_id, s.score_tfidf, s.score_llm, s.`rank`, s.why_similar, s.resolution, "
                  "r.title, r.status, r.done FROM ai_similar s LEFT JOIN requests r ON r.id = s.similar_id "
                  f"WHERE s.request_id = %s AND s.`rank` > 0 ORDER BY s.`rank` LIMIT {int(limit)}",
                  (request_id,))


def comment_cache_get(db, request_id: str) -> dict | None:
    return db.one("SELECT request_id, thread_ts, text, cmt_count, fetched_at FROM ai_comment_cache "
                  "WHERE request_id = %s", (request_id,))


def comment_cache_set(db, request_id: str, thread_ts: str, text: str, cmt_count: int) -> None:
    db.exec("INSERT INTO ai_comment_cache (request_id, thread_ts, text, cmt_count, fetched_at) "
            "VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE thread_ts = VALUES(thread_ts), "
            "text = VALUES(text), cmt_count = VALUES(cmt_count), fetched_at = VALUES(fetched_at)",
            (request_id, thread_ts or "", text, int(cmt_count or 0), now_str()))


# ----------------------------------------------------------------- repos

def repos_active(db) -> list[dict]:
    return db.all("SELECT id, name, school_id, vcs, remote_url, local_path, default_branch, version, php_bin, "
                  "match_rules, notes, active, check_status, head_revision FROM ai_repos WHERE active = 1 "
                  "ORDER BY id")


def repo_get(db, repo_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_repos WHERE id = %s", (repo_id,))


def repo_update_check(db, repo_id: int, status: str, message: str, head: str | None) -> None:
    db.exec("UPDATE ai_repos SET last_checked = %s, check_status = %s, check_message = %s, "
            "head_revision = COALESCE(%s, head_revision), updated_at = %s WHERE id = %s",
            (now_str(), status, (message or "")[:300], head, now_str(), repo_id))


# ----------------------------------------------------------------- plans

def plan_get(db, plan_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_plans WHERE id = %s", (plan_id,))


def plan_latest(db, request_id: str, statuses: tuple[str, ...] | None = None) -> dict | None:
    if statuses:
        ph = ",".join(["%s"] * len(statuses))
        return db.one(f"SELECT * FROM ai_plans WHERE request_id = %s AND status IN ({ph}) "
                      "ORDER BY version DESC LIMIT 1", (request_id, *statuses))
    return db.one("SELECT * FROM ai_plans WHERE request_id = %s ORDER BY version DESC LIMIT 1",
                  (request_id,))


def plan_max_version(db, request_id: str) -> int:
    return int(db.scalar("SELECT COALESCE(MAX(version), 0) FROM ai_plans WHERE request_id = %s",
                         (request_id,)) or 0)


def plan_insert(db, d: dict) -> int:
    db.exec(
        "INSERT INTO ai_plans (request_id, version, status, repo_id, title, plan_md, plan_json, budget_usd, "
        "est_minutes, user_hint, prompt_md, claude_session_id, base_revision, budget_hit, num_turns, duration_ms, model, "
        "cost_usd, job_id, created_by, created_at) VALUES (%s, %s, 'draft', %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, "
        "%s, %s, %s, %s, %s, %s, %s, %s)",
        (d["request_id"], d["version"], d.get("repo_id"), (d.get("title") or "")[:300], d.get("plan_md"),
         d.get("plan_json"), d.get("budget_usd"), d.get("est_minutes"), d.get("user_hint"), d.get("prompt_md"),
         d.get("claude_session_id"), (d.get("base_revision") or "")[:64] or None, int(bool(d.get("budget_hit"))),
         d.get("num_turns"), d.get("duration_ms"), d.get("model"), d.get("cost_usd"), d.get("job_id"),
         d.get("created_by"), now_str()))
    return int(db.last_id)


def plan_supersede_drafts(db, request_id: str, keep_id: int) -> int:
    return db.exec("UPDATE ai_plans SET status = 'superseded' WHERE request_id = %s AND status = 'draft' "
                   "AND id <> %s", (request_id, keep_id))


def plan_set_status(db, plan_id: int, status: str, **cols) -> None:
    allowed = {"executed_at", "metrics_json", "approved_at"}
    sets, args = ["status = %s"], [status]
    for k, v in cols.items():
        if k in allowed:
            sets.append(f"{k} = %s")
            args.append(v)
    args.append(plan_id)
    db.exec(f"UPDATE ai_plans SET {', '.join(sets)} WHERE id = %s", tuple(args))


def lessons_for_plan(db, repo_id: int | None, tag_ids: list[int], limit: int = 10) -> list[dict]:
    """승인 교훈: 같은 레포 또는 global, 태그 무관 또는 문의 태그와 일치. weight 내림차순."""
    args: list = []
    scope = "(scope = 'global'"
    if repo_id:
        scope += " OR repo_id = %s"
        args.append(repo_id)
    scope += ")"
    tag = "(tag_id IS NULL"
    if tag_ids:
        tag += " OR tag_id IN (" + ",".join(["%s"] * len(tag_ids)) + ")"
        args.extend(tag_ids)
    tag += ")"
    rows = db.all(f"SELECT id, kind, scope, title, lesson_md, weight FROM ai_lessons WHERE status = 'approved' "
                  f"AND {scope} AND {tag} ORDER BY weight DESC, evidence_count DESC, id DESC LIMIT {int(limit)}",
                  tuple(args))
    if rows:
        ph = ",".join(["%s"] * len(rows))
        db.exec(f"UPDATE ai_lessons SET used_count = used_count + 1 WHERE id IN ({ph})",
                tuple(r["id"] for r in rows))
    return rows


# ----------------------------------------------------------------- executions / commits / reviews

def execution_get(db, execution_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_executions WHERE id = %s", (execution_id,))


def execution_for_plan(db, plan_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_executions WHERE plan_id = %s ORDER BY id DESC LIMIT 1", (plan_id,))


def execution_insert(db, plan_id: int, request_id: str, approved_by: str | None, job_id: int | None) -> int:
    db.exec("INSERT INTO ai_executions (plan_id, request_id, status, job_id, approved_by, created_at) "
            "VALUES (%s, %s, 'queued', %s, %s, %s)", (plan_id, request_id, job_id, approved_by, now_str()))
    return int(db.last_id)


def execution_update(db, execution_id: int, **cols) -> None:
    allowed = {"status", "resumed", "preexisting_json", "branch", "diff", "diff_stat", "files_changed",
               "lines_added", "lines_deleted", "lint_ok", "lint_output", "result_md", "log", "budget_hit",
               "model", "cost_usd", "job_id", "started_at", "finished_at"}
    sets, args = [], []
    for k, v in cols.items():
        if k in allowed:
            sets.append(f"{k} = %s")
            args.append(v)
    if not sets:
        return
    args.append(execution_id)
    db.exec(f"UPDATE ai_executions SET {', '.join(sets)} WHERE id = %s", tuple(args))


def commit_get(db, commit_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_commits WHERE id = %s", (commit_id,))


def commit_for_execution(db, execution_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_commits WHERE execution_id = %s ORDER BY id DESC LIMIT 1", (execution_id,))


def commit_update(db, commit_id: int, **cols) -> None:
    allowed = {"vcs", "revision", "branch", "message", "status", "log", "job_id", "committed_at", "repo_id"}
    sets, args = [], []
    for k, v in cols.items():
        if k in allowed:
            sets.append(f"{k} = %s")
            args.append(v)
    if not sets:
        return
    args.append(commit_id)
    db.exec(f"UPDATE ai_commits SET {', '.join(sets)} WHERE id = %s", tuple(args))


def commits_for_request(db, request_id: str) -> list[dict]:
    return db.all("SELECT id, execution_id, vcs, revision, message, status, committed_at FROM ai_commits "
                  "WHERE request_id = %s AND status IN ('committed','pushed') ORDER BY id DESC", (request_id,))


def review_insert(db, d: dict) -> int:
    db.exec("INSERT INTO ai_reviews (commit_id, request_id, reviewer, verdict, addresses_inquiry, report_md, "
            "findings_json, model, cost_usd, job_id, requested_by, created_at) "
            "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
            (d["commit_id"], d["request_id"], d["reviewer"], d.get("verdict"),
             None if d.get("addresses_inquiry") is None else int(bool(d.get("addresses_inquiry"))),
             d.get("report_md"), json.dumps(d.get("findings") or [], ensure_ascii=False), d.get("model"),
             d.get("cost_usd"), d.get("job_id"), d.get("requested_by"), now_str()))
    return int(db.last_id)


def review_latest(db, commit_id: int) -> dict | None:
    return db.one("SELECT * FROM ai_reviews WHERE commit_id = %s ORDER BY id DESC LIMIT 1", (commit_id,))


# ----------------------------------------------------------------- lessons

def lessons_by_repo_kind(db, repo_id: int | None, kind: str) -> list[dict]:
    if repo_id:
        return db.all("SELECT id, lesson_md, weight, evidence_count FROM ai_lessons WHERE kind = %s AND "
                      "(repo_id = %s OR scope = 'global') AND status <> 'rejected'", (kind, repo_id))
    return db.all("SELECT id, lesson_md, weight, evidence_count FROM ai_lessons WHERE kind = %s AND "
                  "status <> 'rejected'", (kind,))


def lesson_bump(db, lesson_id: int) -> None:
    db.exec("UPDATE ai_lessons SET weight = LEAST(1.0, weight + 0.1), evidence_count = evidence_count + 1, "
            "updated_at = %s WHERE id = %s", (now_str(), lesson_id))


def lesson_insert(db, d: dict) -> int:
    db.exec("INSERT INTO ai_lessons (request_id, plan_id, commit_id, kind, scope, repo_id, tag_id, title, "
            "lesson_md, evidence_md, weight, evidence_count, status, approved_by, approved_at, job_id, created_at) "
            "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1, %s, %s, %s, %s, %s)",
            (d.get("request_id"), d.get("plan_id"), d.get("commit_id"), d["kind"], d.get("scope") or "repo",
             d.get("repo_id"), d.get("tag_id"), (d.get("title") or "")[:300], d.get("lesson_md"),
             d.get("evidence_md"), float(d.get("weight") or 0.5), d.get("status") or "proposed",
             d.get("approved_by"), d.get("approved_at"), d.get("job_id"), now_str()))
    return int(db.last_id)


def lessons_approved_for_repo(db, repo_id: int, limit: int = 80) -> list[dict]:
    return db.all("SELECT id, kind, scope, title, lesson_md, evidence_md, weight FROM ai_lessons "
                  "WHERE status = 'approved' AND (repo_id = %s OR scope = 'global') "
                  f"ORDER BY weight DESC, evidence_count DESC LIMIT {int(limit)}", (repo_id,))


def tag_id_by_slug(db, slug: str) -> int | None:
    v = db.scalar("SELECT id FROM ai_tags WHERE slug = %s LIMIT 1", (slug,))
    return int(v) if v is not None else None
