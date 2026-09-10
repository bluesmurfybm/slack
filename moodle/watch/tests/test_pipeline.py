import json
import re
from datetime import UTC, datetime

import pytest

import digest
import refresh_queue
import run_weekly
import summarizer
from collectors.base import Result
from conftest import SINCE, UNTIL, make_settings
from core import store
from core.items import Item
from core.snapshot import State
from run_weekly import RunOptions


def _item(source, title, url, focus=False, excerpt="본문"):
    return Item(source, "x", title, url, "2026-09-03T00:00:00+00:00", excerpt, {}, focus)


def test_digest_lists_only_focus_tracker_items_and_stats():
    results = [
        Result("tracker", [_item("tracker", "MDL-1 React", "u1", True),
                           _item("tracker", "MDL-2 typo", "u2")], {"resolved": 2}),
        Result("moodleorg", status="skipped", note="토큰 없음"),
    ]
    text = digest.build(results, "2026-09-01T00:00:00", "2026-09-08T00:00:00")
    assert "MDL-1 React" in text
    assert "MDL-2 typo" not in text
    assert '"resolved": 2' in text
    assert "moodle.org PAG (핵심 소스 — 요약의 중심) — skipped (토큰 없음)" in text
    assert "★" in text
    assert "[NEW]" not in text


def test_digest_update_lists_only_new_items():
    old, new = _item("moodlecom", "old", "u-old"), _item("moodlecom", "new", "u-new")
    results = [Result("moodlecom", [old, new]), Result("tracker", [], {"resolved": 3})]
    text = digest.build_update(results, [new], "2026-09-01", "2026-09-10", 2)
    assert "2회차 갱신" in text
    assert "새로 들어온 항목 1건" in text
    assert "- [NEW] [x] new" in text
    assert "old" not in text
    assert '"resolved": 3' in text


def test_digest_is_capped():
    many = [_item("moodlecom", f"n{i}", f"u{i}", excerpt="x" * 400) for i in range(50)]
    text = digest.build([Result("moodlecom", many)], "a", "b")
    assert "외 30건 생략" in text


def test_summary_parse_accepts_fenced_json_and_drops_bad_impacts():
    raw = '설명\n```json\n{"headline": "h", "summary_md": "## 한눈에", "updates_md": "- 새것", "impacts": ' \
          '[{"url": "u", "impact": "고", "reason": "r"}, {"url": "v", "impact": "매우", "reason": ""}], ' \
          '"actions": ["a"]}\n```'
    s = summarizer.parse(raw, "cli", "")
    assert s.headline == "h"
    assert s.updates_md == "- 새것"
    assert s.impacts == [{"url": "u", "impact": "고", "reason": "r"}]
    assert s.actions == ["a"]


def test_summary_parse_tolerates_missing_updates():
    s = summarizer.parse('{"headline": "h", "summary_md": "m", "impacts": [], "actions": []}', "x", "")
    assert s.updates_md == ""


def test_parse_update_reads_only_the_update_fields():
    s = summarizer.parse_update('{"updates_md": "### PAG\\n- 새 글", "impacts": [{"url": "u", "impact": "중", "reason": "r"}], "actions": ["a"]}', "cli", "")
    assert s.summary_md == ""
    assert s.updates_md.startswith("### PAG")
    assert s.impacts[0]["impact"] == "중"
    assert s.actions == ["a"]


def test_append_update_keeps_previous_text_and_adds_a_divider():
    at = datetime(2026, 9, 10, 3, 0, tzinfo=UTC) # KST 12:00
    out = run_weekly.append_update("## 한눈에\n- a\n", "- 새 글 [x](u)", at, 2)
    assert out.startswith("## 한눈에\n- a\n\n---\n\n### 갱신 2026-09-10 12:00 · 새 항목 2건\n\n- 새 글")
    assert run_weekly.append_update(None, "- x", at, 1).startswith("---")


def test_summarize_none_mode_returns_reason(settings):
    s, why = summarizer.summarize(settings, "digest", "2026-W37")
    assert s is None
    assert why == "SUMMARIZER=none"


def test_summarize_auto_without_credentials_or_cli_explains(settings, monkeypatch):
    monkeypatch.setattr(summarizer.shutil, "which", lambda _: None)
    s, why = summarizer.summarize(settings.model_copy(update={"summarizer": "auto"}), "d", "w")
    assert s is None
    assert "claude CLI" in why


