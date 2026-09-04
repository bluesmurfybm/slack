import contextlib
import mimetypes
import os
import uuid
from pathlib import Path
from urllib.parse import quote

import anyio
from fastapi import HTTPException

from core.config import Settings

# 같은 오리진에서 인라인으로 열어도 안전한 타입만. HTML/SVG 는 스크립트를
# 실행할 수 있어 포털 세션을 노린 XSS 가 되므로 넣지 않는다.
INLINE_TYPES = {
    "application/pdf",
    "image/png", "image/jpeg", "image/gif", "image/webp", "image/bmp",
    "text/plain",
}
CHUNK = 1024 * 1024


def _too_large(settings: Settings) -> HTTPException:
    limit = settings.max_upload_bytes // (1024 * 1024)
    return HTTPException(status_code=413, detail=f"{limit}MB 까지 올릴 수 있습니다")


async def save_upload(settings: Settings, rid: int, upload) -> str:
    original = Path(upload.filename or "이수증").name
    ext = Path(original).suffix[:16]
    stored = f"{rid}_{uuid.uuid4().hex}{ext}"
    dest = Path(settings.upload_dir) / stored

    size = 0
    try:
        async with await anyio.open_file(dest, "wb") as out:
            while True:
                chunk = await upload.read(CHUNK)
                if not chunk:
                    break
                size += len(chunk)
                if size > settings.max_upload_bytes:
                    raise _too_large(settings) # noqa: TRY301
                await out.write(chunk)
    except Exception:
        remove(settings, stored)
        raise
    return stored


def remove(settings: Settings, stored: str | None) -> None:
    if not stored:
        return
    with contextlib.suppress(OSError):
        (Path(settings.upload_dir) / stored).unlink()


def resolve(settings: Settings, stored: str | None) -> str:
    if not stored:
        raise HTTPException(status_code=404, detail="올라온 이수증이 없습니다")
    root = Path(settings.upload_dir).resolve()
    path = (Path(settings.upload_dir) / stored).resolve()
    if os.path.commonpath([path, root]) != str(root):
        raise HTTPException(status_code=404, detail="올라온 이수증이 없습니다")
    if not path.exists():
        raise HTTPException(status_code=404, detail="파일을 찾을 수 없습니다")
    return str(path)


def disposition(name: str):
    ctype = mimetypes.guess_type(name)[0] or "application/octet-stream"
    inline = ctype in INLINE_TYPES
    how = "inline" if inline else "attachment"
    return (ctype if inline else "application/octet-stream",
            f"{how}; filename*=UTF-8''{quote(name)}")
