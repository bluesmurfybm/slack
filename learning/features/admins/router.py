import time

from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel
from sqlalchemy import delete
from sqlmodel import Session, select

from core.config import EMAIL_TO_NAME, MEMBERS, OWNER_EMAILS, OWNERS
from core.db import AdminUser, admin_emails, get_session
from features.identity.auth import is_owner, require_admin, require_owner

router = APIRouter(prefix="/learningapi/admins", tags=["admins"])


class AdminsIn(BaseModel):
    emails: list[str] = []


def _out(request: Request, identity: dict) -> dict:
    admins = request.app.state.admins
    return {
        "owners": OWNER_EMAILS,
        "can_manage": is_owner(identity.get("email")),
        "members": [{**m,
                     "is_admin": m["email"].lower() in admins,
                     "is_owner": m["email"].lower() in OWNERS}
                    for m in MEMBERS],
    }


@router.get("")
def list_admins(request: Request, identity: dict = Depends(require_admin)):
    return _out(request, identity)


@router.put("")
def set_admins(body: AdminsIn, request: Request,
               session: Session = Depends(get_session),
               identity: dict = Depends(require_owner)):
    """화면의 체크 상태를 그대로 반영한다. 고정 관리자는 빼도 남는다."""
    wanted = {e.strip().lower() for e in body.emails if e.strip()}
    unknown = wanted - {e.lower() for e in EMAIL_TO_NAME}
    if unknown:
        raise HTTPException(status_code=422,
                            detail=f"명단에 없는 사람입니다: {sorted(unknown)}")
    wanted |= OWNERS

    now = time.strftime("%Y-%m-%d %H:%M:%S")
    keep = {a.email.lower() for a in session.exec(select(AdminUser)).all()}
    session.execute(delete(AdminUser).where(AdminUser.email.notin_(wanted)))
    session.add_all([AdminUser(email=e, added_by=identity["email"], created_at=now)
                     for e in sorted(wanted - keep)])
    session.commit()

    # 판정은 app.state 를 보므로 여기서 같이 갈아끼워야 즉시 반영된다
    request.app.state.admins = admin_emails(request.app.state.engine)
    return _out(request, identity)
