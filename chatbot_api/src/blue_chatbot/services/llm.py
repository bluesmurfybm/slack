"""LLM 관련 예외 및 클라이언트 API 인터페이스"""

from typing import Protocol, TypeVar

from pydantic import BaseModel

from blue_chatbot.messages import Message


class LLMClientError(Exception):
    """LLM 에러"""


class LLMClientRateLimitError(LLMClientError):
    """사용량 초과 오류"""

    def __init__(self, retry_after: int):
        self.retry_after = retry_after
        super().__init__(f"요청량 초과. {retry_after}초 후 재시도")


class LLMClientUnreachableError(LLMClientError):
    """LLM 연결 관련 오류"""


class LLMClientVendorError(LLMClientError):
    """LLM api 서버 관련 오류"""

class LLMClientRequestError(LLMClientError):
    """LLM 요청 오류"""


T = TypeVar("T", bound=BaseModel)


class LLMClient(Protocol):
    """LLM 클라이언트"""

    def generate(
        self, *, system: str, messages: list[Message], output_format: type[T]
    ) -> T | None:
        ...
