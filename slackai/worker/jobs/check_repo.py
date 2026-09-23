"""check_repo — ai_repos.local_path 존재/vcs 종류/HEAD 리비전을 확인해 last_checked/check_status/check_message/head_revision 갱신.

ref_id(또는 params.repo_id) = ai_repos.id. PHP 는 경로를 검증하지 않고 이 잡에 맡긴다.
"""

from pathlib import Path

from core import store
from jobs.base import JobContext, Outcome
from vcs import detect


def check(ctx: JobContext, repo: dict) -> tuple[str, str, str | None]:
    """(status, message, head)"""
    path = str(repo.get("local_path") or "")
    if not path or not Path(path).is_dir():
        return "fail", f"경로 없음: {path}", None
    kind = detect.detect(path)
    if kind is None:
        return "fail", "작업 사본이 아님(.svn/.git 없음)", None
    if kind != (repo.get("vcs") or ""):
        return "fail", f"vcs 불일치: 등록 {repo.get('vcs')} / 실제 {kind}", None
    try:
        v = detect.open_vcs(kind, ctx.runner, path, ctx.settings)
        head = v.head_revision()
    except Exception as e:
        return "fail", f"{kind} 정보 조회 실패: {str(e)[:200]}", None
    if not head:
        return "fail", f"{kind} HEAD 리비전을 읽지 못함", None
    return "ok", f"{kind} @ {head}", head


def run(ctx: JobContext) -> Outcome:
    repo_id = ctx.job.get("ref_id") or ctx.params.get("repo_id")
    if not repo_id:
        return Outcome.fail("repo_id 없음")
    repo = store.repo_get(ctx.db, int(repo_id))
    if not repo:
        return Outcome.fail(f"ai_repos #{repo_id} 없음")
    ctx.progress(f"레포 점검 {repo.get('name')}")
    status, msg, head = check(ctx, repo)
    store.repo_update_check(ctx.db, int(repo_id), status, msg, head)
    ctx.event("repo.check", "ai_repos", int(repo_id), {"status": status, "message": msg, "head": head})
    ctx.db.commit()
    res = {"repo_id": int(repo_id), "status": status, "message": msg, "head_revision": head}
    if status != "ok":
        return Outcome.fail(msg, result=res)
    return ctx.outcome("done", result=res, progress=msg)
