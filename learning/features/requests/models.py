import re

from pydantic import BaseModel, Field, field_validator

from core.config import ACCOUNT_PERSONAL, ACCOUNT_TYPES, LEVELS, PROGRESSES

DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def _date(v: str) -> str:
    v = (v or "").strip()
    if v and not DATE.match(v):
        raise ValueError("날짜는 YYYY-MM-DD 형식이어야 합니다")
    return v


def _one_of(v: str, allowed: list[str], label: str, *, blank_ok: bool = False) -> str:
    v = (v or "").strip()
    if not v and blank_ok:
        return v
    if v not in allowed:
        raise ValueError(f"{label}은(는) {', '.join(allowed)} 중 하나여야 합니다")
    return v


class RequestIn(BaseModel):
    site: str
    category_large: str = ""
    category_medium: str = ""
    level: str = ""
    title: str
    url: str = ""
    account_type: str = ACCOUNT_PERSONAL

    duration_min: int = Field(default=0, ge=0)
    is_free: bool = False
    price: int = Field(default=0, ge=0)

    start_date: str = ""
    end_date: str = ""

    @field_validator("title")
    @classmethod
    def _title(cls, v):
        if not (v or "").strip():
            raise ValueError("강의명을 입력해 주세요")
        return v.strip()

    @field_validator("level")
    @classmethod
    def _level(cls, v):
        return _one_of(v, LEVELS, "학습수준", blank_ok=True)

    @field_validator("account_type")
    @classmethod
    def _account(cls, v):
        return _one_of(v, ACCOUNT_TYPES, "계정 구분")

    @field_validator("start_date", "end_date")
    @classmethod
    def _dates(cls, v):
        return _date(v)

    @field_validator("url")
    @classmethod
    def _url(cls, v):
        v = (v or "").strip()
        if v and not v.startswith(("http://", "https://")):
            raise ValueError("수강주소는 http(s) 로 시작해야 합니다")
        return v


class RequestPatch(RequestIn):
    # 부분 수정 — 라우터가 exclude_unset 으로 보낸 필드만 반영한다
    site: str | None = None
    title: str | None = None

    @field_validator("title")
    @classmethod
    def _title(cls, v):
        if v is None:
            return v
        if not v.strip():
            raise ValueError("강의명을 입력해 주세요")
        return v.strip()


class ReasonIn(BaseModel):
    reason: str = ""

    @field_validator("reason")
    @classmethod
    def _reason(cls, v):
        if not (v or "").strip():
            raise ValueError("사유를 입력해 주세요")
        return v.strip()


class ProgressIn(BaseModel):
    progress: str

    @field_validator("progress")
    @classmethod
    def _progress(cls, v):
        return _one_of(v, PROGRESSES, "진행상태")
