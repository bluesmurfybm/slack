import sqlite3

from sqlmodel import Session, select

from conftest import make_settings
from core.db import Topic, TopicEmotion, init_db

LEGACY_SCHEMA = """
CREATE TABLE topics(
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    field           TEXT,
    title           TEXT NOT NULL,
    keywords        TEXT,
    magazine        TEXT,
    volume          TEXT,
    page            TEXT,
    year            INTEGER,
    requirement     TEXT DEFAULT 'recommended',
    team            TEXT,
    presenter       TEXT,
    presenter_email TEXT,
    planned_date    TEXT,
    done_date       TEXT,
    note            TEXT,
    created_by      TEXT,
    created_at      TEXT
)
"""


def _legacy_db(settings, *titles):
    conn = sqlite3.connect(settings.db_path)
    conn.executescript(LEGACY_SCHEMA)
    conn.executemany("INSERT INTO topics(title) VALUES(?)", [(t,) for t in titles])
    conn.commit()
    conn.close()


def test_migration_adds_columns_to_an_existing_db(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 주제")

    engine = init_db(settings)
    with Session(engine) as session:
        topic = session.exec(select(Topic)).one() # 시드는 다시 적재되지 않는다
    assert topic.title == "옛 주제"
    assert topic.material_kind is None


def test_migration_backfills_visibility_of_existing_rows(tmp_path):
    # 새 컬럼이 NULL 로 붙으면 기존 주제가 전부 숨김 처리돼 버린다.
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 주제")

    engine = init_db(settings)
    with Session(engine) as session:
        topic = session.exec(select(Topic)).one()
    assert topic.active == 1
    assert topic.archived == 0


def test_emotions_table_is_added_to_an_existing_db(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 주제")

    engine = init_db(settings)
    with Session(engine) as session:
        tid = session.exec(select(Topic)).one().id
        session.add(TopicEmotion(topic_id=tid, email="siyu@bluesoft.co.kr",
                                 kind="like"))
        session.commit()
        assert session.exec(select(TopicEmotion)).one().topic_id == tid


def test_migration_is_repeatable(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 주제")

    init_db(settings)
    engine = init_db(settings)
    with Session(engine) as session:
        assert len(session.exec(select(Topic)).all()) == 1
