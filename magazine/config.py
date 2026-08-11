# -*- coding: utf-8 -*-
"""설정 한 곳.

환경변수는 여기서만 읽는다. 다른 모듈은 Settings 를 주입받아 쓴다.
그래야 테스트가 모듈을 reload 하지 않고 설정만 바꿔 앱을 새로 만들 수 있다.
"""
import os
from dataclasses import dataclass
from typing import FrozenSet, Optional

BASE = os.path.dirname(os.path.abspath(__file__))

DEV_ACCOUNTS = [
    {"email": "jian@bluesoft.co.kr", "name": "김지안", "role": "관리자"},
    {"email": "siyu@bluesoft.co.kr", "name": "유승인", "role": "일반"},
    {"email": "pink@bluesoft.co.kr", "name": "김태주", "role": "일반"},
    {"email": "hjlee@bluesoft.co.kr", "name": "이한재", "role": "일반"},
]


@dataclass(frozen=True)
class Settings:
    db_path: str
    index_path: str
    seed_path: str
    styles_dir: str
    static_dir: str
    upload_dir: str
    max_upload_bytes: int
    shared_styles_dir: str
    sso_secret_path: str
    admin_emails: FrozenSet[str]
    dev_login: bool
    portal_url: str
    slack_url: str
    slack_webhook: Optional[str]

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            db_path=os.environ.get("DB_PATH", os.path.join(BASE, "magazine.db")),
            index_path=os.path.join(BASE, "index.html"),
            seed_path=os.path.join(BASE, "seed.json"),
            styles_dir=os.path.join(BASE, "styles"),
            static_dir=os.path.join(BASE, "static"),
            # 업로드 파일. 도커에서는 DB 와 같은 볼륨(/app/data)에 둔다.
            upload_dir=os.environ.get("UPLOAD_DIR", os.path.join(BASE, "data", "uploads")),
            max_upload_bytes=int(os.environ.get("MAX_UPLOAD_MB", "50")) * 1024 * 1024,
            # 포털 공용 스타일. 컨테이너에는 없을 수 있어 앱 조립 시 존재 여부를 본다.
            shared_styles_dir=os.path.join(BASE, "..", "styles"),
            # book/ 과 달리 경로를 환경변수로 뺀다. 개발 환경에는 포털이 만드는
            # 이 파일이 없어 테스트가 불가능하기 때문이다. 운영 기본값은 동일하다.
            sso_secret_path=os.environ.get(
                "SSO_SECRET_PATH", os.path.join(BASE, "..", "sso_secret.key")),
            admin_emails=frozenset(
                e.strip().lower()
                for e in os.environ.get("ADMIN_EMAILS", "jian@bluesoft.co.kr").split(",")
                if e.strip()),
            dev_login=os.environ.get("DEV_LOGIN") == "1",
            portal_url=os.environ.get("PORTAL_URL", "/"),
            slack_url=os.environ.get("SLACK_URL", "/slack/lists.php"),
            slack_webhook=os.environ.get("SLACK_WEBHOOK_URL") or None,
        )
