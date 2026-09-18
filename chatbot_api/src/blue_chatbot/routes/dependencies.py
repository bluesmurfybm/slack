from fastapi import Depends, Response

from blue_chatbot.llm import anthropic_llm
from blue_chatbot.repositories import db
from blue_chatbot.repositories.conversation import (
    ConversationMessageRepository,
    ConversationRepository,
)
from blue_chatbot.services import faq
from blue_chatbot.configs.core import config
from blue_chatbot.services.conversation import ConversationService
from blue_chatbot.services.faq import FaqEntry
from blue_chatbot.services.llm import LLMClient

_llm_client: LLMClient | None = None


def get_faq() -> list[FaqEntry]:
    return faq.entries


def get_llm_client() -> LLMClient:
    """구현체를 고르는 유일한 자리. 제공자를 바꾸면 이 한 줄만 바뀐다."""
    global _llm_client
    if _llm_client is None:
        _llm_client = anthropic_llm.build_from_config()
    return _llm_client


def get_conversation_repository() -> ConversationRepository:
    return ConversationRepository(db.engine)


def get_message_repository() -> ConversationMessageRepository:
    return ConversationMessageRepository(db.engine)


def get_conversation_service(
    conversation_repository: ConversationRepository = Depends(get_conversation_repository),
    message_repository: ConversationMessageRepository = Depends(get_message_repository),
    llm_client: LLMClient = Depends(get_llm_client),
    faqs: list[FaqEntry] = Depends(get_faq),
) -> ConversationService:
    return ConversationService(
        conversation_repository, message_repository, llm_client, faqs, config.conversation_expires_after
    )


COOKIE_NAME = "conversation_key"


def set_conversation_cookie(response: Response, key: str) -> None:
    response.set_cookie(
        COOKIE_NAME,
        key,
        max_age=int(config.conversation_expires_after.total_seconds()),
        httponly=True,
        samesite="lax",
    )