def test_summarize_falls_back_to_cli_when_anthropic_fails(settings, monkeypatch):
    def broken(*a, **k):
        raise RuntimeError("401")

    monkeypatch.setattr(summarizer, "with_anthropic", broken)
    monkeypatch.setattr(summarizer, "with_cli",
                        lambda st, sy, sc, pr: ('{"headline": "h", "summary_md": "md", "impacts": [], "actions": []}', ""))
    st = settings.model_copy(update={"summarizer": "auto", "anthropic_api_key": "k"})
    s, why = summarizer.summarize(st, "d", "w")
    assert s.backend == "claude-cli"
    assert s.summary_md == "md"
    assert why == ""


def test_apply_impacts_matches_urls_ignoring_anchors():
    items = [_item("moodleorg", "t", "https://x/d?d=1#p9"), _item("tracker", "t2", "https://y")]
    s = summarizer.Summary("h", "m", impacts=[{"url": "https://x/d?d=1", "impact": "고", "reason": "r"},
                                              {"url": "https://y", "impact": "저", "reason": "q"},
                                              {"url": "https://nowhere", "impact": "중", "reason": ""}])
    run_weekly.apply_impacts(items, s)
    assert items[0].meta == {"impact": "고", "impact_reason": "r"}
    assert items[1].meta["impact"] == "저"


@pytest.mark.parametrize(("statuses", "has_summary", "expected"), [
    (["ok", "ok"], True, "ok"),
    (["ok", "skipped"], True, "ok"),
    (["ok", "failed"], True, "partial"),
    (["ok", "ok"], False, "partial"),
    (["failed", "failed"], False, "failed"),
])
def test_decide_status(statuses, has_summary, expected):
    results = [Result(f"s{i}", status=s) for i, s in enumerate(statuses)]
    s = summarizer.Summary("h", "m") if has_summary else None
    assert run_weekly.decide_status(results, s) == expected


def test_decide_status_refresh_without_new_items_is_ok():
    assert run_weekly.decide_status([Result("a")], None, needed_summary=False) == "ok"


class FakeCursor:
    """SELECT 는 conn.rows 에서, 나머지는 conn.log 에 기록한다."""

    def __init__(self, conn):
        self.conn = conn
        self.lastrowid = 42
        self._rows = []

    def execute(self, sql, params=None):
        flat = " ".join(sql.split())
        self.conn.log.append((flat, params))
        self._rows = []
        if flat.startswith("SELECT id, period_start"):
            row = self.conn.report_row
            # (id, start, end, run_count, first[, headline, summary_md, actions_json, model])
            if row and len(row) == 5:
                row = (*row, "이전 헤드라인", "## 한눈에\n- 이전 요약", '["이전 액션"]', "anthropic:x")
            self._rows = [row] if row else []
        elif flat.startswith("SELECT url, added_run"):
            self._rows = list(self.conn.item_rows)
        elif flat.startswith("SELECT week FROM moodle_weekly_report ORDER BY week DESC"):
            self._rows = [(self.conn.latest_week,)] if self.conn.latest_week else []
        elif flat.startswith("SELECT id FROM moodle_weekly_report WHERE week"):
            self._rows = [(self.conn.report_row[0],)] if self.conn.report_row else []

    def executemany(self, sql, rows):
        self.conn.log.append((" ".join(sql.split()), list(rows)))

    def fetchone(self):
        return self._rows[0] if self._rows else None

    def fetchall(self):
        return list(self._rows)

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False


class FakeConn:
    def __init__(self, report_row=None, item_rows=(), latest_week=None):
        self.log = []
        self.commits = 0
        self.closed = False
        self.report_row = report_row
        self.item_rows = list(item_rows)
        self.latest_week = latest_week

    def cursor(self):
        return FakeCursor(self)

    def commit(self):
        self.commits += 1

    def close(self):
        self.closed = True

    def sqls(self):
        return [s for s, _ in self.log]

    def params_of(self, prefix):
        # 스키마 마이그레이션 문장(params 없음)은 건너뛰고 실제 데이터 문장만 본다
        return next(p for s, p in self.log if s.startswith(prefix) and p is not None)


