import logging
from typing import Any

from langchain.agents import create_agent
from langchain.agents.structured_output import ProviderStrategy, StructuredOutputError
from langchain_core.language_models import BaseChatModel
from langchain_core.messages import BaseMessage
from langchain_core.runnables import Runnable
from pydantic import BaseModel

logger = logging.getLogger(__name__)

type Agent = Runnable[Any, Any]


def build(model: BaseChatModel, schema: type[BaseModel], system_prompt: str | None = None) -> Agent:
    """도구 없이 schema 형식의 구조화 출력만 받는 에이전트를 생성합니다."""
    return create_agent(
        model,
        system_prompt=system_prompt,
        response_format=ProviderStrategy(schema, strict=True),
    )


def structured_response[O: BaseModel](
    agent: Agent, messages: list[BaseMessage], schema: type[O]
) -> O | None:
    """에이전트의 구조화 출력을 반환하며, 받지 못하면 None을 반환합니다."""
    try:
        result = agent.invoke({"messages": messages})
    except StructuredOutputError as exc:
        logger.warning("구조화 출력 파싱 실패: %s", exc)
        return None
    output = result.get("structured_response")
    return output if isinstance(output, schema) else None
