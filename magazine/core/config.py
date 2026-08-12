import os
from dataclasses import dataclass
from typing import FrozenSet, Optional

# core/ 의 한 단계 위가 프로젝트 루트다.
BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 발표자 지정 드롭다운이 쓰는 구성원 명단.
# 이 앱은 포털 DB(MySQL)를 보지 않고 SSO 쿠키만 검증하므로 명단을 여기 둔다.
# 입·퇴사가 있으면 이 목록을 고친다.
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

# 개발 로그인 계정 전환 바에 띄울 표본. 명단에서 골라 쓴다.
_DEV_EMAILS = ["jian@bluesoft.co.kr", "siyu@bluesoft.co.kr",
               "pink@bluesoft.co.kr", "hjlee@bluesoft.co.kr"]
DEV_ACCOUNTS = [
    {"email": e, "name": EMAIL_TO_NAME[e],
     "role": "관리자" if e == "jian@bluesoft.co.kr" else "일반"}
    for e in _DEV_EMAILS
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
        # 환경변수는 여기서만 읽는다. 다른 모듈은 Settings 를 주입받아 쓴다.
        # 그래야 테스트가 모듈 reload 없이 설정만 갈아끼울 수 있다.
        return cls(
            db_path=os.environ.get("DB_PATH", os.path.join(BASE, "var", "magazine.db")),
            index_path=os.path.join(BASE, "web", "index.html"),
            seed_path=os.path.join(BASE, "data", "seed.json"),
            styles_dir=os.path.join(BASE, "web", "styles"),
            static_dir=os.path.join(BASE, "web", "static"),
            # 업로드 파일. 도커에서는 DB 와 같은 볼륨(/app/data)에 둔다.
            upload_dir=os.environ.get("UPLOAD_DIR", os.path.join(BASE, "var", "uploads")),
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
