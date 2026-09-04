from pathlib import Path

from fastapi import HTTPException
from sqlmodel import Session, select

from core.db import LearningCert

# HTML/SVG 는 같은 오리진에서 열리면 포털 세션을 노린 XSS 가 된다. 확장자 화이트리스트
# 밖은 아예 받지 않고, 받은 것도 storage.disposition 이 인라인 여부를 다시 판정한다.
ALLOWED_EXT = frozenset({".png", ".jpg", ".jpeg", ".gif", ".webp", ".bmp", ".pdf"})


def ensure_allowed(filename: str) -> None:
    ext = Path(filename or "").suffix.lower()
    if ext not in ALLOWED_EXT:
        raise HTTPException(
            status_code=422,
            detail="이미지(png, jpg, gif, webp, bmp)와 pdf 만 올릴 수 있습니다")


def listing(session: Session, rid: int) -> list[dict]:
    rows = session.exec(select(LearningCert)
                        .where(LearningCert.request_id == rid)
                        .order_by(LearningCert.id)).all()
    return [{"id": c.id, "name": c.name, "uploaded_by": c.uploaded_by,
             "created_at": c.created_at} for c in rows]


def fetch(session: Session, rid: int, cid: int) -> LearningCert:
    cert = session.get(LearningCert, cid)
    if not cert or cert.request_id != rid:
        raise HTTPException(status_code=404, detail="없는 이수증입니다")
    return cert
