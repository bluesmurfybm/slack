"""DB 읽고 쓰기. 표는 화면(onwatch/inc/db.php)이 sql/ 파일로 만든다 — 여기서는 만들지 않는다.

시각은 모두 KST naive datetime 을 넘긴다. 세션 시간대도 +09:00 으로 맞춰 PHP 의 NOW() 와 같게 둔다.
"""

import contextlib
import json
from datetime import datetime, timedelta

import pymysql
import pymysql.cursors

WATCH_COLS = """id, title, prompt, baseline, owner_id, status, starts_at, ends_at, interval_min, run_time,
    weekday, weekdays_only, hour_from, hour_to, notify_mode, min_importance, notify_slack, slack_webhook_enc,
    prefer_domains, block_domains, max_searches, monthly_budget_usd, next_run_at, last_run_at, last_change_at,
    run_requested_at, run_requested_by, fail_streak, end_notice_sent"""


class SchemaMissingError(RuntimeError):
    pass


class Store:
    def __init__(self, params: dict):
        self.conn = pymysql.connect(**params, charset="utf8mb4", autocommit=True,
                                    cursorclass=pymysql.cursors.DictCursor,
                                    init_command="SET time_zone = '+09:00'")

    def close(self) -> None:
        with contextlib.suppress(Exception):
            self.conn.close()

    def _all(self, sql: str, args: tuple = ()) -> list[dict]:
        self.conn.ping(reconnect=True) # 실행 하나가 몇 분 걸려 그 사이 연결이 끊길 수 있다
        with self.conn.cursor() as cur:
            cur.execute(sql, args)
            return list(cur.fetchall())

    def _one(self, sql: str, args: tuple = ()) -> dict | None:
        rows = self._all(sql, args)
        return rows[0] if rows else None

    def _exec(self, sql: str, args: tuple = ()) -> int:
        self.conn.ping(reconnect=True)
        with self.conn.cursor() as cur:
            n = cur.execute(sql, args)
            return n if n is not None else 0

    def _insert(self, sql: str, args: tuple = ()) -> int:
        self.conn.ping(reconnect=True)
        with self.conn.cursor() as cur:
            cur.execute(sql, args)
            return int(cur.lastrowid)

    def check_schema(self) -> None:
        if not self._one("SHOW TABLES LIKE 'ow_watch'"):
            raise SchemaMissingError(
                "ow_watch 표가 없습니다. 웹에서 /onwatch/ 를 한 번 열거나 php onwatch/bin/migrate.php 를 실행하세요")

    # ---------------------------------------------------------------- 고르기
    def get_watch(self, watch_id: int) -> dict | None:
        return self._one(f"SELECT {WATCH_COLS} FROM ow_watch WHERE id = %s", (watch_id,)) # noqa: S608 열 목록은 상수

    def next_due(self, now: datetime) -> dict | None:
        """지금 실행할 감시 하나. 지금 실행 요청이 먼저, 그다음 정기 실행이 늦은 순."""
        return self._one(
            f"""SELECT {WATCH_COLS} FROM ow_watch
                 WHERE (locked_until IS NULL OR locked_until < %s)
                   AND ((run_requested_at IS NOT NULL AND status IN ('active', 'paused'))
                        OR (status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= %s AND starts_at <= %s))
              ORDER BY (run_requested_at IS NULL), run_requested_at, next_run_at
                 LIMIT 1""", (now, now, now)) # noqa: S608

    def claim(self, watch_id: int, now: datetime, minutes: int, who: str) -> bool:
        """다른 배치가 같은 감시를 동시에 돌리지 않게 잠근다. 잠갔으면 True."""
        return self._exec(
            """UPDATE ow_watch SET locked_until = %s, locked_by = %s
                WHERE id = %s AND (locked_until IS NULL OR locked_until < %s)""",
            (now + timedelta(minutes=minutes), who[:80], watch_id, now)) == 1

    # ---------------------------------------------------------------- 정리
    def expired(self, now: datetime) -> list[dict]:
        return self._all("""SELECT id, title, owner_id FROM ow_watch
                             WHERE status IN ('active', 'paused') AND ends_at <= %s""",
                         (now,))

    def end_watch(self, watch_id: int, now: datetime) -> None:
        self._exec("""UPDATE ow_watch SET status = 'ended', ended_at = %s, next_run_at = NULL,
                             run_requested_at = NULL, run_requested_by = NULL, updated_at = %s
                       WHERE id = %s AND status <> 'ended'""", (now, now, watch_id))

    def ending_soon(self, now: datetime, hours: int = 24) -> list[dict]:
        return self._all("""SELECT id, title, owner_id, ends_at FROM ow_watch
                             WHERE status = 'active' AND end_notice_sent = 0 AND ends_at > %s AND ends_at <= %s""",
                         (now, now + timedelta(hours=hours)))

    def mark_end_notice(self, watch_id: int) -> None:
        self._exec("UPDATE ow_watch SET end_notice_sent = 1 WHERE id = %s", (watch_id,))

    # ---------------------------------------------------------------- 비용
    def month_cost(self, now: datetime, watch_id: int | None = None) -> float:
        start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
        if watch_id is None:
            row = self._one("SELECT COALESCE(SUM(cost_usd), 0) AS c FROM ow_run WHERE started_at >= %s", (start,))
        else:
            row = self._one("""SELECT COALESCE(SUM(cost_usd), 0) AS c FROM ow_run
                                WHERE started_at >= %s AND watch_id = %s""",
                            (start, watch_id))
        return float(row["c"]) if row else 0.0

    def budget_skipped_this_month(self, now: datetime, watch_id: int) -> bool:
        start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
        return bool(self._one("""SELECT 1 AS x FROM ow_run
                                  WHERE watch_id = %s AND status = 'budget' AND started_at >= %s LIMIT 1""",
                              (watch_id, start)))

    # ---------------------------------------------------------------- 실행
    def start_run(self, watch_id: int, trigger: str, requested_by: int | None, now: datetime) -> int:
        return self._insert("""INSERT INTO ow_run (watch_id, `trigger`, requested_by, started_at, status)
                               VALUES (%s, %s, %s, %s, 'running')""", (watch_id, trigger, requested_by, now))

    def finish_run(self, run_id: int, now: datetime, **f) -> None:
        cols = ["finished_at = %s"]
        args: list = [now]
        for k in ("status", "engine", "model", "searches", "input_tokens", "output_tokens", "cost_usd",
                  "new_findings", "dup_findings", "summary", "research_md", "baseline_hint", "notified", "error"):
            if k in f:
                cols.append(f"`{k}` = %s")
                v = f[k]
                if k == "summary" and isinstance(v, str):
                    v = v[:500]
                args.append(v)
        args.append(run_id)
        self._exec(f"UPDATE ow_run SET {', '.join(cols)} WHERE id = %s", tuple(args)) # noqa: S608 열 이름은 위 목록

    def mark_notified(self, run_id: int) -> None:
        self._exec("UPDATE ow_run SET notified = 1 WHERE id = %s", (run_id,))

    def after_run(self, watch_id: int, *, now: datetime, started: datetime, next_run_at: datetime | None, # noqa: PLR0913
                  keep_next: bool, changed: bool, failed: bool) -> int:
        """실행 뒤 감시 갱신. 잠금을 풀고, 이 실행이 집어 간 '지금 실행' 요청을 지운다.
        @return 갱신 뒤 연속 실패 수"""
        self._exec(
            f"""UPDATE ow_watch SET
                    last_run_at = %s,
                    {'' if keep_next else 'next_run_at = %s,'}
                    last_change_at = IF(%s, %s, last_change_at),
                    fail_streak = IF(%s, fail_streak + 1, 0),
                    run_requested_by = IF(run_requested_at IS NOT NULL AND run_requested_at <= %s,
                                          NULL, run_requested_by),
                    run_requested_at = IF(run_requested_at IS NOT NULL AND run_requested_at <= %s,
                                          NULL, run_requested_at),
                    locked_until = NULL, locked_by = NULL
                 WHERE id = %s""", # noqa: S608 갈래는 상수 조각
            tuple([now] + ([] if keep_next else [next_run_at])
                  + [changed, now, failed, started, started, watch_id]))
        row = self._one("SELECT fail_streak FROM ow_watch WHERE id = %s", (watch_id,))
        return int(row["fail_streak"]) if row else 0

    # ---------------------------------------------------------------- 소식
    def prior_findings(self, watch_id: int, limit: int = 60) -> list[dict]:
        return self._all("""SELECT subject_key, marker, title, change_type, published, sources_json, created_at
                              FROM ow_finding WHERE watch_id = %s ORDER BY id DESC LIMIT %s""", (watch_id, limit))

    def rejected_findings(self, watch_id: int, limit: int = 30) -> list[dict]:
        return self._all("""SELECT subject_key, title, feedback FROM ow_finding
                             WHERE watch_id = %s AND feedback IN ('duplicate', 'wrong') ORDER BY id DESC LIMIT %s""",
                         (watch_id, limit))

    def fingerprints(self, watch_id: int) -> set[str]:
        rows = self._all("SELECT fingerprint FROM ow_finding WHERE watch_id = %s", (watch_id,))
        return {r["fingerprint"] for r in rows}

    def last_ok_run(self, watch_id: int) -> datetime | None:
        row = self._one("""SELECT MAX(started_at) AS t FROM ow_run
                            WHERE watch_id = %s AND status IN ('changed', 'quiet')""", (watch_id,))
        return row["t"] if row else None

    def insert_finding(self, watch_id: int, run_id: int, f: dict, *, alerted: bool, now: datetime) -> bool:
        """같은 fingerprint 가 이미 있으면 넣지 않는다(동시 실행 대비 — UNIQUE 키가 최후의 방어선)."""
        return self._exec(
            """INSERT IGNORE INTO ow_finding
                 (watch_id, run_id, fingerprint, subject_key, marker, title, detail_md, change_type, importance,
                  confidence, sources_json, verification, published, alerted, created_at)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
            (watch_id, run_id, f["fingerprint"], f["subject_key"], f["marker"], f["title"], f["detail_md"],
             f["change_type"], f["importance"], f["confidence"],
             json.dumps(f["sources"], ensure_ascii=False), f["verification"], f["published"] or None,
             1 if alerted else 0, now)) == 1

    # ---------------------------------------------------------------- 사람
    def subscribers(self, watch_id: int) -> list[int]:
        rows = self._all("SELECT user_id FROM ow_subscriber WHERE watch_id = %s", (watch_id,))
        return [int(r["user_id"]) for r in rows]

    def log(self, watch_id: int, action: str, detail: str, now: datetime) -> None:
        self._exec("INSERT INTO ow_event (watch_id, user_id, action, detail, created_at) VALUES (%s, NULL, %s, %s, %s)",
                   (watch_id, action, detail[:500], now))
