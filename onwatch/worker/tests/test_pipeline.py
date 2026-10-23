"""한 감시의 실행 흐름 — 가짜 DB·엔진·알림으로 본다(MySQL·API·php 를 부르지 않는다)."""

import json
from datetime import datetime, timedelta
from pathlib import Path

from core.config import Settings
from engine import EngineError, Research, Usage
from notify import Notifier
from pipeline import Ctx, housekeeping, run_watch, tick

NOW = datetime(2026, 10, 22, 10, 0, 0)


def watch(**kw):
    w = {"id": 7, "title": "PHP 패치 추적", "prompt": "새 패치가 나오면 알려라", "baseline": "7.4 백포트만 있음",
         "owner_id": 1, "status": "active", "starts_at": NOW - timedelta(days=1), "ends_at": NOW + timedelta(days=30),
         "interval_min": 360, "run_time": None, "weekday": None, "weekdays_only": 0, "hour_from": 0, "hour_to": 24,
         "notify_mode": "change", "min_importance": "medium", "notify_slack": 1, "slack_webhook_enc": None,
         "prefer_domains": "php.net", "block_domains": "spam.com", "max_searches": 9, "monthly_budget_usd": 20,
         "next_run_at": NOW, "run_requested_at": None, "run_requested_by": None, "fail_streak": 0, "end_notice_sent": 0}
    w.update(kw)
    return w


class FakeStore:
    def __init__(self, watches=(), cost=0.0, cost_all=0.0, fps=()):
        self.watches = {w["id"]: w for w in watches}
        self.cost, self.cost_all = cost, cost_all
        self.fps = set(fps)
        self.runs, self.findings, self.events, self.ended = {}, [], [], []
        self.after = []
        self.notice_marked = []
        self.locked = set()

    def next_due(self, now):
        for w in self.watches.values():
            if w["id"] not in self.locked and (w["run_requested_at"] or (w["status"] == "active" and w["next_run_at"] <= now)):
                return w
        return None

    def claim(self, wid, now, minutes, who):
        if wid in self.locked:
            return False
        self.locked.add(wid)
        return True

    def expired(self, now):
        return [w for w in self.watches.values() if w["status"] != "ended" and w["ends_at"] <= now]

    def end_watch(self, wid, now):
        self.watches[wid]["status"] = "ended"
        self.ended.append(wid)

    def ending_soon(self, now, hours=24):
        return [w for w in self.watches.values() if w["status"] == "active" and not w["end_notice_sent"]
                and now < w["ends_at"] <= now + timedelta(hours=hours)]

    def mark_end_notice(self, wid):
        self.watches[wid]["end_notice_sent"] = 1
        self.notice_marked.append(wid)

    def month_cost(self, now, watch_id=None):
        return self.cost if watch_id is not None else self.cost_all

    def budget_skipped_this_month(self, now, wid):
        return any(r.get("status") == "budget" and r["_closed_before"] for r in self.runs.values())

    def start_run(self, wid, trigger, by, now):
        rid = len(self.runs) + 1
        self.runs[rid] = {"watch": wid, "trigger": trigger, "by": by, "_closed_before": False}
        return rid

    def finish_run(self, rid, now, **f):
        self.runs[rid].update(f)

    def mark_notified(self, rid):
        self.runs[rid]["notified"] = 1

    def after_run(self, wid, **kw):
        self.after.append(kw)
        self.locked.discard(wid)
        w = self.watches[wid]
        w["run_requested_at"] = None
        if not kw["keep_next"]:
            w["next_run_at"] = kw["next_run_at"] or datetime.max
        w["fail_streak"] = w["fail_streak"] + 1 if kw["failed"] else 0
        return w["fail_streak"]

    def prior_findings(self, wid, limit=60):
        return [{"subject_key": "php:7.4", "marker": "a1", "title": "옛 소식", "change_type": "new",
                 "published": "", "sources_json": "[]", "created_at": NOW - timedelta(days=2)}]

    def rejected_findings(self, wid, limit=30):
        return []

    def fingerprints(self, wid):
        return set(self.fps)

    def last_ok_run(self, wid):
        return NOW - timedelta(hours=6)

    def insert_finding(self, wid, rid, f, *, alerted, now):
        if f["fingerprint"] in self.fps:
            return False
        self.fps.add(f["fingerprint"])
        self.findings.append({**f, "alerted": alerted})
        return True

    def subscribers(self, wid):
        return [1, 2]

    def log(self, wid, action, detail, now):
        self.events.append((wid, action, detail))


