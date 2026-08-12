from fastapi import APIRouter, Depends, Request, Response
from pydantic import BaseModel

from features.identity.auth import get_settings, is_admin, make_cookie
from core.config import DEV_ACCOUNTS, Settings

router = APIRouter(prefix="/magazineapi", tags=["dev"])


class DevLoginIn(BaseModel):
    email: str
    name: str = ""


@router.post("/devlogin")
def dev_login(body: DevLoginIn, response: Response,
              settings: Settings = Depends(get_settings)):
    # DEV_LOGIN=1 일 때만 app 에 등록되므로 운영에는 이 경로가 없다.
    # 쿠키 "발급"만 대신하고, 이후 검증·권한은 운영과 같은 코드를 탄다.
    name = body.name or next(
        (a["name"] for a in DEV_ACCOUNTS if a["email"] == body.email), body.email)
    response.set_cookie("blueiwork_id",
                        make_cookie(settings, body.email, name, ttl=86400),
                        httponly=True, samesite="lax", path="/")
    return {"ok": True, "email": body.email, "name": name,
            "is_admin": is_admin(settings, body.email)}
