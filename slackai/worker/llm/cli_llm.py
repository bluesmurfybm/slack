"""claude -p 폴백 백엔드(API 키 없을 때). 도구 없음(--tools ""), --json-schema 구조화 출력, 저장소 도구 접근 없음.

cwd 는 워커 var 디렉터리(고객사 레포가 아니다) 로 고정해 CLAUDE.md 탐색이 저장소 규칙을 끌어오지 않게 한다.
"""

import logging
from pathlib import Path

from core import costs
from llm.base import LLMError, LLMResult
from tools import claude_cli

logger = logging.getLogger(__name__)


class CliLLM:
    name = "claude-cli"

    def __init__(self, runner, settings, claude_argv: list[str] | None = None, work_dir: Path | None = None,
                 job_dir_fn=None):
        self.runner = runner
        self.s = settings
        self._claude = claude_argv
        self.work_dir = work_dir or settings.data_path / "llm_cwd"
        self.job_dir_fn = job_dir_fn # () -> Path|None : 현재 잡의 var/jobs/<id>
        self._resolve_error: str | None = None

    def claude(self) -> list[str]:
        if self._claude is None:
            try:
                self._claude = claude_cli.resolve_claude(self.s.claude_cli, self.s.node_bin)
            except FileNotFoundError as e:
                self._resolve_error = str(e)
                self._claude = []
        return self._claude

    def available(self) -> bool:
        return bool(self.claude())

    def generate(self, *, system: str, user: str, schema: dict, model: str, max_tokens: int = 8000,
                 budget_usd: float = 0.5) -> LLMResult:
        _ = max_tokens
        cl = self.claude()
        if not cl:
            raise LLMError(self._resolve_error or "claude CLI 없음", retryable=False)
        self.work_dir.mkdir(parents=True, exist_ok=True)
        argv = claude_cli.llm_argv(cl, schema=schema, model=model, budget=max(0.05, budget_usd),
                                   system_md=system, fallback_model=self.s.claude_fallback_model)
        env = claude_cli.claude_env(use_api_key=self.s.claude_use_api_key,
                                    oauth_token=self.s.claude_code_oauth_token)
        job_dir = self.job_dir_fn() if self.job_dir_fn else None
        tag = "llm_" + (schema.get("title") or _schema_tag(schema))
        r = claude_cli.run_claude(self.runner, argv, user, cwd=str(self.work_dir), env=env,
                                  timeout=self.s.llm_timeout_sec, job_dir=job_dir, tag=tag)
        if r.timed_out:
            raise LLMError(f"claude CLI 타임아웃 {self.s.llm_timeout_sec}초", retryable=True)
        if r.raw is None:
            raise LLMError(f"claude CLI 출력 파싱 실패(rc={r.rc}): {r.error[:500]}", retryable=r.rc != 1)
        data = r.structured_output
        if data is None:
            # 스키마 출력이 비면 텍스트에서 JSON 을 시도
            from core.text import json_block

            try:
                data = json_block(r.result)
            except ValueError as e:
                raise LLMError(f"claude CLI 구조화 출력 없음: {r.error or r.result[:300]}",
                               retryable=not r.is_error) from e
        model_used = r.model or model
        cost = r.total_cost_usd or costs.estimate(model_used, r.input_tokens, r.output_tokens)
        return LLMResult(data=data, text=r.result, model=model_used, backend=self.name, tokens_in=r.input_tokens,
                         tokens_out=r.output_tokens, cost_usd=cost,
                         meta={"session_id": r.session_id, "num_turns": r.num_turns, "duration_ms": r.duration_ms,
                               "budget_hit": r.budget_hit, "subtype": r.subtype})


def _schema_tag(schema: dict) -> str:
    props = list((schema.get("properties") or {}).keys())
    return (props[0] if props else "out")[:20]
