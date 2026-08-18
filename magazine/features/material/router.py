from pathlib import Path

from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel
from sqlmodel import Session

from core.db import Topic, get_session
from features.identity.auth import get_settings, require_identity
from features.material import storage
from features.topics.service import fetch, may_manage_claim, to_dict

# 슬롯 이름이 곧 Topic 의 컬럼 접두어다 (material_kind, scan_kind ...)
SLOTS = ("material", "scan")

router = APIRouter(prefix="/magazineapi/topics/{tid}/{slot}", tags=["material"])


class LinkIn(BaseModel):
    url: str
    name: str = ""


class Fields:
    def __init__(self, slot: str):
        if slot not in SLOTS:
            raise HTTPException(status_code=404, detail="없는 자료 칸입니다")
        self.kind, self.name = f"{slot}_kind", f"{slot}_name"
        self.url, self.path = f"{slot}_url", f"{slot}_path"

    def get(self, topic: Topic, field: str):
        return getattr(topic, getattr(self, field))

    def set(self, topic: Topic, **values) -> None:
        for field, value in values.items():
            setattr(topic, getattr(self, field), value)


def _guard(request: Request, session: Session, tid: int) -> Topic:
    identity = require_identity(request)
    topic = fetch(session, tid)
    if not may_manage_claim(get_settings(request), topic, identity):
        raise HTTPException(status_code=403,
                            detail="발표자 본인이나 관리자만 자료를 올릴 수 있습니다")
    return topic


def _save(session: Session, topic: Topic) -> dict:
    session.add(topic)
    session.commit()
    session.refresh(topic)
    return to_dict(topic)


@router.post("/link")
def attach_link(tid: int, slot: str, body: LinkIn, request: Request,
                session: Session = Depends(get_session)):
    fields = Fields(slot)
    topic = _guard(request, session, tid)
    url = body.url.strip()
    if not url.startswith(("http://", "https://")):
        raise HTTPException(status_code=422,
                            detail="http(s) 로 시작하는 주소만 넣을 수 있습니다")
    storage.remove(get_settings(request), fields.get(topic, "path"))
    fields.set(topic, kind="link", url=url, name=body.name.strip() or url, path=None)
    return _save(session, topic)


@router.post("/file")
async def attach_file(tid: int, slot: str, request: Request,
                      file: UploadFile = File(...),
                      session: Session = Depends(get_session)):
    fields = Fields(slot)
    settings = get_settings(request)
    topic = _guard(request, session, tid)
    stored = await storage.save_upload(settings, tid, file)
    storage.remove(settings, fields.get(topic, "path"))
    fields.set(topic, kind="file", path=stored, url=None,
               name=Path(file.filename or "자료").name)
    return _save(session, topic)


@router.delete("")
def detach(tid: int, slot: str, request: Request,
           session: Session = Depends(get_session)):
    fields = Fields(slot)
    topic = _guard(request, session, tid)
    storage.remove(get_settings(request), fields.get(topic, "path"))
    fields.set(topic, kind=None, name=None, url=None, path=None)
    return _save(session, topic)


@router.get("/download", dependencies=[Depends(require_identity)])
def download(tid: int, slot: str, request: Request,
             session: Session = Depends(get_session)):
    fields = Fields(slot)
    settings = get_settings(request)
    topic = fetch(session, tid)
    path = storage.resolve(settings, fields.get(topic, "path"))
    media_type, disp = storage.disposition(
        fields.get(topic, "name") or fields.get(topic, "path"))
    return FileResponse(path, media_type=media_type,
                        headers={"Content-Disposition": disp})
