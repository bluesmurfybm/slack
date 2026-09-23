"""plan/execute/review/learn 이 공유하는 Claude·레포 헬퍼."""

import json
from pathlib import Path

from core import store
from core.config import KNOWLEDGE_DIR
from jobs.base import JobContext, repo_slug
from tools import claude_cli
from vcs import detect


def claude_prefix(ctx: JobContext) -> list[str]:
    return claude_cli.resolve_claude(ctx.settings.claude_cli, ctx.settings.node_bin)


def claude_env(ctx: JobContext) -> dict:
    return claude_cli.claude_env(use_api_key=ctx.settings.claude_use_api_key,
                                 oauth_token=ctx.settings.claude_code_oauth_token)


def knowledge_md(repo: dict) -> str:
    p = KNOWLEDGE_DIR / f"{repo_slug(repo)}.md"
    try:
        return p.read_text(encoding="utf-8") if p.is_file() else ""
    except OSError:
        return ""


def knowledge_path(repo: dict) -> Path:
    return KNOWLEDGE_DIR / f"{repo_slug(repo)}.md"


def open_repo(ctx: JobContext, repo: dict):
    """(vcs 인스턴스, 오류 문자열). 경로·vcs 종류 검증 포함."""
    path = str(repo.get("local_path") or "")
    if not path or not Path(path).is_dir():
        return None, f"작업 사본 경로 없음: {path}"
    kind = detect.detect(path)
    if kind is None:
        return None, f"작업 사본이 아님(.svn/.git 없음): {path}"
    if kind != (repo.get("vcs") or ""):
        return None, f"vcs 불일치: ai_repos={repo.get('vcs')} 실제={kind}"
    return detect.open_vcs(kind, ctx.runner, path, ctx.settings), ""


def plan_json_of(plan: dict) -> dict:
    try:
        d = json.loads(plan.get("plan_json") or "{}")
        return d if isinstance(d, dict) else {}
    except ValueError:
        return {}


def diff_stat_of(execution: dict) -> list[dict]:
    try:
        st = json.loads(execution.get("diff_stat") or "[]")
        return [s for s in st if isinstance(s, dict)]
    except ValueError:
        return []


def request_and_repo(ctx: JobContext, request_id: str, repo_id: int | None):
    req = store.request_get(ctx.db, request_id)
    repo = store.repo_get(ctx.db, int(repo_id)) if repo_id else None
    return req, repo


def add_claude_cost(ctx: JobContext, r, default_model: str) -> None:
    ctx.add_cost(r.total_cost_usd, r.input_tokens, r.output_tokens, r.model or default_model)


def protect_rules(ctx, repo: dict) -> list[str]:
    """이 레포에서 Claude 에 걸 보호 파일 거부 규칙(core/protect.py)."""
    from core import protect
    return protect.claude_rules(ctx.settings.protected_list, repo.get("remote_url"), repo.get("local_path"))
