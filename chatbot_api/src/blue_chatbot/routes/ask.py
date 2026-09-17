import logging  
from typing import Annotated

import anthropic
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, StringConstraints

from blue_chatbot.routes.dependencies import get_client, get_faq
from blue_chatbot.services import ask
from blue_chatbot.services.faq import FaqEntry

logger = logging.getLogger(__name__)

router = APIRouter()


class AskRequest(BaseModel):
    question: Annotated[str, StringConstraints(strip_whitespace=True, min_length=1)]


@router.post("/ask")
def post_ask(
    ask_request: AskRequest,
    entries: list[FaqEntry] = Depends(get_faq),
    client: anthropic.Anthropic = Depends(get_client),
) -> ask.Answer:
    try:
        return ask.answer(client, entries, ask_request.question)
    except anthropic.RateLimitError as exc:
        retry_after = exc.response.headers.get("retry-after", "60")
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="요청이 많습니다. 잠시 후 다시 시도해 주세요.",
            headers={"retry-after": retry_after},
        ) from exc
    except (anthropic.APIConnectionError, anthropic.APITimeoutError) as exc:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="일시적으로 응답할 수 없습니다.",
        ) from exc
    except anthropic.AuthenticationError as exc:
        logger.error("Anthropic 인증 실패", exc_info=exc)
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR) from exc
    except anthropic.APIStatusError as exc:
        if exc.status_code >= 500:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail="서버 오류입니다.",
            ) from exc
        logger.error("Anthropic 요청 오류: %s", exc, exc_info=exc)
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR) from exc
