from fastapi import APIRouter, Depends

from blui.routes.dependencies import get_knowledge
from common.knowledge import KnowledgeEntry

router = APIRouter()


@router.get("/health")
def health(entries: list[KnowledgeEntry] = Depends(get_knowledge)) -> dict[str, object]:
    return {"status": "ok", "knowledge_count": len(entries)}
