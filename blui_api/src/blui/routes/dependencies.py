from functools import partial

from fastapi import Depends, Request, Response

from blui import answer, db, guard
from blui.configs import config
from blui.tools.handoff import HandoffTool
from common import guard as common_guard
from common.guard import Guard
from common.knowledge import KnowledgeEntry
from common.llm import anthropic
from common.llm.client import LLMClient
from common.repositories.conversation import (
    ConversationMessageRepository,
    ConversationRepository,
)
from common.services.conversation import ConversationService

COOKIE_NAME = "conversation_key"

_llm_client: LLMClient | None = None
_light_llm_client: LLMClient | None = None


def get_knowledge(request: Request) -> list[KnowledgeEntry]:
    knowledge: list[KnowledgeEntry] = request.app.state.knowledge
    return knowledge


def get_llm_client() -> LLMClient:
    global _llm_client
    if _llm_client is None:
        _llm_client = anthropic.build_from_config(config)
    return _llm_client


def get_light_llm_client() -> LLMClient:
    global _light_llm_client
    if _light_llm_client is None:
        _light_llm_client = anthropic.build(
            config, model=config.claude_light_model, max_tokens=common_guard.MAX_TOKENS, effort=None
        )
    return _light_llm_client


def get_guard(light_llm_client: LLMClient = Depends(get_light_llm_client)) -> Guard:
    return guard.build(light_llm_client)


def get_handoff_tool() -> HandoffTool:
    return HandoffTool(config.request_url)


def get_conversation_repository() -> ConversationRepository:
    return ConversationRepository(db.engine)


def get_message_repository() -> ConversationMessageRepository:
    return ConversationMessageRepository(db.engine)


def get_conversation_service(
    conversation_repository: ConversationRepository = Depends(get_conversation_repository),
    message_repository: ConversationMessageRepository = Depends(get_message_repository),
    llm_client: LLMClient = Depends(get_llm_client),
    guard: Guard = Depends(get_guard),
    knowledge: list[KnowledgeEntry] = Depends(get_knowledge),
    handoff: HandoffTool = Depends(get_handoff_tool),
) -> ConversationService[answer.Answer]:
    return ConversationService(
        conversation_repository,
        message_repository,
        partial(
            answer.answer,
            llm_client,
            guard,
            knowledge,
            config.inquiry_url,
            config.request_url,
            handoff,
        ),
        config.max_user_messages,
    )


def set_conversation_cookie(response: Response, key: str) -> None:
    response.set_cookie(COOKIE_NAME, key, httponly=True, samesite="lax")
