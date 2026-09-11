from fastapi import APIRouter, Depends

from blue_chatbot.routes.dependencies import get_faq
from blue_chatbot.services.faq import FaqEntry

router = APIRouter()


@router.get("/health")
def health(entries: list[FaqEntry] = Depends(get_faq)) -> dict:
    return {"status": "ok", "faq_count": len(entries)}