REPORT = {"week": "2026-W36", "period_start": "2026-08-25T00:00:00+00:00",
          "period_end": "2026-09-01T00:00:00+00:00", "generated_at": "2026-09-01T01:00:00+00:00",
          "status": "ok", "headline": "h", "summary_md": "md", "updates_md": None, "actions": ["a"],
          "sources": [{"source": "tracker"}], "model": "anthropic:claude-opus-5"}


def test_store_schema_creates_tables_runs_migrations_and_backfills():
    conn = FakeConn()
    store.ensure_schema(conn)
    sqls = conn.sqls()
    assert sum("CREATE TABLE IF NOT EXISTS" in s for s in sqls) == 3
    assert sum(s.startswith("ALTER TABLE") for s in sqls) == len(store.MIGRATIONS)
    assert any(s.startswith("INSERT INTO moodle_weekly_run") and "NOT EXISTS" in s for s in sqls)


def test_store_schema_ignores_duplicate_column_errors():
    class Conn(FakeConn):
        def cursor(self):
            cur = FakeCursor(self)
            orig = cur.execute

            def execute(sql, params=None):
                if sql.startswith("ALTER TABLE"):
                    raise Exception(1060, "Duplicate column name") # pymysql 예외 흉내
                return orig(sql, params)
            cur.execute = execute
            return cur

    store.ensure_schema(Conn())


def test_store_first_run_inserts_report_items_and_run_row():
    conn = FakeConn()
    items = [Item("tracker", "issue", "t", "u", "2026-09-03T10:00:00+00:00", "e",
                  {"impact": "고", "impact_reason": "r"}, True)]
    out = store.save_report(conn, REPORT, items, trigger="timer")

    assert out == {"id": 42, "run_no": 1, "new_items": 1}
    assert not any(s.startswith("DELETE") for s in conn.sqls())
    ins = conn.params_of("INSERT INTO moodle_weekly_report")
    assert ins[0] == "2026-W36"
    assert ins[1] == "2026-08-25 00:00:00" # ISO → DATETIME
    rows = conn.params_of("INSERT INTO moodle_weekly_item")
    assert rows[0][0] == 42
    assert rows[0][5] == "2026-09-03 10:00:00"
    assert rows[0][9:] == ("고", "r", 1)
    run_row = conn.params_of("INSERT INTO moodle_weekly_run")
    assert run_row[:2] == (42, 1)
    assert run_row[3] == "timer"
    assert conn.commits == 1


def test_store_refresh_keeps_added_run_and_old_impacts():
    conn = FakeConn(report_row=(7, datetime(2026, 8, 25), datetime(2026, 9, 1), 2, datetime(2026, 9, 1)),
                    item_rows=[("u-old", 1, "중", "이전 이유"), ("u-gone", 2, None, None)],
                    latest_week="2026-W36")
    items = [Item("tracker", "issue", "old", "u-old", "", "", {}, False),
             Item("tracker", "issue", "new", "u-new", "", "", {"impact": "고", "impact_reason": "r"}, True)]
    out = store.save_report(conn, {**REPORT, "updates_md": "- 새것"}, items, trigger="manual")

    assert out == {"id": 7, "run_no": 3, "new_items": 1}
    assert items[0].meta == {"added_run": 1, "impact": "중", "impact_reason": "이전 이유"}
    assert items[1].meta["added_run"] == 3
    sqls = conn.sqls()
    assert any(s.startswith("DELETE FROM moodle_weekly_item") for s in sqls)
    assert not any(s.startswith("INSERT INTO moodle_weekly_report") for s in sqls)
    upd = conn.params_of("UPDATE moodle_weekly_report")
    assert upd[-2:] == (3, 7) # run_count, id
    run_row = conn.params_of("INSERT INTO moodle_weekly_run")
    assert run_row[1] == 3
    assert run_row[3] == "manual"
    assert run_row[5] == 1
    assert run_row[7] == "- 새것"


def test_store_refresh_without_summary_keeps_previous_summary():
    conn = FakeConn(report_row=(7, datetime(2026, 8, 25), datetime(2026, 9, 1), 1, None),
                    latest_week="2026-W36")
    store.save_report(conn, {**REPORT, "summary_md": None, "headline": "", "updates_md": None},
                      [Item("tracker", "issue", "n", "u", "", "", {}, False)])
    sql = next(s for s in conn.sqls() if s.startswith("UPDATE moodle_weekly_report"))
    assert "summary_md" not in sql
    assert "headline" not in sql
    assert "run_count=%s" in sql


