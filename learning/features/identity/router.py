import json

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


def _work_systems(settings: Settings) -> list[dict]:
    """포털의 worksystems.json 을 읽어 포털 루트 기준 path 를 절대주소로 바꿔 준다.
    포털 트리가 안 보이면 목록만 비어 나온다 — 화면 자체는 계속 뜬다."""
    try:
        with open(settings.work_systems_path, encoding="utf-8") as f:
            systems = json.load(f)
    except OSError:
        return []
    portal = settings.portal_url.rstrip("/")
    for item in systems:
        item["url"] = portal + "/" + item["path"]
    return systems


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
    base["work_systems"] = _work_systems(settings)
    return base


@router.get("/members", dependencies=[Depends(require_identity)])
def members():
    return MEMBERS
