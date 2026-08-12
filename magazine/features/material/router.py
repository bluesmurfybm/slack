import os

from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel

from core.db import connect
from features.identity.auth import get_settings, require_identity
from features.material import storage
from features.topics.service import fetch, may_manage_claim, to_dict

router = APIRouter(prefix="/magazineapi/topics/{tid}/material", tags=["material"])


class LinkIn(BaseModel):
    url: str
    name: str = ""


def _stored(row):
    return row["material_path"] if "material_path" in row.keys() else None


def _guard(request: Request, tid: int):
    settings = get_settings(request)
    identity = require_identity(request)
    conn = connect(settings)
    row = fetch(conn, tid)
    if not may_manage_claim(settings, row, identity):
        conn.close()
        raise HTTPException(status_code=403,
                            detail="발표자 본인이나 관리자만 자료를 올릴 수 있습니다")
    return settings, conn, row


@router.post("/link")
def attach_link(tid: int, body: LinkIn, request: Request):
    settings, conn, row = _guard(request, tid)
    url = body.url.strip()
    if not url.startswith(("http://", "https://")):
        conn.close()
        raise HTTPException(status_code=422, detail="http(s) 로 시작하는 주소만 넣을 수 있습니다")
    storage.remove(settings, _stored(row))   # 자료는 주제당 하나 — 이전 파일은 디스크에서도 지운다
    conn.execute(
        "UPDATE topics SET material_kind='link', material_url=?, material_name=?, "
        "material_path=NULL WHERE id=?",
        (url, body.name.strip() or url, tid))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/file")
async def attach_file(tid: int, request: Request, file: UploadFile = File(...)):
    settings, conn, row = _guard(request, tid)
    try:
        stored = await storage.save_upload(settings, tid, file)
    except Exception:
        conn.close()
        raise
    storage.remove(settings, _stored(row))
    conn.execute(
        "UPDATE topics SET material_kind='file', material_path=?, material_name=?, "
        "material_url=NULL WHERE id=?",
        (stored, os.path.basename(file.filename or "자료"), tid))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.delete("")
def detach(tid: int, request: Request):
    settings, conn, row = _guard(request, tid)
    storage.remove(settings, _stored(row))
    conn.execute(
        "UPDATE topics SET material_kind=NULL, material_url=NULL, "
        "material_name=NULL, material_path=NULL WHERE id=?", (tid,))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.get("/download")
def download(tid: int, request: Request, identity: dict = Depends(require_identity)):
    settings = get_settings(request)
    conn = connect(settings)
    row = fetch(conn, tid)
    conn.close()

    path = storage.resolve(settings, _stored(row))
    name = row["material_name"] or _stored(row)
    media_type, disp = storage.disposition(name)
    return FileResponse(path, media_type=media_type,
                        headers={"Content-Disposition": disp})
