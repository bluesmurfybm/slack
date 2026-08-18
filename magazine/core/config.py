import os
from typing import Annotated, FrozenSet, Optional

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

MEMBERS = [
    {"name": "김호영", "email": "kimhy@bluesoft.co.kr", "teams": ["App", "SQUARE", "LAB"]},
    {"name": "김지안", "email": "jian@bluesoft.co.kr", "teams": ["SQUARE"]},
    {"name": "박성철", "email": "scpark@bluesoft.co.kr", "teams": ["App"]},
    {"name": "김태주", "email": "pink@bluesoft.co.kr", "teams": ["LAB"]},
    {"name": "안정민", "email": "venus@bluesoft.co.kr", "teams": ["App"]},
    {"name": "조성훈", "email": "akddd@bluesoft.co.kr", "teams": ["App"]},
    {"name": "진소현", "email": "lenda83@bluesoft.co.kr", "teams": ["App", "LAB"]},
    {"name": "김아랑", "email": "amitoa@bluesoft.co.kr", "teams": ["App"]},
    {"name": "박화랑", "email": "phr@bluesoft.co.kr", "teams": ["App"]},
    {"name": "유병문", "email": "bnmmnbhj@bluesoft.co.kr", "teams": ["App"]},
    {"name": "유승인", "email": "siyu@bluesoft.co.kr", "teams": ["App"]},
    {"name": "이한재", "email": "hjlee@bluesoft.co.kr", "teams": ["App"]},
    {"name": "이준영", "email": "jun0@bluesoft.co.kr", "teams": ["App"]},
]
EMAIL_TO_NAME = {m["email"]: m["name"] for m in MEMBERS}
EMAIL_TO_TEAMS = {m["email"]: m["teams"] for m in MEMBERS}

_DEV_EMAILS = ["jian@bluesoft.co.kr", "kimhy@bluesoft.co.kr", "siyu@bluesoft.co.kr",
               "pink@bluesoft.co.kr", "hjlee@bluesoft.co.kr"]
DEV_ACCOUNTS = [
    {"email": e, "name": EMAIL_TO_NAME[e],
     "role": "관리자" if e == "jian@bluesoft.co.kr" else "일반"}
    for e in _DEV_EMAILS
]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(frozen=True, extra="ignore", case_sensitive=False)

    db_path: str = os.path.join(BASE, "var", "magazine.db")
    upload_dir: str = os.path.join(BASE, "var", "uploads")
    max_upload_mb: int = Field(default=50, gt=0)

    sso_secret_path: str = os.path.join(BASE, "..", "sso_secret.key")

    admin_emails: Annotated[FrozenSet[str], NoDecode] = frozenset({"jian@bluesoft.co.kr", "kimhy@bluesoft.co.kr"})
    dev_login: bool = False
    portal_url: str = "/"
    slack_url: str = "/slack/lists.php"
    slack_webhook: Optional[str] = Field(default=None, validation_alias="SLACK_WEBHOOK_URL")

    index_path: str = os.path.join(BASE, "web", "index.html")
    seed_path: str = os.path.join(BASE, "data", "seed.json")
    styles_dir: str = os.path.join(BASE, "web", "styles")
    static_dir: str = os.path.join(BASE, "web", "static")
    shared_styles_dir: str = os.path.join(BASE, "..", "styles")

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
            from config_local import SLACK_WEBHOOK_URL
        except ImportError:
            return None
        return SLACK_WEBHOOK_URL or None

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024
