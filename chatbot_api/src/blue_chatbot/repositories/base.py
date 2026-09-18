from datetime import datetime
from typing import Generic, TypeVar

from sqlalchemy import Engine
from sqlmodel import Field, Session, SQLModel


class BaseSQLModel(SQLModel):
    id: int | None = Field(default=None, primary_key=True)
    created_at: datetime

    def persisted_id(self) -> int:
        """저장된 행의 id. 저장 전이면 ValueError."""
        if self.id is None:
            raise ValueError(f"저장되지 않은 {type(self).__name__}입니다")
        return self.id


T = TypeVar("T", bound=BaseSQLModel)


class BaseRepository(Generic[T]):
    def __init__(self, engine: Engine) -> None:
        self._engine = engine

    def save(self, entity: T) -> None:
        """새 객체는 INSERT, 이미 저장된 객체는 변경분을 UPDATE한다."""
        with Session(self._engine, expire_on_commit=False) as session:
            session.add(entity)
            session.commit()
