from fastapi import APIRouter, Depends, Request

from core.config import ACCOUNT_TYPES, LEVELS, MEMBERS, PROGRESSES, Settings
from features.identity.auth import (
    get_identity,
    get_settings,
    is_admin,
    is_owner,
    require_identity,
)

router = APIRouter(prefix="/learningapi", tags=["identity"])


@router.get("/whoami")
def whoami(request: Request, settings: Settings = Depends(get_settings)):
    ident = get_identity(request)
    base = {"email": None, "name": None, "color": None}
    base.update(ident or {})
    base["is_admin"] = is_admin(request, base.get("email"))
    base["is_owner"] = is_owner(base.get("email"))
    base["levels"] = LEVELS
    base["account_types"] = ACCOUNT_TYPES
    base["progresses"] = PROGRESSES
    base["dev_login"] = settings.dev_login
    base["portal_url"] = settings.portal_url
    base["slack_url"] = settings.slack_url
    return base


@router.get("/members", dependencies=[Depends(require_identity)])
def members():
    return MEMBERS
