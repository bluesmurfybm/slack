import time

from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session

from core.db import TopicEmotion, get_session
from features.emotion.service import Emotion, count_for
from features.identity.auth import require_identity
from features.topics.service import fetch

router = APIRouter(prefix="/magazineapi/topics/{tid}/emotions", tags=["emotions"])


@router.post("/{kind}")
def toggle(tid: int, kind: Emotion, session: Session = Depends(get_session),
           identity: dict = Depends(require_identity)):
    topic = fetch(session, tid)
    if not topic.done_date:
        raise HTTPException(status_code=409,
                            detail="발표가 끝난 주제에만 반응을 남길 수 있습니다")
    already = session.get(TopicEmotion, (tid, identity["email"], kind.value))
    if already:
        session.delete(already)
    else:
        session.add(TopicEmotion(topic_id=tid, email=identity["email"], kind=kind.value,
                                 created_at=time.strftime("%Y-%m-%d %H:%M:%S")))
    session.commit()
    return {"kind": kind.value, "count": count_for(session, tid, kind.value),
            "mine": already is None}
