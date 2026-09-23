"""OpenAI 백엔드(선택, OPENAI_API_KEY 가 있을 때만). Responses API json_schema. 검토 폴백과 LLM 폴백에 쓴다."""

import json
import logging

from core import costs
from core.text import json_block
from llm.base import LLMError, LLMResult

logger = logging.getLogger(__name__)


class OpenAILLM:
    name = "openai"

    def __init__(self, api_key: str | None, default_model: str = "gpt-5", timeout: float = 600):
        self.api_key = api_key
        self.default_model = default_model
        self.timeout = timeout
        self._client = None

    def available(self) -> bool:
        if not self.api_key:
            return False
        try:
            import openai  # noqa: F401
        except ImportError:
            return False
        return True

    def _cli(self):
        if self._client is None:
            import openai

            self._client = openai.OpenAI(api_key=self.api_key, timeout=self.timeout, max_retries=2)
        return self._client

    def generate(self, *, system: str, user: str, schema: dict, model: str = "", max_tokens: int = 8000,
                 budget_usd: float = 0.5) -> LLMResult:
        _ = budget_usd
        model = model or self.default_model
        try:
            resp = self._cli().responses.create(
                model=model, instructions=system, input=user, max_output_tokens=max_tokens,
                text={"format": {"type": "json_schema", "name": "out", "schema": schema, "strict": True}},
            )
        except Exception as e:
            status = getattr(e, "status_code", 0) or 0
            raise LLMError(f"openai 실패: {e}", retryable=(status == 429 or status >= 500 or status == 0)) from e
        text = getattr(resp, "output_text", "") or ""
        try:
            data = json_block(text)
        except ValueError as e:
            raise LLMError(f"openai 출력 파싱 실패: {e}", retryable=True) from e
        u = getattr(resp, "usage", None)
        tin = int(getattr(u, "input_tokens", 0) or 0)
        tout = int(getattr(u, "output_tokens", 0) or 0)
        return LLMResult(data=data, text=text, model=model, backend=self.name, tokens_in=tin, tokens_out=tout,
                         cost_usd=costs.estimate(model, tin, tout),
                         meta={"raw": json.dumps(data, ensure_ascii=False)[:200]})