class FakeEngine:
    name = "anthropic"
    model = "claude-opus-5-5"

    def __init__(self, verdict=None, fail=None):
        self.verdict = verdict or {"summary": "변화 없음 — 새 공지 없음", "findings": [], "baseline_hint": ""}
        self.fail = fail
        self.research_args = None

    def research(self, system, user, *, max_searches, blocked):
        self.research_args = (user, max_searches, blocked)
        if self.fail:
            raise self.fail
        return Research(notes="## 살펴본 곳\n- php.net", seen_urls=["https://php.net/a"],
                        usage=Usage(input_tokens=50_000, output_tokens=3_000, searches=6))

    def judge(self, system, user, schema):
        return self.verdict, Usage(input_tokens=8_000, output_tokens=1_000)


def finding(**kw):
    f = {"subject_key": "debian:php7.4", "marker": "deb11u9", "title": "데비안 PHP 7.4 패치 배포",
         "change_type": "new", "importance": "high", "confidence": "confirmed", "detail_md": "- 7.4.33-1+deb11u9",
         "verification": "Moodle 3.9 호환성 별도 확인", "published": "2026-10-21",
         "sources": [{"url": "https://php.net/a", "title": "공지", "published": ""}]}
    f.update(kw)
    return f


class Sink:
    """알림 가짜 — php·슬랙 호출을 기록만 한다."""

    def __init__(self, slack_on=True):
        self.php, self.posts = [], []
        self.slack_on = slack_on

    def php_runner(self, args, stdin=""):
        if args[0] == "onwatch/bin/settings.php":
            if self.slack_on is None:
                raise RuntimeError("php 없음")
            return json.dumps({"slack_enabled": self.slack_on})
        # 포털 알림은 --json-file 로 넘어온다 — 지워지기 전에 읽어 둔다
        payload = json.loads(Path(args[2]).read_text(encoding="utf-8")) if args[0] == "dev/push_cli.php" else stdin
        self.php.append((args[0], payload))
        return json.dumps({"notified": 2, "sent": 1})

    def post(self, url, json=None, timeout=None):
        self.posts.append((url, json))
        return type("R", (), {"status_code": 200, "text": "ok"})()


def ctx(store, engine, sink, **settings_kw):
    s = Settings(_env_file=None, slack_webhook="https://hooks.slack.com/services/T/B/x", portal_url="https://iw/",
                 **settings_kw)
    return Ctx(settings=s, store=store, engine_factory=lambda _s: engine,
               notifier=Notifier(s, php_runner=sink.php_runner, http_post=sink.post), now=lambda: NOW)


def test_new_finding_is_stored_and_notified_to_subscribers_and_slack():
    store, sink = FakeStore([watch()]), Sink()
    eng = FakeEngine({"summary": "데비안 패치 1건", "baseline_hint": "7.4 백포트 + 데비안 패키지",
                      "findings": [finding(), finding(subject_key="x", marker="m", importance="low", title="낮은 소식")]})
    r = run_watch(ctx(store, eng, sink), store.watches[7], trigger="schedule")

    assert r["status"] == "changed"
    assert r["new"] == 2
    assert r["alerted"] == 1 # 낮음은 최소 중요도(보통) 아래 — 기록만
    assert [f["alerted"] for f in store.findings] == [True, False]
    run = store.runs[1]
    assert run["status"] == "changed"
    assert run["baseline_hint"] == "7.4 백포트 + 데비안 패키지"
    assert run["searches"] == 6
    assert run["cost_usd"] > 0
    assert run["notified"] == 1
    # 포털: 받는 사람 전원, 상세 주소
    script, payload = sink.php[0]
    assert script == "dev/push_cli.php"
    assert payload["kind"] == "onwatch.finding"
    assert payload["to"] == [1, 2]
    assert payload["url"] == "onwatch/view.php?id=7"
    assert "새 소식 1건" in payload["title"]
    # 슬랙: 기본 채널, 원문 링크와 상세 링크
    url, body = sink.posts[0]
    assert url.startswith("https://hooks.slack.com/")
    assert "<https://php.net/a|원문>" in body["text"]
    assert "https://iw/onwatch/view.php?id=7" in body["text"]
    # 다음 실행 — 6시간마다 정각
    assert store.after[0]["next_run_at"] == datetime(2026, 10, 22, 12, 0)
    # 지시문에 기준 상태·이미 알린 것·제외 도메인이 들어갔다
    user, max_s, blocked = eng.research_args
    assert "7.4 백포트만 있음" in user
    assert "[php:7.4] 옛 소식" in user
    assert max_s == 9
    assert blocked == ["spam.com"]


def test_already_reported_is_not_notified_again():
    import dedup  # noqa: PLC0415
    fp = dedup.clean(finding(), {"https://php.net/a"})["fingerprint"]
    store, sink = FakeStore([watch()], fps=[fp]), Sink()
    r = run_watch(ctx(store, FakeEngine({"summary": "", "baseline_hint": "", "findings": [finding()]}), sink),
                  store.watches[7], trigger="schedule")
    assert r["status"] == "quiet"
    assert r["dup"] == 1
    assert sink.php == []
    assert sink.posts == []
    assert store.runs[1]["baseline_hint"] is None # 새 소식이 없으면 기준 상태 제안도 버린다


