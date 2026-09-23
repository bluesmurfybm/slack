"""워커 설정. moodle/watch/core/config.py 와 같은 방식(pydantic-settings, .env, DB 는 php -r 로 config.php).

Settings  = 프로세스 시작 시 고정되는 값(.env / 환경변수).
Effective = Settings 위에 ai_settings(화면 토글) 를 덧씌운 런타임 값. 잡마다 새로 만든다.
"""

import json
import shutil
import subprocess
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

BASE = Path(__file__).resolve().parent.parent # slackai/worker
SLACKAI_ROOT = BASE.parent # slackai/
REPO_ROOT = SLACKAI_ROOT.parent # 저장소 루트(= 포털 문서루트, config.php 위치)
KNOWLEDGE_DIR = BASE / "knowledge"


# 플랜 모델 허용 목록 — slackai/ai/ai_lib.php AI_PLAN_MODELS 와 같다(패널 haiku/sonnet/opus)
PLAN_MODELS = ("claude-haiku-4-5", "claude-sonnet-5", "claude-opus-5")

class Settings(BaseSettings):
    model_config = SettingsConfigDict(frozen=True, extra="ignore", case_sensitive=False,
                                      env_file=str(BASE / ".env"), env_file_encoding="utf-8")

    data_dir: str = ""

    # DB. 비어 있으면 포털 config.php 를 php CLI 로 읽어 채운다(_db_from_php). 이름만 slackai_db_name 으로 바꾼다.
    db_host: str | None = None
    db_port: int = 3306
    db_user: str | None = None
    db_pass: str | None = None
    slackai_db_name: str = "slackai_db"
    portal_db_name_override: str = Field(default="", validation_alias="PORTAL_DB_NAME")  # 비우면 config.php db.name
    protected_paths: str = "config.php;local/ubion/config.php"   # AI 가 조회·판단·수정·커밋하지 않는 환경별 설정 파일(; 구분)
    repo_scan_roots: str = r"F:\project;G:\01_Bluesoft"   # discover_repos 가 훑을 루트(; 구분)

    # 외부 실행 파일
    php_bin: str = "php"
    claude_cli: str = "claude"
    codex_cli: str = "codex"
    gemini_cli: str = ""
    svn_bin: str = "svn"
    git_bin: str = "git"
    node_bin: str = "node"

    # Slack
    slack_token: str | None = None
    slack_bot_token: str | None = None
    slack_app_token: str | None = None
    slackai_push: bool = True
    slack_push_comment_channels: str = "C083TU7F0BZ,C08EEFB15EJ"
    slack_webhook: str | None = Field(default=None, validation_alias="SLACK_WEBHOOK_URL")
    portal_url: str = "http://dev.iworks.co.kr"

    # LLM
    anthropic_api_key: str | None = None
    openai_api_key: str | None = None
    claude_use_api_key: bool = False
    claude_code_oauth_token: str | None = None
    llm_model_fast: str = "claude-haiku-4-5"
    llm_model_smart: str = "claude-sonnet-5"
    claude_plan_model: str = "claude-sonnet-5"      # 레포 지식 부트스트랩 등 기타 Claude 조사용
    claude_plan_model_auto: str = "claude-haiku-4-5" # 자동 플랜 기본(ai_settings.plan_model_auto 가 우선)
    claude_exec_model: str = "claude-sonnet-5"
    claude_review_model: str = "claude-opus-5"
    claude_fallback_model: str = ""
    codex_model: str = ""
    openai_model: str = "gpt-5"
    llm_pricing_json: str = ""

    # 예산·타임아웃
    max_budget_plan_usd: float = 3.0
    max_budget_exec_usd: float = 10.0
    max_budget_review_usd: float = 2.0
    max_daily_usd: float = 30.0
    claude_plan_timeout_sec: int = 1200
    claude_exec_timeout_sec: int = 3600
    claude_review_timeout_sec: int = 1200
    llm_timeout_sec: int = 600

    # 루프
    sync_interval_sec: int = 0
    poll_interval_sec: int = 3
    worker_concurrency: int = 1
    heartbeat_sec: int = 20
    worker_heartbeat_sec: int = 30
    stuck_minutes: int = 30

    # 동작 토글
    slackai_auto_plan: bool = True
    slackai_auto_review: bool = False
    slackai_post_to_slack: bool = False
    slackai_update_before_exec: bool = False
    slackai_git_push: bool = False
    reviewer: str = "codex,openai,gemini,claude"
    repo_confidence_min: float = 0.75
    similar_comment_fetch_max: int = 8
    lesson_auto_approve_weight: float = 1.01
    distill_weekly: bool = False

    @property
    def data_path(self) -> Path:
        return Path(self.data_dir) if self.data_dir else BASE / "var"

    @property
    def jobs_path(self) -> Path:
        return self.data_path / "jobs"

    @property
    def push_channels(self) -> list[str]:
        return [c.strip() for c in (self.slack_push_comment_channels or "").split(",") if c.strip()]

    @property
    def protected_list(self) -> list[str]:
        from core import protect
        return protect.parse(self.protected_paths)

    @property
    def slack_cli_token(self) -> str:
        """PHP CLI(sync/comments/post) 에 넘길 토큰: 개인 xoxp 우선, 없으면 봇."""
        return self.slack_token or self.slack_bot_token or ""

    def portal_db_name(self) -> str:
        """포털 DB(slack_db) 이름 — school_access 가 있는 곳. PORTAL_DB_NAME 또는 config.php db.name."""
        if self.portal_db_name_override:
            return self.portal_db_name_override
        exe = shutil.which(self.php_bin) or (self.php_bin if Path(self.php_bin).is_file() else None)
        if not exe:
            return "slack_db"
        out = subprocess.run([exe, "-r", '$c = require $argv[1]; echo $c["db"]["name"];', str(REPO_ROOT / "config.php")],
                             capture_output=True, text=True, cwd=str(REPO_ROOT), timeout=20, check=False)
        return (out.stdout or "").strip() or "slack_db"

    def db_params(self) -> dict:
        if self.db_host and self.db_user:
            return {"host": self.db_host, "port": self.db_port, "user": self.db_user,
                    "password": self.db_pass or "", "database": self.slackai_db_name}
        d = _db_from_php(self.php_bin)
        d["database"] = self.slackai_db_name
        return d


