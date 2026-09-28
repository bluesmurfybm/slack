"""MoodleUp(무들 동향) 주간 배치. systemd timer 가 월요일 새벽에 한 번 부른다.

    python run_weekly.py                       # 마지막 실행 이후 ~ 지금 → 새 주차
    python run_weekly.py --since 2026-09-01    # 구간 지정
    python run_weekly.py --refresh 2026-W37    # 그 주차를 다시 수집·요약(갱신 이력이 남는다)
    python run_weekly.py --requests            # 화면에서 남긴 갱신 요청을 처리(systemd path 유닛)
    python run_weekly.py --serve               # 로컬: 요청 파일을 기다리며 계속 처리
    python run_weekly.py --dry-run             # DB 에 넣지 않고 스냅샷·요약만
"""

import argparse
import json
import logging
import re
import sys
from dataclasses import dataclass
from datetime import UTC, datetime

import digest as digest_mod
import notify
import refresh_queue
import summarizer
from collectors import devdocs, github, moodlecom, moodleorg, tracker
from collectors.base import Context, Result, run_safely
from core import store, tokencheck
from core.config import Settings
from core.http import Http
from core.items import Item
from core.snapshot import KST, State, week_key

logger = logging.getLogger("moodle-watch")

COLLECTORS = [
    ("moodleorg", moodleorg.collect),
    ("tracker", tracker.collect),
    ("github", github.collect),
    ("devdocs", devdocs.collect),
    ("moodlecom", moodlecom.collect),
]


@dataclass
class RunOptions:
    since: datetime | None = None
    until: datetime | None = None
    week: str | None = None
    dry_run: bool = False
    trigger: str = "cli" # timer | manual | cli
    refresh: bool = False # week 를 다시 수집해 갱신한다(DB 의 구간을 기준으로 삼는다)
    requested_by: str = ""


def _parse_dt(s: str | None) -> datetime | None:
    if not s:
        return None
    dt = datetime.fromisoformat(s)
    return dt.replace(tzinfo=UTC) if dt.tzinfo is None else dt.astimezone(UTC)


def apply_impacts(items: list[Item], summary: summarizer.Summary | None) -> None:
    if not summary:
        return
    by_url = {it.url: it for it in items}
    for imp in summary.impacts:
        it = by_url.get(imp["url"])
        if it is None:
            # 앵커(#p123)나 쿼리만 다른 경우를 한 번 더 맞춰 본다
            base = imp["url"].split("#")[0]
            it = next((x for u, x in by_url.items() if u.split("#")[0] == base), None)
        if it is not None:
            it.meta["impact"] = imp["impact"]
            it.meta["impact_reason"] = imp["reason"]


def decide_status(results: list[Result], summary: summarizer.Summary | None,
                  *, needed_summary: bool = True) -> str:
    failed = [r for r in results if r.status == "failed"]
    if len(failed) == len(results):
        return "failed"
    if failed or (needed_summary and summary is None):
        return "partial"
    return "ok"


UPDATE_HEAD = re.compile(r"^### 갱신 (\d{4}-\d{2}-\d{2} \d{2}:\d{2})")
HR = re.compile(r"(?m)^[ \t]*(?:-{3,}|\*{3,})[ \t]*$") # db.php::moodle_md 가 <hr> 로 그리는 줄
SEP = "\n\n---\n\n"


def reorder_updates(md: str | None) -> str | None:
    """예전 방식(아래에 덧붙임)으로 저장된 요약을 최신 갱신이 위로 오게 다시 세운다.

    구분선으로 나눈 블록 가운데 `### 갱신 …` 으로 시작하는 것이 갱신분이다. 갱신분을 시각
    내림차순으로 앞에 두고, 나머지(처음 요약)는 순서 그대로 맨 밑에 둔다. 블록 안 글은 손대지
    않으니 형광펜·메모 앵커는 그대로다. 이미 그 순서면 원문을 그대로 돌려준다.
    """
    if not md or not md.strip():
        return md
    blocks = [b.strip() for b in HR.split(md) if b.strip()]
    updates = [b for b in blocks if UPDATE_HEAD.match(b)]
    if not updates:
        return md
    body = [b for b in blocks if not UPDATE_HEAD.match(b)]
    updates.sort(key=lambda b: UPDATE_HEAD.match(b).group(1), reverse=True)
    wanted = updates + body
    return md if wanted == blocks else SEP.join(wanted)


def append_update(previous_md: str | None, updates_md: str, at: datetime, new_count: int) -> str:
    """갱신분을 맨 위에 얹고 구분선 아래에 기존 요약 본문을 그대로 둔다.

    아래에 덧붙이면 화면을 열었을 때 늘 같은 첫 화면이라 바뀐 게 없어 보인다. 최신 갱신이
    위에 오고 그 아래로 이전 갱신, 맨 밑이 처음 요약이다. 예전 방식으로 아래에 붙어 있던
    갱신분도 이때 같이 위로 올린다.
    본문을 다시 쓰지 않아야 형광펜·메모(텍스트 앵커)가 자리를 잃지 않는다.
    """
    stamp = at.astimezone(KST).strftime("%Y-%m-%d %H:%M")
    block = f"### 갱신 {stamp} · 새 항목 {new_count}건\n\n{updates_md.strip()}"
    previous_md = reorder_updates(previous_md)
    return (block + SEP + previous_md.strip()) if previous_md else block


