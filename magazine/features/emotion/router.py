import time

from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session

from core.db import PresentationEmotion, get_session
from features.emotion.service import Emotion, count_for
from features.identity.auth import require_identity
from features.presentations import service as presentations
from features.topics.service import fetch

router = APIRouter(prefix="/magazineapi/topics/{tid}/emotions", tags=["emotions"])


@router.post("/{kind}")
def toggle(tid: int, kind: Emotion, session: Session = Depends(get_session),
           identity: dict = Depends(require_identity)):
    fetch(session, tid)
    pres = presentations.of_topic(session, tid)
    if pres is None or not pres.done_date:
        raise HTTPException(status_code=409,
                            detail="발표가 끝난 아티클에만 반응을 남길 수 있습니다")
    already = session.get(PresentationEmotion, (pres.id, identity["email"], kind.value))
    if already:
        session.delete(already)
    else:
        session.add(PresentationEmotion(presentation_id=pres.id, email=identity["email"],
                                        kind=kind.value,
                                        created_at=time.strftime("%Y-%m-%d %H:%M:%S")))
    session.commit()
    return {"kind": kind.value, "count": count_for(session, pres.id, kind.value),
            "mine": already is None}
