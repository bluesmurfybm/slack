from enum import StrEnum
from pathlib import Path
from typing import Annotated

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict

BASE = Path(__file__).resolve().parent.parent


class Level(StrEnum):
    beginner = "초급"
    intermediate = "중급"
    advanced = "고급"


LEVELS = [x.value for x in Level]


ACCOUNT_COMPANY = "회사계정"
ACCOUNT_PERSONAL = "개인계정"
ACCOUNT_TYPES = [ACCOUNT_COMPANY, ACCOUNT_PERSONAL]


class Progress(StrEnum):
    not_started = "시작전"
    in_progress = "진행중"
    done = "완료"


PROGRESSES = [x.value for x in Progress]


MEMBERS = [
    {"name": "김호영", "email": "kimhy@bluesoft.co.kr"},
    {"name": "김지안", "email": "jian@bluesoft.co.kr"},
    {"name": "박성철", "email": "scpark@bluesoft.co.kr"},
    {"name": "김태주", "email": "pink@bluesoft.co.kr"},
    {"name": "안정민", "email": "venus@bluesoft.co.kr"},
    {"name": "조성훈", "email": "akddd@bluesoft.co.kr"},
    {"name": "진소현", "email": "lenda83@bluesoft.co.kr"},
    {"name": "김아랑", "email": "amitoa@bluesoft.co.kr"},
    {"name": "박화랑", "email": "phr@bluesoft.co.kr"},
    {"name": "유병문", "email": "bnmmnbhj@bluesoft.co.kr"},
    {"name": "유승인", "email": "siyu@bluesoft.co.kr"},
    {"name": "이한재", "email": "hjlee@bluesoft.co.kr"},
    {"name": "이준영", "email": "jun0@bluesoft.co.kr"},
]
EMAIL_TO_NAME = {m["email"]: m["name"] for m in MEMBERS}

# 관리자 명단을 화면에서 바꿀 수 있게 한 뒤에도, 이 사람들만은 코드에 고정한다.
# DB 가 비거나 잘못 저장돼도 관리자 없는 상태로 잠기지 않게 하는 안전장치다.
OWNER_EMAILS = ["kimhy@bluesoft.co.kr", "venus@bluesoft.co.kr"]
OWNERS = frozenset(e.lower() for e in OWNER_EMAILS)

# 최초 기동 때 DB 에 심을 초기 명단. 그 뒤로는 DB 가 사실상의 원본이다.
ADMINS = ["jian@bluesoft.co.kr", *OWNER_EMAILS]

_unknown_admins = set(ADMINS) - set(EMAIL_TO_NAME)
if _unknown_admins:
    raise ValueError(f"MEMBERS 에 없는 관리자: {sorted(_unknown_admins)}")


class Settings(BaseSettings):
    model_config = SettingsConfigDict(frozen=True, extra="ignore", case_sensitive=False)

    db_path: str = str(BASE / "var" / "learning.db")
    upload_dir: str = str(BASE / "var" / "uploads")
    max_upload_mb: int = Field(default=50, gt=0)

    sso_secret_path: str = str(BASE.parent / "sso_secret.key")

    admin_emails: Annotated[frozenset[str], NoDecode] = frozenset(ADMINS)
    dev_login: bool = False
    portal_url: str = "/"
    slack_url: str = "/slack/lists.php"
    slack_webhook: str | None = Field(default=None, validation_alias="SLACK_WEBHOOK_URL")

    index_path: str = str(BASE / "web" / "index.html")
    styles_dir: str = str(BASE / "web" / "styles")
    static_dir: str = str(BASE / "web" / "static")
    shared_styles_dir: str = str(BASE.parent / "styles")

    @field_validator("admin_emails", mode="before")
    @classmethod
    def _emails(cls, v):
        items = v.split(",") if isinstance(v, str) else list(v)
        return frozenset(str(e).strip().lower() for e in items if str(e).strip())

    @field_validator("slack_webhook", mode="after")
    @classmethod
    def _webhook_from_config_local(cls, v):
        if v:
            return v
        try:
            from config_local import SLACK_WEBHOOK_URL  # noqa: PLC0415
        except ImportError:
            return None
        return SLACK_WEBHOOK_URL or None

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024