def test_load_week_reads_period_known_urls_and_latest_flag():
    conn = FakeConn(report_row=(7, datetime(2026, 8, 25), "2026-09-01 00:00:00", 1, None),
                    item_rows=[("u1", 1, None, None)], latest_week="2026-W37")
    prev = store.load_week(conn, "2026-W36")
    assert prev["period_start"] == "2026-08-25T00:00:00+00:00"
    assert prev["headline"] == "이전 헤드라인"
    assert prev["actions"] == ["이전 액션"]
    assert prev["period_end"] == "2026-09-01T00:00:00+00:00"
    assert prev["known"] == {"u1": {"added_run": 1, "impact": None, "impact_reason": None}}
    assert prev["is_latest"] is False
    assert store.load_week(FakeConn(), "2026-W99") is None


def test_state_period_uses_last_run_but_caps_lookback(tmp_path):
    st = State(make_settings(tmp_path))
    now = datetime(2026, 9, 8, tzinfo=UTC)
    since, until = st.period(now)
    assert (until - since).days == 7

    st.data["last_run"] = "2026-07-01T00:00:00+00:00"
    since, _ = st.period(now)
    assert (now - since).days == 21 # max_lookback_days

    st.data["last_run"] = "2026-09-05T00:00:00+00:00"
    since, _ = st.period(now)
    assert since == datetime(2026, 9, 5, tzinfo=UTC)

    st.mark_run(now)
    st.save()
    assert json.loads(st.path.read_text(encoding="utf-8"))["last_run"] == "2026-09-08T00:00:00+00:00"


def _stub_summary(**over):
    base = {"headline": "헤드라인", "summary_md": "## 한눈에\n- a",
            "impacts": [{"url": "https://t/1", "impact": "고", "reason": "r"}],
            "actions": ["act"], "backend": "anthropic", "model": "claude-opus-5"}
    return summarizer.Summary(**{**base, **over})


def test_run_end_to_end_with_stub_collectors(tmp_path, monkeypatch):
    settings = make_settings(tmp_path, summarizer="auto", anthropic_api_key="k")
    ok = Result("tracker", [_item("tracker", "MDL-1 React", "https://t/1", True)], {"resolved": 1})
    bad = Result("github", status="failed", note="boom")
    monkeypatch.setattr(run_weekly, "COLLECTORS", [("tracker", lambda ctx: ok),
                                                   ("github", lambda ctx: bad)])
    monkeypatch.setattr(summarizer, "with_anthropic", lambda st, sy, sc, pr: (json.dumps({
        "headline": "헤드라인", "summary_md": "## 한눈에\n- a",
        "impacts": [{"url": "https://t/1", "impact": "고", "reason": "r"}], "actions": ["act"]}), "claude-opus-5"))
    sent = []
    monkeypatch.setattr(run_weekly.notify, "send", lambda st, text: sent.append(text))
    conns = []

    def factory(params):
        c = FakeConn()
        conns.append(c)
        return c

    report = run_weekly.run(settings, RunOptions(since=SINCE, until=UNTIL, trigger="timer"), factory)

    assert report["week"] == "2026-W37"
    assert report["run_no"] == 1
    assert report["status"] == "partial" # github 실패
    assert report["headline"] == "헤드라인"
    assert report["id"] == 42
    assert report["new_items"] == 1
    assert report["item_count"] == 1
    assert conns[0].closed
    assert conns[0].params_of("INSERT INTO moodle_weekly_run")[3] == "timer"
    snap = json.loads((tmp_path / "var" / "snapshots" / "2026-W37-r1.json").read_text(encoding="utf-8"))
    assert snap["items"][0]["meta"]["impact"] == "고"
    assert "MDL-1 React" in snap["digest"]
    state = json.loads((tmp_path / "var" / "state.json").read_text(encoding="utf-8"))
    assert state["last_run"] == "2026-09-08T00:00:00+00:00"
    assert len(sent) == 1
    assert ":warning: *MoodleUp? 주간 리포트 2026-W37* — partial" in sent[0]
    assert "github: boom" in sent[0]
    assert "/moodle/?week=2026-W37" in sent[0]


