from pathlib import Path
from typing import Literal

from pydantic import SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class CoreConfig(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # SDK가 환경변수와 ant auth login 프로필을 직접 읽으므로 필수가 아니다.
    # 여기 두는 것은 이 앱이 무엇을 필요로 하는지 설정 한곳에서 보이게 하려는 것이다.
    anthropic_api_key: SecretStr | None = None

    faq_path: Path = Path("data/faq.yaml")

    claude_model: str = "claude-sonnet-5"
    max_tokens: int = 16000
    effort: Literal["low", "medium", "high", "xhigh", "max"] = "low"


config = CoreConfig()
