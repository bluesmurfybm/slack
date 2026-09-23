"""LLMClient 프로토콜(chatbot_api services/llm.py 패턴) + 결과 타입.

싼 단계(요약·태그·유사 재정렬·커밋 메시지·교훈·증류)는 이 인터페이스로만 호출한다.
백엔드: anthropic(SDK) → cli(claude -p, 도구 없음) → (선택) openai.
"""

from dataclasses import dataclass, field
from typing import Protocol


class LLMError(Exception):
    """LLM 호출 실패(재시도 가능 여부는 retryable)."""

    def __init__(self, msg: str, *, retryable: bool = True):
        super().__init__(msg)
        self.retryable = retryable


@dataclass
class LLMResult:
    data: dict
    text: str = ""
    model: str = ""
    backend: str = ""
    tokens_in: int = 0
    tokens_out: int = 0
    cost_usd: float = 0.0
    meta: dict = field(default_factory=dict)


class LLMClient(Protocol):
    name: str

    def available(self) -> bool: ...

    def generate(self, *, system: str, user: str, schema: dict, model: str,
                 max_tokens: int = 8000, budget_usd: float = 0.5) -> LLMResult: ...
