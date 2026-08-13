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


@router.get("")
def list_topics(request: Request, identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    on_date = "COALESCE(NULLIF(done_date,''), NULLIF(planned_date,''))"
    rows = conn.execute(
        f"SELECT * FROM topics "
        f"ORDER BY ({on_date} IS NULL) DESC, {on_date} DESC, id DESC").fetchall()
    conn.close()
    return [to_dict(r) for r in rows]


@router.get("/{tid}")
def get_topic(tid: int, request: Request, identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    row = fetch(conn, tid)
    conn.close()
    return to_dict(row)


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


@router.post("/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, request: Request,
                identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    fetch(conn, tid)   # 없으면 404
    # 동시 선점 방지 — 조건부 UPDATE 한 방. 임포트된 행은 NULL 이 아니라 빈 문자열이다.
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
    # claim 은 아무도 안 잡은 주제에만 걸려서, 선점 후 날짜를 넣을 경로가 따로 필요하다.
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
    # 선점과 달리 이미 선점된 주제도 덮어쓴다 — 배정 권한은 관리자에게 있다.
    conn = connect(get_settings(request))
    fetch(conn, tid)
    email = body.email.strip()
    if email and email not in EMAIL_TO_NAME:
        conn.close()
        raise HTTPException(status_code=422, detail="명단에 없는 사람입니다")

    if not email:
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
