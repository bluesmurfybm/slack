"""LLMClient의 Anthropic 구현입니다. anthropic 패키지는 이 파일에서만 import합니다."""

import logging
from typing import Any, TypeVar

import anthropic
from anthropic import beta_tool
from anthropic.lib.tools import BetaFunctionTool
from anthropic.types import MessageParam, OutputConfigParam
from anthropic.types.beta import BetaMessageParam, BetaOutputConfigParam
from pydantic import BaseModel

from common.configs import CommonConfig, Effort
from common.llm.client import (
    GenerationResponse,
    LLMClient,
    LLMClientRateLimitError,
    LLMClientRequestError,
    LLMClientUnreachableError,
    LLMClientVendorError,
)
from common.messages import Message
from common.tools.base import Evidence, Tool

logger = logging.getLogger(__name__)


T = TypeVar("T", bound=BaseModel)


class AnthropicLLMClient(LLMClient):
    def __init__(
        self,
        client: anthropic.Anthropic,
        *,
        model: str,
        max_tokens: int,
        effort: Effort | None,
    ):
        self._client = client
        self._model = model
        self._max_tokens = max_tokens
        self._effort = effort

    def _output_config(self) -> OutputConfigParam | anthropic.Omit:
        if not self._effort:
            return anthropic.omit
        return {"effort": self._effort}

    def generate(
        self,
        *,
        system: str,
        messages: list[Message],
        output_format: type[T],
        tools: list[Tool[Any]],
    ) -> GenerationResponse[T]:
        evidences: list[Evidence] = []
        replies: list[str] = []
        sent = [MessageParam(role=m.role, content=m.content) for m in messages]
        try:
            if tools:
                beta_config: BetaOutputConfigParam | anthropic.Omit = (
                    {"effort": self._effort} if self._effort else anthropic.omit
                )
                parsed_message = self._client.beta.messages.tool_runner(
                    model=self._model,
                    max_tokens=self._max_tokens,
                    output_config=beta_config,
                    cache_control={"type": "ephemeral"},
                    system=[
                        {
                            "type": "text",
                            "text": system,
                            "cache_control": {"type": "ephemeral"},
                        }
                    ],
                    messages=[BetaMessageParam(role=m.role, content=m.content) for m in messages],
                    output_format=output_format,
                    tools=build_sdk_tools(tools, evidences, replies),
                ).until_done()
                stop_reason = parsed_message.stop_reason
                parsed = parsed_message.parsed_output
            else:
                message = self._client.messages.parse(
                    model=self._model,
                    max_tokens=self._max_tokens,
                    output_config=self._output_config(),
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
            return GenerationResponse(None, evidences, replies)

        if parsed is None:
            logger.warning("구조화 출력 파싱 실패 (stop_reason=%s)", stop_reason)
            return GenerationResponse(None, evidences, replies)

        return GenerationResponse(parsed, evidences, replies)


def build_sdk_tools(
    tools: list[Tool[Any]], evidences: list[Evidence], replies: list[str]
) -> list[BetaFunctionTool[Any]]:
    """내부 Tool 구현체들을 Claude API SDK에 호환되는 객체로 변경합니다."""
    return [_to_sdk_tool(tool, evidences, replies) for tool in tools]


def build_from_config(config: CommonConfig) -> AnthropicLLMClient:
    """설정의 기본 모델로 Claude SDK 클라이언트를 생성합니다."""
    return build(
        config, model=config.claude_model, max_tokens=config.max_tokens, effort=config.effort
    )


def build(
    config: CommonConfig, *, model: str, max_tokens: int, effort: Effort | None
) -> AnthropicLLMClient:
    """지정한 모델과 생성 조건으로 Claude SDK 클라이언트를 생성합니다."""
    if config.anthropic_api_key is None:
        raise ValueError("ANTHROPIC_API_KEY가 없습니다.")

    return AnthropicLLMClient(
        anthropic.Anthropic(api_key=config.anthropic_api_key.get_secret_value()),
        model=model,
        max_tokens=max_tokens,
        effort=effort,
    )


def _to_sdk_tool(
    tool: Tool[Any], evidences: list[Evidence], replies: list[str]
) -> BetaFunctionTool[Any]:
    """내부 Tool 구현체를 Claude API SDK에 호환되는 객체로 변경합니다."""

    def adapter(**kwargs: Any) -> str:
        """Tool을 실행하고 결과를 반환하는 어댑터입니다."""
        result = tool.run(tool.parameters.model_validate(kwargs))
        evidences.extend(result.evidences)
        if result.reply:
            replies.append(result.reply)
        return result.content

    return beta_tool(
        adapter,
        name=tool.name,
        description=tool.description,
        input_schema=tool.parameters,
    )
