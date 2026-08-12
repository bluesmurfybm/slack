import base64
import hashlib
import hmac
import time
from typing import Optional

from fastapi import Depends, HTTPException, Request

from core.config import Settings

COOKIE_NAME = "blueiwork_id"


def get_settings(request: Request) -> Settings:
    return request.app.state.settings


def _secret(settings: Settings) -> str:
    with open(settings.sso_secret_path, "r", encoding="utf-8") as f:
        return f.read().strip()


def _b64url_decode(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def _b64url_encode(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).decode("ascii").rstrip("=")


def _sign(settings: Settings, payload: str) -> str:
    return hmac.new(_secret(settings).encode("utf-8"),
                    payload.encode("utf-8"), hashlib.sha256).hexdigest()


def make_cookie(settings: Settings, email: str, name: str = "",
                color: str = "", ttl: int = 3600) -> str:
    """포털 auth.php 의 issue_sso_cookie 와 같은 형식. 개발 로그인과 테스트 전용."""
    # b64url(email \t name \t color \t exp) + "." + hmac_sha256(payload, secret)
    inner = f"{email}\t{name}\t{color}\t{int(time.time()) + ttl}"
    payload = _b64url_encode(inner.encode("utf-8"))
    return f"{payload}.{_sign(settings, payload)}"


def verify_cookie(settings: Settings, raw: str) -> Optional[dict]:
    try:
        payload, sig = raw.rsplit(".", 1)
        if not hmac.compare_digest(sig, _sign(settings, payload)):
            return None
        email, name, color, exp_s = _b64url_decode(payload).decode("utf-8").split("\t")
        if int(exp_s) < time.time():
            return None
        return {"email": email, "name": name, "color": color}
    except Exception:
        return None


def get_identity(request: Request) -> Optional[dict]:
    raw = request.cookies.get(COOKIE_NAME)
    return verify_cookie(get_settings(request), raw) if raw else None


def require_identity(request: Request) -> dict:
    ident = get_identity(request)
    if not ident:
        raise HTTPException(status_code=401, detail="로그인이 필요합니다")
    return ident


def is_admin(settings: Settings, email: Optional[str]) -> bool:
    return bool(email) and email.lower() in settings.admin_emails


def require_admin(request: Request,
                  identity: dict = Depends(require_identity)) -> dict:
    if not is_admin(get_settings(request), identity.get("email")):
        raise HTTPException(status_code=403, detail="관리자만 할 수 있습니다")
    return identity
