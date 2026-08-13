import os

from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel
from sqlmodel import Session

from core.db import Topic, get_session
from features.identity.auth import get_settings, require_identity
from features.material import storage
from features.topics.service import fetch, may_manage_claim, to_dict

router = APIRouter(prefix="/magazineapi/topics/{tid}/material", tags=["material"])


class LinkIn(BaseModel):
    url: str
    name: str = ""


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
def attach_link(tid: int, body: LinkIn, request: Request,
                session: Session = Depends(get_session)):
    topic = _guard(request, session, tid)
    url = body.url.strip()
    if not url.startswith(("http://", "https://")):
        raise HTTPException(status_code=422,
                            detail="http(s) 로 시작하는 주소만 넣을 수 있습니다")
    storage.remove(get_settings(request), topic.material_path)   # 자료는 주제당 하나
    topic.material_kind = "link"
    topic.material_url = url
    topic.material_name = body.name.strip() or url
    topic.material_path = None
    return _save(session, topic)


@router.post("/file")
async def attach_file(tid: int, request: Request, file: UploadFile = File(...),
                      session: Session = Depends(get_session)):
    settings = get_settings(request)
    topic = _guard(request, session, tid)
    stored = await storage.save_upload(settings, tid, file)
    storage.remove(settings, topic.material_path)
    topic.material_kind = "file"
    topic.material_path = stored
    topic.material_name = os.path.basename(file.filename or "자료")
    topic.material_url = None
    return _save(session, topic)


@router.delete("")
def detach(tid: int, request: Request, session: Session = Depends(get_session)):
    topic = _guard(request, session, tid)
    storage.remove(get_settings(request), topic.material_path)
    topic.material_kind = None
    topic.material_name = None
    topic.material_url = None
    topic.material_path = None
    return _save(session, topic)


@router.get("/download")
def download(tid: int, request: Request, session: Session = Depends(get_session),
             identity: dict = Depends(require_identity)):
    settings = get_settings(request)
    topic = fetch(session, tid)
    path = storage.resolve(settings, topic.material_path)
    media_type, disp = storage.disposition(topic.material_name or topic.material_path)
    return FileResponse(path, media_type=media_type,
                        headers={"Content-Disposition": disp})
