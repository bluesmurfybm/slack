# -*- coding: utf-8 -*-
"""저장소: 연결, 스키마, 시드 적재.

SQL 은 여기와 topics.py 에만 둔다.
"""
import json
import os
import sqlite3

from config import Settings

# seed.json 의 키이자 topics 컬럼. 순서가 INSERT 와 맞아야 한다.
SEED_FIELDS = (
    "field", "title", "keywords", "magazine", "volume", "page", "year",
    "requirement", "team", "presenter", "presenter_email",
    "planned_date", "done_date", "note",
)

SCHEMA = """
CREATE TABLE IF NOT EXISTS topics(
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


def connect(settings: Settings) -> sqlite3.Connection:
    conn = sqlite3.connect(settings.db_path)
    conn.row_factory = sqlite3.Row
    return conn


# 나중에 추가된 컬럼. 이미 돌고 있는 DB 도 있으므로 없을 때만 붙인다.
ADDED_COLUMNS = (
    ("material_kind", "TEXT"),   # '' | 'link' | 'file'
    ("material_name", "TEXT"),   # 표시 이름 / 원본 파일명
    ("material_url", "TEXT"),    # link 인 경우 외부 URL
    ("material_path", "TEXT"),   # file 인 경우 저장된 파일명
)


def _migrate(conn) -> None:
    have = {r[1] for r in conn.execute("PRAGMA table_info(topics)").fetchall()}
    for name, decl in ADDED_COLUMNS:
        if name not in have:
            conn.execute(f"ALTER TABLE topics ADD COLUMN {name} {decl}")
    conn.commit()


def init_db(settings: Settings) -> None:
    """스키마를 만들고, 비어 있으면 seed.json 을 넣는다."""
    os.makedirs(settings.upload_dir, exist_ok=True)
    conn = connect(settings)
    conn.execute(SCHEMA)
    conn.commit()
    _migrate(conn)

    empty = conn.execute("SELECT COUNT(*) FROM topics").fetchone()[0] == 0
    if empty and os.path.exists(settings.seed_path):
        with open(settings.seed_path, "r", encoding="utf-8") as f:
            rows = json.load(f)
        cols = ",".join(SEED_FIELDS)
        marks = ",".join("?" * len(SEED_FIELDS))
        conn.executemany(
            f"INSERT INTO topics({cols}) VALUES({marks})",
            [tuple(r.get(k) for k in SEED_FIELDS) for r in rows])
        conn.commit()
        print(f"[seed] {len(rows)}건 초기 데이터를 적재했습니다.")
    conn.close()
