import re

from sqlalchemy import delete
from sqlmodel import Session, select

from core.db import Topic, TopicRelated

SAME_FIELD = 40
SHARED_KEYWORD = 22
SHARED_KEYWORD_CAP = 2
TITLE_WEIGHT = 10
TOKEN_MATCH = 0.8
MIN_SCORE = 50
MAX_RELATED = 3


def _bigrams(text: str) -> set[str]:
    norm = re.sub(r"[^0-9a-z가-힣]", "", text.lower())
    return {norm[i:i + 2] for i in range(len(norm) - 1)}


def _dice(a: set[str], b: set[str]) -> float:
    if not a or not b:
        return 0.0
    return 2 * len(a & b) / (len(a) + len(b))


def _overlap(a: set[str], b: set[str]) -> float:
    if not a or not b:
        return 0.0
    return len(a & b) / min(len(a), len(b))


def _keyword_grams(topic: Topic) -> list[set[str]]:
    tokens = re.split(r"[\s,/·]+", (topic.keywords or "").lower())
    return [g for g in (_bigrams(t) for t in tokens if t) if g]


def score(a: Topic, b: Topic) -> int:
    total = 0
    if a.field and a.field == b.field:
        total += SAME_FIELD
    mine = _keyword_grams(a)
    matched = sum(1 for grams in _keyword_grams(b)
                  if any(_overlap(grams, m) >= TOKEN_MATCH for m in mine))
    total += min(matched, SHARED_KEYWORD_CAP) * SHARED_KEYWORD
    total += round(_dice(_bigrams(a.title), _bigrams(b.title)) * TITLE_WEIGHT)
    return total


def rebuild(session: Session) -> None:
    topics = session.exec(select(Topic)).all()
    session.execute(delete(TopicRelated))
    session.add_all([
        TopicRelated(topic_id=a.id, related_id=b.id, score=s)
        for a in topics for b in topics
        if a.id != b.id and (s := score(a, b)) >= MIN_SCORE])
    session.commit()
