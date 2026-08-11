# -*- coding: utf-8 -*-
"""발표 자료 도메인: 주제에 파일이나 링크를 붙이고 내려받는다.

등록·삭제는 그 주제의 발표자 본인이나 관리자만 할 수 있다.
열람은 로그인한 사람이면 된다.
"""
import mimetypes
import os
import uuid
from typing import Optional

from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel

from auth import get_settings, require_identity
from config import Settings
from db import connect
from topics import _fetch, _may_manage_claim, to_dict

router = APIRouter(prefix="/magazineapi/topics/{tid}/material", tags=["material"])

# 같은 오리진에서 인라인으로 열어도 안전한 타입만 허용한다.
# HTML/SVG 는 스크립트를 실행할 수 있어 포털 세션을 노린 XSS 가 되므로 제외한다.
INLINE_TYPES = {
    "application/pdf",
    "image/png", "image/jpeg", "image/gif", "image/webp", "image/bmp",
    "text/plain",
}
CHUNK = 1024 * 1024


class LinkIn(BaseModel):
    url: str
    name: str = ""


def _guard(request: Request, tid: int):
    """대상 행과 커넥션을 돌려준다. 권한이 없으면 403."""
    settings = get_settings(request)
    identity = require_identity(request)
    conn = connect(settings)
    row = _fetch(conn, tid)
    if not _may_manage_claim(settings, row, identity):
        conn.close()
        raise HTTPException(status_code=403,
                            detail="발표자 본인이나 관리자만 자료를 올릴 수 있습니다")
    return settings, conn, row


def _clear_file(settings: Settings, row) -> None:
    """이전에 올린 파일이 있으면 지운다. 자료는 주제당 하나다."""
    old = row["material_path"] if "material_path" in row.keys() else None
    if old:
        try:
            os.remove(os.path.join(settings.upload_dir, old))
        except OSError:
            pass


@router.post("/link")
def attach_link(tid: int, body: LinkIn, request: Request):
    settings, conn, row = _guard(request, tid)
    url = body.url.strip()
    if not url.startswith(("http://", "https://")):
        conn.close()
        raise HTTPException(status_code=422, detail="http(s) 로 시작하는 주소만 넣을 수 있습니다")
    _clear_file(settings, row)
    conn.execute(
        "UPDATE topics SET material_kind='link', material_url=?, material_name=?, "
        "material_path=NULL WHERE id=?",
        (url, body.name.strip() or url, tid))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/file")
async def attach_file(tid: int, request: Request, file: UploadFile = File(...)):
    settings, conn, row = _guard(request, tid)
    original = os.path.basename(file.filename or "자료")
    ext = os.path.splitext(original)[1][:16]
    # 저장 이름은 우리가 만든다. 클라이언트 파일명을 경로로 쓰지 않는다.
    stored = f"{tid}_{uuid.uuid4().hex}{ext}"
    dest = os.path.join(settings.upload_dir, stored)

    size = 0
    try:
        with open(dest, "wb") as out:
            while True:
                chunk = await file.read(CHUNK)
                if not chunk:
                    break
                size += len(chunk)
                if size > settings.max_upload_bytes:
                    raise HTTPException(
                        status_code=413,
                        detail=f"{settings.max_upload_bytes // (1024*1024)}MB 까지 올릴 수 있습니다")
                out.write(chunk)
    except Exception:
        try:
            os.remove(dest)
        except OSError:
            pass
        conn.close()
        raise

    _clear_file(settings, row)
    conn.execute(
        "UPDATE topics SET material_kind='file', material_path=?, material_name=?, "
        "material_url=NULL WHERE id=?",
        (stored, original, tid))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.delete("")
def detach(tid: int, request: Request):
    settings, conn, row = _guard(request, tid)
    _clear_file(settings, row)
    conn.execute(
        "UPDATE topics SET material_kind=NULL, material_url=NULL, "
        "material_name=NULL, material_path=NULL WHERE id=?", (tid,))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.get("/download")
def download(tid: int, request: Request, identity: dict = Depends(require_identity)):
    settings = get_settings(request)
    conn = connect(settings)
    row = _fetch(conn, tid)
    conn.close()
    stored = row["material_path"] if "material_path" in row.keys() else None
    if not stored:
        raise HTTPException(status_code=404, detail="올라온 파일이 없습니다")

    path = os.path.join(settings.upload_dir, stored)
    # upload_dir 밖으로 나가는 경로는 거부한다
    if os.path.commonpath([os.path.realpath(path),
                           os.path.realpath(settings.upload_dir)]) != os.path.realpath(settings.upload_dir):
        raise HTTPException(status_code=404, detail="올라온 파일이 없습니다")
    if not os.path.exists(path):
        raise HTTPException(status_code=404, detail="파일을 찾을 수 없습니다")

    name = row["material_name"] or stored
    ctype = mimetypes.guess_type(name)[0] or "application/octet-stream"
    inline = ctype in INLINE_TYPES
    return FileResponse(
        path,
        media_type=ctype if inline else "application/octet-stream",
        headers={"Content-Disposition":
                 f'{"inline" if inline else "attachment"}; filename*=UTF-8\'\'{_q(name)}'},
    )


def _q(s: str) -> str:
    from urllib.parse import quote
    return quote(s)
