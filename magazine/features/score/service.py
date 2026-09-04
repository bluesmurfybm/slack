from dataclasses import dataclass

from sqlmodel import Session, func, select

from core.config import EMAIL_TO_NAME, MEMBERS
from core.db import Presentation, PresentationEmotion, Topic

DONE = 10
REQUIRED_BONUS = 5
MATERIAL = 3
REACTION = 1
REACTION_DAILY_CAP = 3

KINDS = ("done", "required", "material", "reaction")


@dataclass(frozen=True)
class Event:
    email: str
    date: str # YYYY-MM-DD
    kind: str
    points: int


def events(session: Session) -> list[Event]:
    out: list[Event] = []
    for pres, topic in session.exec(
            select(Presentation, Topic)
            .where(Presentation.topic_id == Topic.id, Presentation.presenter_email != "")).all():
        who = pres.presenter_email
        # 자료 등록 시각은 저장하지 않아 발표일, 없으면 예약 시각으로 귀속한다
        date = pres.done_date or pres.created_at[:10]
        if pres.done_date:
            out.append(Event(who, date, "done", DONE))
            if topic.requirement == "required":
                out.append(Event(who, date, "required", REQUIRED_BONUS))
        if pres.material_kind:
            out.append(Event(who, date, "material", MATERIAL))
    day = func.substr(PresentationEmotion.created_at, 1, 10)
    for email, date, n in session.exec(
            select(PresentationEmotion.email, day, func.count())
            .group_by(PresentationEmotion.email, day)).all():
        out.append(Event(email, date, "reaction", min(n, REACTION_DAILY_CAP) * REACTION))
    return out


def summary(session: Session, start: str = "", end: str = "") -> list[dict]:
    rows = {m["email"]: {"email": m["email"], "name": m["name"], "total": 0,
                         "breakdown": dict.fromkeys(KINDS, 0)}
            for m in MEMBERS}
    for ev in events(session):
        if (start and ev.date < start) or (end and ev.date > end):
            continue
        row = rows.setdefault(ev.email, {
            "email": ev.email, "name": EMAIL_TO_NAME.get(ev.email, ev.email),
            "total": 0, "breakdown": dict.fromkeys(KINDS, 0)})
        row["total"] += ev.points
        row["breakdown"][ev.kind] += ev.points
    return sorted(rows.values(), key=lambda r: (-r["total"], r["name"]))