def _db_from_php(php_bin: str) -> dict:
    """DB 접속 정보는 포털 config.php 한 곳에만 둔다 — php CLI 로 그 배열을 그대로 읽는다."""
    exe = shutil.which(php_bin) or (php_bin if Path(php_bin).is_file() else None)
    if not exe:
        raise RuntimeError("DB 설정이 없고 php CLI 도 없습니다. DB_HOST/DB_USER 또는 PHP_BIN 을 주세요")
    code = ('$c = require $argv[1]; echo json_encode(["db" => $c["db"], '
            '"name" => $c["slackai"]["db_name"] ?? "slackai_db"]);')
    out = subprocess.run(
        [exe, "-r", code, str(REPO_ROOT / "config.php")],
        capture_output=True, text=True, check=True, cwd=str(REPO_ROOT), timeout=20)
    j = json.loads(out.stdout)
    d = j["db"]
    return {"host": d["host"], "port": int(d.get("port") or 3306), "user": d["user"],
            "password": d.get("pass") or "", "database": j.get("name") or "slackai_db"}


def _b(v, default: bool) -> bool:
    if v is None or v == "":
        return default
    return str(v).strip().lower() in ("1", "true", "on", "yes")


def _f(v, default: float) -> float:
    try:
        return float(v) if v not in (None, "") else default
    except (TypeError, ValueError):
        return default


def _i(v, default: int) -> int:
    try:
        return int(float(v)) if v not in (None, "") else default
    except (TypeError, ValueError):
        return default


class Effective:
    """Settings + ai_settings(k/v) 오버레이. 화면 토글이 워커 동작을 바꾼다."""

    def __init__(self, settings: Settings, ai_settings: dict[str, str] | None = None):
        self.s = settings
        self.a = dict(ai_settings or {})

    def raw(self, k: str, default=None):
        return self.a.get(k, default)

    @property
    def paused(self) -> bool:
        return _b(self.a.get("paused"), False)

    @property
    def auto_plan(self) -> bool:
        return _b(self.a.get("auto_plan"), self.s.slackai_auto_plan)

    @property
    def auto_review(self) -> bool:
        return _b(self.a.get("auto_review"), self.s.slackai_auto_review)

    @property
    def post_to_slack(self) -> bool:
        return _b(self.a.get("post_to_slack"), self.s.slackai_post_to_slack)

    @property
    def max_daily_usd(self) -> float:
        return _f(self.a.get("max_daily_usd"), self.s.max_daily_usd)

    @property
    def reviewer_chain(self) -> list[str]:
        v = self.a.get("reviewer") or self.s.reviewer
        return [x.strip().lower() for x in str(v).split(",") if x.strip()]

    @property
    def sync_interval_sec(self) -> int:
        return _i(self.a.get("sync_interval_sec"), self.s.sync_interval_sec)

    @property
    def poll_interval_sec(self) -> int:
        return max(1, _i(self.a.get("poll_interval_sec"), self.s.poll_interval_sec))

    @property
    def plan_model_auto(self) -> str:
        """자동 플랜 모델(기본 haiku). 허용 목록 밖이면 기본값."""
        v = str(self.a.get("plan_model_auto") or self.s.claude_plan_model_auto)
        return v if v in PLAN_MODELS else self.s.claude_plan_model_auto

    def plan_model_for(self, requested: str | None) -> str:
        """잡 params.model(사람이 패널에서 고른 모델) → 허용 목록이면 그것, 아니면 자동 플랜 모델."""
        return requested if requested in PLAN_MODELS else self.plan_model_auto

    @property
    def budget_plan_usd(self) -> float:
        return _f(self.a.get("budget_plan_usd"), self.s.max_budget_plan_usd)

    @property
    def budget_execute_usd(self) -> float:
        return _f(self.a.get("budget_execute_usd"), self.s.max_budget_exec_usd)

    @property
    def budget_review_usd(self) -> float:
        return _f(self.a.get("budget_review_usd"), self.s.max_budget_review_usd)

    @property
    def similar_min(self) -> float:
        return _f(self.a.get("similar_min"), 0.15)

    @property
    def similar_limit(self) -> int:
        return max(1, min(100, _i(self.a.get("similar_limit"), 20)))

    @property
    def heartbeat_stale_sec(self) -> int:
        return _i(self.a.get("heartbeat_stale_sec"), 120)

    @property
    def retriage_on_update(self) -> bool:
        return _b(self.a.get("retriage_on_update"), False)
