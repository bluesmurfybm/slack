import sqlite3

from sqlmodel import Session, func, select

from conftest import make_settings
from core.db import (
    POLICY_ID,
    CategoryOption,
    LearningRequest,
    LearningSite,
    RefundPolicy,
    init_db,
)

LEGACY_SCHEMA = """
CREATE TABLE learning_requests(
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    site            TEXT,
    category_large  TEXT,
    category_medium TEXT,
    level           TEXT,
    title           TEXT NOT NULL,
    url             TEXT,
    account_type    TEXT,
    applicant       TEXT,
    applicant_email TEXT,
    duration_min    INTEGER,
    price           INTEGER,
    start_date      TEXT,
    end_date        TEXT,
    created_by      TEXT,
    created_at      TEXT
)
"""


def _legacy_db(settings, *titles):
    conn = sqlite3.connect(settings.db_path)
    conn.executescript(LEGACY_SCHEMA)
    conn.executemany("INSERT INTO learning_requests(title) VALUES(?)",
                     [(t,) for t in titles])
    conn.commit()
    conn.close()


def test_migration_adds_columns_to_an_existing_db(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 신청")

    engine = init_db(settings)
    with Session(engine) as session:
        req = session.exec(select(LearningRequest)).one()
    assert req.title == "옛 신청"
    assert req.reject_reason is None # 판정에 안 쓰는 텍스트는 NULL 로 붙어도 된다


def test_migration_backfills_defaults_of_existing_rows(tmp_path):
    # 새 컬럼이 NULL 로 붙으면 기존 신청이 전부 숨김 처리되거나 진행상태가 비어 버린다.
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 신청")

    engine = init_db(settings)
    with Session(engine) as session:
        req = session.exec(select(LearningRequest)).one()
    assert req.active == 1
    assert req.archived == 0
    assert req.is_free == 0
    assert req.refund_cap_at_request == 0
    assert req.refund_amount == 0
    assert req.progress == "시작전"
    # 상태 판정이 문자열 비교를 하므로 NULL 이 남으면 안 된다
    assert req.approved_at == ""
    assert req.claimed_at == ""
    assert req.claim_rejected_at == ""
    assert req.refunded_at == ""
    assert req.progress_at == ""


def test_migration_is_repeatable(tmp_path):
    settings = make_settings(tmp_path)
    _legacy_db(settings, "옛 신청")

    init_db(settings)
    engine = init_db(settings)
    with Session(engine) as session:
        assert len(session.exec(select(LearningRequest)).all()) == 1


def test_sites_are_seeded_once(tmp_path):
    settings = make_settings(tmp_path)
    init_db(settings)
    engine = init_db(settings)
    with Session(engine) as session:
        names = [s.name for s in session.exec(
            select(LearningSite).order_by(LearningSite.sort_order)).all()]
    assert names == ["인프런", "패스트캠퍼스"]


def test_categories_are_seeded_with_large_and_medium_rows(tmp_path):
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        larges = session.exec(select(CategoryOption).where(
            CategoryOption.large == "개발 · 프로그래밍",
            CategoryOption.medium == "")).all()
        mediums = session.exec(select(CategoryOption).where(
            CategoryOption.large == "개발 · 프로그래밍",
            CategoryOption.medium != "")).all()
    assert len(larges) == 1
    assert "웹 퍼블리싱" in [m.medium for m in mediums]


def test_the_middle_dot_notation_is_kept_in_seed(tmp_path):
    # 사이트 표기는 일반 가운뎃점(U+00B7) + 양쪽 공백이다. 반각(U+FF65)이나 공백 없는 형태로
    # 바뀌면 시드 값과 화면에서 고른 값이 서로 다른 문자열이 된다.
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        row = session.exec(select(CategoryOption).where(
            CategoryOption.medium == "알고리즘 · 자료구조")).one()
    assert row.large == "개발 · 프로그래밍"


def test_both_sites_are_seeded(tmp_path):
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        rows = session.exec(select(CategoryOption)).all()
    counts = {s: (len([r for r in rows if r.site == s and not r.medium]),
                  len([r for r in rows if r.site == s and r.medium]))
              for s in ("인프런", "패스트캠퍼스")}
    assert counts == {"인프런": (13, 83), "패스트캠퍼스": (9, 37)}


def test_seeded_ids_start_at_one(tmp_path):
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        ids = [r.id for r in session.exec(
            select(CategoryOption).order_by(CategoryOption.id)).all()]
    assert ids == list(range(1, len(ids) + 1))


def test_categories_are_not_reseeded(tmp_path):
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        first = session.exec(select(func.count()).select_from(CategoryOption)).one()
    engine = init_db(settings)
    with Session(engine) as session:
        assert session.exec(select(func.count()).select_from(CategoryOption)).one() == first


def test_policy_row_is_created_with_everything_off(tmp_path):
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        policy = session.get(RefundPolicy, POLICY_ID)
    assert policy is not None
    assert policy.partial_enabled == 0
    assert policy.annual_amount_enabled == 0
    assert policy.annual_count_enabled == 0
    assert policy.claim_deadline_enabled == 0


def test_policy_edits_survive_restart(tmp_path):
    settings = make_settings(tmp_path)
    engine = init_db(settings)
    with Session(engine) as session:
        policy = session.get(RefundPolicy, POLICY_ID)
        policy.partial_enabled = 1
        policy.partial_cap = 500000
        session.add(policy)
        session.commit()

    engine = init_db(settings)
    with Session(engine) as session:
        policy = session.get(RefundPolicy, POLICY_ID)
    assert policy.partial_enabled == 1
    assert policy.partial_cap == 500000
