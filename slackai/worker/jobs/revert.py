"""revert — 사람이 confirm 한 경우에만 실행 결과를 되돌린다(자동 실행 없음). ref_id = ai_executions.id, params.confirm 필수.

svn: svn revert -R -- <paths>, 신규(A) 파일은 revert 후 untracked 로 남으므로 파일을 지운다.
git: 신규 파일은 git rm --cached 후 삭제, 나머지는 git checkout -- <paths>.
"""

from pathlib import Path

from core import store
from core.clock import now_str
from jobs import common
from jobs.base import JobContext, Outcome


def run(ctx: JobContext) -> Outcome:
    if str(ctx.params.get("confirm") or "0") not in ("1", "true", "True"):
        return Outcome.fail("revert 는 confirm=1 이 있어야 실행한다", retryable=False)
    eid = ctx.job.get("ref_id") or ctx.params.get("execution_id")
    if not eid:
        return Outcome.fail("execution_id 없음")
    execution = store.execution_get(ctx.db, int(eid))
    if not execution or execution.get("status") not in ("done", "failed"):
        return Outcome.fail("되돌릴 실행이 없다(done/failed 만)")
    plan = store.plan_get(ctx.db, int(execution["plan_id"]))
    if not plan:
        return Outcome.fail("플랜 없음")
    if plan.get("status") in ("committed", "reviewed"):
        return Outcome.fail("이미 커밋된 실행은 되돌리지 않는다(VCS 에서 직접 처리)", retryable=False)
    _, repo = common.request_and_repo(ctx, execution["request_id"], plan.get("repo_id"))
    if not repo:
        return Outcome.fail("레포 없음")
    vcs, err = common.open_repo(ctx, repo)
    if err:
        return Outcome.fail(err, retryable=False)
    stats = common.diff_stat_of(execution)
    paths = [s["path"] for s in stats if s.get("path")]
    added = [s["path"] for s in stats if s.get("status") == "A" and s.get("path")]
    if not paths:
        return Outcome.fail("되돌릴 파일 목록이 없다", retryable=False)
    ctx.progress(f"{vcs.kind} revert {len(paths)} 파일")
    root = Path(repo["local_path"])
    log = []
    try:
        if vcs.kind == "git":
            if added:
                ctx.runner.run([ctx.settings.git_bin, "rm", "--cached", "-q", "--", *added], cwd=str(root), timeout=120)
            rest = [p for p in paths if p not in added]
            vcs.revert(rest)
        else:
            vcs.revert(paths)
        for p in added:
            f = root / p
            if f.is_file():
                f.unlink()
                log.append(f"삭제 {p}")
    except Exception as e:
        return Outcome.fail(f"revert 실패: {str(e)[:800]}", retryable=False)
    store.execution_update(ctx.db, int(eid), status="reverted",
                           log=((execution.get("log") or "") + f"\n[revert {now_str()}] " + "; ".join(log))[:200000])
    ctx.event("execute.reverted", "ai_executions", int(eid), {"paths": len(paths), "deleted": len(added)})
    ctx.db.commit()
    return ctx.outcome("done", result={"execution_id": int(eid), "paths": paths, "deleted": added},
                       progress=f"되돌림 {len(paths)}")
