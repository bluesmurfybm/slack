import time

from fastapi import APIRouter, Depends, HTTPException, Request
from sqlalchemy import update
from sqlalchemy.exc import IntegrityError
from sqlmodel import Session, func, select

from core.config import EMAIL_TO_NAME
from core.db import Presentation, Topic, get_session
from features.emotion.service import summary as emotion_summary
from features.identity.auth import get_settings, is_admin, require_admin, require_identity
from features.notify import slack
from features.presentations import service as presentations
from features.related.service import rebuild
from features.topics.models import AssignIn, ClaimIn, CompleteIn, ScheduleIn, TopicIn, TopicPatch
from features.topics.service import fetch, may_manage_claim, to_dict

router = APIRouter(prefix="/magazineapi/topics", tags=["topics"])


@router.get("")
def list_topics(request: Request, session: Session = Depends(get_session),
                identity: dict = Depends(require_identity)):
    on_date = func.coalesce(func.nullif(Presentation.done_date, ""),
                            func.nullif(Presentation.planned_date, ""))
    stmt = (select(Topic, Presentation)
           .outerjoin(Presentation, Presentation.topic_id == Topic.id)
           .order_by(on_date.is_(None).desc(), on_date.desc(), Topic.id.desc()))
    if not is_admin(get_settings(request), identity["email"]):
        # 숨김·보관은 관리자 화면에만 있어야 한다. 목록에서 빼는 판정은 서버가 한다.
        stmt = stmt.where(Topic.active == 1, Topic.archived == 0)
    counts, mine = emotion_summary(session, identity["email"])
    return [to_dict(t, p, counts.get(t.id), mine.get(t.id))
           for t, p in session.exec(stmt).all()]


@router.get("/{tid}", dependencies=[Depends(require_identity)])
def get_topic(tid: int, session: Session = Depends(get_session)):
    topic = fetch(session, tid)
    return to_dict(topic, presentations.of_topic(session, tid))


