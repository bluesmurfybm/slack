"""ai_jobs 큐 — enqueue/claim/finish/재시도/복구 + Heartbeat 스레드.

계약(PHP 쌍둥이 slackai/ai/ai_lib.php 와 동일):
  dedupe_key = "kind:request_id:ref_id", open_key = 1 (queued/running) / NULL (종료) → UNIQUE(dedupe_key, open_key)
  PRIORITY / MAX_ATTEMPTS 맵은 AI_JOB_PRIORITY / AI_JOB_MAX_ATTEMPTS 와 같다.
"""

import json
import logging
import os
import socket
import threading
import time
import traceback

from core import store
from core.clock import now_str

logger = logging.getLogger(__name__)

PRIORITY = {"commit": 30, "revert": 25, "execute": 20, "review": 10, "ingest": 8, "plan": 5,
            "resync": 2, "check_repo": 1, "discover_repos": 1, "triage": 0, "learn": -5, "distill": -10}
MAX_ATTEMPTS = {"triage": 3, "review": 3, "resync": 3, "ingest": 3, "check_repo": 2,
                "plan": 2, "learn": 2, "distill": 2, "discover_repos": 1, "execute": 1, "commit": 1, "revert": 1}
SERIALIZED_KINDS = ("plan", "execute", "commit", "revert") # 같은 repo_id 에서 동시에 하나만

ENQUEUE_SQL = (
    "INSERT IGNORE INTO ai_jobs (kind, request_id, ref_id, repo_id, dedupe_key, params, status, open_key, "
    "priority, max_attempts, requested_by, created_at) "
    "VALUES (%s, %s, %s, %s, %s, %s, 'queued', 1, %s, %s, %s, %s)")

CLAIM_SELECT_SQL = (
    "SELECT j.id, j.kind, j.request_id, j.ref_id, j.repo_id, j.params, j.attempts, j.max_attempts, "
    "j.requested_by, j.priority FROM ai_jobs j "
    "WHERE j.status = 'queued' AND (j.run_after IS NULL OR j.run_after <= NOW()) "
    "AND (j.repo_id IS NULL OR NOT EXISTS (SELECT 1 FROM ai_jobs r WHERE r.status = 'running' "
    "AND r.repo_id = j.repo_id AND r.kind IN ('plan','execute','commit','revert'))) "
    "ORDER BY j.priority DESC, j.id LIMIT 1")

CLAIM_UPDATE_SQL = (
    "UPDATE ai_jobs SET status = 'running', worker = %s, started_at = NOW(), heartbeat_at = NOW(), "
    "attempts = attempts + 1, error = NULL WHERE id = %s AND status = 'queued'")

HEARTBEAT_SQL = "UPDATE ai_jobs SET heartbeat_at = NOW(), progress = COALESCE(%s, progress) WHERE id = %s"
CANCEL_CHECK_SQL = "SELECT cancel_requested FROM ai_jobs WHERE id = %s"

FINISH_SQL = (
    "UPDATE ai_jobs SET status = %s, open_key = NULL, finished_at = NOW(), error = %s, result = %s, "
    "model = %s, tokens_in = %s, tokens_out = %s, cost_usd = %s, progress = %s WHERE id = %s")

RETRY_SQL = (
    "UPDATE ai_jobs SET status = 'queued', worker = NULL, heartbeat_at = NULL, error = %s, "
    "run_after = DATE_ADD(NOW(), INTERVAL %s SECOND) WHERE id = %s")

STUCK_SQL = (
    "UPDATE ai_jobs SET status = 'failed', open_key = NULL, finished_at = NOW(), "
    "error = CONCAT('stuck: heartbeat 없음 ', %s, '분') "
    "WHERE status = 'running' AND heartbeat_at < DATE_SUB(NOW(), INTERVAL %s MINUTE)")

ORPHAN_SQL = (
    "UPDATE ai_jobs SET status = 'failed', open_key = NULL, finished_at = NOW(), "
    "error = 'worker restarted' WHERE status = 'running' AND worker LIKE %s")


def worker_name() -> str:
    return f"{socket.gethostname()}:{os.getpid()}"[:60]


def dedupe_key(kind: str, request_id: str | None, ref_id: int | None) -> str:
    return f"{kind}:{request_id or ''}:{'' if ref_id is None else ref_id}"[:90]


def enqueue(db, kind: str, request_id: str | None, params: dict | None = None, requested_by: str = "",
            ref_id: int | None = None, repo_id: int | None = None, priority: int | None = None,
            max_attempts: int | None = None) -> int | None:
    """같은 kind+request_id(+ref_id) 의 열린 잡이 있으면 None(중복). 성공 시 ai_changed_at 갱신."""
    n = db.exec(ENQUEUE_SQL, (
        kind, request_id or None, ref_id, repo_id, dedupe_key(kind, request_id, ref_id),
        json.dumps(params, ensure_ascii=False) if params else None,
        PRIORITY.get(kind, 0) if priority is None else priority,
        MAX_ATTEMPTS.get(kind, 1) if max_attempts is None else max_attempts,
        (requested_by or "")[:190], now_str()))
    if n == 0:
        return None
    jid = int(db.last_id)
    store.mark_changed(db)
    return jid


def claim(db, worker: str) -> dict | None:
    """queued 1건을 running 으로 (레포별 직렬화 포함). rowcount 1 이면 획득."""
    row = db.one(CLAIM_SELECT_SQL)
    if not row:
        return None
    n = db.exec(CLAIM_UPDATE_SQL, (worker, row["id"]))
    db.commit()
    if n != 1:
        return None
    row["params"] = _params(row.get("params"))
    row["attempts"] = int(row.get("attempts") or 0) + 1
    return row



