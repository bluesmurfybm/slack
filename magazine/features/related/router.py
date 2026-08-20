from fastapi import APIRouter, Depends
from sqlmodel import Session, select

from core.db import Topic, TopicRelated, get_session
from features.identity.auth import require_identity
from features.related.service import MAX_RELATED
from features.topics.service import fetch

router = APIRouter(prefix="/magazineapi/topics/{tid}/related", tags=["related"])


@router.get("", dependencies=[Depends(require_identity)])
def related(tid: int, session: Session = Depends(get_session)):
    fetch(session, tid)
    rows = session.exec(
        select(TopicRelated, Topic)
        .where(TopicRelated.topic_id == tid, TopicRelated.related_id == Topic.id,
               Topic.active == 1, Topic.archived == 0)
        .order_by(TopicRelated.score.desc(), Topic.id.desc())
        .limit(MAX_RELATED)).all()
    return [{"id": t.id, "title": t.title, "field": t.field, "magazine": t.magazine,
             "volume": t.volume, "page": t.page, "score": r.score}
            for r, t in rows]
