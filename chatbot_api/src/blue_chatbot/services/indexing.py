import logging
from collections.abc import Callable, Iterator
from datetime import datetime

from blue_chatbot.repositories.workhub import (
    RequestRead,
    RequestRepository,
    RequestVector,
    RequestVectorRepository,
)
from blue_chatbot.services.embedder import EmbedderClient
from blue_chatbot.support import utc_now

logger = logging.getLogger(__name__)

BODY_LIMIT = 2000


def embedding_text(request: RequestRead) -> str:
    """임베딩할 문장. 제목과 본문 앞부분을 합친다."""
    if not request.body:
        return request.title
    return f"{request.title}\n\n{request.body[:BODY_LIMIT]}"


def chunked(items: list[RequestRead], size: int) -> Iterator[list[RequestRead]]:
    for start in range(0, len(items), size):
        yield items[start : start + size]


def index_requests(
    requests: RequestRepository,
    vectors: RequestVectorRepository,
    embedder: EmbedderClient,
    model_type: type[RequestVector],
    *,
    batch_size: int = 100,
    now: Callable[[], datetime] = utc_now,
) -> int:
    """바뀐 요청을 임베딩해 저장하고 저장한 건수를 돌려준다."""
    watermark = vectors.find_watermark(model_type)
    changed = requests.find_changed_since(watermark)
    logger.info("워터마크 %d 이후 요청 %d건을 확인한다", watermark, len(changed))

    saved = 0
    for chunk in chunked(changed, batch_size):
        indexed = vectors.find_by_request_ids(model_type, [request.id for request in chunk])
        pending = [request for request in chunk if _needs_embedding(request, indexed)]
        if not pending:
            continue

        embeddings = embedder.embed([embedding_text(request) for request in pending])
        for request, embedding in zip(pending, embeddings, strict=True):
            vectors.save(_to_entity(model_type, request, embedding, indexed, now))
            saved += 1

    logger.info("%s 모델로 요청 %d건을 저장했다", embedder.name, saved)
    return saved


def _needs_embedding(request: RequestRead, indexed: dict[str, RequestVector]) -> bool:
    """임베딩이 필요한지 확인"""
    entity = indexed.get(request.id)
    return entity is None or entity.source_updated != request.updated


def _to_entity(
    model_type: type[RequestVector],
    request: RequestRead,
    embedding: list[float],
    indexed: dict[str, RequestVector],
    now: Callable[[], datetime],
) -> RequestVector:
    entity = indexed.get(request.id)
    if entity is None:
        return model_type(
            request_id=request.id,
            source_updated=request.updated,
            status=request.status,
            team=request.team,
            archived=request.archived,
            embedding=embedding,
            created_at=now(),
        )
    entity.embedding = embedding
    entity.source_updated = request.updated
    entity.status = request.status
    entity.team = request.team
    entity.archived = request.archived
    return entity


def sync_metadata(
    requests: RequestRepository,
    vectors: RequestVectorRepository,
    model_type: type[RequestVector],
) -> int:
    indexed = vectors.find_metadata(model_type)
    changed = 0
    for request in requests.find_changed_since(0):
        current = indexed.get(request.id)
        if current is None or current == (request.status, request.team, request.archived):
            continue
        vectors.update_metadata(
            model_type, request.id, request.status, request.team, request.archived
        )
        changed += 1
    logger.info("메타데이터 갱신 %d건", changed)
    return changed