def _resolve_period(state: State, opts: RunOptions, conn) -> tuple:
    """(since, until, week, previous). 갱신이면 DB 의 그 주차 구간을 쓴다."""
    now = opts.until or datetime.now(UTC)
    if opts.refresh:
        if not opts.week:
            raise ValueError("--refresh 에는 주차가 필요하다")
        previous = store.load_week(conn, opts.week)
        if previous is None:
            raise ValueError(f"갱신할 주차가 DB 에 없다: {opts.week}")
        since = datetime.fromisoformat(previous["period_start"])
        # 가장 최근 주차는 지금까지로 늘려 이번 주에 새로 생긴 것까지 잡는다.
        # 지난 주차는 같은 구간을 다시 본다.
        until = now if previous["is_latest"] else datetime.fromisoformat(previous["period_end"])
        return since, until, opts.week, previous
    since, until = state.period(now, opts.since)
    return since, until, opts.week or week_key(until), None


def _base_report(results: list[Result], period: tuple[str, str], week: str, *, # noqa: PLR0913
                 run_no: int, generated_at: datetime, status: str) -> dict:
    return {"week": week, "period_start": period[0], "period_end": period[1],
            "generated_at": generated_at.isoformat(timespec="seconds"), "status": status,
            "sources": [r.to_dict() for r in results], "run_no": run_no}


def _full_report(settings: Settings, results: list[Result], items: list[Item], *, # noqa: PLR0913
                 period: tuple[str, str], week: str, generated_at: datetime):
    """1회차: 주차 전체를 요약한다."""
    digest = digest_mod.build(results, period[0], period[1])
    summary, why = summarizer.summarize(settings, digest, week)
    if summary is None:
        logger.warning("요약 없음: %s", why)
    apply_impacts(items, summary)
    report = _base_report(results, period, week, run_no=1, generated_at=generated_at,
                          status=decide_status(results, summary))
    report.update({
        "headline": summary.headline if summary else "",
        "summary_md": summary.summary_md if summary else None,
        "updates_md": None,
        "actions": summary.actions if summary else [],
        "model": f"{summary.backend}:{summary.model}" if summary else "",
        "note": None if summary else why,
    })
    return report, digest, summary, why


def _refresh_report(settings: Settings, results: list[Result], items: list[Item], *, # noqa: PLR0913
                    previous: dict, period: tuple[str, str], week: str, run_no: int,
                    generated_at: datetime):
    """갱신: 기존 요약은 그대로 두고, 이전 실행 이후 새로 들어온 항목만 요약해 맨 위에 얹는다.

    이전 실행이 요약 없이 끝났으면(요약기 실패 등) 덧붙일 본문이 없으니 전체 요약을 다시 만든다.
    """
    if not previous["summary_md"]:
        report, digest, summary, why = _full_report(
            settings, results, items, period=period, week=week, generated_at=generated_at)
        report["run_no"] = run_no
        return report, digest, summary, why
    new_items = [it for it in items if it.url not in previous["known"]]
    if new_items:
        digest = digest_mod.build_update(results, new_items, period[0], period[1], run_no)
        summary, why = summarizer.summarize_update(settings, digest, week)
        if summary is None:
            logger.warning("갱신 요약 없음: %s", why)
    else:
        digest, summary, why = "", None, "새 항목 없음 — 요약을 만들지 않았다"
    apply_impacts(items, summary)
    status = decide_status(results, summary, needed_summary=bool(new_items))
    combined = (append_update(previous["summary_md"], summary.updates_md, generated_at,
                              len(new_items)) if summary and summary.updates_md else None)
    report = _base_report(results, period, week, run_no=run_no, generated_at=generated_at,
                          status=status)
    report.update({
        "headline": previous["headline"],
        "summary_md": combined, # None 이면 store 가 기존 요약을 그대로 둔다
        "updates_md": (summary.updates_md or None) if summary else None,
        "actions": previous["actions"] + (summary.actions if summary else []),
        "model": previous["model"],
        "note": None if (summary or not new_items) else why,
    })
    return report, digest, summary, why


