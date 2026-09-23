"""review — Claude 가 아닌 다른 AI 가 문의+플랜+diff 를 검토. 체인 ai_settings.reviewer(기본 codex,openai,gemini,claude) 에서
available() 인 첫 백엔드, 예외 시 다음. ref_id = ai_commits.id.
가드: critical 지적 → fail, major 또는 addresses_inquiry=false → 최소 warn.
"""

import json

from core import protect, store
from jobs import common
from jobs.base import JobContext, NextJob, Outcome
from llm import prompts
from llm.openai_llm import OpenAILLM
from tools import claude_cli, codex_cli, gemini_cli


def guard_verdict(data: dict) -> str:
    v = str(data.get("verdict") or "warn")
    if v not in ("pass", "warn", "fail"):
        v = "warn"
    sev = {str(f.get("severity")) for f in (data.get("findings") or []) if isinstance(f, dict)}
    if "critical" in sev:
        return "fail"
    if ("major" in sev or data.get("addresses_inquiry") is False) and v == "pass":
        return "warn"
    return v


def render_report(data: dict) -> str:
    lines = [f"**verdict: {data.get('verdict')}** · 문의 해결: {'예' if data.get('addresses_inquiry') else '아니오/불확실'}", ""]
    lines.append((data.get("summary_md") or "").strip())
    if data.get("findings"):
        lines.append("\n## 지적 사항")
        for f in data["findings"]:
            loc = f.get("file") or ""
            if f.get("line"):
                loc += f":{f['line']}"
            lines.append(f"- [{f.get('severity')}] {loc} — {f.get('text')}" + (f"\n  - 제안: {f['suggestion']}" if f.get("suggestion") else ""))
    if data.get("missing"):
        lines.append("\n## 누락 의심")
        lines.extend(f"- {m}" for m in data["missing"])
    if data.get("test_suggestions"):
        lines.append("\n## 테스트 제안")
        lines.extend(f"- {t}" for t in data["test_suggestions"])
    return "\n".join(lines).strip() + "\n"


class _Result:
    def __init__(self, data: dict, backend: str, model: str, cost: float = 0.0, tin: int = 0, tout: int = 0):
        self.data, self.backend, self.model, self.cost, self.tin, self.tout = data, backend, model, cost, tin, tout


def _via_codex(ctx: JobContext, repo: dict, user: str) -> _Result:
    codex = codex_cli.resolve_codex(ctx.settings.codex_cli)
    if not codex:
        raise RuntimeError("codex 미설치")
    out_file = ctx.job_dir / "codex_last.md"
    argv = codex_cli.review_argv(codex, local_path=repo["local_path"], out_file=str(out_file),
                                 model=ctx.settings.codex_model)
    prompt = (prompts.REVIEW_SYSTEM + "\n\n" + user + "\n\n## 출력 스키마(JSON 하나만)\n"
              + json.dumps(prompts.REVIEW_SCHEMA, ensure_ascii=False))
    env = dict(common.claude_env(ctx))
    if ctx.settings.openai_api_key:
        env["OPENAI_API_KEY"] = ctx.settings.openai_api_key
    r = codex_cli.run_codex(ctx.runner, argv, prompt, cwd=repo["local_path"], env=env,
                            timeout=ctx.settings.claude_review_timeout_sec, out_file=out_file, job_dir=ctx.job_dir)
    if not r.ok:
        raise RuntimeError(f"codex 실패: {r.error[:500]}")
    return _Result(r.data, "codex", ctx.settings.codex_model or "codex")


def _via_openai(ctx: JobContext, user: str) -> _Result:
    llm = OpenAILLM(ctx.settings.openai_api_key, ctx.settings.openai_model, timeout=ctx.settings.claude_review_timeout_sec)
    if not llm.available():
        raise RuntimeError("OPENAI_API_KEY 없음")
    r = llm.generate(system=prompts.REVIEW_SYSTEM, user=user, schema=prompts.REVIEW_SCHEMA,
                     model=ctx.settings.openai_model, max_tokens=12000, budget_usd=ctx.eff.budget_review_usd)
    return _Result(r.data, "openai", r.model, r.cost_usd, r.tokens_in, r.tokens_out)


def _via_gemini(ctx: JobContext, repo: dict, user: str) -> _Result:
    if not gemini_cli.available(ctx.settings.gemini_cli):
        raise RuntimeError("gemini CLI 없음")
    return gemini_cli.review(ctx, repo, user)


