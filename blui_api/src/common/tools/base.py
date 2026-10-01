"""Tool 관련 예외 및 인터페이스입니다."""

from dataclasses import dataclass
from typing import Protocol

from pydantic import BaseModel


class Evidence(BaseModel):
    """모델이 인용할 수 있는 근거입니다."""

    source: str  # 사용한 Tool
    id: str  # 식별값
    title: str
    content: str


@dataclass(frozen=True)
class ToolResult:
    content: str  # 모델에게 돌아가는 실행 결과입니다
    evidences: list[Evidence]
    reply: str | None = None  # 유저에게 그대로 보낼 대답입니다


class Tool[P: BaseModel](Protocol):
    """모델이 호출할 Tool 입니다."""

    name: str  # Tool 이름
    description: str  # Tool을 언제 사용해야 하는지 기술합니다
    parameters: type[P]  # run을 실행하기 위해 채워야 할 인자 정보입니다

    def run(self, params: P) -> ToolResult: ...


class ToolError(Exception):
    """Tool 실행 오류입니다."""
