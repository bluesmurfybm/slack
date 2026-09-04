from enum import StrEnum

from sqlmodel import Session, func, select

from core.db import Presentation, PresentationEmotion


class Emotion(StrEnum):
    like = "like"
    apply = "apply"
    easy = "easy"
    new = "new"


def empty_counts() -> dict:
    return {e.value: 0 for e in Emotion}


def count_for(session: Session, presentation_id: int, kind: str) -> int:
    return session.exec(
        select(func.count()).select_from(PresentationEmotion)
        .where(PresentationEmotion.presentation_id == presentation_id,
               PresentationEmotion.kind == kind)).one()


def summary(session: Session, me: str) -> tuple[dict[int, dict], dict[int, list]]:
    # 목록은 아티클 단위로 그리므로 topic_id 로 묶어 돌려준다
    joined = (select(Presentation.topic_id, PresentationEmotion.kind, PresentationEmotion.email)
              .select_from(PresentationEmotion)
              .join(Presentation, Presentation.id == PresentationEmotion.presentation_id))
    counts: dict[int, dict] = {}
    mine: dict[int, list] = {}
    for topic_id, kind, email in session.exec(joined).all():
        bucket = counts.setdefault(topic_id, empty_counts())
        bucket[kind] += 1
        if email == me:
            mine.setdefault(topic_id, []).append(kind)
    return counts, mine
