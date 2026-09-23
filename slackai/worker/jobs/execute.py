"""execute — 승인된 플랜을 Claude 가 같은 세션(--resume) 을 이어 실제로 수정한다. 커밋/리버트는 절대 하지 않는다.

ref_id = ai_plans.id (PHP approve_execute 가 ai_executions queued 행 + 이 잡을 만든다).
pre-flight: tracked 변경이 있으면 failed(untracked 는 preexisting_json 으로 기록해 svn add 대상에서 제외).
사후(항상): 변경 파일 − preexisting → 신규 파일 add(svn add --parents / git add -N) → diff(2MB 캡)·diff_stat → 변경 .php 에 php -l.
"""

import json
import uuid
from pathlib import Path

from core import protect, store
from core.clock import now_str
from jobs import common
from jobs.base import JobContext, Outcome
from llm import prompts
from tools import claude_cli, php_cli
from vcs.base import cap_diff, changed_files, diff_stat, sum_stat, tracked_changes, untracked


def _exec_system(repo: dict, protected: list[str] | tuple = ()) -> str:
    out = prompts.EXEC_SYSTEM + protect.prompt_rule(protected)
    km = common.knowledge_md(repo)
    if km.strip():
        out += "\n\n## 저장소 규칙(학습 지식)\n" + km.strip()[:9000]
    if (repo.get("notes") or "").strip():
        out += "\n\n## 저장소 메모\n" + str(repo["notes"]).strip()[:3000]
    return out


