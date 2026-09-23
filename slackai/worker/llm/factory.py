"""LLM 백엔드 선택: ANTHROPIC_API_KEY 있으면 SDK, 없으면 claude -p 폴백. 실패 시 다음 백엔드로 넘어가는 체인."""

import logging

from llm.anthropic_llm import AnthropicLLM
from llm.base import LLMError, LLMResult
from llm.cli_llm import CliLLM
from llm.openai_llm import OpenAILLM

logger = logging.getLogger(__name__)


class ChainLLM:
    """첫 available() 백엔드로 호출, 재시도 가능 오류면 다음 백엔드. 모두 실패하면 마지막 오류."""

    name = "chain"

    def __init__(self, backends: list, fast: str, smart: str):
        self.backends = backends
        self.fast = fast
        self.smart = smart

    def available(self) -> bool:
        return any(b.available() for b in self.backends)

    def generate(self, *, system: str, user: str, schema: dict, model: str, max_tokens: int = 8000,
                 budget_usd: float = 0.5) -> LLMResult:
        last: Exception | None = None
        for b in self.backends:
            if not b.available():
                continue
            try:
                r = b.generate(system=system, user=user, schema=schema, model=model, max_tokens=max_tokens,
                               budget_usd=budget_usd)
                logger.info("LLM %s/%s in=%s out=%s cost=%.4f", b.name, r.model, r.tokens_in, r.tokens_out,
                            r.cost_usd)
                return r
            except LLMError as e:
                logger.warning("LLM %s 실패: %s", b.name, e)
                last = e
                if not e.retryable and b.name == "anthropic":
                    # 비재시도(거부 등)는 다른 백엔드도 같을 가능성이 크다
                    raise
            except Exception as e:
                logger.exception("LLM %s 예외", b.name)
                last = LLMError(f"{b.name}: {e}")
        raise last or LLMError("사용 가능한 LLM 백엔드가 없다(ANTHROPIC_API_KEY 도 claude CLI 도 없음)",
                               retryable=False)

    def names(self) -> list[str]:
        return [b.name for b in self.backends if b.available()]


def build_llm(settings, runner, job_dir_fn=None) -> ChainLLM:
    backends = [AnthropicLLM(settings.anthropic_api_key, timeout=settings.llm_timeout_sec),
                CliLLM(runner, settings, job_dir_fn=job_dir_fn)]
    if settings.openai_api_key:
        backends.append(OpenAILLM(settings.openai_api_key, settings.openai_model, timeout=settings.llm_timeout_sec))
    return ChainLLM(backends, settings.llm_model_fast, settings.llm_model_smart)
