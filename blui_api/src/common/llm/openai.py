"""LLMClient의 OpenAI 구현입니다. openai 패키지는 이 파일에서만 import합니다."""

import logging
from collections.abc import Iterator
from contextlib import contextmanager
from typing import Any, TypeVar

import openai
from openai.types.responses import FunctionToolParam
from pydantic import BaseModel, ValidationError

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


class OpenAILLMClient(LLMClient):
    def __init__(
        self,
        client: openai.OpenAI,
        *,
        model: str,
        max_tokens: int,
        effort: Effort | None,
    ):
        self._client = client
        self._model = model
        self._max_tokens = max_tokens
        self._effort = effort

    def generate(
        self,
        *,
        system: str,
        messages: list[Message],
        output_format: type[T],
        tools: list[Tool[Any]],
        temperature: float | None = None,
    ) -> GenerationResponse[T]:
        evidences: list[Evidence] = []
        replies: list[str] = []
        name2tool = {tool.name: tool for tool in tools}
        items: list[Any] = [{"role": m.role, "content": m.content} for m in messages]
        request: dict[str, Any] = {
            "model": self._model,
            "max_output_tokens": self._max_tokens,
            "instructions": system,
            "text_format": output_format,
        }
        if self._effort:
            request["reasoning"] = {"effort": self._effort}
        if temperature is not None:
            request["temperature"] = temperature
        if tools:
            request["tools"] = build_sdk_tools(tools)

        with _translate_errors():
            while True:
                try:
                    response = self._client.responses.parse(input=items, **request)
                except ValidationError as exc:
                    logger.warning("구조화 출력 파싱 실패: %s", exc)
                    return GenerationResponse(None, evidences, replies)
                calls = [item for item in response.output if item.type == "function_call"]
                if not calls:
                    break
                items = [*items, *response.output]
                for call in calls:
                    tool = name2tool[call.name]
                    result = tool.run(tool.parameters.model_validate_json(call.arguments))
                    evidences.extend(result.evidences)
                    if result.reply:
                        replies.append(result.reply)
                    items.append(
                        {
                            "type": "function_call_output",
                            "call_id": call.call_id,
                            "output": result.content,
                        }
                    )

        if _refused(response.output):
            return GenerationResponse(None, evidences, replies)

        parsed = _parsed(response.output)
        if parsed is None:
            logger.warning("구조화 출력 파싱 실패")
            return GenerationResponse(None, evidences, replies)

        return GenerationResponse(parsed, evidences, replies)


def build_sdk_tools(tools: list[Tool[Any]]) -> list[FunctionToolParam]:
    """내부 Tool 구현체들을 OpenAI Responses API의 함수 도구 정의로 변경합니다."""
    return [_to_sdk_tool(tool) for tool in tools]


def build_from_config(config: CommonConfig) -> OpenAILLMClient:
    """설정의 기본 모델로 OpenAI SDK 클라이언트를 생성합니다."""
    return build(
        config, model=config.openai_model, max_tokens=config.max_tokens, effort=config.effort
    )


def build(
    config: CommonConfig,
    *,
    model: str,
    max_tokens: int,
    effort: Effort | None,
) -> OpenAILLMClient:
    """지정한 모델과 생성 조건으로 OpenAI SDK 클라이언트를 생성합니다."""
    if config.openai_api_key is None:
        raise ValueError("OPENAI_API_KEY가 없습니다.")

    return OpenAILLMClient(
        openai.OpenAI(
            api_key=config.openai_api_key.get_secret_value(), base_url=config.openai_base_url
        ),
        model=model,
        max_tokens=max_tokens,
        effort=effort,
    )


def _to_sdk_tool(tool: Tool[Any]) -> FunctionToolParam:
    function = openai.pydantic_function_tool(
        tool.parameters, name=tool.name, description=tool.description
    )["function"]
    return {
        "type": "function",
        "name": function["name"],
        "description": function.get("description"),
        "parameters": dict(function.get("parameters") or {}),
        "strict": function.get("strict"),
    }


@contextmanager
def _translate_errors() -> Iterator[None]:
    """SDK 예외를 LLMClient 예외로 바꿉니다."""
    try:
        yield
    except openai.RateLimitError as exc:
        retry_after = int(exc.response.headers.get("retry-after", "60"))
        raise LLMClientRateLimitError(retry_after) from exc
    except (openai.APIConnectionError, openai.APITimeoutError) as exc:
        raise LLMClientUnreachableError(str(exc)) from exc
    except openai.APIStatusError as exc:
        if exc.status_code >= 500:
            raise LLMClientVendorError(str(exc)) from exc
        raise LLMClientRequestError(str(exc)) from exc


def _refused(output: list[Any]) -> bool:
    return any(
        content.type == "refusal"
        for item in output
        if item.type == "message"
        for content in item.content
    )


def _parsed(output: list[Any]) -> Any:
    for item in output:
        if item.type == "message":
            for content in item.content:
                if content.type == "output_text" and content.parsed is not None:
                    return content.parsed
    return None
