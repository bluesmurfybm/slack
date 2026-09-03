import sqlite3

from sqlmodel import Session, create_engine, select

from conftest import ADMIN, USER, login, make_settings
from core.db import Presentation, PresentationEmotion, Topic, init_db
from migrations.backfill_presentations import run
from tests.test_db import LEGACY_SCHEMA

NEW = {"title": "분리 검증", "field": "AX"}


def _tid(client, settings):
    login(client, settings, ADMIN)
    return client.post("/magazineapi/topics", json=NEW).json()["id"]


def test_claim_creates_presentation_row(client, settings):
    tid = _tid(client, settings)
    login(client, settings, USER, "유승인")
    body = client.post(f"/magazineapi/topics/{tid}/claim",
                       json={"planned_date": "2026-09-01"}).json()
    assert body["presenter_email"] == USER
    assert body["planned_date"] == "2026-09-01"
    assert body["status"] == "발표예정"


def test_release_removes_presentation_row(client, settings):
    tid = _tid(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    client.post(f"/magazineapi/topics/{tid}/release")
    rows = client.get("/magazineapi/topics").json()
    assert next(r for r in rows if r["id"] == tid)["status"] == "미지정"


def test_unassign_after_complete_keeps_done_date(client, settings):
    tid = _tid(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, ADMIN)
    client.post(f"/magazineapi/topics/{tid}/complete", json={"done_date": "2026-09-01"})
    body = client.post(f"/magazineapi/topics/{tid}/assign", json={"email": ""}).json()
    assert body["status"] == "발표완료"
    assert body["presenter_email"] in ("", None)


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
    run(settings.db_path)
    engine = create_engine(f"sqlite:///{settings.db_path}")
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
    run(settings.db_path)
    engine = create_engine(f"sqlite:///{settings.db_path}")
    with Session(engine) as session:
        emo = session.exec(select(PresentationEmotion)).one()
        pres = session.get(Presentation, emo.presentation_id)
    assert emo.kind == "like"
    assert pres.topic_id == 1


def test_backfill_is_idempotent(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_with_presentation(settings)
    run(settings.db_path)
    run(settings.db_path)
    engine = create_engine(f"sqlite:///{settings.db_path}")
    with Session(engine) as session:
        assert len(session.exec(select(Presentation)).all()) == 2


def test_backfill_adds_missing_columns_to_legacy_topics(tmp_path):
    settings = make_settings(tmp_path)
    conn = sqlite3.connect(settings.db_path)
    conn.executescript(LEGACY_SCHEMA) # material_* 컬럼이 없는 스키마
    conn.commit()
    conn.close()
    run(settings.db_path)
    conn = sqlite3.connect(settings.db_path)
    conn.execute("SELECT material_kind FROM topics").fetchall()
    conn.close()


def test_seeded_db_gets_presentations(tmp_path):
    engine = init_db(make_settings(tmp_path))
    with Session(engine) as session:
        done = session.exec(select(Presentation)
                            .where(Presentation.done_date != "")).all()
        topics = session.exec(select(Topic)).all()
    assert len(topics) == 31
    assert len(done) == 16 # 시드의 발표완료 행 수
