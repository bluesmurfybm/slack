import sys
from pathlib import Path

from sqlalchemy import Engine, inspect
from sqlmodel import Session, SQLModel, create_engine, func, select

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from core.db import Presentation, PresentationEmotion

PRESENTATION_COLUMNS = ("presenter", "presenter_email", "planned_date", "done_date",
                        "material_kind", "material_name", "material_url", "material_path")


def run(db_path: str) -> None:
    engine = create_engine(f"sqlite:///{db_path}", connect_args={"check_same_thread": False})
    SQLModel.metadata.create_all(engine) # 레거시 DB 는 새 테이블이 아직 없어 여기서 직접 만든다
    _migrate_presentations(engine)


def _migrate_presentations(engine: Engine) -> None:
    # 옛 컬럼은 Topic 모델에서 빠지므로 이 함수만 raw SELECT 로 읽는다 (마이그레이션 전용)
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(Presentation)).one():
            return
        have = {c["name"] for c in inspect(engine).get_columns("topics")}
        cols = [c for c in PRESENTATION_COLUMNS if c in have]
        if not cols:
            return
        rows = session.connection().exec_driver_sql(
            f"SELECT id, {', '.join(cols)} FROM topics").all() # noqa: S608 cols 는 화이트리스트에서만 온다
        by_topic = {}
        for row in rows:
            values = {c: row[i + 1] for i, c in enumerate(cols)}
            if not any(values.get(c) for c in
                       ("presenter_email", "planned_date", "done_date", "material_kind")):
                continue
            fields = {c: values.get(c) or (None if c.startswith("material") else "")
                      for c in PRESENTATION_COLUMNS}
            pres = Presentation(topic_id=row[0], **fields)
            session.add(pres)
            by_topic[row[0]] = pres
        session.commit()

        if "topic_emotions" in inspect(engine).get_table_names():
            emotions = session.connection().exec_driver_sql(
                "SELECT topic_id, email, kind, created_at FROM topic_emotions").all()
            session.add_all([
                PresentationEmotion(presentation_id=by_topic[tid].id, email=email,
                                    kind=kind, created_at=created or "")
                for tid, email, kind, created in emotions if tid in by_topic])
            session.commit()


if __name__ == "__main__":
    default_db = Path(__file__).resolve().parent.parent / "var" / "magazine.db"
    db_path = sys.argv[1] if len(sys.argv) > 1 else str(default_db)
    run(db_path)
    _engine = create_engine(f"sqlite:///{db_path}", connect_args={"check_same_thread": False})
    with Session(_engine) as _session:
        _count = _session.exec(select(func.count()).select_from(Presentation)).one()
    print(f"백필된 발표 행: {_count}건") # noqa: T201 배포 절차에서 사람이 읽는 CLI 출력이다
