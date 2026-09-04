from pathlib import Path

from fastapi import APIRouter, Depends, File, Request, UploadFile
from fastapi.responses import FileResponse
from sqlmodel import Session

from core.db import LearningCert, get_session
from features.cert import service, storage
from features.identity.auth import get_settings, require_identity
from features.requests import service as requests_service

router = APIRouter(prefix="/learningapi/requests/{rid}/certs", tags=["cert"])


def _guard(request: Request, session: Session, rid: int):
    identity = require_identity(request)
    req = requests_service.fetch(session, rid)
    requests_service.require_owner_or_admin(request, req, identity,
                                            "이수증을 다룰")
    requests_service.ensure_transition(req, requests_service.ATTACH)
    return req, identity


@router.get("", dependencies=[Depends(require_identity)])
def list_certs(rid: int, session: Session = Depends(get_session)):
    requests_service.fetch(session, rid)
    return service.listing(session, rid)


@router.post("", status_code=201)
async def upload_cert(rid: int, request: Request, file: UploadFile = File(...),
                      session: Session = Depends(get_session)):
    _, identity = _guard(request, session, rid)
    original = Path(file.filename or "이수증").name
    service.ensure_allowed(original)

    settings = get_settings(request)
    stored = await storage.save_upload(settings, rid, file)
    session.add(LearningCert(request_id=rid, name=original, path=stored,
                             uploaded_by=identity["email"],
                             created_at=requests_service.now()))
    session.commit()
    return service.listing(session, rid)


@router.get("/{cid}/download", dependencies=[Depends(require_identity)])
def download_cert(rid: int, cid: int, request: Request,
                  session: Session = Depends(get_session)):
    cert = service.fetch(session, rid, cid)
    settings = get_settings(request)
    path = storage.resolve(settings, cert.path)
    media_type, disp = storage.disposition(cert.name or cert.path)
    return FileResponse(path, media_type=media_type,
                        headers={"Content-Disposition": disp})


@router.delete("/{cid}")
def delete_cert(rid: int, cid: int, request: Request,
                session: Session = Depends(get_session)):
    _guard(request, session, rid)
    cert = service.fetch(session, rid, cid)
    storage.remove(get_settings(request), cert.path)
    session.delete(cert)
    session.commit()
    return service.listing(session, rid)