def run(ctx: JobContext) -> Outcome:
    plan_id = ctx.job.get("ref_id") or ctx.params.get("plan_id")
    if not plan_id:
        return Outcome.fail("plan_id 없음")
    plan = store.plan_get(ctx.db, int(plan_id))
    if not plan:
        return Outcome.fail(f"ai_plans #{plan_id} 없음")
    if plan.get("status") != "approved":
        return Outcome.fail(f"플랜 상태가 approved 가 아님: {plan.get('status')}")
    rid = plan["request_id"]
    req, repo = common.request_and_repo(ctx, rid, plan.get("repo_id"))
    if not req or not repo:
        return Outcome.fail("문의 또는 레포 없음")
    execution = store.execution_for_plan(ctx.db, int(plan_id))
    if not execution or execution.get("status") not in ("queued", "running"):
        eid = store.execution_insert(ctx.db, int(plan_id), rid, ctx.job.get("requested_by"), ctx.job_id or None)
        execution = store.execution_get(ctx.db, eid)
    eid = int(execution["id"])
    vcs, err = common.open_repo(ctx, repo)
    if err:
        _fail_exec(ctx, eid, int(plan_id), err)
        return Outcome.fail(err, retryable=False)

    # pre-flight
    ctx.progress("작업 사본 사전 점검")
    if ctx.settings.slackai_update_before_exec:
        try:
            if vcs.kind == "svn":
                vcs.update()
            else:
                vcs.pull_ff()
        except Exception as e:
            _fail_exec(ctx, eid, int(plan_id), f"사전 update 실패: {e}")
            return Outcome.fail(f"사전 update 실패: {e}", retryable=False)
    prot = ctx.settings.protected_list
    st = protect.filter_entries(vcs.status(), prot)   # 로컬용으로 고쳐 둔 config.php 등은 dirty 판정에서 제외
    dirty = tracked_changes(st)
    if dirty:
        msg = "작업사본이 깨끗하지 않음: " + ", ".join(f"{e.code} {e.path}" for e in dirty[:10])
        _fail_exec(ctx, eid, int(plan_id), msg)
        return Outcome.fail(msg, retryable=False)
    pre = untracked(st)
    branch = vcs.branch() if vcs.kind == "git" else None
    store.plan_set_status(ctx.db, int(plan_id), "executing")
    store.execution_update(ctx.db, eid, status="running", started_at=now_str(), job_id=ctx.job_id or None,
                           preexisting_json=json.dumps(pre, ensure_ascii=False), branch=branch)
    ctx.db.commit()

    plan_json = common.plan_json_of(plan)
    php_bin = repo.get("php_bin") or ctx.settings.php_bin
    system_md = _exec_system(repo, prot)
    rules = common.protect_rules(ctx, repo)
    fp_before = protect.fingerprint(repo["local_path"], prot)
    budget = float(plan.get("budget_usd") or ctx.eff.budget_execute_usd)
    model = ctx.settings.claude_exec_model
    claude = common.claude_prefix(ctx)
    env = common.claude_env(ctx)
    sid = plan.get("claude_session_id") or ""
    resumed = 1
    user = prompts.exec_user(plan_json, php_bin)
    ctx.progress(f"Claude 실행(세션 재개, 예산 ${budget:.2f})")
    r = None
    if sid:
        argv = claude_cli.exec_argv(claude, session_id=sid, resume=True, model=model, budget=budget,
                                    system_md=system_md, fallback_model=ctx.settings.claude_fallback_model,
                                    protect=rules)
        r = claude_cli.run_claude(ctx.runner, argv, user, cwd=repo["local_path"], env=env,
                                  timeout=ctx.settings.claude_exec_timeout_sec, job_dir=ctx.job_dir, tag="exec")
        common.add_claude_cost(ctx, r, model)
    if r is None or (r.raw is None and not r.timed_out) or r.not_found_session:
        ctx.progress("세션 재개 실패 → 새 세션으로 계획 전문 포함 재실행")
        resumed = 0
        new_sid = str(uuid.uuid4())
        user = prompts.exec_user(plan_json, php_bin, req, plan.get("plan_md"))
        argv = claude_cli.exec_argv(claude, session_id=new_sid, resume=False, model=model, budget=budget,
                                    system_md=system_md, fallback_model=ctx.settings.claude_fallback_model,
                                    protect=rules)
        r = claude_cli.run_claude(ctx.runner, argv, user, cwd=repo["local_path"], env=env,
                                  timeout=ctx.settings.claude_exec_timeout_sec, job_dir=ctx.job_dir, tag="exec_fresh")
        common.add_claude_cost(ctx, r, model)

    # 사후 처리(항상)
    ctx.progress("변경 수집(diff/lint)")
    log_lines = [f"resumed={resumed} rc={r.rc} subtype={r.subtype} turns={r.num_turns} cost={r.total_cost_usd:.4f}"]
    if r.timed_out:
        log_lines.append(f"타임아웃 {ctx.settings.claude_exec_timeout_sec}초")
    if r.error:
        log_lines.append("error: " + r.error[:1000])
    fp_after = protect.fingerprint(repo["local_path"], prot)
    touched = [p for p in prot if fp_before.get(p) != fp_after.get(p)]
    if touched:   # 거부 규칙을 우회해 보호 파일이 바뀜 → 되돌리지 않고(로컬 설정일 수 있음) 크게 알린다. 커밋에서는 항상 제외
        log_lines.append("⚠️ 보호 파일이 실행 중 바뀌었습니다(커밋에서 제외됨, 사람이 확인 필요): " + ", ".join(touched))
        ctx.log.warning("보호 파일 변경 감지: %s", touched)
    st2 = protect.filter_entries(vcs.status(), prot)
    pre_set = set(pre)
    changed = changed_files(st2, pre_set)
    new_files = [e.path for e in st2 if e.untracked and e.path not in pre_set]
    if new_files:
        try:
            vcs.add(new_files)
        except Exception as e:
            log_lines.append(f"add 실패: {e}")
    diff = ""
    try:
        diff = cap_diff(protect.filter_diff(vcs.diff(), prot))   # 보호 파일 구간은 저장·검토 AI 전달에서 제외
    except Exception as e:
        log_lines.append(f"diff 실패: {e}")
    stats = diff_stat(diff)
    n_files, n_add, n_del = sum_stat(stats)
    stat_paths = {s.path for s in stats}
    for p in changed:
        if p not in stat_paths:
            stats.append(_stat_placeholder(p, new_files))
    ctx.save("diff.patch", diff)
    lint_ok: bool | None = None
    lint_out: list[str] = []
    php_files = [p for p in changed if p.lower().endswith(".php") and (Path(repo["local_path"]) / p).is_file()]
    if php_files:
        lint_ok = True
        for p in php_files:
            ok, text = php_cli.php_lint(ctx.runner, php_bin, str(Path(repo["local_path"]) / p))
            lint_ok = lint_ok and ok
            lint_out.append(f"[{'OK' if ok else 'FAIL'}] {p}: {text.strip()[:400]}")
    status = "done" if changed else "failed"
    if not changed:
        log_lines.append("변경 없음")
    store.execution_update(
        ctx.db, eid, status=status, resumed=resumed, diff=diff,
        diff_stat=json.dumps([s.to_dict() for s in stats], ensure_ascii=False), files_changed=max(n_files, len(changed)),
        lines_added=n_add, lines_deleted=n_del, lint_ok=None if lint_ok is None else int(lint_ok),
        lint_output="\n".join(lint_out)[:60000] or None, result_md=(r.result or "")[:500000],
        log="\n".join(log_lines)[:200000], budget_hit=int(r.budget_hit), model=r.model or model,
        cost_usd=round(r.total_cost_usd, 4), finished_at=now_str())
    store.plan_set_status(ctx.db, int(plan_id), "executed" if status == "done" else "failed",
                          executed_at=now_str() if status == "done" else None)
    ctx.event("execute." + status, "ai_executions", eid, {"files": len(changed), "add": n_add, "del": n_del,
                                                          "lint_ok": lint_ok, "resumed": resumed,
                                                          "budget_hit": r.budget_hit, "turns": r.num_turns})
    ctx.db.commit()
    result = {"execution_id": eid, "files": changed, "lines_added": n_add, "lines_deleted": n_del,
              "lint_ok": lint_ok, "resumed": resumed, "budget_hit": r.budget_hit}
    if status != "done":
        return ctx.outcome("failed", error="변경 없음: " + (r.error or r.subtype or "Claude 가 파일을 수정하지 않았다")[:800],
                           retryable=False, result=result)
    return ctx.outcome("done", result=result, progress=f"파일 {len(changed)} +{n_add} -{n_del} lint={lint_ok}")


def _stat_placeholder(path: str, new_files: list[str]):
    from vcs.base import DiffStat

    return DiffStat(path, 0, 0, "A" if path in new_files else "M")


def _fail_exec(ctx: JobContext, eid: int, plan_id: int, msg: str) -> None:
    store.execution_update(ctx.db, eid, status="failed", log=msg[:4000], finished_at=now_str(),
                           job_id=ctx.job_id or None)
    store.plan_set_status(ctx.db, plan_id, "failed")
    ctx.event("execute.failed", "ai_executions", eid, {"error": msg[:500]})
    ctx.db.commit()
