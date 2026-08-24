import sqlite3

from sqlmodel import Session, select

from conftest import make_settings
from core.db import Presentation, PresentationEmotion, Topic, init_db
from tests.test_db import LEGACY_SCHEMA


def _legacy_with_presentation(settings):
    conn = sqlite3.connect(settings.db_path)
    conn.executescript(LEGACY_SCHEMA)
    conn.execute(
        "INSERT INTO topics(title, presenter, presenter_email, planned_date, done_date)"
        " VALUES('발표된 주제', '유승인', 'siyu@bluesoft.co.kr', '2026-01-10', '2026-01-10')")
    conn.execute("INSERT INTO topics(title) VALUES('빈 주제')")
    conn.execute("INSERT INTO topics(title, planned_date) VALUES('날짜만', '2026-02-01')")
    conn.commit()
    conn.close()


def test_backfill_creates_presentations(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_with_presentation(settings)
    engine = init_db(settings)
    with Session(engine) as session:
        rows = session.exec(select(Presentation)).all()
    assert len(rows) == 2 # 발표된 주제 + 날짜만. 빈 주제는 만들지 않는다
    done = next(r for r in rows if r.presenter_email)
    assert done.presenter == "유승인"
    assert done.done_date == "2026-01-10"


def test_backfill_copies_emotions(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_with_presentation(settings)
    conn = sqlite3.connect(settings.db_path)
    conn.executescript(
        "CREATE TABLE topic_emotions(topic_id INTEGER, email TEXT, kind TEXT,"
        " created_at TEXT, PRIMARY KEY(topic_id, email, kind));"
        "INSERT INTO topic_emotions VALUES(1, 'hjlee@bluesoft.co.kr', 'like', '');")
    conn.commit()
    conn.close()
    engine = init_db(settings)
    with Session(engine) as session:
        emo = session.exec(select(PresentationEmotion)).one()
        pres = session.get(Presentation, emo.presentation_id)
    assert emo.kind == "like"
    assert pres.topic_id == 1


def test_backfill_is_idempotent(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_with_presentation(settings)
    init_db(settings)
    engine = init_db(settings)
    with Session(engine) as session:
        assert len(session.exec(select(Presentation)).all()) == 2


def test_seeded_db_gets_presentations(tmp_path):
    engine = init_db(make_settings(tmp_path))
    with Session(engine) as session:
        done = session.exec(select(Presentation)
                            .where(Presentation.done_date != "")).all()
        topics = session.exec(select(Topic)).all()
    assert len(topics) == 31
    assert len(done) == 16 # 시드의 발표완료 행 수
