# -*- coding: utf-8 -*-
"""주제 도메인 규칙: 상태 파생과 권한 판정.

상태(미지정/발표예정/발표완료)는 컬럼으로 저장하지 않고 파생한다.
원본 xlsx 에 '발표자와 예정일이 있는데 비고는 미지정' 같은 어긋난 행이
실제로 있어서, 저장하면 계속 어긋나기 때문이다.
"""
from fastapi import HTTPException

from core.config import Settings
from features.identity.auth import is_admin

STATUS_OPEN = "미지정"
STATUS_PLANNED = "발표예정"
STATUS_DONE = "발표완료"


def derive_status(row) -> str:
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
    """없으면 커넥션을 닫고 404."""
    row = conn.execute("SELECT * FROM topics WHERE id=?", (tid,)).fetchone()
    if not row:
        conn.close()
        raise HTTPException(status_code=404, detail="없는 주제입니다")
    return row


def may_manage_claim(settings: Settings, row, identity: dict) -> bool:
    """선점자 본인이거나 관리자."""
    return (row["presenter_email"] == identity["email"]
            or is_admin(settings, identity["email"]))
