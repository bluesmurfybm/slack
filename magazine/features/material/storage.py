# -*- coding: utf-8 -*-
"""발표 자료 파일 저장.

보안상 지키는 것:
- 저장 파일명은 서버가 만든다. 클라이언트가 준 이름을 경로로 쓰지 않는다.
- 업로드 디렉터리 밖을 가리키는 경로는 거부한다.
- HTML/SVG 는 같은 오리진에서 인라인으로 열면 포털 세션을 노린 XSS 가
  되므로 인라인 허용 목록에서 뺀다.
"""
import mimetypes
import os
import uuid
from typing import Optional
from urllib.parse import quote

from fastapi import HTTPException

from core.config import Settings

INLINE_TYPES = {
    "application/pdf",
    "image/png", "image/jpeg", "image/gif", "image/webp", "image/bmp",
    "text/plain",
}
CHUNK = 1024 * 1024


async def save_upload(settings: Settings, tid: int, upload) -> str:
    """업로드를 저장하고 저장 파일명을 돌려준다. 상한을 넘으면 413."""
    original = os.path.basename(upload.filename or "자료")
    ext = os.path.splitext(original)[1][:16]
    stored = f"{tid}_{uuid.uuid4().hex}{ext}"
    dest = os.path.join(settings.upload_dir, stored)

    size = 0
    try:
        with open(dest, "wb") as out:
            while True:
                chunk = await upload.read(CHUNK)
                if not chunk:
                    break
                size += len(chunk)
                if size > settings.max_upload_bytes:
                    limit = settings.max_upload_bytes // (1024 * 1024)
                    raise HTTPException(status_code=413,
                                        detail=f"{limit}MB 까지 올릴 수 있습니다")
                out.write(chunk)
    except Exception:
        remove(settings, stored)
        raise
    return stored


def remove(settings: Settings, stored: Optional[str]) -> None:
    if not stored:
        return
    try:
        os.remove(os.path.join(settings.upload_dir, stored))
    except OSError:
        pass


def resolve(settings: Settings, stored: Optional[str]) -> str:
    """저장 파일의 실제 경로. 없거나 디렉터리를 벗어나면 404."""
    if not stored:
        raise HTTPException(status_code=404, detail="올라온 파일이 없습니다")
    root = os.path.realpath(settings.upload_dir)
    path = os.path.realpath(os.path.join(settings.upload_dir, stored))
    if os.path.commonpath([path, root]) != root:
        raise HTTPException(status_code=404, detail="올라온 파일이 없습니다")
    if not os.path.exists(path):
        raise HTTPException(status_code=404, detail="파일을 찾을 수 없습니다")
    return path


def disposition(name: str):
    """(media_type, Content-Disposition) — 안전한 타입만 인라인."""
    ctype = mimetypes.guess_type(name)[0] or "application/octet-stream"
    inline = ctype in INLINE_TYPES
    how = "inline" if inline else "attachment"
    return (ctype if inline else "application/octet-stream",
            f"{how}; filename*=UTF-8''{quote(name)}")
