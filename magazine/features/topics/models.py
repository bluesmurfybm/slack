from typing import Optional

from pydantic import BaseModel


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
    active: int = 1


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
    active: Optional[int] = None
    archived: Optional[int] = None


class ClaimIn(BaseModel):
    planned_date: str = ""


class ScheduleIn(BaseModel):
    planned_date: str = ""


class CompleteIn(BaseModel):
    done_date: str = ""


class AssignIn(BaseModel):
    email: str = ""                      # 비우면 지정 해제
    planned_date: Optional[str] = None   # None 이면 기존 예정일 유지
