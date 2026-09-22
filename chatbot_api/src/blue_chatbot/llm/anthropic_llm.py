"""LLMClient의 Anthropic 구현. anthropic 패키지는 이 파일에서만 import한다."""

import logging
from typing import Any, TypeVar

import anthropic
from anthropic import beta_tool
from anthropic.lib.tools import BetaFunctionTool
from anthropic.types import MessageParam, OutputConfigParam
from anthropic.types.beta import BetaMessageParam, BetaOutputConfigParam
from pydantic import BaseModel

from blue_chatbot.configs.core import config
from blue_chatbot.messages import Message
from blue_chatbot.services.llm import (
    LLMClient,
    LLMClientRateLimitError,
    LLMClientRequestError,
    LLMClientUnreachableError,
    LLMClientVendorError,
)
from blue_chatbot.tools.base import Evidence, Tool

logger = logging.getLogger(__name__)

T = TypeVar("T", bound=BaseModel)


def _output_config() -> OutputConfigParam | anthropic.Omit:
    if not config.effort:
        return anthropic.omit
    return {"effort": config.effort}


def build_sdk_tools(
    tools: list[Tool[Any]], evidences: list[Evidence]
) -> list[BetaFunctionTool[Any]]:
    """내부 Tool 구현체들을 Claude API SDK에 호환되는 객체로 변경합니다."""
    return [_to_sdk_tool(tool, evidences) for tool in tools]


def _to_sdk_tool(tool: Tool[Any], evidences: list[Evidence]) -> BetaFunctionTool[Any]:
    """내부 Tool 구현체를 Claude API SDK에 호환되는 객체로 변경합니다."""

    def adapter(**kwargs: Any) -> str:
        """Tool을 실행하고 결과를 반환하는 어댑터"""
        result = tool.run(tool.parameters.model_validate(kwargs))
        evidences.extend(result.evidences)
        return result.content

    return beta_tool(
        adapter,
        name=tool.name,
        description=tool.description,
        input_schema=tool.parameters,
    )


class AnthropicLLMClient(LLMClient):
    def __init__(self, client: anthropic.Anthropic):
        self._client = client

    def generate(
        self,
        *,
        system: str,
        messages: list[Message],
        output_format: type[T],
        tools: list[Tool[Any]],
    ) -> tuple[T | None, list[Evidence]]:
        """(최종 응답, 모델이 인용할 수 있는 후보 목록)를 반환합니다."""
        evidences: list[Evidence] = []
        sent = [MessageParam(role=m.role, content=m.content) for m in messages]
        try:
            if tools:
                beta_config: BetaOutputConfigParam | anthropic.Omit = (
                    {"effort": config.effort} if config.effort else anthropic.omit
                )
                parsed_message = self._client.beta.messages.tool_runner(
                    model=config.claude_model,
                    max_tokens=config.max_tokens,
                    output_config=beta_config,
                    system=[
                        {
                            "type": "text",
                            "text": system,
                            "cache_control": {"type": "ephemeral"},
                        }
                    ],
                    messages=[
                        BetaMessageParam(role=m.role, content=m.content)
                        for m in messages
                    ],
                    output_format=output_format,
                    tools=build_sdk_tools(tools, evidences),
                ).until_done()
                stop_reason = parsed_message.stop_reason
                parsed = parsed_message.parsed_output
            else:
                message = self._client.messages.parse(
                    model=config.claude_model,
                    max_tokens=config.max_tokens,
                    output_config=_output_config(),
                    system=[
                        {
                            "type": "text",
                            "text": system,
                            "cache_control": {"type": "ephemeral"},
                        }
                    ],
                    messages=sent,
                    output_format=output_format,
                )
                stop_reason = message.stop_reason
                parsed = message.parsed_output
        except anthropic.RateLimitError as exc:
            retry_after = int(exc.response.headers.get("retry-after", "60"))
            raise LLMClientRateLimitError(retry_after) from exc
        except (anthropic.APIConnectionError, anthropic.APITimeoutError) as exc:
            raise LLMClientUnreachableError(str(exc)) from exc
        except anthropic.APIStatusError as exc:
            if exc.status_code >= 500:
                raise LLMClientVendorError(str(exc)) from exc
            raise LLMClientRequestError(str(exc)) from exc

        if stop_reason == "refusal":  # 응답 거부
            return None, evidences

        if parsed is None:
            logger.warning("구조화 출력 파싱 실패 (stop_reason=%s)", stop_reason)
            return None, evidences

        return parsed, evidences


def build_from_config() -> AnthropicLLMClient:
    """Claude SDK 클라이언트를 생성한다"""
    if config.anthropic_api_key is None:
        raise ValueError("ANTHROPIC_API_KEY가 없습니다.")

    return AnthropicLLMClient(
        anthropic.Anthropic(api_key=config.anthropic_api_key.get_secret_value())
    )