def run(settings: Settings, opts: RunOptions, conn_factory=None) -> dict:
    conn_factory = conn_factory or store.connect # 테스트가 store.connect 를 바꿔 끼울 수 있게
    state = State(settings)
    conn = None
    if opts.refresh or not opts.dry_run:
        conn = conn_factory(settings.db_params())
        store.ensure_schema(conn)
    try:
        since_dt, until_dt, week, previous = _resolve_period(state, opts, conn)
        run_no = previous["run_count"] + 1 if previous else 1
        logger.info("주차 %s (%d회차, %s), 구간 %s ~ %s", week, run_no, opts.trigger,
                    since_dt.isoformat(), until_dt.isoformat())

        ctx = Context(settings, Http(settings), state, since_dt, until_dt)
        results = [run_safely(name, fn, ctx) for name, fn in COLLECTORS]
        items = [it for r in results for it in r.items]
        for r in results:
            logger.info("[%s] %s %d건 %s", r.source, r.status, len(r.items),
                        r.note.splitlines()[0] if r.note else "")

        generated_at = datetime.now(UTC)
        period = (since_dt.isoformat(timespec="seconds"), until_dt.isoformat(timespec="seconds"))
        if previous:
            report, digest, _summary, why = _refresh_report(
                settings, results, items, previous=previous, period=period, week=week,
                run_no=run_no, generated_at=generated_at)
        else:
            report, digest, _summary, why = _full_report(
                settings, results, items, period=period, week=week, generated_at=generated_at)
        report["trigger"] = opts.trigger
        state.write_snapshot(f"{week}-r{run_no}", {"report": report, "digest": digest,
                                                   "items": [it.to_dict() for it in items]})

        if conn is not None and not opts.dry_run and report["status"] != "failed":
            saved = store.save_report(conn, report, items, previous, opts.trigger)
            report.update(saved)
            last = state.data.get("last_run")
            if not last or datetime.fromisoformat(last) < until_dt:
                state.mark_run(until_dt)
        state.save()
    finally:
        if conn is not None:
            conn.close()

    problems = [f"{r.source}: {r.note.splitlines()[0]}" for r in results if r.status != "ok"]
    if report["note"]:
        problems.append(f"요약 없음: {why}")
    notify.report_done(settings, report, {r.source: len(r.items) for r in results}, problems)
    report["item_count"] = len(items)
    return report


def _refresh_runner(settings: Settings, conn_factory=None):
    def runner(week: str, requested_by: str) -> dict:
        return run(settings, RunOptions(week=week, refresh=True, trigger="manual",
                                        requested_by=requested_by), conn_factory)
    return runner


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--since", help="ISO 날짜/시각(UTC). 기본은 마지막 실행 시각 또는 7일 전")
    ap.add_argument("--until", help="ISO 날짜/시각(UTC). 기본은 지금")
    ap.add_argument("--week", help="저장할 주차 키(기본은 until 의 ISO 주차)")
    ap.add_argument("--refresh", metavar="WEEK", help="이 주차를 다시 수집·요약해 갱신한다")
    ap.add_argument("--requests", action="store_true", help="화면에서 남긴 갱신 요청을 처리한다")
    ap.add_argument("--serve", action="store_true", help="갱신 요청을 기다리며 계속 처리한다(로컬)")
    ap.add_argument("--reorder-updates", action="store_true",
                    help="저장된 모든 주차의 요약에서 예전 방식으로 아래에 붙은 갱신분을 위로 "
                         "올린다(한 번만 돌리면 된다. --dry-run 이면 대상 주차만 보여 준다)")
    ap.add_argument("--check-token", action="store_true",
                    help="moodle.org 토큰이 살아 있는지만 확인해 var/token_status.json 에 남긴다"
                         "(금요일 오후 timer 용). 무효면 종료 코드 1")
    ap.add_argument("--trigger", default="cli", choices=["cli", "timer", "manual"],
                    help="실행 이력에 남길 실행 주체")
    ap.add_argument("--dry-run", action="store_true", help="DB 에 저장하지 않는다")
    ap.add_argument("--no-summary", action="store_true", help="LLM 요약을 건너뛴다")
    ap.add_argument("-v", "--verbose", action="store_true")
    a = ap.parse_args(argv)

    logging.basicConfig(level=logging.DEBUG if a.verbose else logging.INFO,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    settings = Settings()
    if a.no_summary:
        settings = settings.model_copy(update={"summarizer": "none"})

    if a.serve or a.requests:
        refresh_queue.serve(settings, _refresh_runner(settings), once=a.requests)
        return 0
    if a.reorder_updates:
        conn = store.connect(settings.db_params())
        try:
            weeks = store.rewrite_summaries(conn, reorder_updates, dry_run=a.dry_run)
        finally:
            conn.close()
        print(json.dumps({"dry_run": a.dry_run, "reordered": weeks}, ensure_ascii=False))
        return 0
    if a.check_token:
        st = tokencheck.probe_and_record(settings, Http(settings))
        logger.info("[token] %s", st["note"])
        print(json.dumps(st, ensure_ascii=False))
        return 0 if st["ok"] else 1

    opts = RunOptions(since=_parse_dt(a.since), until=_parse_dt(a.until),
                      week=a.refresh or a.week, dry_run=a.dry_run, trigger=a.trigger,
                      refresh=bool(a.refresh))
    report = run(settings, opts)
    print(json.dumps({k: report.get(k) for k in ("week", "run_no", "status", "headline",
                                                 "item_count", "new_items", "model")},
                     ensure_ascii=False))
    return 0 if report["status"] != "failed" else 1


if __name__ == "__main__":
    sys.exit(main())
