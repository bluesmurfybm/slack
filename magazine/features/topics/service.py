from fastapi import HTTPException
from sqlmodel import Session

from core.config import Settings
from core.db import Presentation, Topic
from features.emotion.service import empty_counts
from features.identity.auth import is_admin

STATUS_OPEN = "미지정"
STATUS_PLANNED = "발표예정"
STATUS_DONE = "발표완료"

PRESENTATION_DEFAULTS = {
    "presenter": "", "presenter_email": "", "planned_date": "", "done_date": "",
    "material_kind": None, "material_name": None, "material_url": None,
    "material_path": None,
}


def derive_status(pres: Presentation | None) -> str:
    if pres and pres.done_date:
        return STATUS_DONE
    if pres and pres.presenter_email:
        return STATUS_PLANNED
    return STATUS_OPEN


def to_dict(topic: Topic, pres: Presentation | None,
           emotions: dict | None = None, my_emotions: list | None = None) -> dict:
    flat = ({k: getattr(pres, k) for k in PRESENTATION_DEFAULTS} if pres
           else dict(PRESENTATION_DEFAULTS))
    return {**topic.model_dump(), **flat, "status": derive_status(pres),
           "emotions": emotions or empty_counts(), "my_emotions": my_emotions or []}


def fetch(session: Session, tid: int) -> Topic:
    topic = session.get(Topic, tid)
    if not topic:
        raise HTTPException(status_code=404, detail="없는 아티클입니다")
    return topic


def may_manage_claim(settings: Settings, pres: Presentation | None,
                     identity: dict) -> bool:
    return ((pres is not None and pres.presenter_email == identity["email"])
           or is_admin(settings, identity["email"]))
