"""LLM 관련 예외 및 클라이언트 API 인터페이스입니다."""

from dataclasses import dataclass
from typing import Any, Protocol, TypeVar

from pydantic import BaseModel

from common.messages import Message
from common.tools.base import Evidence, Tool

T = TypeVar("T", bound=BaseModel)


@dataclass(frozen=True)
class GenerationResponse[O: BaseModel]:
    """LLM 호출 결과 DTO"""

    output: O | None  # 거부되거나 파싱에 실패하면 None
    evidences: list[Evidence]
    replies: list[str]


class LLMClient(Protocol):
    """LLM 클라이언트입니다."""

    def generate(
        self,
        *,
        system: str,
        messages: list[Message],
        output_format: type[T],
        tools: list[Tool[Any]],
        temperature: float | None = None,
    ) -> GenerationResponse[T]: ...


class LLMClientError(Exception):
    """LLM 오류입니다."""


class LLMClientRateLimitError(LLMClientError):
    """사용량 초과 오류입니다."""

    def __init__(self, retry_after: int):
        self.retry_after = retry_after
        super().__init__(f"요청량 초과. {retry_after}초 후 재시도")


class LLMClientUnreachableError(LLMClientError):
    """LLM 연결 관련 오류입니다."""


class LLMClientVendorError(LLMClientError):
    """LLM API 서버 관련 오류입니다."""


class LLMClientRequestError(LLMClientError):
    """LLM 요청 오류입니다."""
