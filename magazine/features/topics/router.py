import time

from fastapi import APIRouter, Depends, HTTPException, Request
from sqlalchemy import or_, update
from sqlmodel import Session, func, select

from core.config import EMAIL_TO_NAME
from core.db import Topic, get_session
from features.identity.auth import (get_settings, require_admin,
                                    require_identity)
from features.notify import slack
from features.topics.models import (AssignIn, ClaimIn, CompleteIn, ScheduleIn,
                                    TopicIn, TopicPatch)
from features.topics.service import fetch, may_manage_claim, to_dict

router = APIRouter(prefix="/magazineapi/topics", tags=["topics"])


@router.get("")
def list_topics(session: Session = Depends(get_session),
                identity: dict = Depends(require_identity)):
    on_date = func.coalesce(func.nullif(Topic.done_date, ""),
                            func.nullif(Topic.planned_date, ""))
    stmt = select(Topic).order_by(on_date.is_(None).desc(), on_date.desc(),
                                 Topic.id.desc())
    return [to_dict(t) for t in session.exec(stmt).all()]


@router.get("/{tid}")
def get_topic(tid: int, session: Session = Depends(get_session),
              identity: dict = Depends(require_identity)):
    return to_dict(fetch(session, tid))


@router.post("", status_code=201)
def create_topic(body: TopicIn, request: Request,
                 session: Session = Depends(get_session),
                 identity: dict = Depends(require_admin)):
    topic = Topic(**body.model_dump(), created_by=identity["email"],
                  created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    session.add(topic)
    session.commit()
    session.refresh(topic)
    slack.new_topic(get_settings(request), topic)
    return to_dict(topic)


@router.put("/{tid}")
def update_topic(tid: int, body: TopicPatch, session: Session = Depends(get_session),
                 identity: dict = Depends(require_admin)):
    topic = fetch(session, tid)
    for name, value in body.model_dump(exclude_unset=True).items():
        setattr(topic, name, value)
    session.add(topic)
    session.commit()
    session.refresh(topic)
    return to_dict(topic)


@router.delete("/{tid}")
def delete_topic(tid: int, session: Session = Depends(get_session),
                 identity: dict = Depends(require_admin)):
    session.delete(fetch(session, tid))
    session.commit()
    return {"ok": True}


@router.post("/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, session: Session = Depends(get_session),
                identity: dict = Depends(require_identity)):
    fetch(session, tid)   # 없으면 404
    values = {"presenter_email": identity["email"],
              "presenter": identity.get("name") or ""}
    if body.planned_date:
        values["planned_date"] = body.planned_date
    # 동시 선점 방지 — 조건부 UPDATE 한 방. 임포트된 행은 NULL 이 아니라 빈 문자열이다.
    result = session.execute(
        update(Topic)
        .where(Topic.id == tid,
               or_(Topic.presenter_email.is_(None), Topic.presenter_email == ""),
               or_(Topic.done_date.is_(None), Topic.done_date == ""))
        .values(**values))
    session.commit()
    if result.rowcount == 0:
        raise HTTPException(status_code=409,
                            detail="이미 선점되었거나 발표가 끝난 주제입니다")
    return to_dict(fetch(session, tid))


@router.post("/{tid}/release")
def release_topic(tid: int, request: Request, session: Session = Depends(get_session),
                  identity: dict = Depends(require_identity)):
    topic = fetch(session, tid)
    if not may_manage_claim(get_settings(request), topic, identity):
        raise HTTPException(status_code=403, detail="본인이 선점한 주제만 취소할 수 있습니다")
    topic.presenter_email = ""
    topic.presenter = ""
    topic.planned_date = ""
    session.add(topic)
    session.commit()
    session.refresh(topic)
    return to_dict(topic)


@router.post("/{tid}/schedule")
def schedule_topic(tid: int, body: ScheduleIn, request: Request,
                   session: Session = Depends(get_session),
                   identity: dict = Depends(require_identity)):
    # claim 은 아무도 안 잡은 주제에만 걸려서, 선점 후 날짜를 넣을 경로가 따로 필요하다.
    topic = fetch(session, tid)
    if not may_manage_claim(get_settings(request), topic, identity):
        raise HTTPException(status_code=403,
                            detail="본인이 선점한 주제만 예정일을 정할 수 있습니다")
    if topic.done_date:
        raise HTTPException(status_code=409, detail="이미 발표가 끝난 주제입니다")
    topic.planned_date = body.planned_date
    session.add(topic)
    session.commit()
    session.refresh(topic)
    return to_dict(topic)


@router.post("/{tid}/complete")
def complete_topic(tid: int, body: CompleteIn, session: Session = Depends(get_session),
                   identity: dict = Depends(require_admin)):
    topic = fetch(session, tid)
    topic.done_date = body.done_date or time.strftime("%Y-%m-%d")
    session.add(topic)
    session.commit()
    session.refresh(topic)
    return to_dict(topic)


@router.post("/{tid}/assign")
def assign_presenter(tid: int, body: AssignIn, session: Session = Depends(get_session),
                     identity: dict = Depends(require_admin)):
    # 선점과 달리 이미 선점된 주제도 덮어쓴다 — 배정 권한은 관리자에게 있다.
    topic = fetch(session, tid)
    email = body.email.strip()
    if email and email not in EMAIL_TO_NAME:
        raise HTTPException(status_code=422, detail="명단에 없는 사람입니다")

    topic.presenter_email = email
    topic.presenter = EMAIL_TO_NAME[email] if email else ""
    if not email:
        topic.planned_date = ""
    elif body.planned_date is not None:
        topic.planned_date = body.planned_date
    session.add(topic)
    session.commit()
    session.refresh(topic)
    return to_dict(topic)
