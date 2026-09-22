"""유지보수 요청을 검색하는 찾는 도구"""

from typing import Literal

from pydantic import BaseModel, Field

from blue_chatbot.repositories.workhub import (
    RequestRead,
    RequestRepository,
    RequestVector,
    RequestVectorRepository,
)
from blue_chatbot.services.embedder import EmbedderClient
from blue_chatbot.tools.base import Evidence, ToolResult

SOURCE = "requests"
BODY_LIMIT = 500
MAX_LIMIT = 10

Status = Literal[
    "등록",
    "시작 전",
    "공수산정요청",
    "진행중",
    "보류",
    "확인요청(개발서버반영)",
    "확인요청(검토완료)",
    "확인요청(운영서버반영)",
    "운영배포요청",
    "재확인필요",
    "처리불가",
    "완료",
]
Team = Literal["블루소프트", "시스템개발", "달빛소프트", "미지정", "W > B", "B > W"]


class SearchRequestsParams(BaseModel):
    query: str = Field(description="검색 쿼리")
    status: Status | None = Field(default=None, description="요청 상태. 정해진 값 중 하나여야 한다")
    team: Team | None = Field(default=None, description="개발담당팀 필터링, 없으면 전체 검색")
    include_archived: bool = Field(default=False, description="보관된 요청 검색 포함 여부")
    limit: int = Field(
        default=5, le=MAX_LIMIT, description=f"응답할 건수, 최대 {MAX_LIMIT}건을 응답합니다"
    )


class SearchRequestsTool:
    name = "search_requests"
    description = "유지보수 요청 이력을 검색합니다"
    parameters = SearchRequestsParams

    def __init__(
        self,
        vectors: RequestVectorRepository,
        requests: RequestRepository,
        embedder: EmbedderClient,
        model_type: type[RequestVector],
    ) -> None:
        self._vectors = vectors
        self._requests = requests
        self._embedder = embedder
        self._model_type = model_type

    def run(self, params: SearchRequestsParams) -> ToolResult:
        query = self._embedder.embed([params.query])[0]
        found = self._vectors.search_top_k(
            self._model_type,
            query,
            params.limit,
            status=params.status,
            team=params.team,
            archived=None if params.include_archived else False,
        )
        rows = self._requests.find_by_ids([vector.request_id for vector in found])
        evidences = [_to_evidence(row) for row in rows]
        return ToolResult(content=_format(evidences), evidences=evidences)


def _to_evidence(row: RequestRead) -> Evidence:
    body = (row.body or "")[:BODY_LIMIT]
    return Evidence(source=SOURCE, id=row.id, title=row.title, content=body)


def _format(evidence: list[Evidence]) -> str:
    if not evidence:
        return "비슷한 요청을 찾지 못했습니다."
    return "\n\n".join(f"[{item.id}] {item.title}\n{item.content}" for item in evidence)
