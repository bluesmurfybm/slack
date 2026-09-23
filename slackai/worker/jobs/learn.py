"""learn / distill / bootstrap_knowledge (플랜 §2.2).

learn   : 플랜 예측 파일 vs 실제 변경 파일 지표 → ai_plans.metrics_json, LLM LESSONS → 중복 제거(토큰 Jaccard ≥ 0.6 → weight+0.1) → proposed 삽입
distill : 승인 교훈 → knowledge/<slug>.md 재작성(원자적) + ai_settings.knowledge_<slug>_updated_at
bootstrap_knowledge(distill params.bootstrap=1) : Claude 읽기 전용 스캔으로 첫 버전
"""

import json
import os
import re
import uuid
from pathlib import Path

from core import protect, store
from core.clock import now_str
from core.text import jaccard, tokens
from jobs import common
from jobs.base import JobContext, Outcome, repo_slug
from llm import prompts
from llm.base import LLMError
from tools import claude_cli

DEDUPE_JACCARD = 0.6
SECTIONS = ["## 저장소 개요", "## 디렉터리·모듈 지도", "## 코딩 규칙", "## 자주 놓치는 것", "## 테스트 방법"]


def metrics(predicted: list[str], actual: list[str], est_minutes: int | None, actual_minutes: float | None,
            verdict: str | None, plan_versions: int) -> dict:
    p, a = set(predicted), set(actual)
    inter = p & a
    jac = (len(inter) / len(p | a)) if (p | a) else 1.0
    precision = (len(inter) / len(p)) if p else (1.0 if not a else 0.0)
    recall = (len(inter) / len(a)) if a else 1.0 # 실제 변경이 없으면 놓친 것도 없다
    ratio = None
    if est_minutes and actual_minutes:
        ratio = round(actual_minutes / est_minutes, 2)
    return {"jaccard": round(jac, 3), "precision": round(precision, 3), "recall": round(recall, 3),
            "missed": sorted(a - p), "unexpected": sorted(p - a), "predicted": len(p), "actual": len(a),
            "est_minutes": est_minutes, "actual_minutes": None if actual_minutes is None else round(actual_minutes, 1),
            "est_vs_actual": ratio, "verdict": verdict, "plan_versions": plan_versions}


def _minutes(execution: dict) -> float | None:
    s, f = execution.get("started_at"), execution.get("finished_at")
    if not s or not f:
        return None
    try:
        return max(0.0, (f - s).total_seconds() / 60.0)
    except Exception:
        return None


def dedupe_or_insert(ctx: JobContext, lesson: dict, repo_id: int | None) -> tuple[str, int]:
    """(action 'bumped'|'inserted', id)"""
    new_t = tokens(lesson.get("lesson_md") or "")
    for ex in store.lessons_by_repo_kind(ctx.db, repo_id, lesson["kind"]):
        if jaccard(new_t, tokens(ex.get("lesson_md") or "")) >= DEDUPE_JACCARD:
            store.lesson_bump(ctx.db, int(ex["id"]))
            return "bumped", int(ex["id"])
    status = "approved" if float(lesson.get("weight") or 0) >= ctx.settings.lesson_auto_approve_weight else "proposed"
    lesson = dict(lesson, status=status,
                  approved_by=(ctx.actor if status == "approved" else None),
                  approved_at=(now_str() if status == "approved" else None))
    return "inserted", store.lesson_insert(ctx.db, lesson)


