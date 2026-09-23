"""ingest — Slack 이벤트(커스텀 스텝/댓글) → php slackai/sync.php 단건 모드.

params: {list_id, item_id, event}  또는  {event:'comment', channel}
결과 JSON(result inserted|updated|same|skipped|excluded, enqueued …)을 ai_jobs.result 에 남긴다.
inserted==1 이면 PHP 가 이미 triage 를 enqueue 했으므로 여기서 다시 넣지 않는다.
"""

import sync_trigger
from jobs.base import JobContext, Outcome


def run(ctx: JobContext) -> Outcome:
    p = ctx.params
    event = str(p.get("event") or "updated")
    if event == "comment":
        channel = str(p.get("channel") or "")
        if not channel:
            return Outcome.fail("comment 이벤트에 channel 이 없다")
        ctx.progress(f"댓글 수 갱신 {channel}")
        res = sync_trigger.run_comments(ctx.settings, ctx.runner, channel)
    else:
        item_id = str(p.get("item_id") or ctx.request_id or "")
        if not item_id:
            return Outcome.fail("item_id 없음")
        ctx.progress(f"단건 동기화 {item_id}")
        res = sync_trigger.run_item(ctx.settings, ctx.runner, item_id, str(p.get("list_id") or ""))
    if not res.get("ok"):
        err = str(res.get("error") or "sync 실패")
        return Outcome.fail(f"sync.php: {err}", retryable=err not in ("no_token", "unknown_list", "unknown_channel"),
                            result=res)
    if res.get("skipped") == "locked":
        return Outcome.fail("sync 잠금 중 → 재시도", retryable=True, result=res)
    return ctx.outcome("done", result=res, progress=str(res.get("text") or "")[:200])
