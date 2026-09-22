import struct
from collections.abc import Callable
from datetime import datetime
from typing import Any

from sqlalchemy import Engine
from sqlalchemy.dialects.mysql.base import MySQLDialect
from sqlalchemy.engine.interfaces import Dialect
from sqlalchemy.types import UserDefinedType
from sqlmodel import Field, Session, SQLModel


class Vector(UserDefinedType[list[float]]):
    """MariaDB VECTOR(n) -> list[float] 매퍼"""

    cache_ok = True

    def __init__(self, dimension: int) -> None:
        self.dimension = dimension

    def get_col_spec(self, **kw: Any) -> str:
        return f"VECTOR({self.dimension})"

    def bind_processor(self, dialect: Dialect) -> Callable[[Any], Any]:
        return lambda value: None if value is None else struct.pack(f"<{len(value)}f", *value)

    def result_processor(self, dialect: Dialect, coltype: Any) -> Callable[[Any], Any]:
        return lambda value: (
            None if value is None else list(struct.unpack(f"<{self.dimension}f", value))
        )


# SQLAlchemy 2.0에는 MariaDB/MySQL 전용 Vector schema
MySQLDialect.ischema_names["vector"] = Vector


class BaseSQLModel(SQLModel):
    id: int | None = Field(default=None, primary_key=True)
    created_at: datetime

    def persisted_id(self) -> int:
        """저장된 행의 id. 저장 전이면 ValueError."""
        if self.id is None:
            raise ValueError(f"저장되지 않은 {type(self).__name__}입니다")
        return self.id


class BaseRepository[T: BaseSQLModel]:
    def __init__(self, engine: Engine) -> None:
        self._engine = engine

    def save(self, entity: T) -> None:
        """새 객체는 INSERT, 이미 저장된 객체는 변경분을 UPDATE한다."""
        with Session(self._engine, expire_on_commit=False) as session:
            session.add(entity)
            session.commit()
