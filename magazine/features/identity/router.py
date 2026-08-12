from fastapi import APIRouter, Depends, Request

from core.config import DEV_ACCOUNTS, Settings
from features.identity.auth import get_identity, get_settings, is_admin

router = APIRouter(prefix="/magazineapi", tags=["identity"])


@router.get("/whoami")
def whoami(request: Request, settings: Settings = Depends(get_settings)):
    ident = get_identity(request)
    base = {"email": None, "name": None, "color": None}
    base.update(ident or {})
    base["is_admin"] = is_admin(settings, base.get("email"))
    base["dev_login"] = settings.dev_login
    base["dev_accounts"] = DEV_ACCOUNTS if settings.dev_login else []
    base["portal_url"] = settings.portal_url
    base["slack_url"] = settings.slack_url
    return base