def run(ctx: JobContext) -> Outcome:
    rid = ctx.request_id
    commit_id = ctx.job.get("ref_id") or ctx.params.get("commit_id")
    cm = store.commit_get(ctx.db, int(commit_id)) if commit_id else None
    if cm is None and rid:
        cms = store.commits_for_request(ctx.db, rid)
        cm = store.commit_get(ctx.db, int(cms[0]["id"])) if cms else None
    if not cm:
        return Outcome.fail("학습할 커밋이 없다")
    rid = rid or cm["request_id"]
    execution = store.execution_get(ctx.db, int(cm["execution_id"]))
    plan = store.plan_get(ctx.db, int(execution["plan_id"])) if execution else None
    if not execution or not plan:
        return Outcome.fail("실행/플랜 없음")
    req, repo = common.request_and_repo(ctx, rid, cm.get("repo_id") or plan.get("repo_id"))
    if not req:
        return Outcome.fail("문의 없음")
    repo_id = int(repo["id"]) if repo else None
    plan_json = common.plan_json_of(plan)
    predicted = [f["path"] for f in plan_json.get("files") or [] if f.get("action") != "inspect"]
    actual = [s.get("path") for s in common.diff_stat_of(execution) if s.get("path")]
    review = store.review_latest(ctx.db, int(cm["id"]))
    m = metrics(predicted, actual, plan.get("est_minutes"), _minutes(execution),
                (review or {}).get("verdict"), store.plan_max_version(ctx.db, rid))
    ctx.progress(f"지표 jaccard={m['jaccard']} missed={len(m['missed'])}")
    ctx.db.exec("UPDATE ai_plans SET metrics_json = %s WHERE id = %s", (json.dumps(m, ensure_ascii=False), plan["id"]))
    corrections = store.events_for(ctx.db, rid, ["set_tags", "reject_plan", "set_repo"])
    for c in corrections:
        c["created_at"] = str(c.get("created_at"))
    tags = store.tags_active(ctx.db)
    user = prompts.lessons_user(req, plan, execution.get("diff") or "", review, m, corrections, tags)
    try:
        r = ctx.llm_call("LESSONS", user, smart=True, budget_usd=0.6)
    except LLMError as e:
        ctx.db.commit()
        return Outcome.fail(f"LESSONS LLM 실패: {e}", retryable=e.retryable, result={"metrics": m})
    inserted, bumped = [], []
    for ls in (r.data.get("lessons") or [])[:5]:
        if not isinstance(ls, dict) or not str(ls.get("lesson_md") or "").strip():
            continue
        kind = ls.get("kind") if ls.get("kind") in prompts.LESSON_KINDS else "other"
        scope = ls.get("scope") if ls.get("scope") in ("repo", "tag", "global") else "repo"
        tag_id = store.tag_id_by_slug(ctx.db, str(ls["tag_slug"])) if ls.get("tag_slug") else None
        try:
            weight = min(1.0, max(0.0, float(ls.get("weight") or 0.5)))
        except (TypeError, ValueError):
            weight = 0.5
        lesson = {"request_id": rid, "plan_id": int(plan["id"]), "commit_id": int(cm["id"]), "kind": kind,
                  "scope": scope, "repo_id": repo_id if scope != "global" else None, "tag_id": tag_id,
                  "title": str(ls["lesson_md"]).strip().splitlines()[0][:300], "lesson_md": str(ls["lesson_md"]).strip(),
                  "evidence_md": str(ls.get("evidence_md") or "").strip(), "weight": round(weight, 3),
                  "job_id": ctx.job_id or None}
        action, lid = dedupe_or_insert(ctx, lesson, repo_id)
        (inserted if action == "inserted" else bumped).append(lid)
    ctx.event("learn.done", "ai_plans", int(plan["id"]), {"metrics": m, "inserted": inserted, "bumped": bumped,
                                                          "plan_quality": r.data.get("plan_quality")})
    ctx.db.commit()
    return ctx.outcome("done", result={"metrics": m, "inserted": inserted, "bumped": bumped,
                                       "plan_quality": r.data.get("plan_quality")},
                       progress=f"교훈 신규 {len(inserted)} 보강 {len(bumped)}")


# ----------------------------------------------------------------------------- distill / bootstrap

def atomic_write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + f".{uuid.uuid4().hex[:6]}.tmp")
    tmp.write_text(text, encoding="utf-8", newline="\n")
    os.replace(tmp, path)


def _ensure_sections(md: str) -> str:
    lines = md.strip().splitlines()
    if len(lines) > 200:
        lines = lines[:200] # 본문을 먼저 200줄로 캡하고, 빠진 섹션 제목은 그 뒤에 붙인다
    out = "\n".join(lines)
    for sec in SECTIONS:
        if sec not in out:
            out += f"\n\n{sec}\n- (없음)\n"
    return out.strip() + "\n"


def run_distill(ctx: JobContext) -> Outcome:
    if str(ctx.params.get("bootstrap") or "0") in ("1", "true", "True"):
        return bootstrap(ctx)
    repo_id = ctx.params.get("repo_id") or ctx.job.get("ref_id") or ctx.job.get("repo_id")
    if not repo_id:
        return Outcome.fail("repo_id 없음")
    repo = store.repo_get(ctx.db, int(repo_id))
    if not repo:
        return Outcome.fail(f"ai_repos #{repo_id} 없음")
    lessons = store.lessons_approved_for_repo(ctx.db, int(repo_id), 80)
    path = common.knowledge_path(repo)
    current = common.knowledge_md(repo)
    if not lessons and not current.strip():
        return Outcome.fail("승인된 교훈도 기존 지식 문서도 없다(bootstrap 먼저)", retryable=False)
    ctx.progress(f"지식 증류 {repo['name']} (교훈 {len(lessons)})")
    try:
        r = ctx.llm_call("DISTILL", prompts.distill_user(repo, lessons, current or None), smart=True, budget_usd=0.8,
                         max_tokens=12000)
    except LLMError as e:
        return Outcome.fail(f"DISTILL LLM 실패: {e}", retryable=e.retryable)
    md = _ensure_sections(str(r.data.get("knowledge_md") or ""))
    atomic_write(path, md)
    slug = repo_slug(repo)
    store.setting_set(ctx.db, f"knowledge_{slug}_updated_at", now_str(), ctx.actor)
    ctx.event("distill.done", "ai_repos", int(repo_id), {"lessons": len(lessons), "lines": md.count("\n")}, request_id=None)
    ctx.db.commit()
    return ctx.outcome("done", result={"path": str(path), "lines": md.count("\n"), "lessons": len(lessons)},
                       progress=f"{slug}.md {md.count(chr(10))}줄")


