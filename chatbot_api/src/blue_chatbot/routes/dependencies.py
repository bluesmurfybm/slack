import anthropic
from fastapi import Request

from blue_chatbot.configs.core import config
from blue_chatbot.services.faq import FaqEntry

_client: anthropic.Anthropic | None = None


def get_faq(request: Request) -> list[FaqEntry]:
    return request.app.state.faq


def get_client() -> anthropic.Anthropic:
    global _client
    if _client is None:
        key = config.anthropic_api_key
        _client = anthropic.Anthropic(
            api_key=key.get_secret_value() if key else None
        )
    return _client
