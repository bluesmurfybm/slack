from fastapi import HTTPException
from sqlmodel import Session

from core.config import Settings
from core.db import Topic
from features.emotion.service import empty_counts
from features.identity.auth import is_admin

STATUS_OPEN = "미지정"
STATUS_PLANNED = "발표예정"
STATUS_DONE = "발표완료"


def derive_status(topic: Topic) -> str:
    # 컬럼으로 저장하지 않는다 — 원본 xlsx 에 상태와 값이 어긋난 행이 있었다.
    if topic.done_date:
        return STATUS_DONE
    if topic.presenter_email:
        return STATUS_PLANNED
    return STATUS_OPEN


def to_dict(topic: Topic, emotions: dict = None, my_emotions: list = None) -> dict:
    return {**topic.model_dump(), "status": derive_status(topic),
            "emotions": emotions or empty_counts(), "my_emotions": my_emotions or []}


def fetch(session: Session, tid: int) -> Topic:
    topic = session.get(Topic, tid)
    if not topic:
        raise HTTPException(status_code=404, detail="없는 주제입니다")
    return topic


def may_manage_claim(settings: Settings, topic: Topic, identity: dict) -> bool:
    return (topic.presenter_email == identity["email"]
            or is_admin(settings, identity["email"]))
