import json
import logging
from collections.abc import Iterator
from pathlib import Path

from fastapi import Request
from sqlalchemy import Engine, inspect
from sqlmodel import Field, Session, SQLModel, create_engine, func, select

from core.config import Settings

logger = logging.getLogger(__name__)


class Topic(SQLModel, table=True):
    __tablename__ = "topics"

    id: int | None = Field(default=None, primary_key=True)
    title: str
    field: str = ""
    keywords: str = ""
    magazine: str = ""
    volume: str = ""
    page: str = ""
    year: int | None = None
    requirement: str = "recommended" # required | recommended | normal
    team: str = ""
    presenter: str = ""
    presenter_email: str = ""
    planned_date: str = ""
    done_date: str = ""
    note: str = ""

    # server_default 를 주는 이유: 운영 DB 에는 ALTER 로 붙는 컬럼이라
    # 기존 행까지 이 값으로 채워져야 한다. 파이썬 기본값만으로는 NULL 이 남는다.
    active: int = Field(default=1, sa_column_kwargs={"server_default": "1"})
    archived: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    # 자료 칸은 슬롯당 하나. 없음을 NULL 로 두는 건 화면·테스트가 기대하는 계약이다.
    material_kind: str | None = None # link | file
    material_name: str | None = None
    material_url: str | None = None
    material_path: str | None = None

    scan_kind: str | None = None # link | file
    scan_name: str | None = None
    scan_url: str | None = None
    scan_path: str | None = None

    created_by: str = ""
    created_at: str = ""


class TopicEmotion(SQLModel, table=True):
    __tablename__ = "topic_emotions"

    topic_id: int = Field(foreign_key="topics.id", primary_key=True)
    email: str = Field(primary_key=True)
    kind: str = Field(primary_key=True) # features/emotion/service.py 의 Emotion
    created_at: str = ""


class FieldOption(SQLModel, table=True):
    __tablename__ = "fields"

    id: int | None = Field(default=None, primary_key=True)
    name: str = Field(unique=True)


# 관리자가 화면에서 추가하기 전까지의 초기 선택지
DEFAULT_FIELDS = ["UI/UX", "Marketing", "Trend", "AX", "Etc"]


COLUMNS = frozenset(Topic.__table__.columns.keys())


def init_db(settings: Settings) -> Engine:
    Path(settings.upload_dir).mkdir(parents=True, exist_ok=True)
    Path(settings.db_path).resolve().parent.mkdir(parents=True, exist_ok=True)
    engine = create_engine(f"sqlite:///{settings.db_path}",
                           connect_args={"check_same_thread": False})
    SQLModel.metadata.create_all(engine)
    _add_missing_columns(engine)
    _seed(engine, settings.seed_path)
    _seed_fields(engine)
    return engine


def get_session(request: Request) -> Iterator[Session]:
    with Session(request.app.state.engine) as session:
        yield session


def _add_missing_columns(engine: Engine) -> None:
    # create_all 은 이미 있는 테이블에 컬럼을 붙이지 않는다. 먼저 만들어진 DB 는 이 경로로 온다.
    have = {c["name"] for c in inspect(engine).get_columns(Topic.__tablename__)}
    missing = [c for c in Topic.__table__.columns if c.name not in have]
    if not missing:
        return
    with engine.begin() as conn:
        for col in missing:
            decl = col.type.compile(engine.dialect)
            if col.server_default is not None:
                decl += f" DEFAULT {col.server_default.arg}"
            conn.exec_driver_sql(
                f"ALTER TABLE {Topic.__tablename__} ADD COLUMN {col.name} {decl}")


def _seed_fields(engine: Engine) -> None:
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(FieldOption)).one():
            return
        session.add_all([FieldOption(name=n) for n in DEFAULT_FIELDS])
        session.commit()


def _seed(engine: Engine, seed_path: str) -> None:
    if not Path(seed_path).exists():
        return
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(Topic)).one():
            return
        with Path(seed_path).open(encoding="utf-8") as f:
            rows = json.load(f)
        session.add_all([Topic(**{k: v for k, v in r.items()
                                  if k in COLUMNS and v is not None}) for r in rows])
        session.commit()
        logger.info("[seed] %d건 초기 데이터를 적재했습니다.", len(rows))
