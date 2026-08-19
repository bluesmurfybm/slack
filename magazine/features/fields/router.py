from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlmodel import Session, select

from core.db import FieldOption, get_session
from features.identity.auth import require_admin, require_identity

router = APIRouter(prefix="/magazineapi/fields", tags=["fields"])


class FieldIn(BaseModel):
    name: str


@router.get("", dependencies=[Depends(require_identity)])
def list_fields(session: Session = Depends(get_session)):
    rows = session.exec(select(FieldOption).order_by(FieldOption.id)).all()
    return [{"id": f.id, "name": f.name} for f in rows]


@router.post("", status_code=201, dependencies=[Depends(require_admin)])
def create_field(body: FieldIn, session: Session = Depends(get_session)):
    name = body.name.strip()
    if not name:
        raise HTTPException(status_code=422, detail="분야 이름을 입력해 주세요")
    if session.exec(select(FieldOption).where(FieldOption.name == name)).first():
        raise HTTPException(status_code=409, detail="이미 있는 분야입니다")
    row = FieldOption(name=name)
    session.add(row)
    session.commit()
    session.refresh(row)
    return {"id": row.id, "name": row.name}


@router.delete("/{fid}", dependencies=[Depends(require_admin)])
def delete_field(fid: int, session: Session = Depends(get_session)):
    row = session.get(FieldOption, fid)
    if not row:
        raise HTTPException(status_code=404, detail="없는 분야입니다")
    session.delete(row)
    session.commit()
    return {"ok": True}
