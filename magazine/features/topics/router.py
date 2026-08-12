import time

from fastapi import APIRouter, Depends, HTTPException, Request

from core.config import EMAIL_TO_NAME
from core.db import connect
from features.identity.auth import get_settings, require_admin, require_identity
from features.notify import slack
from features.topics.models import (AssignIn, ClaimIn, CompleteIn, ScheduleIn,
                                    TopicIn, TopicPatch)
from features.topics.service import fetch, may_manage_claim, to_dict

router = APIRouter(prefix="/magazineapi/topics", tags=["topics"])


# ---------- 조회 ----------
@router.get("")
def list_topics(request: Request, identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    rows = conn.execute(
        "SELECT * FROM topics ORDER BY (done_date IS NULL OR done_date='') DESC, "
        "COALESCE(NULLIF(planned_date,''), '9999') ASC, id ASC").fetchall()
    conn.close()
    return [to_dict(r) for r in rows]


@router.get("/{tid}")
def get_topic(tid: int, request: Request, identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


# ---------- 관리자 CRUD ----------
@router.post("", status_code=201)
def create_topic(body: TopicIn, request: Request,
                 identity: dict = Depends(require_admin)):
    settings = get_settings(request)
    conn = connect(settings)
    cur = conn.execute(
        "INSERT INTO topics(field,title,keywords,magazine,volume,page,year,"
        "requirement,team,planned_date,note,created_by,created_at) "
        "VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
        (body.field, body.title, body.keywords, body.magazine, body.volume,
         body.page, body.year, body.requirement, body.team, body.planned_date,
         body.note, identity["email"], time.strftime("%Y-%m-%d %H:%M:%S")))
    conn.commit()
    row = fetch(conn, cur.lastrowid)
    conn.close()
    slack.new_topic(settings, row)
    return to_dict(row)


@router.put("/{tid}")
def update_topic(tid: int, body: TopicPatch, request: Request,
                 identity: dict = Depends(require_admin)):
    conn = connect(get_settings(request))
    fetch(conn, tid)
    patch = body.model_dump(exclude_unset=True)
    if patch:
        sets = ",".join(f"{k}=?" for k in patch)
        conn.execute(f"UPDATE topics SET {sets} WHERE id=?", (*patch.values(), tid))
        conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.delete("/{tid}")
def delete_topic(tid: int, request: Request,
                 identity: dict = Depends(require_admin)):
    conn = connect(get_settings(request))
    fetch(conn, tid)
    conn.execute("DELETE FROM topics WHERE id=?", (tid,))
    conn.commit()
    conn.close()
    return {"ok": True}


# ---------- 선점 흐름 ----------
@router.post("/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, request: Request,
                identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    fetch(conn, tid)   # 없으면 404
    # 조건부 UPDATE 한 방으로 동시 선점을 막는다.
    # - 임포트된 행의 presenter_email 은 NULL 이 아니라 빈 문자열이라 둘 다 본다.
    # - 발표까지 끝난 주제는 발표자가 비어 있어도 선점 대상이 아니다.
    cur = conn.execute(
        "UPDATE topics SET presenter_email=?, presenter=?, "
        "planned_date=CASE WHEN ?<>'' THEN ? ELSE planned_date END "
        "WHERE id=? AND (presenter_email IS NULL OR presenter_email='') "
        "AND (done_date IS NULL OR done_date='')",
        (identity["email"], identity.get("name") or "",
         body.planned_date, body.planned_date, tid))
    conn.commit()
    if cur.rowcount == 0:
        conn.close()
        raise HTTPException(status_code=409, detail="이미 선점되었거나 발표가 끝난 주제입니다")
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/{tid}/release")
def release_topic(tid: int, request: Request,
                  identity: dict = Depends(require_identity)):
    settings = get_settings(request)
    conn = connect(settings)
    row = fetch(conn, tid)
    if not may_manage_claim(settings, row, identity):
        conn.close()
        raise HTTPException(status_code=403, detail="본인이 선점한 주제만 취소할 수 있습니다")
    conn.execute("UPDATE topics SET presenter_email='', presenter='', planned_date='' "
                 "WHERE id=?", (tid,))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/{tid}/schedule")
def schedule_topic(tid: int, body: ScheduleIn, request: Request,
                   identity: dict = Depends(require_identity)):
    """선점자가 발표 예정일을 나중에 정하거나 바꾼다.

    선점(claim)은 아직 아무도 안 잡은 주제에만 걸리므로, 날짜를 비우고
    선점한 사람이 나중에 날짜를 넣을 방법이 따로 필요하다.
    """
    settings = get_settings(request)
    conn = connect(settings)
    row = fetch(conn, tid)
    if not may_manage_claim(settings, row, identity):
        conn.close()
        raise HTTPException(status_code=403, detail="본인이 선점한 주제만 예정일을 정할 수 있습니다")
    if row["done_date"]:
        conn.close()
        raise HTTPException(status_code=409, detail="이미 발표가 끝난 주제입니다")
    conn.execute("UPDATE topics SET planned_date=? WHERE id=?", (body.planned_date, tid))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/{tid}/complete")
def complete_topic(tid: int, body: CompleteIn, request: Request,
                   identity: dict = Depends(require_admin)):
    conn = connect(get_settings(request))
    fetch(conn, tid)
    done = body.done_date or time.strftime("%Y-%m-%d")
    conn.execute("UPDATE topics SET done_date=? WHERE id=?", (done, tid))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/{tid}/assign")
def assign_presenter(tid: int, body: AssignIn, request: Request,
                     identity: dict = Depends(require_admin)):
    """관리자가 발표자를 직접 지정한다. 이메일이 비면 지정을 푼다.

    선점(claim)과 달리 이미 선점된 주제도 덮어쓴다 — 배정 권한은 관리자에게 있다.
    """
    conn = connect(get_settings(request))
    fetch(conn, tid)
    email = body.email.strip()
    if email and email not in EMAIL_TO_NAME:
        conn.close()
        raise HTTPException(status_code=422, detail="명단에 없는 사람입니다")

    if not email:
        # 발표자가 없으면 예정일도 의미가 없다. release 와 같게 맞춘다.
        conn.execute("UPDATE topics SET presenter_email='', presenter='', "
                     "planned_date='' WHERE id=?", (tid,))
    elif body.planned_date is None:
        conn.execute("UPDATE topics SET presenter_email=?, presenter=? WHERE id=?",
                     (email, EMAIL_TO_NAME[email], tid))
    else:
        conn.execute("UPDATE topics SET presenter_email=?, presenter=?, "
                     "planned_date=? WHERE id=?",
                     (email, EMAIL_TO_NAME[email], body.planned_date, tid))
    conn.commit()
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)
