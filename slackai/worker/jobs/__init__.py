"""잡 종류 → 핸들러. PRIORITY / MAX_ATTEMPTS 는 slackai/ai/ai_lib.php 의 AI_JOB_PRIORITY / AI_JOB_MAX_ATTEMPTS 와 동일."""

from core.jobs import MAX_ATTEMPTS, PRIORITY
from jobs import check_repo, commit, discover_repos, execute, ingest, learn, plan, resync, revert, review, triage

HANDLERS = {
    "ingest": ingest.run,
    "triage": triage.run,
    "plan": plan.run,
    "execute": execute.run,
    "commit": commit.run,
    "review": review.run,
    "learn": learn.run,
    "distill": learn.run_distill, # params.bootstrap=1 이면 bootstrap_knowledge
    "check_repo": check_repo.run,
    "discover_repos": discover_repos.run,   # school_access.repo ↔ 로컬 작업사본 자동 매핑
    "revert": revert.run,
    "resync": resync.run,
}

# 실행 전 일일 비용 게이트를 거치는 잡(플랜 §2.1)
COST_GATED = {"plan", "execute", "review"}

__all__ = ["COST_GATED", "HANDLERS", "MAX_ATTEMPTS", "PRIORITY"]