def bootstrap(ctx: JobContext) -> Outcome:
    repo_id = ctx.params.get("repo_id") or ctx.job.get("ref_id") or ctx.job.get("repo_id")
    if not repo_id:
        return Outcome.fail("repo_id 없음")
    repo = store.repo_get(ctx.db, int(repo_id))
    if not repo:
        return Outcome.fail(f"ai_repos #{repo_id} 없음")
    path = common.knowledge_path(repo)
    force = str(ctx.params.get("force") or "0") in ("1", "true", "True")
    if path.is_file() and not force:
        return Outcome.fail(f"이미 있음: {path} (force=1 로 덮어쓰기)", retryable=False)
    _, err = common.open_repo(ctx, repo)
    if err:
        return Outcome.fail(err, retryable=False)
    claude = common.claude_prefix(ctx)
    budget = float(ctx.params.get("budget_usd") or ctx.eff.budget_plan_usd)
    argv = claude_cli.review_argv(claude, schema=prompts.BOOTSTRAP_SCHEMA, model=ctx.settings.claude_plan_model,
                                  budget=budget,
                                  system_md=prompts.BOOTSTRAP_SYSTEM + protect.prompt_rule(ctx.settings.protected_list),
                                  fallback_model=ctx.settings.claude_fallback_model, protect=common.protect_rules(ctx, repo))
    user = (f"## 저장소\n- 이름: {repo['name']} / vcs: {repo['vcs']} / 버전: {repo.get('version') or '-'}\n"
            + (f"- 메모: {repo['notes']}\n" if repo.get("notes") else "")
            + "\n## 요구 출력\n디렉터리 구조·주요 모듈·코딩 규칙·테스트 방법을 읽기 전용으로 조사해 스키마 JSON 하나.")
    if ctx.dry_run:
        ctx.dry_out.append("=== bootstrap argv ===\n" + json.dumps(argv, ensure_ascii=False, indent=1) + "\n" + user)
        return ctx.outcome("done", result={"dry_run": True})
    ctx.progress(f"Claude 읽기 전용 스캔 {repo['name']}")
    r = claude_cli.run_claude(ctx.runner, argv, user, cwd=repo["local_path"], env=common.claude_env(ctx),
                              timeout=ctx.settings.claude_plan_timeout_sec, job_dir=ctx.job_dir, tag="bootstrap")
    common.add_claude_cost(ctx, r, ctx.settings.claude_plan_model)
    if r.timed_out or r.structured_output is None:
        return ctx.outcome("failed", error=f"bootstrap 출력 없음: {r.error or r.subtype}"[:800], retryable=False)
    d = r.structured_output
    md = "\n\n".join([
        f"# {repo['name']} 저장소 지식",
        "## 저장소 개요\n" + str(d.get("overview_md") or "").strip(),
        "## 디렉터리·모듈 지도\n" + str(d.get("module_map_md") or "").strip(),
        "## 코딩 규칙\n" + str(d.get("coding_rules_md") or "").strip(),
        "## 자주 놓치는 것\n" + str(d.get("pitfalls_md") or "").strip(),
        "## 테스트 방법\n" + str(d.get("testing_md") or "").strip(),
    ])
    md = _ensure_sections(re.sub(r"\n{3,}", "\n\n", md))
    atomic_write(path, md)
    slug = repo_slug(repo)
    store.setting_set(ctx.db, f"knowledge_{slug}_updated_at", now_str(), ctx.actor)
    ctx.event("knowledge.bootstrap", "ai_repos", int(repo_id), {"lines": md.count("\n"), "cost": r.total_cost_usd},
              request_id=None)
    ctx.db.commit()
    return ctx.outcome("done", result={"path": str(path), "lines": md.count("\n")}, progress=f"{slug}.md 생성")
