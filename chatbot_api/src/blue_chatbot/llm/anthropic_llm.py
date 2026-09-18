"""LLMClient의 Anthropic 구현. anthropic 패키지는 이 파일에서만 import한다."""

import logging
from typing import TypeVar

import anthropic
from anthropic.types import MessageParam, OutputConfigParam
from pydantic import BaseModel

from blue_chatbot.messages import Message
from blue_chatbot.configs.core import config
from blue_chatbot.services.llm import (
    LLMClientRequestError,
    LLMClientRateLimitError,
    LLMClientUnreachableError,
    LLMClientVendorError,
)

logger = logging.getLogger(__name__)

T = TypeVar("T", bound=BaseModel)


def _output_config() -> OutputConfigParam | anthropic.Omit:
    if not config.effort:
        return anthropic.omit
    return {"effort": config.effort}


class AnthropicLLMClient:
    def __init__(self, client: anthropic.Anthropic):
        self._client = client

    def generate(
        self, *, system: str, messages: list[Message], output_format: type[T]
    ) -> T | None:
        """직렬화된 응답을 반환한다. 직렬화에 실패하면 None을 반환한다."""
        try:
            message = self._client.messages.parse(
                model=config.claude_model,
                max_tokens=config.max_tokens,
                output_config=_output_config(),
                system=system,
                messages=[
                    MessageParam(role=m.role, content=m.content) for m in messages
                ],
                output_format=output_format,
            )
        except anthropic.RateLimitError as exc:
            retry_after = int(exc.response.headers.get("retry-after", "60"))
            raise LLMClientRateLimitError(retry_after) from exc
        except (anthropic.APIConnectionError, anthropic.APITimeoutError) as exc:
            raise LLMClientUnreachableError(str(exc)) from exc
        except anthropic.APIStatusError as exc:
            if exc.status_code >= 500:
                raise LLMClientVendorError(str(exc)) from exc
            raise LLMClientRequestError(str(exc)) from exc

        if message.stop_reason == "refusal":
            return None

        if message.parsed_output is None:
            logger.warning("구조화 출력 파싱 실패 (stop_reason=%s)", message.stop_reason)
            return None

        return message.parsed_output


def build_from_config() -> AnthropicLLMClient:
    """Claude SDK 클라이언트를 생성한다"""
    key = config.anthropic_api_key
    return AnthropicLLMClient(
        anthropic.Anthropic(api_key=key.get_secret_value() if key else None)
    )
