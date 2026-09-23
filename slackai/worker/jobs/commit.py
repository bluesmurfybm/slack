"""commit — 사람이 confirm 한 뒤 워커가 svn/git 커밋(플랜 §2.2). ref_id = ai_commits.id (status pending, PHP 가 만든 행).

메시지: 행의 message(사용자 편집값) 가 비면 LLM fast COMMITMSG → `fix|feat|chore(area): 제목 [RecId]` ≤72자 강제, 실패 시 템플릿.
svn: --non-interactive --encoding UTF-8 -F msgfile -- <paths + 신규 파일 부모 디렉터리>; git: add/commit/rev-parse (push 는 SLACKAI_GIT_PUSH=1 ∧ params.push).
"""

import json
import re

from core import protect, store
from core.clock import now_str
from core.text import ellipsis
from jobs import common
from jobs.base import JobContext, NextJob, Outcome
from llm import prompts
from llm.base import LLMError
from vcs.base import changed_files

SUBJECT_RE = re.compile(r"^(fix|feat|chore)\(([^()\n]{1,20})\): (.+?) \[(Rec[A-Z0-9]+)\]$")
TYPES = ("fix", "feat", "chore")
MAX_SUBJECT = 72


def area_from_files(files: list[str]) -> str:
    """첫 변경 파일의 의미 있는 디렉터리 이름(≤20자)."""
    for f in files:
        parts = [p for p in f.replace("\\", "/").split("/") if p]
        if len(parts) >= 2:
            # local/ubattend/x.php → ubattend, mod/vod/… → vod, theme/coursemos/… → coursemos
            cand = parts[-2] if parts[-2] not in ("classes", "lib", "db", "lang", "templates", "amd") else parts[0]
            return cand[:20]
        if parts:
            return parts[0].rsplit(".", 1)[0][:20]
    return "misc"


def format_subject(subject: str | None, rec_id: str, *, area_hint: str = "misc", title_hint: str = "",
                   ctype: str = "fix") -> str:
    """LLM/사용자 subject 를 규격으로 맞춘다. 규격 밖이면 재조립."""
    s = (subject or "").strip().splitlines()[0] if subject and subject.strip() else ""
    m = SUBJECT_RE.match(s)
    if m and m.group(4) == rec_id:
        t, area, title, _ = m.groups()
    else:
        t, area, title = ctype, area_hint, s
        m2 = re.match(r"^([a-z]+)\(([^()\n]{1,40})\):\s*(.+)$", s)
        if m2:
            t, area, title = m2.group(1), m2.group(2)[:20], m2.group(3)
        title = re.sub(r"\s*\[Rec[A-Z0-9]+\]\s*$", "", title).strip() or title_hint or "문의 처리"
    if t not in TYPES:
        t = ctype
    area = (area or area_hint or "misc").strip()[:20] or "misc"
    fixed = f"{t}({area}): "
    tail = f" [{rec_id}]"
    room = MAX_SUBJECT - len(fixed) - len(tail)
    title = re.sub(r"\s+", " ", title).strip()
    if len(title) > room:
        title = title[: max(1, room - 1)].rstrip() + "…"
    return f"{fixed}{title}{tail}"


def template_message(req: dict, files: list[str], stats: list[dict], portal_url: str, summary: str | None) -> str:
    rec_id = req["id"]
    subject = format_subject(None, rec_id, area_hint=area_from_files(files), title_hint=ellipsis(req.get("title") or "", 50))
    lines = [subject, ""]
    if summary:
        lines.append(ellipsis(summary.replace("\n", " "), 200))
    for s in stats or [{"path": f, "add": 0, "del": 0} for f in files]:
        lines.append(f"- {s.get('path')} (+{s.get('add', 0)} -{s.get('del', 0)})")
    lines.append("")
    lines.append(f"Ref: {portal_url.rstrip('/')}/slackai/lists.php?id={rec_id}")
    return "\n".join(lines) + "\n"


def build_message(ctx: JobContext, req: dict, files: list[str], stats: list[dict], summary: str | None) -> str:
    portal = ctx.settings.portal_url
    try:
        r = ctx.llm_call("COMMITMSG", prompts.commitmsg_user(req, files, stats, summary, portal), smart=False,
                         budget_usd=0.1)
        subject = format_subject(str(r.data.get("subject") or ""), req["id"], area_hint=area_from_files(files),
                                 title_hint=ellipsis(req.get("title") or "", 50))
        body = str(r.data.get("body") or "").strip()
        if f"Ref: {portal.rstrip('/')}" not in body:
            body += f"\n\nRef: {portal.rstrip('/')}/slackai/lists.php?id={req['id']}"
        return subject + "\n\n" + body.strip() + "\n"
    except LLMError as e:
        ctx.log.warning("COMMITMSG LLM 실패 → 템플릿: %s", e)
        return template_message(req, files, stats, portal, summary)


