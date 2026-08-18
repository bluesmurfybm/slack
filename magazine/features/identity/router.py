from fastapi import APIRouter, Depends, Request

from core.config import DEV_ACCOUNTS, EMAIL_TO_TEAMS, MAGAZINES, MEMBERS, TEAMS, Settings
from features.identity.auth import get_identity, get_settings, is_admin, require_identity

router = APIRouter(prefix="/magazineapi", tags=["identity"])


@router.get("/whoami")
def whoami(request: Request, settings: Settings = Depends(get_settings)):
    ident = get_identity(request)
    base = {"email": None, "name": None, "color": None}
    base.update(ident or {})
    base["is_admin"] = is_admin(settings, base.get("email"))
    base["teams"] = EMAIL_TO_TEAMS.get(base.get("email"), [])
    base["all_teams"] = TEAMS
    base["all_magazines"] = MAGAZINES
    base["dev_login"] = settings.dev_login
    base["dev_accounts"] = DEV_ACCOUNTS if settings.dev_login else []
    base["portal_url"] = settings.portal_url
    base["slack_url"] = settings.slack_url
    return base


@router.get("/members", dependencies=[Depends(require_identity)])
def members():
    return MEMBERS
