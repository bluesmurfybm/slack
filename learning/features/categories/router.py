from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, field_validator
from sqlmodel import Session, func, select

from core.db import CategoryOption, LearningRequest, get_session
from features.identity.auth import is_admin, require_admin, require_identity

router = APIRouter(prefix="/learningapi/categories", tags=["categories"])


class CategoryIn(BaseModel):
    site: str
    large: str
    medium: str = ""
    sort_order: int = 0
    recommended: bool = False

    @field_validator("site", "large")
    @classmethod
    def _required(cls, v):
        if not (v or "").strip():
            raise ValueError("플랫폼과 대분류는 비울 수 없습니다")
        return v.strip()

    @field_validator("medium")
    @classmethod
    def _medium(cls, v):
        return (v or "").strip()


class CategoryPatch(CategoryIn):
    site: str | None = None
    large: str | None = None
    medium: str | None = None
    sort_order: int | None = None
    recommended: bool | None = None
    active: bool | None = None

    @field_validator("site", "large")
    @classmethod
    def _required(cls, v):
        if v is None:
            return v
        if not v.strip():
            raise ValueError("플랫폼과 대분류는 비울 수 없습니다")
        return v.strip()


def _out(row: CategoryOption) -> dict:
    return {"id": row.id, "site": row.site, "large": row.large, "medium": row.medium,
            "sort_order": row.sort_order, "recommended": row.recommended,
            "active": row.active}


def _fetch(session: Session, cid: int) -> CategoryOption:
    row = session.get(CategoryOption, cid)
    if not row:
        raise HTTPException(status_code=404, detail="없는 분류입니다")
    return row


def _find(session: Session, site: str, large: str, medium: str) -> CategoryOption | None:
    return session.exec(select(CategoryOption).where(
        CategoryOption.site == site, CategoryOption.large == large,
        CategoryOption.medium == medium)).first()


def _ensure_shape(session: Session, site: str, large: str, medium: str,
                  cid: int | None = None) -> None:
    dup = _find(session, site, large, medium)
    if dup and dup.id != cid:
        raise HTTPException(status_code=409, detail="이미 있는 분류입니다")
    if medium and not _find(session, site, large, ""):
        raise HTTPException(status_code=422, detail="대분류를 먼저 등록해 주세요")


@router.get("")
def list_categories(request: Request, site: str = "",
                    session: Session = Depends(get_session),
                    identity: dict = Depends(require_identity)):
    stmt = select(CategoryOption).order_by(CategoryOption.sort_order, CategoryOption.id)
    if site:
        stmt = stmt.where(CategoryOption.site == site)
    if not is_admin(request, identity["email"]):
        stmt = stmt.where(CategoryOption.active == 1)
    return [_out(r) for r in session.exec(stmt).all()]


@router.post("", status_code=201, dependencies=[Depends(require_admin)])
def create_category(body: CategoryIn, session: Session = Depends(get_session)):
    _ensure_shape(session, body.site, body.large, body.medium)
    row = CategoryOption(**{**body.model_dump(), "recommended": int(body.recommended)})
    session.add(row)
    session.commit()
    session.refresh(row)
    return _out(row)


class RecommendIn(BaseModel):
    site: str
    ids: list[int] = []


# "/{cid}" 보다 먼저 선언해야 한다 — 뒤에 두면 cid 로 먼저 잡혀 422 가 난다
@router.put("/recommended", dependencies=[Depends(require_admin)])
def set_recommended(body: RecommendIn, session: Session = Depends(get_session)):
    """한 사이트의 추천 지정을 화면 상태 그대로 맞춘다. 목록에 없는 행은 해제된다."""
    rows = session.exec(select(CategoryOption)
                        .where(CategoryOption.site == body.site)
                        .order_by(CategoryOption.sort_order, CategoryOption.id)).all()
    if not rows:
        raise HTTPException(status_code=404, detail="분류가 없는 플랫폼입니다")

    unknown = set(body.ids) - {r.id for r in rows}
    if unknown:
        raise HTTPException(status_code=422,
                            detail=f"{body.site} 의 분류가 아닌 항목이 있습니다: "
                                   f"{sorted(unknown)}")

    wanted = set(body.ids)
    for row in rows:
        row.recommended = int(row.id in wanted)
        session.add(row)
    session.commit()
    return [_out(r) for r in rows]


@router.put("/{cid}", dependencies=[Depends(require_admin)])
def update_category(cid: int, body: CategoryPatch,
                    session: Session = Depends(get_session)):
    row = _fetch(session, cid)
    patch = body.model_dump(exclude_unset=True, exclude_none=True)
    after = {k: patch.get(k, getattr(row, k)) for k in ("site", "large", "medium")}
    _ensure_shape(session, after["site"], after["large"], after["medium"], cid)

    children = _children(session, row)
    for name, value in patch.items():
        setattr(row, name, int(value) if isinstance(value, bool) else value)
    session.add(row)

    # 대분류를 고치면 딸린 중분류가 같은 이름을 들고 따라와야 한다
    for child in children:
        child.site, child.large = row.site, row.large
        if "active" in patch:
            child.active = row.active
        session.add(child)

    session.commit()
    session.refresh(row)
    return _out(row)


@router.delete("/{cid}", dependencies=[Depends(require_admin)])
def deactivate_category(cid: int, session: Session = Depends(get_session)):
    # 하드 삭제 금지 — 지난 신청 건이 이 이름을 그대로 들고 있다.
    row = _fetch(session, cid)
    row.active = 0
    session.add(row)
    for child in _children(session, row):
        child.active = 0
        session.add(child)
    session.commit()
    session.refresh(row)
    return _out(row)


def _usage_count(session: Session, row: CategoryOption) -> int:
    """이 분류를 쓰는 신청이 몇 건인지. 신청 행은 분류를 이름 문자열로 들고 있어
    (site/category_large/category_medium) 옵션 행이 사라져도 표시는 남는다.
    그래도 쓰는 중인 분류를 지우면 관리자가 필터에서 그 이름을 다시 고를 수 없게 되므로,
    쓰고 있으면 지우지 못하게 막고 숨기기(비활성)로 보낸다."""
    stmt = select(func.count()).select_from(LearningRequest).where(
        LearningRequest.site == row.site,
        LearningRequest.category_large == row.large)
    if row.medium:
        # 중분류는 그 중분류를 고른 건만, 대분류는 그 아래 전부를 센다
        stmt = stmt.where(LearningRequest.category_medium == row.medium)
    return session.exec(stmt).one()


@router.delete("/{cid}/purge", dependencies=[Depends(require_admin)])
def delete_category(cid: int, session: Session = Depends(get_session)):
    """정말 지운다. 대분류를 지우면 그 아래 중분류도 함께 사라진다.
    쓰는 신청이 있으면 409 로 거부한다 — 그 경우는 숨기기(DELETE /{cid})가 맞다."""
    row = _fetch(session, cid)
    kids = _children(session, row)
    used = _usage_count(session, row)
    if used:
        raise HTTPException(
            status_code=409,
            detail=f"이 분류를 쓰는 신청이 {used}건 있어 지울 수 없습니다. 숨기기만 됩니다.")
    for child in kids:
        session.delete(child)
    session.delete(row)
    session.commit()
    return {"deleted": 1 + len(kids)}


def _children(session: Session, row: CategoryOption) -> list[CategoryOption]:
    if row.medium:
        return []
    return list(session.exec(select(CategoryOption).where(
        CategoryOption.site == row.site, CategoryOption.large == row.large,
        CategoryOption.medium != "")).all())
