import json
import os
from typing import Iterator, Optional

from fastapi import Request
from sqlalchemy import Engine, inspect
from sqlmodel import Field, Session, SQLModel, create_engine, func, select

from core.config import Settings


class Topic(SQLModel, table=True):
    __tablename__ = "topics"

    id: Optional[int] = Field(default=None, primary_key=True)
    title: str
    field: str = ""
    keywords: str = ""
    magazine: str = ""
    volume: str = ""
    page: str = ""
    year: Optional[int] = None
    requirement: str = "recommended"       # required | recommended
    team: str = ""
    presenter: str = ""
    presenter_email: str = ""
    planned_date: str = ""
    done_date: str = ""
    note: str = ""

    # 자료는 주제당 하나. 없음을 NULL 로 두는 건 화면·테스트가 기대하는 계약이다.
    material_kind: Optional[str] = None    # link | file
    material_name: Optional[str] = None
    material_url: Optional[str] = None
    material_path: Optional[str] = None

    created_by: str = ""
    created_at: str = ""


COLUMNS = frozenset(Topic.__table__.columns.keys())


def init_db(settings: Settings) -> Engine:
    os.makedirs(settings.upload_dir, exist_ok=True)
    os.makedirs(os.path.dirname(os.path.abspath(settings.db_path)), exist_ok=True)
    engine = create_engine(f"sqlite:///{settings.db_path}",
                           connect_args={"check_same_thread": False})
    SQLModel.metadata.create_all(engine)
    _add_missing_columns(engine)
    _seed(engine, settings.seed_path)
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


def _seed(engine: Engine, seed_path: str) -> None:
    if not os.path.exists(seed_path):
        return
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(Topic)).one():
            return
        with open(seed_path, "r", encoding="utf-8") as f:
            rows = json.load(f)
        session.add_all([Topic(**{k: v for k, v in r.items()
                                  if k in COLUMNS and v is not None}) for r in rows])
        session.commit()
        print(f"[seed] {len(rows)}건 초기 데이터를 적재했습니다.")
