from datetime import date

from pydantic import BaseModel
from sqlalchemy import Column, Engine, bindparam, func, text, update
from sqlmodel import Field, Session, col, select
from sqlmodel.sql.expression import SelectOfScalar

from blue_chatbot.repositories.base import BaseRepository, BaseSQLModel, Vector


class RequestVector(BaseSQLModel):
    """추상 모델, 실제 테이블이 아님, RequestVector 구현체들은 모델에 따라서 각자 다른 테이블을 만들어야 함"""

    request_id: str = Field(
        max_length=32,
        unique=True,
        sa_column_kwargs={"comment": "slack_db.requests의 id 식별자"},
    )
    source_updated: int = Field(
        index=True,
        sa_column_kwargs={"comment": "임베딩할 때 원본 requests.updated 값. 색인 위치로도 쓴다"},
    )
    status: str = Field(max_length=32)
    team: str = Field(max_length=32)
    archived: bool
    embedding: list[float]


class RequestVectorOpenAI3Small(RequestVector, table=True):
    __tablename__ = "request_vectors_openai_3_small"

    embedding: list[float] = Field(sa_column=Column(Vector(1536), nullable=False))


REQUEST_VECTOR_TABLES: dict[str, type[RequestVector]] = {
    "openai-3-small": RequestVectorOpenAI3Small,
}


class RequestRead(BaseModel):
    id: str
    title: str
    body: str | None
    status: str
    team: str
    board: str
    archived: bool
    date: date | None
    updated: int


class RequestVectorRepository(BaseRepository[RequestVector]):
    def search_top_k[T: RequestVector](
        self,
        model_type: type[T],
        query: list[float],
        k: int,
        *,
        status: str | None = None,
        team: str | None = None,
        archived: bool | None = None,
    ) -> list[T]:
        statement = self._build_search_statement(
            model_type, query, k, status=status, team=team, archived=archived
        )
        with Session(self._engine) as session:
            return list(session.exec(statement).all())

    def find_watermark[T: RequestVector](self, model_type: type[T]) -> int:
        """임베딩한 원본 갱신 시각 중 가장 큰 값."""
        with Session(self._engine) as session:
            return session.exec(select(func.max(model_type.source_updated))).one() or 0

    def find_by_request_ids[T: RequestVector](
        self, model_type: type[T], ids: list[str]
    ) -> dict[str, T]:
        """이미 색인된 것을 request_id로 찾는다. 있으면 벡터만 갈아끼워 다시 저장한다."""
        if not ids:
            return {}
        with Session(self._engine) as session:
            found = session.exec(select(model_type).where(model_type.request_id.in_(ids))).all()  # type: ignore[attr-defined]
        return {entity.request_id: entity for entity in found}

    def find_metadata[T: RequestVector](
        self, model_type: type[T]
    ) -> dict[str, tuple[str, str, bool]]:
        with Session(self._engine) as session:
            rows = session.exec(
                select(
                    model_type.request_id, model_type.status, model_type.team, model_type.archived
                )
            ).all()
        return {request_id: (status, team, archived) for request_id, status, team, archived in rows}

    def update_metadata[T: RequestVector](
        self, model_type: type[T], request_id: str, status: str, team: str, archived: bool
    ) -> None:
        with Session(self._engine) as session:
            session.execute(
                update(model_type)
                .where(col(model_type.request_id) == request_id)
                .values(status=status, team=team, archived=archived)
            )
            session.commit()

    def _build_search_statement[T: RequestVector](
        self,
        model_type: type[T],
        query: list[float],
        k: int,
        *,
        status: str | None = None,
        team: str | None = None,
        archived: bool | None = None,
    ) -> SelectOfScalar[T]:
        """질문 벡터와 코사인 거리가 가까운 순으로 k건을 검색합니다"""
        distance = func.VEC_DISTANCE_COSINE(
            model_type.embedding, bindparam("query", query, type_=Vector(len(query)))
        )
        statement = select(model_type).order_by(distance).limit(k)
        if status is not None:
            statement = statement.where(model_type.status == status)
        if team is not None:
            statement = statement.where(model_type.team == team)
        if archived is not None:
            statement = statement.where(model_type.archived == archived)
        return statement


class RequestRepository:
    """slack_db의 요청을 읽기만 합니다."""

    COLUMS = ", ".join(RequestRead.model_fields)

    def __init__(self, engine: Engine) -> None:
        self._engine = engine

    def find_by_ids(self, ids: list[str]) -> list[RequestRead]:
        if not ids:
            return []
        query = text(
            f"SELECT {RequestRepository.COLUMS} FROM requests WHERE id IN :ids"
        ).bindparams(bindparam("ids", expanding=True))

        with self._engine.connect() as connection:
            rows = connection.execute(query, {"ids": ids}).mappings().all()
        found = {row["id"]: RequestRead.model_validate(dict(row)) for row in rows}
        return [found[id] for id in ids if id in found]

    def find_changed_since(self, watermark: int) -> list[RequestRead]:
        """워터마크 이후에 갱신된 요청을 찾음"""
        query = text(
            f"SELECT {RequestRepository.COLUMS} FROM requests"
            " WHERE updated >= :watermark ORDER BY updated, id"
        )
        with self._engine.connect() as connection:
            rows = connection.execute(query, {"watermark": watermark}).mappings().all()
        return [RequestRead.model_validate(dict(row)) for row in rows]
