from datetime import timedelta
from pathlib import Path
from typing import Literal

from pydantic import SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class CoreConfig(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Claude
    anthropic_api_key: SecretStr | None = None
    claude_model: str = "claude-sonnet-5"
    max_tokens: int = 16000
    # Sonnet 4.5나 Haiku 4.5처럼 effort를 아예 받지 않는 모델이 있다.
    # 그런 모델로 바꿀 때는 비워 두면 파라미터를 보내지 않는다.
    effort: Literal["low", "medium", "high", "xhigh", "max"] | None = "low"

    # 임베딩
    openai_api_key: SecretStr | None = None
    embedding_model: str = "openai-3-small"

    # FAQ
    faq_dir: Path = Path("data/faq")

    # DB
    database_url: SecretStr  # chatbot 전용 DB
    iwork_db_url: SecretStr | None = None  # iworks 통합 DB

    # 대화
    conversation_expires_after: timedelta = timedelta(minutes=30)

    @field_validator("effort", "iwork_db_url", "openai_api_key", mode="before")
    @classmethod
    def _empty_means_unset(cls, value: object) -> object:
        """EFFORT= 처럼 빈 값을 주면 설정하지 않은 것으로 읽는다."""
        return None if value == "" else value


config = CoreConfig()
