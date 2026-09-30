from pydantic import BaseModel

from common.llm.client import LLMClient
from common.messages import Message

MAX_TOKENS = 256


class GuardOutput(BaseModel):
    forbidden: bool


class Guard:
    """금지된 메시지를 정한 지시문과 그것을 판정할 모델을 묶어, 대화 이력만 받아 판정합니다."""

    def __init__(self, llm_client: LLMClient, instructions: str) -> None:
        self._llm_client = llm_client
        self._instructions = instructions

    def is_forbidden_message(self, message: Message) -> bool:
        """대화의 마지막 유저 메시지가 금지된 메시지이면 True, 판정을 받지 못하면 False"""
        generated = self._llm_client.generate(
            system=self._instructions, messages=[message], output_format=GuardOutput, tools=[]
        )
        if generated.output is None:
            return False
        return generated.output.forbidden
