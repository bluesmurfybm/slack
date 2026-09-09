import json
import shutil
import subprocess
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

BASE = Path(__file__).resolve().parent.parent # moodle/watch
PORTAL_ROOT = BASE.parent.parent # 저장소 루트(= 포털 문서루트)

PAG_COURSE_ID = 17257
PAG_COURSE_URL = f"https://moodle.org/course/view.php?id={PAG_COURSE_ID}"

# 코스모스 관점에서 직접 영향이 큰 주제. 트래커·포럼·커밋을 이 낱말로 걸러 "주목" 표시를 붙인다.
FOCUS_KEYWORDS = [
    "react", "esm", "composer", "oauth", "rest api", "web service", "webservice",
    "opentelemetry", "observability", "deprecat", "removal", "removed", "legacy",
    "technical debt", "mustache", "amd", "requirejs", "yui", "php 8", "php8",
    "psr", "psr-", "di container", "dependency injection", "hook", "callback",
    "core_courseformat", "theme", "bootstrap 5", "lts", "5.3", "roadmap",
]

# moodle.org PAG 코스의 모듈(cmid). 기획 문서 1-1 표를 옮긴 것이다.
PAG_FORUMS = {
    8863: "PAG Announcements",
    8866: "PAG General Discussion",
    8952: "React Discussions",
    8950: "Composer Discussion",
    8897: "REST API Discussion",
    8971: "REST API Discussion 2",
}
PAG_PAGES = [8864, 8869, 8876, 8873, 8955, 8922, 8923, 8947, 8917, 8878, 8883, 8896]
PAG_BOOKS = [8868, 8867]


class Settings(BaseSettings):
    # 환경변수가 우선이고, 없으면 moodle/watch/.env 를 읽는다(.env.example 참고, git 제외).
    # 서버는 systemd Environment= 로 주고, 로컬은 .env 에 적어 두면 --serve 와 CLI 가 같이 본다.
    model_config = SettingsConfigDict(frozen=True, extra="ignore", case_sensitive=False,
                                      env_file=str(BASE / ".env"), env_file_encoding="utf-8")

    data_dir: str = str(BASE / "var")

    # 수집 대상 인증. 없으면 그 소스만 건너뛴다.
    moodle_org_token: str | None = None
    github_token: str | None = None

    # 요약. anthropic → claude CLI → 요약 없음 순으로 시도한다.
    anthropic_api_key: str | None = None
    anthropic_model: str = "claude-opus-5"
    summary_effort: str = "high"
    claude_cli: str = "claude"
    summarizer: str = "auto" # auto | anthropic | cli | none

    slack_webhook: str | None = Field(default=None, validation_alias="SLACK_WEBHOOK_URL")
    portal_url: str = "/"

    # MySQL. 비어 있으면 포털 config.php 를 php CLI 로 읽어 채운다(_db_from_php).
    db_host: str | None = None
    db_port: int = 3306
    db_user: str | None = None
    db_pass: str | None = None
    db_name: str | None = None
    php_bin: str = "php"

    lookback_days: int = 7
    max_lookback_days: int = 21
    http_timeout: float = 30.0
    user_agent: str = "BluesoftMoodleWatch/1.0 (+https://bluesoft.co.kr; weekly read-only digest)"

    tracker_max_pages: int = 6
    github_max_pages: int = 5

    @property
    def data_path(self) -> Path:
        return Path(self.data_dir)

    def db_params(self) -> dict:
        if self.db_host and self.db_user and self.db_name:
            return {"host": self.db_host, "port": self.db_port, "user": self.db_user,
                    "password": self.db_pass or "", "database": self.db_name}
        return _db_from_php(self.php_bin)


def _db_from_php(php_bin: str) -> dict:
    """DB 접속 정보는 포털 config.php 한 곳에만 둔다 — php CLI 로 그 배열을 그대로 읽는다."""
    exe = shutil.which(php_bin)
    if not exe:
        raise RuntimeError("DB 설정이 없고 php CLI 도 없습니다. DB_HOST/DB_USER/DB_NAME 을 주세요")
    code = 'echo json_encode((require $argv[1])["db"]);'
    out = subprocess.run( # noqa: S603 인자는 우리가 정한 상수와 저장소 안의 파일 경로다
        [exe, "-r", code, str(PORTAL_ROOT / "config.php")],
        capture_output=True, text=True, check=True, cwd=str(PORTAL_ROOT), timeout=20)
    d = json.loads(out.stdout)
    return {"host": d["host"], "port": int(d.get("port") or 3306), "user": d["user"],
            "password": d.get("pass") or "", "database": d["name"]}
