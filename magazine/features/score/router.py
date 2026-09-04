from fastapi import APIRouter, Depends, Query
from sqlmodel import Session

from core.db import get_session
from features.identity.auth import require_admin
from features.score.service import summary

router = APIRouter(prefix="/magazineapi/score", tags=["score"])

DATE = r"^(\d{4}-\d{2}-\d{2})?$"


@router.get("", dependencies=[Depends(require_admin)])
def scores(session: Session = Depends(get_session),
           start: str = Query("", pattern=DATE), end: str = Query("", pattern=DATE)):
    return summary(session, start, end)
