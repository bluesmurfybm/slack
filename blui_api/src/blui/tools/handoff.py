"""봇이 다루지 않는 요청을 담당자에게 넘기는 도구입니다."""

from pydantic import BaseModel, Field

from common.tools.base import Tool, ToolResult

RESULT = "담당자만 처리할 수 있는 요청입니다. 되묻지 말고 고객에게 상담 요청 폼을 안내하세요."
REPLY = "상담 요청 {request_url}에 문의 사항을 제출해주시면 담당자가 연락드리겠습니다. 감사합니다."


class HandoffParams(BaseModel):
    request: str = Field(description="담당자에게 전달할 유저의 요청사항 요약")


class HandoffTool(Tool[HandoffParams]):
    name = "handoff"
    description = (
        "담당자가 이어받아야 하는 문의를 담당자에게 이관합니다. <handoff> 안의 항목과 같은 내용의"
        " 문의가 해당합니다."
    )
    parameters = HandoffParams

    def __init__(self, request_url: str) -> None:
        self._request_url = request_url
        self.requests: list[str] = []

    def run(self, params: HandoffParams) -> ToolResult:
        self.requests.append(params.request)
        return ToolResult(
            content=RESULT, evidences=[], reply=REPLY.format(request_url=self._request_url)
        )
