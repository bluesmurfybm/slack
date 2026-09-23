"""resync — php slackai/sync.php [full] json (화면 🔄 / 수동 대조)."""

import sync_trigger
from jobs.base import JobContext, Outcome


def run(ctx: JobContext) -> Outcome:
    full = str(ctx.params.get("full") or "0") in ("1", "true", "True")
    ctx.progress("전체 동기화" if full else "증분 동기화")
    res = sync_trigger.run_once(ctx.settings, ctx.runner, full=full,
                                enqueue=str(ctx.params.get("enqueue") or "0") == "1")
    if not res.get("ok"):
        err = str(res.get("error") or "sync 실패")
        return Outcome.fail(f"sync.php: {err}", retryable=err != "no_token", result=res)
    return ctx.outcome("done", result=res, progress=str(res.get("text") or "")[:200])