def test_run_refresh_summarizes_only_new_items_and_appends(tmp_path, monkeypatch):
    settings = make_settings(tmp_path, summarizer="auto", anthropic_api_key="k")
    res = Result("moodlecom", [_item("moodlecom", "old", "u-old"), _item("moodlecom", "new", "u-new")])
    monkeypatch.setattr(run_weekly, "COLLECTORS", [("moodlecom", lambda ctx: res)])
    seen = {}

    def fake_anthropic(st, system, schema, prompt):
        seen["system"] = system
        seen["prompt"] = prompt
        seen["schema"] = schema
        return json.dumps({"updates_md": "- new 가 들어왔다 [x](u-new)",
                           "impacts": [{"url": "u-new", "impact": "중", "reason": "r"}],
                           "actions": ["새 액션"]}), "claude-opus-5"

    monkeypatch.setattr(summarizer, "with_anthropic", fake_anthropic)
    monkeypatch.setattr(run_weekly.notify, "send", lambda st, text: None)
    conn = FakeConn(report_row=(7, datetime(2026, 9, 1), datetime(2026, 9, 8), 1, datetime(2026, 9, 8)),
                    item_rows=[("u-old", 1, "저", "x")], latest_week="2026-W37")
    now = datetime(2026, 9, 10, 12, tzinfo=UTC)

    report = run_weekly.run(settings, RunOptions(week="2026-W37", refresh=True, trigger="manual",
                                                 until=now), lambda p: conn)

    assert report["run_no"] == 2
    assert report["period_end"] == "2026-09-10T12:00:00+00:00" # 최신 주차는 지금까지 늘린다
    assert report["new_items"] == 1
    assert "갱신 실행" in seen["system"]
    assert "updates_md" in seen["schema"]["properties"]
    assert "- [NEW] [x] new" in seen["prompt"]
    assert "old" not in seen["prompt"].split("새로 들어온 항목")[1]
    assert report["headline"] == "이전 헤드라인" # 헤드라인·기존 요약은 그대로
    assert report["summary_md"].startswith("## 한눈에\n- 이전 요약\n\n---\n\n### 갱신 20")
    assert re.search(r"### 갱신 \d{4}-\d{2}-\d{2} \d{2}:\d{2} · 새 항목 1건\n\n", report["summary_md"])
    assert report["summary_md"].endswith("- new 가 들어왔다 [x](u-new)")
    assert report["updates_md"] == "- new 가 들어왔다 [x](u-new)"
    assert report["actions"] == ["이전 액션", "새 액션"]
    assert report["status"] == "ok"
    assert conn.params_of("UPDATE moodle_weekly_report")[-2:] == (2, 7)
    assert (tmp_path / "var" / "snapshots" / "2026-W37-r2.json").is_file()


def test_run_refresh_without_new_items_skips_the_model_and_keeps_summary(tmp_path, monkeypatch):
    settings = make_settings(tmp_path, summarizer="auto", anthropic_api_key="k")
    res = Result("moodlecom", [_item("moodlecom", "old", "u-old")])
    monkeypatch.setattr(run_weekly, "COLLECTORS", [("moodlecom", lambda ctx: res)])

    def never(*a, **k):
        raise AssertionError("새 항목이 없으면 모델을 부르지 않는다")

    monkeypatch.setattr(summarizer, "with_anthropic", never)
    monkeypatch.setattr(run_weekly.notify, "send", lambda st, text: None)
    conn = FakeConn(report_row=(7, datetime(2026, 9, 1), datetime(2026, 9, 8), 1, None),
                    item_rows=[("u-old", 1, None, None)], latest_week="2026-W37")
    report = run_weekly.run(settings, RunOptions(week="2026-W37", refresh=True), lambda p: conn)
    assert report["new_items"] == 0
    assert report["status"] == "ok"
    assert report["summary_md"] is None # store 가 기존 요약을 그대로 둔다
    assert report["note"] is None
    sql = next(s for s in conn.sqls() if s.startswith("UPDATE moodle_weekly_report"))
    assert "summary_md" not in sql


