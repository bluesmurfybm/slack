from fastapi import APIRouter, Depends, Response
from pydantic import BaseModel

from blui.answer import Answer
from blui.routes.dependencies import get_conversation_service, set_conversation_cookie
from common.services.conversation import ConversationService

router = APIRouter()


class ConversationResponse(BaseModel):
    key: str


@router.post("/conversations")
def post_conversation(
    response: Response,
    service: ConversationService[Answer] = Depends(get_conversation_service),
) -> ConversationResponse:
    """쿠키와 무관하게 새 대화를 만들어 쿠키를 발급합니다."""
    conversation = service.start_conversation()
    set_conversation_cookie(response, conversation.key)
    response.headers["Cache-Control"] = "no-store"
    return ConversationResponse(key=conversation.key)
