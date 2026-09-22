"""요청 임베딩 색인 명령.

EMBEDDING_MODEL이 가리키는 모델로 색인한다. 다른 모델로 돌리려면 그 값을 바꿔 준다.

  python -m blue_chatbot.cli.indexing
"""

import logging

from blue_chatbot.configs.core import config
from blue_chatbot.embedders import build_embedder
from blue_chatbot.repositories import db
from blue_chatbot.repositories.workhub import (
    REQUEST_VECTOR_TABLES,
    RequestRepository,
    RequestVectorRepository,
)
from blue_chatbot.services.indexing import index_requests, sync_metadata


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s")
    if config.iwork_db_url is None:
        raise SystemExit("IWORK_DB_URL이 없습니다. 색인이 읽을 원본 데이터베이스를 지정하세요.")

    if config.embedding_model not in REQUEST_VECTOR_TABLES:
        raise SystemExit(f"{config.embedding_model} 모델의 벡터 테이블이 없습니다.")

    embedder = build_embedder(config.embedding_model)
    source_engine = db.build_iwork_engine()
    requests = RequestRepository(source_engine)
    vectors = RequestVectorRepository(db.engine)
    model_type = REQUEST_VECTOR_TABLES[config.embedding_model]
    try:
        index_requests(requests, vectors, embedder, model_type)
        sync_metadata(requests, vectors, model_type)
    finally:
        source_engine.dispose()


if __name__ == "__main__":
    main()