def _via_claude(ctx: JobContext, repo: dict, user: str) -> _Result:
    claude = common.claude_prefix(ctx)
    model = ctx.settings.claude_review_model
    argv = claude_cli.review_argv(claude, schema=prompts.REVIEW_SCHEMA, model=model, budget=ctx.eff.budget_review_usd,
                                  system_md=prompts.REVIEW_SYSTEM + protect.prompt_rule(ctx.settings.protected_list),
                                  fallback_model=ctx.settings.claude_fallback_model, protect=common.protect_rules(ctx, repo))
    r = claude_cli.run_claude(ctx.runner, argv, user, cwd=repo["local_path"], env=common.claude_env(ctx),
                              timeout=ctx.settings.claude_review_timeout_sec, job_dir=ctx.job_dir, tag="review")
    if r.timed_out or r.structured_output is None:
        raise RuntimeError(f"claude 검토 실패: {r.error or r.subtype}"[:500])
    return _Result(r.structured_output, "claude", r.model or model, r.total_cost_usd, r.input_tokens, r.output_tokens)


def _openai_backend(ctx: JobContext, _repo: dict, user: str) -> _Result:
    return _via_openai(ctx, user)


BACKENDS = {"codex": _via_codex, "openai": _openai_backend, "gemini": _via_gemini, "claude": _via_claude}


def run(ctx: JobContext) -> Outcome:
    commit_id = ctx.job.get("ref_id") or ctx.params.get("commit_id")
    if not commit_id:
        return Outcome.fail("commit_id 없음")
    cm = store.commit_get(ctx.db, int(commit_id))
    if not cm or cm.get("status") not in ("committed", "pushed"):
        return Outcome.fail("커밋이 committed/pushed 상태가 아님")
    execution = store.execution_get(ctx.db, int(cm["execution_id"]))
    plan = store.plan_get(ctx.db, int(execution["plan_id"])) if execution else None
    rid = cm["request_id"]
    req, repo = common.request_and_repo(ctx, rid, cm.get("repo_id") or (plan or {}).get("repo_id"))
    if not req or not repo or not execution:
        return Outcome.fail("문의/레포/실행 없음")
    _vcs, err = common.open_repo(ctx, repo)
    if err:
        return Outcome.fail(err, retryable=False)
    diff_text = protect.filter_diff(execution.get("diff") or "", ctx.settings.protected_list)
    # codex/openai 는 권한 규칙을 걸 수 없으니 프롬프트로도 금지
    user = prompts.review_user(req, plan, diff_text, common.diff_stat_of(execution), cm.get("message") or "") \
        + protect.prompt_rule(ctx.settings.protected_list)
    ctx.save("review_user.md", user)
    tried: list[str] = []
    res: _Result | None = None
    for name in ctx.eff.reviewer_chain:
        fn = BACKENDS.get(name)
        if not fn:
            continue
        tried.append(name)
        ctx.progress(f"검토 백엔드 {name}")
        try:
            res = fn(ctx, repo, user)
            break
        except NotImplementedError as e:
            ctx.log.info("검토 %s 미구현: %s", name, e)
        except Exception as e:
            ctx.log.warning("검토 %s 실패 → 다음: %s", name, e)
    if res is None:
        return Outcome.fail("검토 백엔드 모두 실패: " + ",".join(tried), retryable=True)
    ctx.add_cost(res.cost, res.tin, res.tout, res.model)
    data = dict(res.data)
    data["verdict"] = guard_verdict(data)
    report = render_report(data)
    review_id = store.review_insert(ctx.db, {
        "commit_id": int(commit_id), "request_id": rid, "reviewer": res.backend, "verdict": data["verdict"],
        "addresses_inquiry": data.get("addresses_inquiry"), "report_md": report, "findings": data.get("findings") or [],
        "model": res.model, "cost_usd": round(res.cost, 4), "job_id": ctx.job_id or None,
        "requested_by": ctx.job.get("requested_by")})
    if plan:
        store.plan_set_status(ctx.db, int(plan["id"]), "reviewed")
    ctx.event("review.done", "ai_reviews", review_id, {"reviewer": res.backend, "verdict": data["verdict"],
                                                       "findings": len(data.get("findings") or []), "tried": tried})
    ctx.db.commit()
    nxt = [NextJob("learn", rid, {"from": "review"}, ref_id=int(commit_id), repo_id=int(repo["id"]))]
    return ctx.outcome("done", result={"review_id": review_id, "reviewer": res.backend, "verdict": data["verdict"],
                                       "tried": tried}, next_jobs=nxt,
                       progress=f"{res.backend}: {data['verdict']}")
