from functools import partial

from fastapi import Depends, Request, Response
from langchain_core.language_models import BaseChatModel

from blui import agents, answer, classifier, db, guard, llm
from blui.agents import Agent
from blui.configs import config
from blui.tools.handoff import HandoffTool
from common.knowledge import KnowledgeEntry
from common.repositories.conversation import (
    ConversationMessageRepository,
    ConversationRepository,
)
from common.services.conversation import ConversationService

COOKIE_NAME = "conversation_key"

_model: BaseChatModel | None = None
_light_model: BaseChatModel | None = None


def get_knowledge(request: Request) -> list[KnowledgeEntry]:
    knowledge: list[KnowledgeEntry] = request.app.state.knowledge
    return knowledge


def get_model() -> BaseChatModel:
    global _model
    if _model is None:
        _model = llm.build_model(config)
    return _model


def get_light_model() -> BaseChatModel:
    global _light_model
    if _light_model is None:
        _light_model = llm.build_light_model(config)
    return _light_model


def get_guard(light_model: BaseChatModel = Depends(get_light_model)) -> Agent:
    return guard.build(light_model)


def get_classifier(light_model: BaseChatModel = Depends(get_light_model)) -> Agent:
    return classifier.build(light_model)


def get_generator(model: BaseChatModel = Depends(get_model)) -> Agent:
    return agents.build(model, answer.LLMOutput)


def get_handoff_tool() -> HandoffTool:
    return HandoffTool(config.request_url)


def get_conversation_repository() -> ConversationRepository:
    return ConversationRepository(db.engine)


def get_message_repository() -> ConversationMessageRepository:
    return ConversationMessageRepository(db.engine)


def get_conversation_service(
    conversation_repository: ConversationRepository = Depends(get_conversation_repository),
    message_repository: ConversationMessageRepository = Depends(get_message_repository),
    guard: Agent = Depends(get_guard),
    classifier: Agent = Depends(get_classifier),
    generator: Agent = Depends(get_generator),
    knowledge: list[KnowledgeEntry] = Depends(get_knowledge),
    handoff: HandoffTool = Depends(get_handoff_tool),
) -> ConversationService[answer.Answer]:
    graph = answer.build(
        guard,
        classifier,
        generator,
        knowledge,
        config.inquiry_url,
        config.request_url,
        handoff,
    )
    return ConversationService(
        conversation_repository,
        message_repository,
        partial(answer.answer, graph),
        config.max_user_messages,
    )


def set_conversation_cookie(response: Response, key: str) -> None:
    response.set_cookie(COOKIE_NAME, key, httponly=True, samesite="lax")
