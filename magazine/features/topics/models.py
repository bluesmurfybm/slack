from typing import Annotated

from pydantic import AfterValidator, BaseModel

from core.config import MAGAZINES, TEAMS


def _check_team(v):
    if v and v not in TEAMS:
        raise ValueError(f"없는 팀입니다: {v}")
    return v


TeamStr = Annotated[str, AfterValidator(_check_team)]


def _check_magazine(v):
    if v and v not in MAGAZINES:
        raise ValueError(f"없는 매거진입니다: {v}")
    return v


MagazineStr = Annotated[str, AfterValidator(_check_magazine)]


class TopicIn(BaseModel):
    title: str
    field: str = ""
    keywords: str = ""
    magazine: MagazineStr = ""
    volume: str = ""
    page: str = ""
    year: int | None = None
    requirement: str = "recommended"
    team: TeamStr = ""
    planned_date: str = ""
    note: str = ""
    active: int = 1


class TopicPatch(BaseModel):
    title: str | None = None
    field: str | None = None
    keywords: str | None = None
    magazine: MagazineStr | None = None
    volume: str | None = None
    page: str | None = None
    year: int | None = None
    requirement: str | None = None
    team: TeamStr | None = None
    presenter: str | None = None
    presenter_email: str | None = None
    planned_date: str | None = None
    done_date: str | None = None
    note: str | None = None
    active: int | None = None
    archived: int | None = None


class ClaimIn(BaseModel):
    planned_date: str = ""


class ScheduleIn(BaseModel):
    planned_date: str = ""


class CompleteIn(BaseModel):
    done_date: str = ""


class AssignIn(BaseModel):
    email: str = ""
    planned_date: str | None = None

