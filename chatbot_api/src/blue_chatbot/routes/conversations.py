from datetime import datetime
from typing import Annotated

from fastapi import APIRouter, Cookie, Depends, Response
from pydantic import BaseModel

from blue_chatbot.messages import Role
from blue_chatbot.routes.dependencies import get_conversation_service, set_conversation_cookie
from blue_chatbot.services.conversation import ConversationService

router = APIRouter()


class MessageResponse(BaseModel):
    role: Role
    content: str
    created_at: datetime


class ConversationResponse(BaseModel):
    key: str
    messages: list[MessageResponse]


@router.get("/conversations")
def get_conversation(
    response: Response,
    conversation_key: Annotated[str | None, Cookie()] = None,
    service: ConversationService = Depends(get_conversation_service),
) -> ConversationResponse:
    """쿠키의 대화와 메시지를 반환한다. 없거나 만료된 대화면 새로 만들어 쿠키를 발급한다."""
    conversation, stored = service.get_conversation(conversation_key)
    set_conversation_cookie(response, conversation.key)
    response.headers["Cache-Control"] = "no-store"
    return ConversationResponse(
        key=conversation.key,
        messages=[
            MessageResponse(role=m.role, content=m.content, created_at=m.created_at) for m in stored
        ],
    )
