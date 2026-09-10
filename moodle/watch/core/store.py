"""MySQL 저장. 주차당 리포트 1행 + 항목 N행 + 실행 이력.

주 1회 timer 가 새 주차를 만들고, 화면의 '다시 가져오기'는 같은 주차를 갱신한다. 갱신은 항목을
다시 넣되 처음 들어온 실행 번호(added_run)를 보존해서 "처음 것"과 "나중에 추가된 것"을 가른다.
쓰기는 실행당 한 트랜잭션이고 폴링·UPDATE 는 없다.
"""

import json
from datetime import UTC, datetime

from core.items import Item

DDL = [
    """
    CREATE TABLE IF NOT EXISTS `moodle_weekly_report` (
        `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `week`               VARCHAR(10)  NOT NULL COMMENT 'ISO 주차 예: 2026-W37',
        `period_start`       DATETIME     NOT NULL,
        `period_end`         DATETIME     NOT NULL,
        `generated_at`       DATETIME     NOT NULL COMMENT '마지막 갱신 시각(UTC)',
        `first_generated_at` DATETIME     NULL     COMMENT '처음 생성 시각(UTC)',
        `run_count`          INT UNSIGNED NOT NULL DEFAULT 1,
        `status`             VARCHAR(12)  NOT NULL COMMENT 'ok | partial | failed',
        `headline`           VARCHAR(300) NOT NULL DEFAULT '',
        `summary_md`         MEDIUMTEXT   NULL,
        `updates_md`         MEDIUMTEXT   NULL     COMMENT '마지막 갱신에서 새로 들어온 것 요약',
        `actions_json`       TEXT         NULL,
        `sources_json`       TEXT         NOT NULL COMMENT '소스별 status/note/stats',
        `model`              VARCHAR(80)  NOT NULL DEFAULT '',
        `note`               TEXT         NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_week` (`week`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS `moodle_weekly_item` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `report_id`     INT UNSIGNED NOT NULL,
        `source`        VARCHAR(20)  NOT NULL,
        `kind`          VARCHAR(20)  NOT NULL,
        `title`         VARCHAR(500) NOT NULL,
        `url`           VARCHAR(700) NOT NULL,
        `published_at`  DATETIME     NULL,
        `excerpt`       TEXT         NULL,
        `meta_json`     TEXT         NULL,
        `is_focus`      TINYINT(1)   NOT NULL DEFAULT 0,
        `impact`        VARCHAR(4)   NULL COMMENT '고 | 중 | 저 (요약 모델 판정)',
        `impact_reason` TEXT         NULL,
        `added_run`     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '이 항목이 처음 들어온 실행 번호',
        PRIMARY KEY (`id`),
        KEY `ix_report` (`report_id`, `source`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS `moodle_weekly_run` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `report_id`  INT UNSIGNED NOT NULL,
        `run_no`     INT UNSIGNED NOT NULL,
        `ran_at`     DATETIME     NOT NULL,
        `trigger`    VARCHAR(12)  NOT NULL COMMENT 'timer | manual | cli',
        `status`     VARCHAR(12)  NOT NULL,
        `new_items`  INT UNSIGNED NOT NULL DEFAULT 0,
        `headline`   VARCHAR(300) NOT NULL DEFAULT '',
        `updates_md` MEDIUMTEXT   NULL,
        `note`       TEXT         NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_run` (`report_id`, `run_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
]

# 첫 배포판 테이블(실행 이력 이전)에 붙이는 컬럼. 이미 있으면 MySQL 이 1060 을 내고 그냥 넘어간다.
MIGRATIONS = [
    ("ALTER TABLE `moodle_weekly_report` ADD COLUMN `first_generated_at` DATETIME NULL"
     " AFTER `generated_at`"),
    ("ALTER TABLE `moodle_weekly_report` ADD COLUMN `run_count` INT UNSIGNED NOT NULL DEFAULT 1"
     " AFTER `first_generated_at`"),
    "ALTER TABLE `moodle_weekly_report` ADD COLUMN `updates_md` MEDIUMTEXT NULL AFTER `summary_md`",
    "ALTER TABLE `moodle_weekly_item` ADD COLUMN `added_run` INT UNSIGNED NOT NULL DEFAULT 1",
]
DUPLICATE_COLUMN = 1060


def _dt(iso_s: str) -> str | None:
    """ISO(UTC) → MySQL DATETIME 문자열(UTC). 화면(PHP)에서 KST 로 바꿔 보인다."""
    if not iso_s:
        return None
    return iso_s[:19].replace("T", " ")


def _iso(v) -> str:
    """DB 에서 읽은 DATETIME(naive, UTC) → ISO."""
    if v is None:
        return ""
    if isinstance(v, datetime):
        return v.replace(tzinfo=UTC).isoformat(timespec="seconds")
    return str(v).replace(" ", "T") + "+00:00"


def connect(params: dict):
    import pymysql  # noqa: PLC0415 테스트는 가짜 커넥션을 주입한다

    return pymysql.connect(**params, charset="utf8mb4", autocommit=False)


def ensure_schema(conn) -> None:
    with conn.cursor() as cur:
        for ddl in DDL:
            cur.execute(ddl)
        for sql in MIGRATIONS:
            try:
                cur.execute(sql)
            except Exception as e:
                if not (e.args and e.args[0] == DUPLICATE_COLUMN):
                    raise
        cur.execute("UPDATE moodle_weekly_report SET first_generated_at = generated_at"
                    " WHERE first_generated_at IS NULL")
        # 이력 없이 만들어진 리포트는 1회차 행을 만들어 타임라인이 비지 않게 한다
        cur.execute(
            "INSERT INTO moodle_weekly_run (report_id, run_no, ran_at, `trigger`, status,"
            " new_items, headline)"
            " SELECT r.id, 1, r.generated_at, 'cli', r.status,"
            " (SELECT COUNT(*) FROM moodle_weekly_item i WHERE i.report_id = r.id), r.headline"
            " FROM moodle_weekly_report r"
            " WHERE NOT EXISTS (SELECT 1 FROM moodle_weekly_run x WHERE x.report_id = r.id)")
    conn.commit()


def load_week(conn, week: str) -> dict | None:
    """갱신 대상 주차. 없으면 None. known 은 url → 처음 들어온 실행 번호와 이전 영향도."""
    with conn.cursor() as cur:
        cur.execute("SELECT id, period_start, period_end, run_count, first_generated_at,"
                    " headline, summary_md, actions_json, model"
                    " FROM moodle_weekly_report WHERE week = %s", (week,))
        row = cur.fetchone()
        if not row:
            return None
        rid, start, end, run_count, first, headline, summary_md, actions_json, model = row
        cur.execute("SELECT url, added_run, impact, impact_reason FROM moodle_weekly_item"
                    " WHERE report_id = %s", (rid,))
        known = {url: {"added_run": int(added_run or 1), "impact": impact, "impact_reason": reason}
                 for url, added_run, impact, reason in cur.fetchall()}
        cur.execute("SELECT week FROM moodle_weekly_report ORDER BY week DESC LIMIT 1")
        latest = cur.fetchone()
    try:
        actions = json.loads(actions_json) if actions_json else []
    except ValueError:
        actions = []
    return {"id": rid, "week": week, "period_start": _iso(start), "period_end": _iso(end),
            "run_count": int(run_count or 1), "first_generated_at": _iso(first),
            "headline": headline or "", "summary_md": summary_md, "actions": actions,
            "model": model or "",
            "known": known, "is_latest": bool(latest) and latest[0] == week}


def save_report(conn, report: dict, items: list[Item], previous: dict | None = None,
                trigger: str = "cli") -> dict:
    """리포트를 넣거나(1회차) 갱신한다(2회차 이후). 결과: id, run_no, new_items."""
    if previous is None:
        # 이력 없이 같은 주차를 다시 돌린 경우(--since 로 재실행 등)도 갱신으로 취급한다
        previous = load_week(conn, report["week"])

    run_no = previous["run_count"] + 1 if previous else 1
    known = previous["known"] if previous else {}
    for it in items:
        old = known.get(it.url)
        it.meta["added_run"] = old["added_run"] if old else run_no
        # 새 요약이 영향도를 안 매긴 기존 항목은 이전 판정을 유지한다
        if old and not it.meta.get("impact") and old.get("impact"):
            it.meta["impact"] = old["impact"]
            it.meta["impact_reason"] = old.get("impact_reason")
    new_items = sum(1 for it in items if it.meta["added_run"] == run_no)

    with conn.cursor() as cur:
        if previous:
            report_id = previous["id"]
            cur.execute("DELETE FROM moodle_weekly_item WHERE report_id = %s", (report_id,))
            cols = {"period_end": _dt(report["period_end"]),
                    "generated_at": _dt(report["generated_at"]), "status": report["status"],
                    "sources_json": json.dumps(report.get("sources") or [], ensure_ascii=False),
                    "note": report.get("note")}
            # 요약 없이 돈 갱신(요약기 꺼짐·실패)은 이전 요약을 지우지 않는다.
            # 항목과 이력만 새로 쓴다.
            if report.get("summary_md") is not None:
                cols.update(headline=report.get("headline", "")[:300],
                            summary_md=report.get("summary_md"),
                            updates_md=report.get("updates_md"),
                            actions_json=json.dumps(report.get("actions") or [],
                                                    ensure_ascii=False),
                            model=report.get("model", "")[:80])
            cols["run_count"] = run_no
            sets = ", ".join(f"{k}=%s" for k in cols)
            cur.execute(f"UPDATE moodle_weekly_report SET {sets} WHERE id=%s", # noqa: S608 상수 컬럼명
                        (*cols.values(), report_id))
        else:
            cur.execute(
                "INSERT INTO moodle_weekly_report (week, period_start, first_generated_at,"
                " run_count, period_end, generated_at, status, headline, summary_md, updates_md,"
                " actions_json, sources_json, model, note)"
                " VALUES (%s,%s,%s,1,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (report["week"], _dt(report["period_start"]), _dt(report["generated_at"]),
                 _dt(report["period_end"]), _dt(report["generated_at"]), report["status"],
                 report.get("headline", "")[:300], report.get("summary_md"),
                 report.get("updates_md"),
                 json.dumps(report.get("actions") or [], ensure_ascii=False),
                 json.dumps(report.get("sources") or [], ensure_ascii=False),
                 report.get("model", "")[:80], report.get("note")))
            report_id = cur.lastrowid

        rows = [(report_id, it.source, it.kind[:20], it.title[:500], it.url[:700],
                 _dt(it.published_at), it.excerpt or None,
                 json.dumps(it.meta, ensure_ascii=False) if it.meta else None,
                 int(it.is_focus), it.meta.get("impact"), it.meta.get("impact_reason"),
                 it.meta["added_run"])
                for it in items]
        if rows:
            cur.executemany(
                "INSERT INTO moodle_weekly_item (report_id, source, kind, title, url, published_at,"
                " excerpt, meta_json, is_focus, impact, impact_reason, added_run)"
                " VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", rows)
        cur.execute(
            "INSERT INTO moodle_weekly_run (report_id, run_no, ran_at, `trigger`, status,"
            " new_items, headline, updates_md, note) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            (report_id, run_no, _dt(report["generated_at"]), trigger[:12], report["status"],
             new_items, report.get("headline", "")[:300], report.get("updates_md"),
             report.get("note")))
    conn.commit()
    return {"id": report_id, "run_no": run_no, "new_items": new_items}
