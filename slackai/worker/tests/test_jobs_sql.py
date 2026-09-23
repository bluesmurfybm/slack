"""SQL 문자열·계약 검증 + PHP 쌍둥이(ai_lib.php / db.php) 드리프트 검사(오프라인)."""

import re
from pathlib import Path

import pytest
from conftest import FakeDb

from core import costs, jobs, store
from jobs import COST_GATED, HANDLERS

PHP_LIB = Path(__file__).resolve().parents[2] / "ai" / "ai_lib.php"
PHP_DB = Path(__file__).resolve().parents[2] / "db.php"


def test_enqueue_sql_contract():
    assert jobs.ENQUEUE_SQL.startswith("INSERT IGNORE INTO ai_jobs")
    assert "'queued', 1," in jobs.ENQUEUE_SQL # status, open_key=1
    assert "dedupe_key" in jobs.ENQUEUE_SQL and "max_attempts" in jobs.ENQUEUE_SQL
    assert jobs.dedupe_key("triage", "Rec1", None) == "triage:Rec1:"
    assert jobs.dedupe_key("commit", "Rec1", 12) == "commit:Rec1:12"
    assert jobs.dedupe_key("distill", None, None) == "distill::"


def test_enqueue_uses_defaults_and_marks_changed():
    db = FakeDb()
    db.last_id = 42
    jid = jobs.enqueue(db, "plan", "Rec1", {"a": 1}, "cli", repo_id=3)
    assert jid == 42
    sql, args = db.execs[0]
    assert args[0] == "plan" and args[3] == 3 and args[4] == "plan:Rec1:" and args[6] == 5 and args[7] == 2
    assert any("ai_changed_at" in str(a) for _, a in db.execs[1:])


def test_enqueue_dedupe_returns_none():
    class Dup(FakeDb):
        def exec(self, sql, args=None):
            self.execs.append((sql, args))
            return 0

    assert jobs.enqueue(Dup(), "triage", "Rec1") is None


def test_claim_sql_repo_serialization_and_order():
    s = jobs.CLAIM_SELECT_SQL
    assert "status = 'queued'" in s and "run_after IS NULL OR j.run_after <= NOW()" in s
    assert "NOT EXISTS" in s and "r.status = 'running'" in s and "r.repo_id = j.repo_id" in s
    assert "r.kind IN ('plan','execute','commit','revert')" in s
    assert s.rstrip().endswith("ORDER BY j.priority DESC, j.id LIMIT 1")
    u = jobs.CLAIM_UPDATE_SQL
    assert "status = 'running'" in u and "attempts = attempts + 1" in u and u.endswith("AND status = 'queued'")


def test_finish_retry_stuck_orphan_sql():
    assert "open_key = NULL" in jobs.FINISH_SQL and "finished_at = NOW()" in jobs.FINISH_SQL
    assert "status = 'queued'" in jobs.RETRY_SQL and "run_after = DATE_ADD(NOW(), INTERVAL %s SECOND)" in jobs.RETRY_SQL
    assert "heartbeat_at < DATE_SUB(NOW(), INTERVAL %s MINUTE)" in jobs.STUCK_SQL and "open_key = NULL" in jobs.STUCK_SQL
    assert "worker LIKE %s" in jobs.ORPHAN_SQL and "'worker restarted'" in jobs.ORPHAN_SQL
    assert costs.DAILY_TOTAL_SQL == "SELECT COALESCE(SUM(cost_usd), 0) AS total FROM ai_jobs WHERE finished_at >= CURDATE()"


def test_fail_or_retry_backoff():
    db = FakeDb()
    st = jobs.fail_or_retry(db, {"id": 1, "attempts": 1, "max_attempts": 3}, "boom", retryable=True)
    assert st == "queued" and db.execs[0][1][1] == 120 # 60 * 2^1
    db2 = FakeDb()
    st2 = jobs.fail_or_retry(db2, {"id": 1, "attempts": 3, "max_attempts": 3}, "boom", retryable=True)
    assert st2 == "failed" and db2.execs[0][0] == jobs.FINISH_SQL
    db3 = FakeDb()
    assert jobs.fail_or_retry(db3, {"id": 1, "attempts": 1, "max_attempts": 3}, "x", retryable=False) == "failed"


def test_handlers_cover_all_kinds():
    assert set(HANDLERS) == set(jobs.PRIORITY) == set(jobs.MAX_ATTEMPTS)
    assert COST_GATED == {"plan", "execute", "review"}


def _php_const(text: str, name: str) -> dict[str, int]:
    m = re.search(name + r"\s*=\s*\[(.*?)\];", text, re.DOTALL)
    assert m, name
    return {k: int(v) for k, v in re.findall(r"'(\w+)'\s*=>\s*(-?\d+)", m.group(1))}


@pytest.mark.skipif(not PHP_LIB.is_file(), reason="ai_lib.php 없음")
def test_priority_and_attempts_match_php():
    text = PHP_LIB.read_text(encoding="utf-8")
    assert _php_const(text, "AI_JOB_PRIORITY") == jobs.PRIORITY
    assert _php_const(text, "AI_JOB_MAX_ATTEMPTS") == jobs.MAX_ATTEMPTS


def _tables(text: str) -> dict[str, list[str]]:
    out = {}
    for m in re.finditer(r"CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\)\s*(?:\$E|\{_E\}|ENGINE)", text, re.DOTALL):
        cols = re.findall(r"^\s*`(\w+)`\s", m.group(2), re.MULTILINE)
        keys = re.findall(r"^\s*(?:UNIQUE KEY|KEY|PRIMARY KEY)\s*(`\w+`)?\s*\(([^)]*)\)", m.group(2), re.MULTILINE)
        out[m.group(1)] = (cols, [(k or "", re.sub(r"\s", "", v)) for k, v in keys])
    return out


@pytest.mark.skipif(not PHP_DB.is_file(), reason="db.php 없음")
def test_ddl_twin_matches_php():
    php = _tables(PHP_DB.read_text(encoding="utf-8"))
    py = _tables("\n".join(store.DDL))
    assert set(py) == set(store.AI_TABLES)
    for t in store.AI_TABLES:
        assert t in php, f"PHP 에 {t} 없음"
        assert php[t][0] == py[t][0], f"{t} 컬럼 드리프트: php={php[t][0]} py={py[t][0]}"
        assert php[t][1] == py[t][1], f"{t} 인덱스 드리프트"
