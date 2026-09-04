from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, field_validator
from sqlmodel import Session, select

from core.db import CategoryOption, LearningSite, get_session
from features.identity.auth import is_admin, require_admin, require_identity

router = APIRouter(prefix="/learningapi/sites", tags=["sites"])


class SiteIn(BaseModel):
    name: str
    url: str = ""
    sort_order: int = 0

    @field_validator("name")
    @classmethod
    def _name(cls, v):
        if not (v or "").strip():
            raise ValueError("플랫폼 이름을 입력해 주세요")
        return v.strip()

    @field_validator("url")
    @classmethod
    def _url(cls, v):
        v = (v or "").strip()
        if v and not v.startswith(("http://", "https://")):
            raise ValueError("플랫폼 주소는 http(s) 로 시작해야 합니다")
        return v


class SitePatch(SiteIn):
    name: str | None = None
    url: str | None = None
    sort_order: int | None = None
    active: bool | None = None

    @field_validator("name")
    @classmethod
    def _name(cls, v):
        if v is None:
            return v
        if not v.strip():
            raise ValueError("플랫폼 이름을 입력해 주세요")
        return v.strip()


def _out(site: LearningSite) -> dict:
    return {"id": site.id, "name": site.name, "url": site.url,
            "sort_order": site.sort_order, "active": site.active}


def _fetch(session: Session, sid: int) -> LearningSite:
    site = session.get(LearningSite, sid)
    if not site:
        raise HTTPException(status_code=404, detail="없는 플랫폼입니다")
    return site


def _ensure_unique(session: Session, name: str, sid: int | None = None) -> None:
    dup = session.exec(select(LearningSite).where(LearningSite.name == name)).first()
    if dup and dup.id != sid:
        raise HTTPException(status_code=409, detail="이미 있는 플랫폼입니다")


@router.get("")
def list_sites(request: Request, session: Session = Depends(get_session),
               identity: dict = Depends(require_identity)):
    stmt = select(LearningSite).order_by(LearningSite.sort_order, LearningSite.id)
    if not is_admin(request, identity["email"]):
        stmt = stmt.where(LearningSite.active == 1)
    return [_out(s) for s in session.exec(stmt).all()]


@router.post("", status_code=201, dependencies=[Depends(require_admin)])
def create_site(body: SiteIn, session: Session = Depends(get_session)):
    _ensure_unique(session, body.name)
    site = LearningSite(**body.model_dump())
    session.add(site)
    session.commit()
    session.refresh(site)
    return _out(site)


@router.put("/{sid}", dependencies=[Depends(require_admin)])
def update_site(sid: int, body: SitePatch, session: Session = Depends(get_session)):
    site = _fetch(session, sid)
    patch = body.model_dump(exclude_unset=True, exclude_none=True)

    if "name" in patch and patch["name"] != site.name:
        _ensure_unique(session, patch["name"], sid)
        # 분류는 사이트를 이름으로 참조한다 — 같이 옮기지 않으면 통째로 고아가 된다.
        # 지난 신청 건의 site 는 그대로 둔다(당시 이름이 남아야 한다).
        for row in session.exec(select(CategoryOption)
                                .where(CategoryOption.site == site.name)).all():
            row.site = patch["name"]
            session.add(row)

    for name, value in patch.items():
        setattr(site, name, int(value) if isinstance(value, bool) else value)
    session.add(site)
    session.commit()
    session.refresh(site)
    return _out(site)


@router.delete("/{sid}", dependencies=[Depends(require_admin)])
def deactivate_site(sid: int, session: Session = Depends(get_session)):
    # 하드 삭제 금지 — 지난 신청 건이 이 이름을 그대로 들고 있다.
    site = _fetch(session, sid)
    site.active = 0
    session.add(site)
    session.commit()
    session.refresh(site)
    return _out(site)
