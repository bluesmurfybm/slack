from enum import Enum

from sqlmodel import Session, func, select

from core.db import TopicEmotion


class Emotion(str, Enum):
    like = "like"
    apply = "apply"
    easy = "easy"
    new = "new"


def empty_counts() -> dict:
    return {e.value: 0 for e in Emotion}


def count_for(session: Session, topic_id: int, kind: str) -> int:
    return session.exec(
        select(func.count()).select_from(TopicEmotion)
        .where(TopicEmotion.topic_id == topic_id, TopicEmotion.kind == kind)).one()


def summary(session: Session, me: str) -> tuple[dict[int, dict], dict[int, list]]:
    counts: dict[int, dict] = {}
    for topic_id, kind, n in session.exec(
            select(TopicEmotion.topic_id, TopicEmotion.kind, func.count())
            .group_by(TopicEmotion.topic_id, TopicEmotion.kind)).all():
        counts.setdefault(topic_id, empty_counts())[kind] = n

    mine: dict[int, list] = {}
    for topic_id, kind in session.exec(
            select(TopicEmotion.topic_id, TopicEmotion.kind)
            .where(TopicEmotion.email == me)).all():
        mine.setdefault(topic_id, []).append(kind)
    return counts, mine
