from typing import Any

from fastapi import Depends, Response
from sqlalchemy import Engine

from blue_chatbot.configs.core import config
from blue_chatbot.embedders import build_embedder
from blue_chatbot.llm import anthropic_llm
from blue_chatbot.repositories import db
from blue_chatbot.repositories.conversation import (
    ConversationMessageRepository,
    ConversationRepository,
)
from blue_chatbot.repositories.workhub import (
    REQUEST_VECTOR_TABLES,
    RequestRepository,
    RequestVectorRepository,
)
from blue_chatbot.services import faq
from blue_chatbot.services.conversation import ConversationService
from blue_chatbot.services.embedder import EmbedderClient
from blue_chatbot.services.faq import FaqEntry
from blue_chatbot.services.llm import LLMClient
from blue_chatbot.tools.base import Tool
from blue_chatbot.tools.search_requests import SearchRequestsTool

_llm_client: LLMClient | None = None
_iwork_engine: Engine | None = None
_embedder: EmbedderClient | None = None


def get_faq() -> list[FaqEntry]:
    return faq.entries


def get_llm_client() -> LLMClient:
    global _llm_client
    if _llm_client is None:
        _llm_client = anthropic_llm.build_from_config()
    return _llm_client


def get_iwork_db_engine() -> Engine:
    global _iwork_engine
    if _iwork_engine is None:
        _iwork_engine = db.build_iwork_engine()
    return _iwork_engine


def get_embedder() -> EmbedderClient:
    global _embedder
    if _embedder is None:
        _embedder = build_embedder(config.embedding_model)
    return _embedder


def get_request_repository(
    iwork_db_engine: Engine = Depends(get_iwork_db_engine),
) -> RequestRepository:
    return RequestRepository(iwork_db_engine)


def get_request_vector_repository() -> RequestVectorRepository:
    return RequestVectorRepository(db.engine)


def get_search_requests_tool(
    vectors: RequestVectorRepository = Depends(get_request_vector_repository),
    requests: RequestRepository = Depends(get_request_repository),
    embedder: EmbedderClient = Depends(get_embedder),
) -> SearchRequestsTool:
    return SearchRequestsTool(
        vectors,
        requests,
        embedder,
        REQUEST_VECTOR_TABLES[config.embedding_model],
    )


def get_tools(
    search_requests: SearchRequestsTool = Depends(get_search_requests_tool),
) -> list[Tool[Any]]:
    return [search_requests]


def get_conversation_repository() -> ConversationRepository:
    return ConversationRepository(db.engine)


def get_message_repository() -> ConversationMessageRepository:
    return ConversationMessageRepository(db.engine)


def get_conversation_service(
    conversation_repository: ConversationRepository = Depends(get_conversation_repository),
    message_repository: ConversationMessageRepository = Depends(get_message_repository),
    llm_client: LLMClient = Depends(get_llm_client),
    faqs: list[FaqEntry] = Depends(get_faq),
    tools: list[Tool[Any]] = Depends(get_tools),
) -> ConversationService:
    return ConversationService(
        conversation_repository,
        message_repository,
        llm_client,
        faqs,
        tools,
        config.conversation_expires_after,
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
