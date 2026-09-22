"""Tool 관련 예외 및 인터페이스"""

from dataclasses import dataclass
from typing import Protocol

from pydantic import BaseModel


class ToolError(Exception):
    """Tool 실행 오류"""


class Evidence(BaseModel):
    """모델이 인용할 수 있는 근거."""

    source: str  # 사용한 Tool
    id: str  # 식별값
    title: str
    content: str


@dataclass(frozen=True)
class ToolResult:
    content: str
    evidences: list[Evidence]


class Tool[P: BaseModel](Protocol):
    """모델이 호출할 Tool 입니다."""

    name: str  # Tool 이름
    description: str  # Tool 을 언제 사용해야 하는지 기술
    parameters: type[P]  # run을 실행하기 위해 채워야 할 인자 정보

    def run(self, params: P) -> ToolResult: ...
