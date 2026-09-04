"""분류를 DEFAULT_CATEGORIES 로 갈아엎는다. id 는 1 부터 다시 매겨진다.

    python tools/reset_categories.py --yes

관리자 화면에서 손으로 고친 분류가 전부 사라지므로, 시드를 통째로 교체할 때만 쓴다.
지난 신청 건은 분류를 이름 문자열로 들고 있어 이 작업의 영향을 받지 않는다 —
다만 이름이 바뀐 분류를 쓰던 건은 드롭다운에 없는 값을 들고 있게 된다.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sqlalchemy import text
from sqlmodel import Session, func, select

from core.config import Settings
from core.db import CategoryOption, LearningRequest, _seed_categories, init_db


def main() -> int:
    if "--yes" not in sys.argv:
        print(__doc__)
        return 1

    settings = Settings()
    print(f"DB: {settings.db_path}")
    engine = init_db(settings)

    with Session(engine) as session:
        before = session.exec(select(func.count()).select_from(CategoryOption)).one()
        used = session.exec(select(LearningRequest.category_large,
                                   LearningRequest.category_medium)
                            .where(LearningRequest.category_large != "")).all()

    with engine.begin() as conn:
        conn.execute(text("DELETE FROM learning_categories"))
        # AUTOINCREMENT 를 쓰지 않으므로 보통 sqlite_sequence 자체가 없다. 있으면 지워야
        # 다음 id 가 1 부터 시작한다(없으면 빈 테이블이라 어차피 1 부터다).
        if conn.execute(text("SELECT 1 FROM sqlite_master "
                             "WHERE type='table' AND name='sqlite_sequence'")).first():
            conn.execute(text("DELETE FROM sqlite_sequence WHERE name='learning_categories'"))

    _seed_categories(engine)

    with Session(engine) as session:
        rows = session.exec(select(CategoryOption).order_by(CategoryOption.id)).all()
    print(f"{before}건 삭제 → {len(rows)}건 적재 (id {rows[0].id} ~ {rows[-1].id})")
    for site in dict.fromkeys(r.site for r in rows):
        larges = [r for r in rows if r.site == site and not r.medium]
        mediums = [r for r in rows if r.site == site and r.medium]
        print(f"  {site}: 대분류 {len(larges)} · 중분류 {len(mediums)}")

    names = {(r.large, r.medium) for r in rows}
    orphans = {(a, b) for a, b in used if (a, b) not in names}
    if orphans:
        print("\n주의 — 새 분류에 없는 이름을 쓰는 신청 건이 있습니다:")
        for a, b in sorted(orphans):
            print(f"  {a} > {b}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
