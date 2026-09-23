"""triage — 접수 분석(플랜 §2.2).

① requests 로드, content_hash 같고 force 아니면 done(skip)
② 결정적 레포 매핑(repo_resolve)
③ LLM smart TRIAGE(펜스된 문의 + 활성 태그 + 레포 목록 + 힌트) → 미지 slug/id 제거, 결정적 매핑 우선, LLM 추정은 conf ≥ REPO_CONFIDENCE_MIN
④ ai_triage 저장, request_tags(source='ai' 만 교체), requests.ai_* 채움
⑤ 유사: similar_cli → 댓글(ai_comment_cache) → LLM fast RERANK → ai_similar(후보 rank 0 + 상위 rank 1~5)
⑥ 다음 잡: repo 확정 ∧ auto_plan ∧ 보관 아님 ∧ 진행 중인 플랜(승인~검토) 없음 → plan (플랜 프롬프트는 plan 잡이
   문의·요약·유사 사례·교훈·레포 지식으로 자동 조립). 상태/문제유형으로는 막지 않는다(분석하면 플랜까지가 기본).
"""

import json

from core import store
from core.text import ellipsis, src_hash
from jobs import repo_resolve
from jobs.base import JobContext, NextJob, Outcome
from llm import prompts
from llm.base import LLMError
from tools import php_cli

DIFF_CONF = {"high": "high", "medium": "medium", "low": "low"}


def _comments_cached(ctx: JobContext, rid: str, cmt_count: int, allow_fetch: bool) -> str:
    """ai_comment_cache 경유. cmt_count 가 같으면 재사용, 다르면 comments_cli 로 재조회(토큰 없으면 캐시/빈 문자열)."""
    cached = store.comment_cache_get(ctx.db, rid)
    if cached and int(cached.get("cmt_count") or 0) == int(cmt_count or 0) and cached.get("text") is not None:
        return cached["text"] or ""
    if not allow_fetch or int(cmt_count or 0) <= 0:
        return (cached or {}).get("text") or ""
    res = php_cli.comments(ctx.runner, ctx.settings, rid, max_messages=60)
    if not res.get("ok"):
        ctx.log.info("댓글 조회 불가 %s: %s", rid, res.get("error"))
        return (cached or {}).get("text") or ""
    text = str(res.get("text") or "")
    store.comment_cache_set(ctx.db, rid, str(res.get("anchor") or ""), text, cmt_count)
    return text


def _extra_resolution(ctx: JobContext, rid: str) -> str:
    """후보 문의에 우리 커밋/실행 기록이 있으면 첨부."""
    commits = store.commits_for_request(ctx.db, rid)
    if not commits:
        return ""
    parts = []
    for c in commits[:2]:
        parts.append(f"커밋 {c.get('vcs')} {c.get('revision')}: {ellipsis(c.get('message') or '', 300)}")
        ex = store.execution_get(ctx.db, int(c["execution_id"])) if c.get("execution_id") else None
        if ex and ex.get("diff_stat"):
            try:
                st = json.loads(ex["diff_stat"])
                parts.append("변경 파일: " + ", ".join(s.get("path", "") for s in st[:15]))
            except ValueError:
                pass
    return "\n".join(parts)


def _pick_tags(llm_tags: list, tags: list[dict]) -> list[tuple[int, float]]:
    by_slug = {t["slug"]: t for t in tags}
    out: list[tuple[int, float]] = []
    seen: set[int] = set()
    for t in llm_tags or []:
        if not isinstance(t, dict):
            continue
        tag = by_slug.get(str(t.get("slug") or ""))
        if not tag or tag["id"] in seen:
            continue
        seen.add(tag["id"])
        try:
            conf = min(1.0, max(0.0, float(t.get("confidence") or 0)))
        except (TypeError, ValueError):
            conf = 0.5
        out.append((int(tag["id"]), round(conf, 3)))
        if len(out) >= 3:
            break
    return out


def decide_repo(guess: repo_resolve.RepoGuess, llm_guess: dict | None, repo_ids: set[int],
                conf_min: float) -> tuple[int | None, float, str | None, list[dict]]:
    """결정적 매핑이 LLM 추정보다 우선. LLM 은 목록 안의 id 이고 conf ≥ conf_min 일 때만."""
    cands = list(guess.candidates)
    if guess.repo_id is not None:
        return guess.repo_id, guess.confidence, guess.reason, cands
    if llm_guess and isinstance(llm_guess, dict):
        rid = llm_guess.get("repo_id")
        try:
            conf = float(llm_guess.get("confidence") or 0)
        except (TypeError, ValueError):
            conf = 0.0
        if isinstance(rid, int) and rid in repo_ids:
            cands.append({"id": rid, "score": round(conf, 3), "reason": "llm",
                          "note": ellipsis(str(llm_guess.get("reason") or ""), 200)})
            if conf >= conf_min:
                return rid, round(conf, 3), "llm", cands
    return None, guess.confidence, None, cands