def test_run_refresh_after_a_summaryless_run_makes_a_full_summary(tmp_path, monkeypatch):
    settings = make_settings(tmp_path, summarizer="auto", anthropic_api_key="k")
    res = Result("moodlecom", [_item("moodlecom", "old", "u-old")])
    monkeypatch.setattr(run_weekly, "COLLECTORS", [("moodlecom", lambda ctx: res)])
    seen = {}

    def fake_anthropic(st, system, schema, prompt):
        seen["schema"] = schema
        return json.dumps({"headline": "첫 요약", "summary_md": "## 한눈에\n- 전체", "impacts": [],
                           "actions": []}), "claude-opus-5"

    monkeypatch.setattr(summarizer, "with_anthropic", fake_anthropic)
    monkeypatch.setattr(run_weekly.notify, "send", lambda st, text: None)
    conn = FakeConn(report_row=(7, datetime(2026, 9, 1), datetime(2026, 9, 8), 1, None, "", None, "[]", ""),
                    item_rows=[("u-old", 1, None, None)], latest_week="2026-W37")
    report = run_weekly.run(settings, RunOptions(week="2026-W37", refresh=True), lambda p: conn)
    assert "summary_md" in seen["schema"]["properties"] # 갱신용이 아닌 전체 요약 스키마
    assert report["run_no"] == 2
    assert report["headline"] == "첫 요약"
    assert report["summary_md"] == "## 한눈에\n- 전체"
    assert report["status"] == "ok"


def test_run_refresh_of_unknown_week_fails_loudly(tmp_path):
    settings = make_settings(tmp_path)
    with pytest.raises(ValueError, match="갱신할 주차가 DB 에 없다"):
        run_weekly.run(settings, RunOptions(week="2026-W99", refresh=True), lambda p: FakeConn())


def test_run_dry_run_does_not_touch_db_or_last_run(tmp_path, monkeypatch):
    settings = make_settings(tmp_path)
    monkeypatch.setattr(run_weekly, "COLLECTORS", [("tracker", lambda ctx: Result("tracker"))])

    def factory(params):
        raise AssertionError("dry-run 인데 DB 에 붙었다")

    report = run_weekly.run(settings, RunOptions(since=SINCE, until=UNTIL, dry_run=True, week="2026-W99"),
                            factory)
    assert report["week"] == "2026-W99"
    assert report["status"] == "partial" # 요약 없음
    assert report["note"] == "SUMMARIZER=none"
    assert json.loads((tmp_path / "var" / "state.json").read_text(encoding="utf-8"))["last_run"] is None


def test_run_all_failed_skips_save_and_exits_nonzero(tmp_path, monkeypatch):
    settings = make_settings(tmp_path)
    monkeypatch.setattr(run_weekly, "COLLECTORS",
                        [("tracker", lambda ctx: Result("tracker", status="failed", note="x"))])
    monkeypatch.setattr(run_weekly, "Settings", lambda: settings)
    conn = FakeConn()
    monkeypatch.setattr(run_weekly.store, "connect", lambda p: conn)
    code = run_weekly.main(["--since", "2026-09-01", "--until", "2026-09-08"])
    assert code == 1
    assert not any(s.startswith("INSERT INTO moodle_weekly_report") for s in conn.sqls())
    assert conn.closed


def test_refresh_queue_processes_pending_and_records_failures(tmp_path):
    settings = make_settings(tmp_path)
    d = refresh_queue.requests_dir(settings)
    d.mkdir(parents=True)
    (d / "2026-W37.json").write_text(json.dumps({"week": "2026-W37", "requested_by": "amitoa"}))
    (d / "2026-W36.json").write_text("{}")
    (d / "2026-W35.failed").write_text("{}") # 이전 실패 파일은 대기 목록이 아니다
    calls = []

    def runner(week, who):
        calls.append((week, who))
        if week == "2026-W36":
            raise RuntimeError("DB 없음")
        return {"status": "ok"}

    done = refresh_queue.process_all(settings, runner)

    assert calls == [("2026-W36", ""), ("2026-W37", "amitoa")]
    assert done == [{"week": "2026-W36", "ok": False, "error": "DB 없음"},
                    {"week": "2026-W37", "ok": True, "status": "ok"}]
    names = sorted(p.name for p in d.iterdir())
    assert names == ["2026-W35.failed", "2026-W36.failed"]
    failed = json.loads((d / "2026-W36.failed").read_text(encoding="utf-8"))
    assert failed["error"] == "RuntimeError: DB 없음"
    assert refresh_queue.pending(settings) == []


def test_refresh_queue_serve_once_creates_dir_and_returns(tmp_path):
    settings = make_settings(tmp_path)
    refresh_queue.serve(settings, lambda w, u: {}, once=True)
    assert refresh_queue.requests_dir(settings).is_dir()