def claim_by_id(db, job_id: int, worker: str) -> dict | None:
    """특정 잡 1건을 running 으로(--once --request/--kind 경로). queued 가 아니면 None."""
    row = db.one("SELECT id, kind, request_id, ref_id, repo_id, params, attempts, max_attempts, requested_by, priority "
                 "FROM ai_jobs WHERE id = %s AND status = 'queued'", (job_id,))
    if not row:
        return None
    n = db.exec(CLAIM_UPDATE_SQL, (worker, row["id"]))
    db.commit()
    if n != 1:
        return None
    row["params"] = _params(row.get("params"))
    row["attempts"] = int(row.get("attempts") or 0) + 1
    return row


def open_job_id(db, kind: str, request_id: str | None, ref_id: int | None) -> int | None:
    """같은 dedupe_key 의 열린(queued/running) 잡 id."""
    v = db.scalar("SELECT id FROM ai_jobs WHERE dedupe_key = %s AND open_key = 1 ORDER BY id DESC LIMIT 1",
                  (dedupe_key(kind, request_id, ref_id),))
    return int(v) if v is not None else None


def _params(v) -> dict:
    if not v:
        return {}
    try:
        d = json.loads(v)
        return d if isinstance(d, dict) else {}
    except (TypeError, ValueError):
        return {}


def finish(db, job_id: int, status: str, *, error: str | None = None, result: dict | None = None,
           model: str | None = None, tokens_in: int | None = None, tokens_out: int | None = None,
           cost_usd: float | None = None, progress: str | None = None) -> None:
    db.exec(FINISH_SQL, (status, (error or None) and error[:4000],
                         json.dumps(result, ensure_ascii=False)[:1_000_000] if result else None,
                         model, tokens_in, tokens_out, cost_usd, (progress or None) and progress[:200], job_id))
    store.mark_changed(db)
    db.commit()


def fail_or_retry(db, job: dict, error: str, *, retryable: bool = True) -> str:
    """재시도 가능 오류 + attempts < max_attempts 면 백오프 후 queued, 아니면 failed. 결과 상태 반환."""
    attempts = int(job.get("attempts") or 1)
    max_att = int(job.get("max_attempts") or 1)
    if retryable and attempts < max_att:
        delay = 60 * (2 ** attempts)
        db.exec(RETRY_SQL, (error[:4000], delay, job["id"]))
        store.mark_changed(db)
        db.commit()
        return "queued"
    finish(db, job["id"], "failed", error=error)
    return "failed"


def recover_stuck(db, minutes: int = 30) -> int:
    n = db.exec(STUCK_SQL, (str(minutes), minutes))
    if n:
        store.mark_changed(db)
    db.commit()
    return n


def fail_orphans(db, host: str | None = None) -> int:
    """이 호스트의 running 행(죽은 프로세스) → failed('worker restarted')."""
    host = host or socket.gethostname()
    n = db.exec(ORPHAN_SQL, (f"{host}:%",))
    if n:
        store.mark_changed(db)
    db.commit()
    return n


def cancel_requested(db, job_id: int) -> bool:
    v = db.scalar(CANCEL_CHECK_SQL, (job_id,))
    return bool(v)


def tb_tail(n: int = 4000) -> str:
    return traceback.format_exc()[-n:]


class Heartbeat:
    """별도 커넥션으로 heartbeat_at 을 갱신하고 cancel_requested 를 감시하는 스레드.

    with Heartbeat(params, job_id, interval, on_cancel=proc_killer) as hb:
        hb.progress("…")
    """

    def __init__(self, db_params: dict, job_id: int, interval: float = 20.0, on_cancel=None,
                 db_factory=None):
        self.db_params = db_params
        self.job_id = job_id
        self.interval = max(2.0, float(interval))
        self.on_cancel = on_cancel
        self.db_factory = db_factory
        self._progress: str | None = None
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, name=f"hb-{job_id}", daemon=True)
        self.cancelled = False
        self.error: str | None = None

    def progress(self, text: str) -> None:
        self._progress = (text or "")[:200]

    def __enter__(self):
        self._thread.start()
        return self

    def __exit__(self, *exc):
        self._stop.set()
        self._thread.join(timeout=5)
        return False

    def _open(self):
        if self.db_factory:
            return self.db_factory()
        from core.db import Db

        return Db(self.db_params)

    def _run(self) -> None:
        db = None
        try:
            db = self._open()
        except Exception as e:
            self.error = f"heartbeat db: {e}"
            logger.warning("heartbeat 커넥션 실패: %s", e)
            return
        while not self._stop.is_set():
            try:
                db.ensure()
                db.exec(HEARTBEAT_SQL, (self._progress, self.job_id))
                db.commit()
                if not self.cancelled and cancel_requested(db, self.job_id):
                    self.cancelled = True
                    logger.warning("잡 %s 취소 요청 감지", self.job_id)
                    if self.on_cancel:
                        try:
                            self.on_cancel()
                        except Exception:
                            logger.exception("on_cancel 실패")
            except Exception as e:
                self.error = str(e)
                logger.warning("heartbeat 실패: %s", e)
                time.sleep(1)
            self._stop.wait(self.interval)
        try:
            if db:
                db.close()
        except Exception:
            pass