def test_quiet_run_notifies_only_in_always_mode():
    store, sink = FakeStore([watch()]), Sink()
    run_watch(ctx(store, FakeEngine(), sink), store.watches[7], trigger="schedule")
    assert sink.php == []

    store, sink = FakeStore([watch(notify_mode="always", notify_slack=0)]), Sink()
    run_watch(ctx(store, FakeEngine(), sink), store.watches[7], trigger="schedule")
    assert "변화 없음" in sink.php[0][1]["title"]
    assert sink.posts == [] # 슬랙을 끈 감시


def test_budget_exceeded_skips_and_tells_owner_once():
    store, sink = FakeStore([watch()], cost=20.0), Sink()
    eng = FakeEngine()
    r = run_watch(ctx(store, eng, sink), store.watches[7], trigger="schedule")
    assert r["status"] == "budget"
    assert eng.research_args is None # 모델을 부르지 않았다
    assert sink.php[0][1]["kind"] == "onwatch.system"
    assert sink.php[0][1]["to"] == [1]


def test_global_budget_also_stops():
    store, sink = FakeStore([watch()], cost_all=100.0), Sink()
    assert run_watch(ctx(store, FakeEngine(), sink), store.watches[7], trigger="schedule")["status"] == "budget"


def test_failure_records_error_and_warns_owner_on_third():
    store, sink = FakeStore([watch(fail_streak=2)]), Sink()
    r = run_watch(ctx(store, FakeEngine(fail=EngineError("검색 실패")), sink), store.watches[7], trigger="schedule")
    assert r["status"] == "failed"
    assert "검색 실패" in store.runs[1]["error"]
    assert store.after[0]["failed"] is True
    assert store.after[0]["next_run_at"] is not None # 실패해도 다음 정기 실행은 잡는다
    assert sink.php[0][1]["kind"] == "onwatch.system"
    assert "3번" in sink.php[0][1]["title"]


def test_manual_run_of_paused_watch_keeps_schedule():
    store, sink = FakeStore([watch(status="paused", run_requested_at=NOW, run_requested_by=2)]), Sink()
    res = tick(ctx(store, FakeEngine(), sink))
    assert len(res["runs"]) == 1
    assert store.runs[1]["trigger"] == "manual"
    assert store.runs[1]["by"] == 2
    assert store.after[0]["keep_next"] is True


def test_housekeeping_ends_expired_and_warns_before_end():
    old = watch(id=1, ends_at=NOW - timedelta(minutes=1))
    soon = watch(id=2, ends_at=NOW + timedelta(hours=5))
    store, sink = FakeStore([old, soon]), Sink()
    housekeeping(ctx(store, FakeEngine(), sink))
    assert store.ended == [1]
    assert store.notice_marked == [2]
    titles = [p[1]["title"] for p in sink.php]
    assert any("끝났습니다" in t for t in titles)
    assert any("곧 끝납니다" in t for t in titles)
    assert all(p[1]["to"] == [1] for p in sink.php) # 등록한 사람에게만
    assert sink.posts == [] # 상태 알림은 슬랙에 보내지 않는다


def test_per_watch_webhook_is_decrypted_through_php():
    store, sink = FakeStore([watch(slack_webhook_enc="ENC")]), Sink()
    calls = []

    def php(args, stdin=""):
        calls.append(args[0])
        if args[0] == "onwatch/bin/secret.php":
            return "https://hooks.slack.com/services/OWN/CH/x"
        return sink.php_runner(args, stdin)

    c = ctx(store, FakeEngine({"summary": "", "baseline_hint": "", "findings": [finding()]}), sink)
    c.notifier._php = php # noqa: SLF001 시험에서 다리만 바꿔 끼운다
    run_watch(c, store.watches[7], trigger="schedule")
    assert "onwatch/bin/secret.php" in calls
    assert sink.posts[0][0] == "https://hooks.slack.com/services/OWN/CH/x"


def test_global_slack_switch_off_sends_portal_only():
    store, sink = FakeStore([watch()]), Sink(slack_on=False)
    run_watch(ctx(store, FakeEngine({"summary": "", "baseline_hint": "", "findings": [finding()]}), sink),
              store.watches[7], trigger="schedule")
    assert [p[0] for p in sink.php if p[0] == "dev/push_cli.php"] # 포털 알림은 간다
    assert sink.posts == []


def test_unreadable_slack_switch_counts_as_off():
    store, sink = FakeStore([watch()]), Sink(slack_on=None)
    run_watch(ctx(store, FakeEngine({"summary": "", "baseline_hint": "", "findings": [finding()]}), sink),
              store.watches[7], trigger="schedule")
    assert sink.posts == []
