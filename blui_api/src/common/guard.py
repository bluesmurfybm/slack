from pydantic import BaseModel, Field

from common.llm.client import LLMClient
from common.messages import Message

MAX_TOKENS = 256


class GuardOutput(BaseModel):
    forbidden: bool = Field(description="블루소프트와 관련되지 않은 요청이면 true")


class Guard:
    """금지된 메시지를 정한 지시문과 그것을 판정할 모델을 묶어, 대화 이력만 받아 판정합니다."""

    def __init__(self, llm_client: LLMClient, instructions: str) -> None:
        self._llm_client = llm_client
        self._instructions = instructions

    def is_forbidden_message(self, messages: list[Message]) -> bool:
        """대화 전체를 보고 마지막 유저 메시지가 금지된 메시지이면 True, 판정을 받지 못하면 False"""
        generated = self._llm_client.generate(
            system=self._instructions,
            messages=messages,
            output_format=GuardOutput,
            tools=[],
            temperature=0,
        )
        if generated.output is None:
            return False
        return generated.output.forbidden