@router.post("", status_code=201)
def create_topic(body: TopicIn, session: Session = Depends(get_session),
                 identity: dict = Depends(require_admin)):
    values = body.model_dump()
    planned_date = values.pop("planned_date")
    topic = Topic(**values, created_by=identity["email"],
                  created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    session.add(topic)
    session.flush()
    if planned_date:
        presentations.create(session, topic.id, planned_date=planned_date)
    session.commit()
    rebuild(session) # commit 으로 인스턴스가 만료되므로 refresh 는 이 뒤여야 한다
    session.refresh(topic)
    return to_dict(topic, presentations.of_topic(session, topic.id))


@router.put("/{tid}", dependencies=[Depends(require_admin)])
def update_topic(tid: int, body: TopicPatch, session: Session = Depends(get_session)):
    topic = fetch(session, tid)
    patch = body.model_dump(exclude_unset=True)
    planned_date = patch.pop("planned_date", None)
    for name, value in patch.items():
        setattr(topic, name, value)
    session.add(topic)
    if planned_date is not None:
        pres = presentations.of_topic(session, tid)
        if pres is None and planned_date:
            pres = presentations.create(session, tid)
        if pres is not None:
            pres.planned_date = planned_date
            session.add(pres)
    session.commit()
    rebuild(session)
    session.refresh(topic)
    return to_dict(topic, presentations.of_topic(session, tid))


@router.delete("/{tid}", dependencies=[Depends(require_admin)])
def delete_topic(tid: int, request: Request, session: Session = Depends(get_session)):
    topic = fetch(session, tid)
    pres = presentations.of_topic(session, tid)
    if pres:
        presentations.purge(get_settings(request), session, pres)
    session.delete(topic)
    session.commit()
    rebuild(session)
    return {"ok": True}


@router.post("/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, request: Request,
                session: Session = Depends(get_session),
                identity: dict = Depends(require_identity)):
    topic = fetch(session, tid) # 없으면 404
    if not topic.active or topic.archived:
        raise HTTPException(status_code=409, detail="지금은 예약할 수 없는 아티클입니다")
    values = {"presenter_email": identity["email"],
              "presenter": identity.get("name") or ""}
    if body.planned_date:
        values["planned_date"] = body.planned_date
    # 동시 예약 방지 — 조건부 UPDATE 한 방. 발표자 없는 행(자료만 등)이 있으면 그 행을 차지한다.
    result = session.execute(
        update(Presentation)
        .where(Presentation.topic_id == tid,
               Presentation.presenter_email == "", Presentation.done_date == "")
        .values(**values))
    if result.rowcount == 0:
        try:
            presentations.create(session, tid, **values)
            session.commit()
        except IntegrityError:
            session.rollback()
            raise HTTPException(status_code=409,
                                detail="이미 예약되었거나 발표가 끝난 아티클입니다")
    else:
        session.commit()
    pres = presentations.of_topic(session, tid)
    slack.new_presenter(get_settings(request), topic, pres)
    return to_dict(topic, pres)


@router.post("/{tid}/release")
def release_topic(tid: int, request: Request, session: Session = Depends(get_session),
                  identity: dict = Depends(require_identity)):
    topic = fetch(session, tid)
    pres = presentations.of_topic(session, tid)
    if not may_manage_claim(get_settings(request), pres, identity):
        raise HTTPException(status_code=403, detail="본인이 예약한 아티클만 취소할 수 있습니다")
    if pres:
        presentations.unassign(get_settings(request), session, pres)
        session.commit()
    return to_dict(topic, presentations.of_topic(session, tid))


@router.post("/{tid}/schedule")
def schedule_topic(tid: int, body: ScheduleIn, request: Request,
                   session: Session = Depends(get_session),
                   identity: dict = Depends(require_identity)):
    # claim 은 아무도 안 잡은 주제에만 걸려서, 예약 후 날짜를 넣을 경로가 따로 필요하다.
    topic = fetch(session, tid)
    pres = presentations.of_topic(session, tid)
    if not may_manage_claim(get_settings(request), pres, identity):
        raise HTTPException(status_code=403,
                            detail="본인이 예약한 아티클만 예정일을 정할 수 있습니다")
    if pres is None:
        raise HTTPException(status_code=409, detail="예약이 없는 아티클입니다")
    if pres.done_date:
        raise HTTPException(status_code=409, detail="이미 발표가 끝난 아티클입니다")
    pres.planned_date = body.planned_date
    session.add(pres)
    session.commit()
    session.refresh(pres)
    return to_dict(topic, pres)


@router.post("/{tid}/complete", dependencies=[Depends(require_admin)])
def complete_topic(tid: int, body: CompleteIn, session: Session = Depends(get_session)):
    topic = fetch(session, tid)
    pres = presentations.of_topic(session, tid)
    if pres is None:
        pres = presentations.create(session, tid)
    pres.done_date = body.done_date or time.strftime("%Y-%m-%d")
    session.add(pres)
    session.commit()
    session.refresh(pres)
    return to_dict(topic, pres)


@router.post("/{tid}/assign", dependencies=[Depends(require_admin)])
def assign_presenter(tid: int, body: AssignIn, request: Request,
                     session: Session = Depends(get_session)):
    # 예약과 달리 이미 예약된 주제도 덮어쓴다 — 배정 권한은 관리자에게 있다.
    topic = fetch(session, tid)
    email = body.email.strip()
    if email and email not in EMAIL_TO_NAME:
        raise HTTPException(status_code=422, detail="명단에 없는 사람입니다")

    pres = presentations.of_topic(session, tid)
    if not email:
        if pres:
            presentations.unassign(get_settings(request), session, pres)
            session.commit()
        return to_dict(topic, presentations.of_topic(session, tid))

    values = {"presenter_email": email, "presenter": EMAIL_TO_NAME[email]}
    if body.planned_date is not None:
        values["planned_date"] = body.planned_date
    if pres:
        for field, value in values.items():
            setattr(pres, field, value)
        session.add(pres)
    else:
        pres = presentations.create(session, tid, **values)
    session.commit()
    session.refresh(pres)
    slack.new_presenter(get_settings(request), topic, pres)
    return to_dict(topic, pres)
