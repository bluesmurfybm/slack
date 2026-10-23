"""한 번 깨어날 때(tick) 하는 일.

1. 정리 — 기간이 지난 감시를 끝내고, 하루 안에 끝날 감시의 등록자에게 미리 알린다.
2. 실행 — 지금 실행 요청과 때가 된 정기 실행을 하나씩 잠가(claim) 돌린다.

한 감시의 실행
  예산 확인 → 조사(웹) → 판정(JSON) → 정리·중복 거르기 → 저장 → 알림 → 다음 실행 시각
실패해도 다음 정기 실행은 잡는다. 놓친 정기 실행을 몰아서 따라잡지 않는다 — 지금 이후 첫 시각으로 간다.
"""

import logging
import os
import socket
import traceback
from collections.abc import Callable
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import dedup
import prompts
from core import schedule
from core.config import Settings
from engine import EngineError, Usage, cost_of
from notify import Notifier
from store import Store

logger = logging.getLogger("onwatch")
KST = timezone(timedelta(hours=9)) # 한국은 서머타임이 없다. ZoneInfo 는 Windows 에 tzdata 가 없으면 실패한다
FAIL_NOTICE_AT = 3


def kst_now() -> datetime:
    return datetime.now(KST).replace(tzinfo=None, microsecond=0)


@dataclass
class Ctx:
    settings: Settings
    store: Store
    engine_factory: Callable
    notifier: Notifier
    now: Callable[[], datetime] = kst_now


def tick(ctx: Ctx, *, limit: int | None = None) -> dict:
    housekeeping(ctx)
    who = f"{socket.gethostname()}:{os.getpid()}"
    done = []
    for _ in range(limit or ctx.settings.max_runs_per_tick):
        now = ctx.now()
        w = ctx.store.next_due(now)
        if not w:
            break
        if not ctx.store.claim(w["id"], now, ctx.settings.lock_minutes, who):
            continue
        trigger = "manual" if w.get("run_requested_at") else "schedule"
        done.append(run_watch(ctx, w, trigger=trigger))
    return {"runs": done}


def housekeeping(ctx: Ctx) -> None:
    now = ctx.now()
    for w in ctx.store.expired(now):
        ctx.store.end_watch(w["id"], now)
        ctx.store.log(w["id"], "end", "기간 만료로 종료", now)
        ctx.notifier.system(w, "감시가 끝났습니다",
                            "정해 둔 기간이 지나 감시를 끝냈습니다. 더 보려면 상세 화면에서 종료일을 늘리세요.",
                            [int(w["owner_id"])])
    for w in ctx.store.ending_soon(now):
        ctx.store.mark_end_notice(w["id"])
        ctx.notifier.system(w, "곧 끝납니다",
                            f"{w['ends_at']:%m월 %d일 %H:%M} 에 감시가 끝납니다. "
                            "더 보려면 상세 화면에서 종료일을 늘리세요.",
                            [int(w["owner_id"])])


@dataclass
class _Run:
    """실행 하나의 공통 값 — 단계별 함수가 나눠 쓴다."""

    ctx: Ctx
    w: dict
    run_id: int
    started: datetime
    keep_next: bool # 일시정지 감시의 '지금 실행' 은 정기 일정을 건드리지 않는다

    def next_at(self) -> datetime | None:
        return None if self.keep_next else schedule.next_run(self.w, self.ctx.now())

    def close(self, *, changed: bool, failed: bool) -> int:
        return self.ctx.store.after_run(self.w["id"], now=self.ctx.now(), started=self.started,
                                        next_run_at=self.next_at(), keep_next=self.keep_next,
                                        changed=changed, failed=failed)

    def tell_owner(self, title: str, body: str) -> None:
        self.ctx.notifier.system(self.w, title, body, [int(self.w["owner_id"])])


def run_watch(ctx: Ctx, w: dict, *, trigger: str) -> dict:
    started = ctx.now()
    requested_by = w.get("run_requested_by") if trigger == "manual" else None
    r = _Run(ctx, w, ctx.store.start_run(w["id"], trigger, requested_by, started), started,
             keep_next=w["status"] != "active")
    return _skip_if_over_budget(r) or _investigate(r)


