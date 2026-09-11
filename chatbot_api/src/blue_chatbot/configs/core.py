from pathlib import Path
from typing import Literal

from pydantic import SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class CoreConfig(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Claude 환경변수
    anthropic_api_key: SecretStr | None = None

    faq_path: Path = Path("data/faq.yaml")

    claude_model: str = "claude-sonnet-5"
    max_tokens: int = 16000
    effort: Literal["low", "medium", "high", "xhigh", "max"] = "low"


config = CoreConfig()
