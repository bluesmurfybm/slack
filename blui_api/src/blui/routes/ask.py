import logging
from typing import Annotated

from fastapi import APIRouter, Cookie, Depends, HTTPException, Response, status
from pydantic import BaseModel, StringConstraints

from blui.answer import Answer
from blui.configs import config
from blui.routes.dependencies import get_conversation_service, set_conversation_cookie
from common.llm.client import (
    LLMClientRateLimitError,
    LLMClientRequestError,
    LLMClientUnreachableError,
    LLMClientVendorError,
)
from common.services.conversation import (
    ConversationLimitError,
    ConversationNotFoundError,
    ConversationService,
)

NO_CONVERSATION = "대화가 없습니다. POST /conversations로 대화를 시작하세요."

logger = logging.getLogger(__name__)

router = APIRouter()


class AskRequest(BaseModel):
    question: Annotated[
        str,
        StringConstraints(
            strip_whitespace=True, min_length=1, max_length=config.max_question_length
        ),
    ]


class AskResponse(BaseModel):
    content: str
    matched_ids: list[str]


@router.post("/ask")
def post_ask(
    ask_request: AskRequest,
    response: Response,
    conversation_key: Annotated[str | None, Cookie()] = None,
    service: ConversationService[Answer] = Depends(get_conversation_service),
) -> AskResponse:
    if conversation_key is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=NO_CONVERSATION)
    try:
        answer = service.send_message(conversation_key, ask_request.question)
    except ConversationNotFoundError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=NO_CONVERSATION) from exc
    except ConversationLimitError as exc:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="이 대화에서 보낼 수 있는 메시지 수를 넘었습니다. 새 대화를 시작하세요.",
        ) from exc
    except LLMClientRateLimitError as exc:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="요청이 많습니다. 잠시 후 다시 시도해 주세요.",
            headers={"retry-after": str(exc.retry_after)},
        ) from exc
    except LLMClientUnreachableError as exc:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="일시적으로 응답할 수 없습니다.",
        ) from exc
    except LLMClientVendorError as exc:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="서버 오류입니다.",
        ) from exc
    except LLMClientRequestError as exc:
        logger.error("LLM 호출 실패", exc_info=exc)
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR) from exc

    set_conversation_cookie(response, conversation_key)
    return AskResponse(content=answer.content, matched_ids=answer.matched_ids)
