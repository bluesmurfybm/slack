"""Anthropic SDK 백엔드. messages.create + output_config.format json_schema(구조화 출력). anthropic 은 여기서만 import."""

import json
import logging

from core import costs
from core.text import json_block
from llm.base import LLMError, LLMResult

logger = logging.getLogger(__name__)


class AnthropicLLM:
    name = "anthropic"

    def __init__(self, api_key: str | None, timeout: float = 600):
        self.api_key = api_key
        self.timeout = timeout
        self._client = None

    def available(self) -> bool:
        return bool(self.api_key)

    def _cli(self):
        if self._client is None:
            import anthropic

            self._client = anthropic.Anthropic(api_key=self.api_key, timeout=self.timeout, max_retries=2)
        return self._client

    def generate(self, *, system: str, user: str, schema: dict, model: str, max_tokens: int = 8000,
                 budget_usd: float = 0.5) -> LLMResult:
        import anthropic

        _ = budget_usd # SDK 경로는 토큰 상한으로만 제어한다
        try:
            msg = self._cli().messages.create(
                model=model, max_tokens=max_tokens, system=system,
                messages=[{"role": "user", "content": user}],
                output_config={"format": {"type": "json_schema", "schema": schema}},
            )
        except anthropic.RateLimitError as e:
            raise LLMError(f"anthropic rate limit: {e}", retryable=True) from e
        except (anthropic.APIConnectionError, anthropic.APITimeoutError) as e:
            raise LLMError(f"anthropic 연결 실패: {e}", retryable=True) from e
        except anthropic.APIStatusError as e:
            raise LLMError(f"anthropic {e.status_code}: {e}", retryable=e.status_code >= 500) from e
        if msg.stop_reason == "refusal":
            detail = getattr(msg, "stop_details", None)
            raise LLMError(f"모델 거부: {getattr(detail, 'category', '')}", retryable=False)
        text = "".join(b.text for b in msg.content if getattr(b, "type", "") == "text")
        try:
            data = json_block(text)
        except ValueError as e:
            raise LLMError(f"구조화 출력 파싱 실패: {e}", retryable=True) from e
        u = msg.usage
        tin = int(getattr(u, "input_tokens", 0) or 0) + int(getattr(u, "cache_read_input_tokens", 0) or 0) \
            + int(getattr(u, "cache_creation_input_tokens", 0) or 0)
        tout = int(getattr(u, "output_tokens", 0) or 0)
        return LLMResult(data=data, text=text, model=msg.model, backend=self.name, tokens_in=tin,
                         tokens_out=tout, cost_usd=costs.estimate(msg.model, tin, tout),
                         meta={"stop_reason": msg.stop_reason, "raw": json.dumps(data, ensure_ascii=False)[:200]})
