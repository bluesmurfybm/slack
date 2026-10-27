"""배치 설정. 환경변수가 우선이고, 없으면 onwatch/worker/.env 를 읽는다(.env.example 참고, git 제외).

서버는 systemd 유닛의 Environment= 로 주고, 로컬은 .env 에 적어 두면 CLI 와 --serve 가 같이 본다.
DB 접속 정보는 포털 config.php 한 곳에만 둔다 — 비어 있으면 php CLI 로 그 배열을 읽는다
(moodle/watch/core/config.py 와 같은 방식).
"""

import json
import shutil
import subprocess
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

BASE = Path(__file__).resolve().parent.parent # onwatch/worker
MODULE_ROOT = BASE.parent # onwatch
PORTAL_ROOT = MODULE_ROOT.parent # 저장소 루트(= 포털 문서루트)

# 1M 토큰당 USD (입력, 출력, 캐시 읽기). 모르는 모델이면 Opus 값으로 친다 — 적게 잡는 것보다 낫다.
PRICES = {
    "claude-opus-5-5": (4.0, 20.0, 0.20),
    "claude-opus-5": (5.0, 25.0, 0.50),
    "claude-sonnet-5-5": (2.0, 10.0, 0.20),
    "claude-haiku-5-5": (0.10, 0.50, 0.01),
}


class Settings(BaseSettings):
    model_config = SettingsConfigDict(frozen=True, extra="ignore", case_sensitive=False, populate_by_name=True,
                                      env_file=str(BASE / ".env"), env_file_encoding="utf-8")

    # 엔진. auto 면 API 키가 있으면 anthropic, 없으면 claude CLI.
    engine: str = Field(default="auto", validation_alias="ONWATCH_ENGINE") # auto | anthropic | cli
    anthropic_api_key: str | None = None
    model: str = Field(default="claude-opus-5-5", validation_alias="ONWATCH_MODEL")
    research_effort: str = Field(default="high", validation_alias="ONWATCH_RESEARCH_EFFORT")
    judge_effort: str = Field(default="medium", validation_alias="ONWATCH_JUDGE_EFFORT")
    claude_cli: str = "claude"
    cli_timeout: int = 1500 # 초. 조사 한 번이 이보다 길면 실패로 본다

    # 비용
    web_search_usd_per_1k: float = 10.0
    monthly_budget_usd: float = Field(default=100.0, validation_alias="ONWATCH_MONTHLY_BUDGET_USD")

    # 알림
    slack_webhook: str | None = Field(default=None, validation_alias="ONWATCH_SLACK_WEBHOOK_URL")
    portal_url: str = "/" # 슬랙 메시지의 링크 앞부분. 예: https://iworks.example.com/
    php_bin: str = "php"

    # 실행
    max_runs_per_tick: int = 5 # 한 번 깨어날 때 최대 몇 건
    lock_minutes: int = 40 # 실행 중 표시 유지 시간. 이보다 오래 걸리면 다른 배치가 다시 집는다
    data_dir: str = str(BASE / "var")

    # MySQL. 비어 있으면 포털 config.php 를 php CLI 로 읽어 채운다.
    db_host: str | None = None
    db_port: int = 3306
    db_user: str | None = None
    db_pass: str | None = None
    db_name: str | None = None

    @property
    def data_path(self) -> Path:
        return Path(self.data_dir)

    def db_params(self) -> dict:
        if self.db_host and self.db_user and self.db_name:
            return {"host": self.db_host, "port": self.db_port, "user": self.db_user,
                    "password": self.db_pass or "", "database": self.db_name}
        return _db_from_php(self.php_bin)

    def price(self, model: str) -> tuple[float, float, float]:
        for key, p in PRICES.items():
            if model.startswith(key):
                return p
        return PRICES["claude-opus-5-5"]


def _db_from_php(php_bin: str) -> dict:
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
