import time

from sqlmodel import Session, select

from core.config import Settings
from core.db import Presentation, PresentationEmotion
from features.material import storage


def of_topic(session: Session, tid: int) -> Presentation | None:
    return session.exec(
        select(Presentation).where(Presentation.topic_id == tid)).first()


def create(session: Session, tid: int, **values) -> Presentation:
    pres = Presentation(topic_id=tid, created_at=time.strftime("%Y-%m-%d %H:%M:%S"),
                        **values)
    session.add(pres)
    return pres


def purge(settings: Settings, session: Session, pres: Presentation) -> None:
    storage.remove(settings, pres.material_path)
    for emo in session.exec(select(PresentationEmotion)
                            .where(PresentationEmotion.presentation_id == pres.id)).all():
        session.delete(emo)
    session.delete(pres)


def unassign(settings: Settings, session: Session, pres: Presentation) -> None:
    if pres.done_date:
        pres.presenter = ""
        pres.presenter_email = ""
        pres.planned_date = ""
        session.add(pres)
        return
    purge(settings, session, pres)