def _skip_if_over_budget(r: _Run) -> dict | None:
    s, store, w = r.ctx.settings, r.ctx.store, r.w
    spent = store.month_cost(r.started, w["id"])
    spent_all = store.month_cost(r.started)
    limit = float(w["monthly_budget_usd"])
    if spent < limit and spent_all < s.monthly_budget_usd:
        return None
    first = not store.budget_skipped_this_month(r.started, w["id"])
    if spent >= limit:
        why = f"이번 달 이 감시의 비용 ${spent:.2f} 이 상한 ${limit:.2f} 에 닿았습니다"
    else:
        why = f"이번 달 OnWatch 전체 비용 ${spent_all:.2f} 이 상한 ${s.monthly_budget_usd:.2f} 에 닿았습니다"
    store.finish_run(r.run_id, r.ctx.now(), status="budget", summary=why)
    r.close(changed=False, failed=False)
    if first:
        r.tell_owner("예산 초과로 실행을 건너뜁니다",
                     why + ". 이번 달 남은 정기 실행을 건너뜁니다. 상한을 올리면 다시 돕니다.")
    return {"watch": w["id"], "status": "budget"}


def _investigate(r: _Run) -> dict:
    s, store, w = r.ctx.settings, r.ctx.store, r.w
    usage = Usage()
    engine = None
    notes = None
    try:
        engine = r.ctx.engine_factory(s)
        context = prompts.context_block(w, now=r.started, last_ok=store.last_ok_run(w["id"]),
                                        prior=store.prior_findings(w["id"]),
                                        rejected=store.rejected_findings(w["id"]))
        blocked = [d for d in (w.get("block_domains") or "").splitlines() if d.strip()]
        research = engine.research(prompts.RESEARCH_SYSTEM, prompts.research_user(context, int(w["max_searches"])),
                                   max_searches=int(w["max_searches"]), blocked=blocked)
        usage.add(research.usage)
        notes = research.notes
        verdict, judge_usage = engine.judge(prompts.JUDGE_SYSTEM,
                                            prompts.judge_user(context, research.notes, research.seen_urls),
                                            prompts.JUDGE_SCHEMA)
        usage.add(judge_usage)
    except Exception as e: # noqa: BLE001 어떤 실패든 기록하고 다음 감시로 간다
        return _record_failure(r, e, engine, usage, notes)
    return _record_result(r, engine, usage, research, verdict)


def _record_failure(r: _Run, e: Exception, engine, usage: Usage, notes: str | None) -> dict:
    s, w = r.ctx.settings, r.w
    logger.error("감시 %s 실행 실패", w["id"], exc_info=e)
    err = f"{type(e).__name__}: {e}" if isinstance(e, EngineError) else traceback.format_exc(limit=4)
    model = getattr(engine, "model", None)
    r.ctx.store.finish_run(r.run_id, r.ctx.now(), status="failed", error=err[-4000:], research_md=notes,
                           engine=getattr(engine, "name", None), model=model, searches=usage.searches,
                           input_tokens=usage.input_tokens, output_tokens=usage.output_tokens,
                           cost_usd=cost_of(s, model or s.model, usage))
    streak = r.close(changed=False, failed=True)
    if streak == FAIL_NOTICE_AT:
        r.tell_owner(f"{streak}번 연달아 실패했습니다",
                     f"마지막 오류: {str(e)[:200]}\n실행 이력에서 자세한 내용을 보세요.")
    return {"watch": w["id"], "status": "failed", "error": str(e)}


def _record_result(r: _Run, engine, usage: Usage, research, verdict: dict) -> dict:
    s, store, w = r.ctx.settings, r.ctx.store, r.w
    seen = set(research.seen_urls)
    cleaned = [c for c in (dedup.clean(f, seen) for f in verdict.get("findings") or []) if c]
    fresh, dup = dedup.split(cleaned, store.fingerprints(w["id"]))
    now = r.ctx.now()
    stored, alerted = [], []
    for f in fresh:
        ok_alert = dedup.meets(f["importance"], w["min_importance"])
        if store.insert_finding(w["id"], r.run_id, f, alerted=ok_alert, now=now):
            stored.append(f)
            if ok_alert:
                alerted.append(f)
        else:
            dup += 1
    summary = str(verdict.get("summary") or "").strip() or (f"새 소식 {len(stored)}건" if stored else "변화 없음")
    hint = str(verdict.get("baseline_hint") or "").strip()
    status = "changed" if stored else "quiet"
    cost = cost_of(s, engine.model, usage)
    store.finish_run(r.run_id, now, status=status, engine=engine.name, model=engine.model, searches=usage.searches,
                     input_tokens=usage.input_tokens, output_tokens=usage.output_tokens, cost_usd=cost,
                     new_findings=len(stored), dup_findings=dup, summary=summary, research_md=research.notes,
                     baseline_hint=hint if stored else None)
    r.close(changed=bool(stored), failed=False)

    want = alerted or w["notify_mode"] == "always"
    if want and r.ctx.notifier.findings(w, alerted, summary, store.subscribers(w["id"])):
        store.mark_notified(r.run_id)
    return {"watch": w["id"], "status": status, "new": len(stored), "alerted": len(alerted), "dup": dup, "cost": cost}
