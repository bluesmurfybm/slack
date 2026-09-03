from pathlib import Path

from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel
from sqlmodel import Session

from core.db import Presentation, Topic, get_session
from features.identity.auth import get_settings, require_identity
from features.material import storage
from features.presentations import service as presentations
from features.topics.service import fetch, may_manage_claim, to_dict

# 슬롯 이름이 곧 컬럼 접두어다. 발표 자료는 발표 행에, 스캔 원본은 아티클 행에 있다.
SLOTS = ("material", "scan")

router = APIRouter(prefix="/magazineapi/topics/{tid}/{slot}", tags=["material"])


class LinkIn(BaseModel):
    url: str
    name: str = ""


class Slot:
    def __init__(self, name: str):
        if name not in SLOTS:
            raise HTTPException(status_code=404, detail="없는 자료 칸입니다")
        self.name = name

    def holder(self, topic: Topic, pres: Presentation | None) -> Topic | Presentation | None:
        return topic if self.name == "scan" else pres

    def holder_for_write(self, session: Session, topic: Topic,
                         pres: Presentation | None) -> Topic | Presentation:
        return self.holder(topic, pres) or presentations.create(session, topic.id)

    def get(self, holder, field: str):
        return getattr(holder, f"{self.name}_{field}") if holder is not None else None

    def set(self, holder, **values) -> None:
        for field, value in values.items():
            setattr(holder, f"{self.name}_{field}", value)


def _guard(request: Request, session: Session, tid: int) -> tuple[Topic, Presentation | None]:
    identity = require_identity(request)
    topic = fetch(session, tid)
    pres = presentations.of_topic(session, tid)
    if not may_manage_claim(get_settings(request), pres, identity):
        raise HTTPException(status_code=403,
                            detail="발표자 본인이나 관리자만 자료를 올릴 수 있습니다")
    return topic, pres


def _save(session: Session, topic: Topic, holder) -> dict:
    session.add(holder)
    session.commit()
    return to_dict(topic, presentations.of_topic(session, topic.id))


@router.post("/link")
def attach_link(tid: int, slot: str, body: LinkIn, request: Request,
                session: Session = Depends(get_session)):
    where = Slot(slot)
    topic, pres = _guard(request, session, tid)
    url = body.url.strip()
    if not url.startswith(("http://", "https://")):
        raise HTTPException(status_code=422,
                            detail="http(s) 로 시작하는 주소만 넣을 수 있습니다")
    holder = where.holder_for_write(session, topic, pres)
    storage.remove(get_settings(request), where.get(holder, "path"))
    where.set(holder, kind="link", url=url, name=body.name.strip() or url, path=None)
    return _save(session, topic, holder)


@router.post("/file")
async def attach_file(tid: int, slot: str, request: Request,
                      file: UploadFile = File(...),
                      session: Session = Depends(get_session)):
    where = Slot(slot)
    settings = get_settings(request)
    topic, pres = _guard(request, session, tid)
    stored = await storage.save_upload(settings, tid, file)
    holder = where.holder_for_write(session, topic, pres)
    storage.remove(settings, where.get(holder, "path"))
    where.set(holder, kind="file", path=stored, url=None,
              name=Path(file.filename or "자료").name)
    return _save(session, topic, holder)


@router.delete("")
def detach(tid: int, slot: str, request: Request,
           session: Session = Depends(get_session)):
    where = Slot(slot)
    topic, pres = _guard(request, session, tid)
    holder = where.holder(topic, pres)
    if holder is None:
        return to_dict(topic, None)
    storage.remove(get_settings(request), where.get(holder, "path"))
    where.set(holder, kind=None, name=None, url=None, path=None)
    return _save(session, topic, holder)


@router.get("/download", dependencies=[Depends(require_identity)])
def download(tid: int, slot: str, request: Request,
             session: Session = Depends(get_session)):
    where = Slot(slot)
    settings = get_settings(request)
    topic = fetch(session, tid)
    holder = where.holder(topic, presentations.of_topic(session, tid))
    path = storage.resolve(settings, where.get(holder, "path"))
    media_type, disp = storage.disposition(
        where.get(holder, "name") or where.get(holder, "path"))
    return FileResponse(path, media_type=media_type,
                        headers={"Content-Disposition": disp})
