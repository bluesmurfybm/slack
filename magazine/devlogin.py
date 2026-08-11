# -*- coding: utf-8 -*-
"""개발 전용 로그인.

포털이 없는 환경(도커·로컬)에서 화면을 보기 위한 스텁이다.
DEV_LOGIN=1 일 때만 앱에 등록되므로 운영에는 존재하지 않는다.
쿠키 "발급"만 대신하고, 이후 검증·권한 경로는 운영과 완전히 동일하다.
"""
from fastapi import APIRouter, Depends, Request, Response
from pydantic import BaseModel

from auth import get_settings, is_admin, make_cookie
from config import DEV_ACCOUNTS, Settings

router = APIRouter(prefix="/magazineapi", tags=["dev"])


class DevLoginIn(BaseModel):
    email: str
    name: str = ""


@router.post("/devlogin")
def dev_login(body: DevLoginIn, response: Response,
              settings: Settings = Depends(get_settings)):
    name = body.name or next(
        (a["name"] for a in DEV_ACCOUNTS if a["email"] == body.email), body.email)
    response.set_cookie("blueiwork_id",
                        make_cookie(settings, body.email, name, ttl=86400),
                        httponly=True, samesite="lax", path="/")
    return {"ok": True, "email": body.email, "name": name,
            "is_admin": is_admin(settings, body.email)}