# 이 상태의 플랜이 있으면 사람이 이미 진행 중인 것 → 새 플랜으로 덮지 않는다(draft/rejected/failed/superseded 는 새 버전 허용)
PLAN_BUSY = ("approved", "executing", "executed", "committed", "reviewed")


def should_plan(eff, req: dict, repo_id: int | None, latest_plan_status: str | None = None) -> bool:
    """분석 후 플랜 자동 실행 여부. 문의 상태·유형과 관계없이 레포만 확정되면 플랜을 만든다."""
    if not repo_id or not eff.auto_plan:
        return False
    if int(req.get("archived") or 0) == 1:
        return False
    return latest_plan_status not in PLAN_BUSY


def run(ctx: JobContext) -> Outcome:
    rid = ctx.request_id
    if not rid:
        return Outcome.fail("request_id 없음")
    req = store.request_get(ctx.db, rid)
    if not req:
        return Outcome.fail(f"requests 에 {rid} 없음")
    h = src_hash(req.get("title"), req.get("body"))
    existing = store.triage_get(ctx.db, rid)
    force = str(ctx.params.get("force") or "0") in ("1", "true", "True") or ctx.dry_run
    if existing and existing.get("content_hash") == h and not force:
        return ctx.outcome("done", result={"skipped": "same_hash"}, progress="내용 변경 없음 → 스킵")

    tags = store.tags_active(ctx.db)
    repos = store.repos_active(ctx.db)
    schools = store.schools_all(ctx.db)
    repo_ids = {int(r["id"]) for r in repos}
    guess = repo_resolve.resolve(req, repos, schools)
    ctx.progress("문의 댓글 확보")
    self_comments = _comments_cached(ctx, rid, int(req.get("cmt_count") or 0), allow_fetch=True)

    # ③ LLM TRIAGE
    ctx.progress("LLM 접수 분석")
    user = prompts.triage_user(req, tags, repos, guess.to_hint(), self_comments or None)
    try:
        r = ctx.llm_call("TRIAGE", user, smart=True, budget_usd=0.6)
    except LLMError as e:
        if ctx.dry_run:
            return ctx.outcome("done", result={"dry_run": True})
        return Outcome.fail(f"TRIAGE LLM 실패: {e}", retryable=e.retryable)
    d = r.data
    picks = _pick_tags(d.get("tags"), tags)
    repo_id, repo_conf, repo_reason, cands = decide_repo(guess, d.get("repo_guess"), repo_ids,
                                                         ctx.settings.repo_confidence_min)
    problem_type = d.get("problem_type") if d.get("problem_type") in prompts.PROBLEM_TYPES else "other"
    urgency = d.get("urgency") if d.get("urgency") in prompts.URGENCIES else "normal"
    try:
        difficulty = min(5, max(1, int(d.get("difficulty") or 3)))
    except (TypeError, ValueError):
        difficulty = 3
    summary_md = str(d.get("summary_md") or "").strip()
    first = next((ln.lstrip("-*• ").strip() for ln in summary_md.splitlines() if ln.strip()), "")
    new_tag = d.get("new_tag_suggestion")
    triage_row = {
        "summary_md": summary_md, "summary_short": ellipsis(first or summary_md, 300),
        "problem_type": problem_type, "urgency": urgency,
        "affected_area": ellipsis(str(d.get("affected_area") or ""), 200) or None,
        "difficulty": difficulty,
        "questions": [str(q) for q in (d.get("needs_more_info") or [])][:10],
        "suggested_new_tags": [str(new_tag)] if new_tag else [],
        "repo_id": repo_id, "repo_confidence": repo_conf if repo_id else (cands[0]["score"] if cands else None),
        "repo_reason": repo_reason, "repo_candidates": cands, "content_hash": h,
        "model": r.model, "cost_usd": round(r.cost_usd, 4), "job_id": ctx.job_id or None,
    }
    # ④ 저장
    store.triage_upsert(ctx.db, rid, triage_row)
    store.request_tags_replace_ai(ctx.db, rid, picks, ctx.actor)
    store.request_set_ai(ctx.db, rid, difficulty, str(d.get("difficulty_reason") or ""),
                         DIFF_CONF.get(str(d.get("difficulty_conf") or ""), "medium"), h)
    ctx.event("triage.done", "ai_triage", None, {"problem_type": problem_type, "urgency": urgency,
                                                 "difficulty": difficulty, "repo_id": repo_id,
                                                 "repo_reason": repo_reason, "tags": picks,
                                                 "version": (existing or {}).get("version", 0) + 1})
    ctx.db.commit()

    # ⑤ 유사 사례
    similar_count = 0
    top_count = 0
    try:
        similar_count, top_count = _similar(ctx, req, self_comments)
    except LLMError as e:
        ctx.log.warning("유사 재정렬 LLM 실패(후보만 저장): %s", e)
    except Exception:
        ctx.log.exception("유사 사례 처리 실패(무시)")
        ctx.db.rollback()
    ctx.db.commit()

    # ⑥ 다음 잡
    nxt: list[NextJob] = []
    latest = store.plan_latest(ctx.db, rid)
    if should_plan(ctx.eff, req, repo_id, latest["status"] if latest else None):
        params = {"source": "auto", "from": "triage", "repo_id": repo_id}
        if latest:
            params["prev_plan_id"] = int(latest["id"])
        nxt.append(NextJob("plan", rid, params, repo_id=repo_id))
    elif ctx.eff.auto_plan and not repo_id:
        ctx.log.info("플랜 자동 실행 보류: 레포 미확정(화면에서 레포를 고르면 플랜이 자동 등록됨)")
    result = {"problem_type": problem_type, "urgency": urgency, "difficulty": difficulty, "tags": picks,
              "repo_id": repo_id, "repo_reason": repo_reason, "similar": similar_count, "similar_top": top_count,
              "next": [n.kind for n in nxt]}
    return ctx.outcome("done", result=result, next_jobs=nxt,
                       progress=f"{problem_type}/{urgency}/★{difficulty} 태그 {len(picks)} 유사 {top_count}")


