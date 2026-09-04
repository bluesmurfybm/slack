from fastapi import APIRouter, Depends, HTTPException, Request, Response
from pydantic import BaseModel

from core.config import EMAIL_TO_NAME, Settings
from features.identity.auth import get_settings, is_admin, make_cookie

router = APIRouter(prefix="/learningapi", tags=["dev"])


class DevLoginIn(BaseModel):
    email: str


@router.post("/devlogin")
def dev_login(body: DevLoginIn, request: Request, response: Response,
              settings: Settings = Depends(get_settings)):
    email = body.email.strip().lower()
    if email not in EMAIL_TO_NAME:
        raise HTTPException(status_code=422, detail="명단에 없는 사람입니다")
    name = EMAIL_TO_NAME[email]
    response.set_cookie("blueiwork_id",
                        make_cookie(settings, email, name, ttl=86400),
                        httponly=True, samesite="lax", path="/")
    return {"ok": True, "email": email, "name": name,
            "is_admin": is_admin(request, email)}
