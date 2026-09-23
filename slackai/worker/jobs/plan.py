"""plan — 고객사 작업 사본에서 Claude 를 읽기 전용으로 실행해 작업 계획(ai_plans draft) 을 만든다.

① triage 없으면 인라인 실행 ② repo 확정·경로·vcs 검증, base_revision ③ system.md = PLAN_SYSTEM + knowledge + notes,
user = 문의/요약/댓글/유사/교훈/이전 계획 ④ claude -p --json-schema PLAN --permission-mode dontAsk 읽기 도구, --session-id uuid4
⑤ structured_output 없으면 failed(원문 var/jobs/<id>/) ⑥ 경로 정규화 ⑦ ai_plans v=max+1 draft, 이전 draft → superseded
"""

import json
import uuid

from core import protect, store
from jobs import common
from jobs.base import JobContext, Outcome
from llm import prompts
from tools import claude_cli
from vcs.base import tracked_changes


def _pick_repo(ctx: JobContext, triage: dict | None) -> dict | None:
    rid = ctx.params.get("repo_id") or (triage or {}).get("repo_id") or ctx.job.get("repo_id")
    if not rid:
        return None
    return store.repo_get(ctx.db, int(rid))


def run(ctx: JobContext) -> Outcome:
    rid = ctx.request_id
    if not rid:
        return Outcome.fail("request_id 없음")
    req = store.request_get(ctx.db, rid)
    if not req:
        return Outcome.fail(f"requests 에 {rid} 없음")
    triage = store.triage_get(ctx.db, rid)
    if triage is None and not ctx.dry_run:
        ctx.progress("접수 분석이 없어 먼저 실행")
        from jobs import triage as triage_job

        o = triage_job.run(ctx)
        if o.status != "done":
            return o
        triage = store.triage_get(ctx.db, rid)
    repo = _pick_repo(ctx, triage)
    if not repo and ctx.dry_run:
        # dry-run 은 레포가 없어도 argv/프롬프트를 보여준다: 저장소 루트를 자리표시자로 쓴다
        from core.config import REPO_ROOT
        from vcs import detect

        repo = {"id": 0, "name": "(dry-run placeholder)", "vcs": detect.detect(str(REPO_ROOT)) or "git",
                "local_path": str(REPO_ROOT), "version": "", "notes": "", "active": 1}
        ctx.dry_out.append("[dry-run] ai_repos 매핑이 없어 저장소 루트를 자리표시자 레포로 사용\n")
    if not repo:
        return Outcome.fail("레포 선택 필요(ai_triage.repo_id 없음)", retryable=False)
    if int(repo.get("active", 1) or 0) != 1:
        return Outcome.fail(f"레포 비활성: {repo.get('name')}", retryable=False)
    vcs, err = common.open_repo(ctx, repo)
    if err:
        return Outcome.fail(err, retryable=False)

    ctx.progress("작업 사본 상태 확인")
    base_revision = ""
    try:
        base_revision = vcs.head_revision()
        dirty = tracked_changes(vcs.status())
        if dirty:
            ctx.log.warning("작업 사본에 로컬 변경 %d건(플랜은 계속)", len(dirty))
    except Exception as e:
        ctx.log.warning("vcs 상태 조회 실패(계속): %s", e)

    tag_ids = store.request_tag_ids(ctx.db, rid)
    lessons = [] if ctx.dry_run else store.lessons_for_plan(ctx.db, int(repo["id"]), tag_ids, 10)
    similar = store.similar_top(ctx.db, rid, 5)
    cc = store.comment_cache_get(ctx.db, rid)
    comments_text = (cc or {}).get("text") or None
    previous = store.plan_latest(ctx.db, rid)
    user_hint = str(ctx.params.get("hint") or "") or None
    system_md = prompts.plan_system(common.knowledge_md(repo), repo.get("notes"), ctx.settings.protected_list)
    user = prompts.plan_user(req, triage, comments_text, similar, lessons, previous, repo, base_revision, user_hint)
    session_id = str(uuid.uuid4())
    budget = float(ctx.params.get("budget_usd") or ctx.eff.budget_plan_usd)
    model = ctx.eff.plan_model_for(ctx.params.get("model"))   # 자동 플랜 = haiku, 사람이 고르면 sonnet/opus
    claude = common.claude_prefix(ctx)
    argv = claude_cli.plan_argv(claude, schema=prompts.PLAN_SCHEMA, session_id=session_id, model=model,
                                budget=budget, system_md=system_md, fallback_model=ctx.settings.claude_fallback_model,
                                protect=common.protect_rules(ctx, repo))
    ctx.save("plan_system.md", system_md)
    ctx.save("plan_user.md", user)
    if ctx.dry_run:
        shown = [a if len(a) < 400 else a[:200] + f"…(+{len(a) - 200}자)" for a in argv]
        ctx.dry_out.append(f"=== plan argv (cwd={repo['local_path']}) ===\n"
                           f"{json.dumps(shown, ensure_ascii=False, indent=1)}\n")
        ctx.dry_out.append("=== system prompt ===\n" + system_md + "\n")
        ctx.dry_out.append("=== user prompt (stdin) ===\n" + user + "\n")
        return ctx.outcome("done", result={"dry_run": True, "session_id": session_id})

    ctx.progress(f"Claude 플랜 실행 ({repo['name']}, {model}, 예산 ${budget:.2f})")
    r = claude_cli.run_claude(ctx.runner, argv, user, cwd=repo["local_path"], env=common.claude_env(ctx),
                              timeout=ctx.settings.claude_plan_timeout_sec, job_dir=ctx.job_dir, tag="plan")
    common.add_claude_cost(ctx, r, model)
    if r.timed_out:
        return ctx.outcome("failed", error=f"플랜 타임아웃 {ctx.settings.claude_plan_timeout_sec}초", retryable=False)
    if r.structured_output is None:
        return ctx.outcome("failed", error=f"플랜 구조화 출력 없음: {r.error or r.subtype}"[:1500], retryable=False,
                           result={"session_id": r.session_id, "num_turns": r.num_turns})
    plan = protect.strip_plan(prompts.normalize_plan(r.structured_output), ctx.settings.protected_list)
    plan_md = prompts.render_plan_md(plan, repo_name=repo["name"], base_revision=base_revision)
    if r.permission_denials:
        ctx.log.info("permission_denials %d건", len(r.permission_denials))
        ctx.save("plan_denials.json", json.dumps(r.permission_denials, ensure_ascii=False, indent=1))

    version = store.plan_max_version(ctx.db, rid) + 1
    plan_id = store.plan_insert(ctx.db, {
        "request_id": rid, "version": version, "repo_id": int(repo["id"]), "title": plan["title"],
        "plan_md": plan_md, "plan_json": json.dumps(plan, ensure_ascii=False), "budget_usd": None,
        "est_minutes": plan["estimated_minutes"], "user_hint": user_hint,
        "prompt_md": "## 시스템 프롬프트\n\n" + system_md + "\n\n## 사용자 프롬프트\n\n" + user,
        "claude_session_id": r.session_id or session_id, "base_revision": base_revision,
        "budget_hit": r.budget_hit, "num_turns": r.num_turns, "duration_ms": r.duration_ms,
        "model": r.model or model, "cost_usd": round(r.total_cost_usd, 4), "job_id": ctx.job_id or None,
        "created_by": ctx.job.get("requested_by") or ctx.actor,
    })
    superseded = store.plan_supersede_drafts(ctx.db, rid, plan_id)
    ctx.event("plan.created", "ai_plans", plan_id, {"version": version, "files": len(plan["files"]),
                                                   "needs_human": plan["needs_human"], "budget_hit": r.budget_hit,
                                                   "superseded": superseded, "denials": len(r.permission_denials)})
    ctx.db.commit()
    return ctx.outcome("done", result={"plan_id": plan_id, "version": version, "files": len(plan["files"]),
                                       "needs_human": plan["needs_human"], "budget_hit": r.budget_hit,
                                       "session_id": r.session_id or session_id, "num_turns": r.num_turns},
                       progress=f"플랜 v{version} 파일 {len(plan['files'])} 예상 {plan['estimated_minutes']}분")
