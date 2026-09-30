from typing import Literal

from pydantic import SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

Effort = Literal["low", "medium", "high", "xhigh", "max"]


class CommonConfig(BaseSettings):
    """두 앱이 같이 쓰는 설정입니다. 각 앱의 설정이 이를 상속해 자기 필드를 더합니다."""

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Claude
    anthropic_api_key: SecretStr | None = None
    claude_model: str = "claude-sonnet-5"
    claude_light_model: str = "claude-haiku-4-5"
    max_tokens: int = 16000
    # Sonnet 4.5나 Haiku 4.5처럼 effort를 아예 받지 않는 모델이 있습니다.
    # 그런 모델로 바꿀 때는 비워 두면 파라미터를 보내지 않습니다.
    effort: Effort | None = "low"

    # 임베딩
    openai_api_key: SecretStr | None = None
    embedding_model: str = "openai-3-small"

    # DB
    database_url: SecretStr

    @field_validator("effort", "openai_api_key", mode="before")
    @classmethod
    def _empty_means_unset(cls, value: object) -> object:
        """EFFORT= 처럼 빈 값을 주면 설정하지 않은 것으로 읽습니다."""
        return None if value == "" else value