def _similar(ctx: JobContext, req: dict, self_comments: str) -> tuple[int, int]:
    rid = req["id"]
    ctx.progress("유사 문의 검색(TF-IDF)")
    res = php_cli.similar(ctx.runner, ctx.settings, rid, limit=ctx.eff.similar_limit, min_score=ctx.eff.similar_min)
    if not res.get("ok"):
        ctx.log.warning("similar_cli 실패: %s", res.get("error"))
        return 0, 0
    cands = [c for c in (res.get("results") or []) if isinstance(c, dict) and c.get("id") and c["id"] != rid]
    if not cands:
        store.similar_replace(ctx.db, rid, [], ctx.job_id or None)
        return 0, 0
    brief = store.requests_brief(ctx.db, [c["id"] for c in cands])
    for c in cands:
        b = brief.get(c["id"]) or {}
        c["cmt_count"] = int(b.get("cmt_count") or 0)
        c.setdefault("status", b.get("status"))
        c.setdefault("done", b.get("done"))
    # 댓글: cmt_count>0 인 상위 ≤ SIMILAR_COMMENT_FETCH_MAX 건
    comments: dict[str, str] = {}
    fetched = 0
    for c in cands:
        if c["cmt_count"] <= 0:
            continue
        allow = fetched < ctx.settings.similar_comment_fetch_max
        text = _comments_cached(ctx, c["id"], c["cmt_count"], allow_fetch=allow)
        if allow:
            fetched += 1
        if text:
            comments[c["id"]] = text
    extra = {c["id"]: _extra_resolution(ctx, c["id"]) for c in cands}
    extra = {k: v for k, v in extra.items() if v}
    ctx.db.commit()

    rows = [{"id": c["id"], "score_tfidf": round(float(c.get("score") or 0), 3), "rank": 0} for c in cands]
    ctx.progress("LLM 유사 재정렬")
    user = prompts.rerank_user(req, cands, comments, extra, self_comments or None)
    try:
        r = ctx.llm_call("RERANK", user, smart=False, budget_usd=0.3)
        top = [t for t in (r.data.get("top") or []) if isinstance(t, dict)]
    except LLMError as e:
        store.similar_replace(ctx.db, rid, rows, ctx.job_id or None)
        raise e
    by_id = {row["id"]: row for row in rows}
    rank = 0
    for t in top:
        sid = str(t.get("id") or "")
        if sid not in by_id or by_id[sid]["rank"]:
            continue
        rank += 1
        try:
            sc = min(1.0, max(0.0, float(t.get("score") or 0)))
        except (TypeError, ValueError):
            sc = 0.0
        by_id[sid].update({"rank": rank, "score_llm": round(sc, 3),
                           "why_similar": ellipsis(str(t.get("why_similar") or ""), 300),
                           "resolution": str(t.get("resolution_note") or "처리 내용 미확인")})
        if rank >= 5:
            break
    store.similar_replace(ctx.db, rid, rows, ctx.job_id or None)
    return len(rows), rank
