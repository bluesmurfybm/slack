# -*- coding: utf-8 -*-
"""주제 도메인: 발표 주제의 등록·수정·삭제와 선점 흐름.

상태(미지정/발표예정/발표완료)는 컬럼으로 저장하지 않고 파생한다.
원본 xlsx 에 '발표자와 예정일이 있는데 비고는 미지정' 같은 어긋난 행이
실제로 있어서, 저장하면 계속 어긋나기 때문이다.
"""
import time
from typing import Optional

import notify
from auth import get_settings, is_admin, require_admin, require_identity
from config import Settings
from db import connect
from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel

router = APIRouter(prefix="/magazineapi/topics", tags=["topics"])

STATUS_OPEN = "미지정"
STATUS_PLANNED = "발표예정"
STATUS_DONE = "발표완료"


# ---------- 모델 ----------
class TopicIn(BaseModel):
    title: str
    field: str = ""
    keywords: str = ""
    magazine: str = ""
    volume: str = ""
    page: str = ""
    year: Optional[int] = None
    requirement: str = "recommended"
    team: str = ""
    planned_date: str = ""
    note: str = ""


class TopicPatch(BaseModel):
    title: Optional[str] = None
    field: Optional[str] = None
    keywords: Optional[str] = None
    magazine: Optional[str] = None
    volume: Optional[str] = None
    page: Optional[str] = None
    year: Optional[int] = None
    requirement: Optional[str] = None
    team: Optional[str] = None
    presenter: Optional[str] = None
    presenter_email: Optional[str] = None
    planned_date: Optional[str] = None
    done_date: Optional[str] = None
    note: Optional[str] = None


class ClaimIn(BaseModel):
    planned_date: str = ""


class ScheduleIn(BaseModel):
    planned_date: str = ""


class CompleteIn(BaseModel):
    done_date: str = ""


# ---------- 도메인 규칙 ----------
def derive_status(row) -> str:
    if row["done_date"]:
        return STATUS_DONE
    if row["presenter_email"]:
        return STATUS_PLANNED
    return STATUS_OPEN


def to_dict(row) -> dict:
    d = dict(row)
    d["status"] = derive_status(row)
    return d


def _fetch(conn, tid):
    row = conn.execute("SELECT * FROM topics WHERE id=?", (tid,)).fetchone()
    if not row:
        conn.close()
        raise HTTPException(status_code=404, detail="없는 주제입니다")
    return row


def _may_manage_claim(settings: Settings, row, identity: dict) -> bool:
    """선점자 본인이거나 관리자."""
    return (row["presenter_email"] == identity["email"]
            or is_admin(settings, identity["email"]))


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
    row = _fetch(conn, tid)
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
    row = _fetch(conn, cur.lastrowid)
    conn.close()
    notify.new_topic(settings, row)
    return to_dict(row)


@router.put("/{tid}")
def update_topic(tid: int, body: TopicPatch, request: Request,
                 identity: dict = Depends(require_admin)):
    conn = connect(get_settings(request))
    _fetch(conn, tid)
    patch = body.model_dump(exclude_unset=True)
    if patch:
        sets = ",".join(f"{k}=?" for k in patch)
        conn.execute(f"UPDATE topics SET {sets} WHERE id=?", (*patch.values(), tid))
        conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.delete("/{tid}")
def delete_topic(tid: int, request: Request,
                 identity: dict = Depends(require_admin)):
    conn = connect(get_settings(request))
    _fetch(conn, tid)
    conn.execute("DELETE FROM topics WHERE id=?", (tid,))
    conn.commit()
    conn.close()
    return {"ok": True}


# ---------- 선점 흐름 ----------
@router.post("/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, request: Request,
                identity: dict = Depends(require_identity)):
    conn = connect(get_settings(request))
    _fetch(conn, tid)   # 없으면 404
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
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/{tid}/release")
def release_topic(tid: int, request: Request,
                  identity: dict = Depends(require_identity)):
    settings = get_settings(request)
    conn = connect(settings)
    row = _fetch(conn, tid)
    if not _may_manage_claim(settings, row, identity):
        conn.close()
        raise HTTPException(status_code=403, detail="본인이 선점한 주제만 취소할 수 있습니다")
    conn.execute("UPDATE topics SET presenter_email='', presenter='', planned_date='' "
                 "WHERE id=?", (tid,))
    conn.commit()
    row = _fetch(conn, tid)
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
    row = _fetch(conn, tid)
    if not _may_manage_claim(settings, row, identity):
        conn.close()
        raise HTTPException(status_code=403, detail="본인이 선점한 주제만 예정일을 정할 수 있습니다")
    if row["done_date"]:
        conn.close()
        raise HTTPException(status_code=409, detail="이미 발표가 끝난 주제입니다")
    conn.execute("UPDATE topics SET planned_date=? WHERE id=?", (body.planned_date, tid))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)


@router.post("/{tid}/complete")
def complete_topic(tid: int, body: CompleteIn, request: Request,
                   identity: dict = Depends(require_admin)):
    conn = connect(get_settings(request))
    _fetch(conn, tid)
    done = body.done_date or time.strftime("%Y-%m-%d")
    conn.execute("UPDATE topics SET done_date=? WHERE id=?", (done, tid))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return to_dict(row)