def run(ctx: JobContext) -> Outcome:
    commit_id = ctx.job.get("ref_id") or ctx.params.get("commit_id")
    if not commit_id:
        return Outcome.fail("commit_id 없음")
    cm = store.commit_get(ctx.db, int(commit_id))
    if not cm:
        return Outcome.fail(f"ai_commits #{commit_id} 없음")
    if cm.get("status") != "pending":
        return Outcome.fail(f"커밋 행 상태가 pending 이 아님: {cm.get('status')}")
    execution = store.execution_get(ctx.db, int(cm["execution_id"]))
    if not execution or execution.get("status") != "done":
        return Outcome.fail("실행(ai_executions) 이 done 이 아님")
    plan = store.plan_get(ctx.db, int(execution["plan_id"]))
    if not plan or plan.get("status") not in ("executed", "committed", "reviewed"):
        return Outcome.fail(f"플랜 상태가 executed 가 아님: {(plan or {}).get('status')}")
    rid = cm["request_id"]
    req, repo = common.request_and_repo(ctx, rid, cm.get("repo_id") or plan.get("repo_id"))
    if not req or not repo:
        return Outcome.fail("문의 또는 레포 없음")
    vcs, err = common.open_repo(ctx, repo)
    if err:
        _fail(ctx, int(commit_id), err)
        return Outcome.fail(err, retryable=False)

    ctx.progress("커밋 대상 확인")
    try:
        pre = set(json.loads(execution.get("preexisting_json") or "[]"))
    except ValueError:
        pre = set()
    prot = ctx.settings.protected_list
    st = protect.filter_entries(vcs.status(), prot)   # 보호 파일(config.php 등)은 절대 커밋하지 않는다
    current = changed_files(st, pre)
    if not current:
        _fail(ctx, int(commit_id), "커밋할 변경 없음")
        return Outcome.fail("커밋할 변경 없음", retryable=False)
    stats = common.diff_stat_of(execution)
    recorded = {s.get("path") for s in stats}
    extra = [p for p in current if p not in recorded]
    log_lines = []
    if extra:
        log_lines.append("기록 외 추가 파일 포함: " + ", ".join(extra[:20]))
        ctx.log.warning("기록 외 변경 파일 %d건 포함", len(extra))
    new_files = [e.path for e in st if e.untracked and e.path not in pre]
    if new_files:
        vcs.add(new_files)

    message = (cm.get("message") or "").strip()
    triage = store.triage_get(ctx.db, rid)
    if not message:
        ctx.progress("커밋 메시지 생성")
        message = build_message(ctx, req, current, stats, (triage or {}).get("summary_short"))
    else:
        first, _, rest = message.partition("\n")
        message = format_subject(first, rid, area_hint=area_from_files(current),
                                 title_hint=ellipsis(req.get("title") or "", 50)) + ("\n" + rest if rest else "") + "\n"
    msgfile = ctx.save("commit_msg.txt", message)

    paths = sorted(x for x in set(current) if not protect.is_protected(x, prot))
    if vcs.kind == "svn":
        # 신규 파일의 부모 디렉터리(svn add --parents 로 함께 A 된 것)도 커밋 대상에
        st_after = protect.filter_entries(vcs.status(), prot)
        added_dirs = [e.path for e in st_after if e.code == "A" and e.path not in paths]
        paths = sorted(set(paths) | set(added_dirs))
    ctx.progress(f"{vcs.kind} commit ({len(paths)} 경로)")
    try:
        rev = vcs.commit(str(msgfile), paths)
    except Exception as e:
        _fail(ctx, int(commit_id), f"commit 실패: {str(e)[:1500]}")
        return Outcome.fail(f"commit 실패: {str(e)[:500]}", retryable=False)
    status = "committed"
    branch = vcs.branch() if vcs.kind == "git" else None
    if vcs.kind == "git" and ctx.settings.slackai_git_push and str(ctx.params.get("push") or "0") in ("1", "true"):
        try:
            log_lines.append(vcs.push()[-1000:])
            status = "pushed"
        except Exception as e:
            log_lines.append(f"push 실패: {str(e)[:500]}")
    store.commit_update(ctx.db, int(commit_id), vcs=vcs.kind, revision=(rev or "")[:80], branch=branch,
                        message=message, status=status, log="\n".join(log_lines)[:60000] or None,
                        job_id=ctx.job_id or None, committed_at=now_str(), repo_id=int(repo["id"]))
    store.plan_set_status(ctx.db, int(plan["id"]), "committed")
    ctx.event("commit." + status, "ai_commits", int(commit_id), {"revision": rev, "paths": len(paths), "vcs": vcs.kind})
    ctx.db.commit()
    nxt = [NextJob("review" if ctx.eff.auto_review else "learn", rid, {"from": "commit"}, ref_id=int(commit_id),
                   repo_id=int(repo["id"]))]
    return ctx.outcome("done", result={"commit_id": int(commit_id), "revision": rev, "status": status,
                                       "paths": paths, "subject": message.splitlines()[0]},
                       next_jobs=nxt, progress=f"{vcs.kind} {rev}")


def _fail(ctx: JobContext, commit_id: int, msg: str) -> None:
    store.commit_update(ctx.db, commit_id, status="failed", log=msg[:4000], job_id=ctx.job_id or None)
    ctx.event("commit.failed", "ai_commits", commit_id, {"error": msg[:500]})
    ctx.db.commit()
