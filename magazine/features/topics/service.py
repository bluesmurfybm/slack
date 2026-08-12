from fastapi import HTTPException

from core.config import Settings
from features.identity.auth import is_admin

STATUS_OPEN = "미지정"
STATUS_PLANNED = "발표예정"
STATUS_DONE = "발표완료"


def derive_status(row) -> str:
    # 컬럼으로 저장하지 않는다 — 원본 xlsx 에 상태와 값이 어긋난 행이 있었다.
    if row["done_date"]:
        return STATUS_DONE
    if row["presenter_email"]:
        return STATUS_PLANNED
    return STATUS_OPEN


def to_dict(row) -> dict:
    d = dict(row)
    d["status"] = derive_status(row)
    return d


def fetch(conn, tid):
    row = conn.execute("SELECT * FROM topics WHERE id=?", (tid,)).fetchone()
    if not row:
        conn.close()
        raise HTTPException(status_code=404, detail="없는 주제입니다")
    return row


def may_manage_claim(settings: Settings, row, identity: dict) -> bool:
    return (row["presenter_email"] == identity["email"]
            or is_admin(settings, identity["email"]))
