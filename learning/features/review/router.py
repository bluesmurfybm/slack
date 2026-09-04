from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field, field_validator
from sqlmodel import Session

from core.db import get_session
from features.identity.auth import require_identity
from features.requests import service

router = APIRouter(prefix="/learningapi/requests/{rid}/review", tags=["review"])

STEP = 0.5
MIN_SCORE = 0.5
MAX_SCORE = 5.0


def _score(v: float | None) -> float | None:
    if v is None:
        return None
    if not MIN_SCORE <= v <= MAX_SCORE:
        raise ValueError("별점은 0.5 에서 5.0 사이여야 합니다")
    # 0 은 "미입력"이지 0점이 아니다 — 0.5 단위가 아니면 화면에서 그릴 수 없다
    if round(v / STEP) * STEP != v:
        raise ValueError("별점은 0.5 단위로만 매길 수 있습니다")
    return v


class ReviewIn(BaseModel):
    rating: float | None = None
    recommend: float | None = None
    review_note: str = Field(default="", max_length=2000)

    @field_validator("rating", "recommend")
    @classmethod
    def _scores(cls, v):
        return _score(v)


@router.post("")
def save_review(rid: int, body: ReviewIn, session: Session = Depends(get_session),
                identity: dict = Depends(require_identity)):
    req = service.fetch(session, rid)
    service.require_owner(req, identity, "평가할")
    service.ensure_transition(req, service.REVIEW)

    patch = body.model_dump(exclude_unset=True)
    for name, value in patch.items():
        setattr(req, name, value)
    session.add(req)
    session.commit()
    session.refresh(req)
    return service.to_dict(req, service.cert_count(session, rid))
