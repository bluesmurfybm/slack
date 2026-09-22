"""검색 품질 측정 명령.

이미 색인된 벡터를 질문으로 그대로 쓴다. 임베딩 API를 부르지 않으므로 과금이 없다.
EMBEDDING_MODEL을 바꿔 다시 색인한 뒤 이 명령을 돌리면 모델끼리 같은 잣대로 비교된다.

  python -m blue_chatbot.cli.evaluation
"""

import logging
import time

from blue_chatbot.configs.core import config
from blue_chatbot.repositories import db
from blue_chatbot.repositories.workhub import (
    REQUEST_VECTOR_TABLES,
    RequestRepository,
    RequestVectorRepository,
)
from blue_chatbot.services.evaluation import answer_groups, evaluate

K = 5


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s")
    if config.iwork_db_url is None:
        raise SystemExit("IWORK_DB_URL이 없습니다. 정답을 만들 원본 데이터베이스를 지정하세요.")

    if config.embedding_model not in REQUEST_VECTOR_TABLES:
        raise SystemExit(f"{config.embedding_model} 모델의 벡터 테이블이 없습니다.")

    model_type = REQUEST_VECTOR_TABLES[config.embedding_model]
    vectors = RequestVectorRepository(db.engine)

    source_engine = db.build_iwork_engine()
    try:
        # 워터마크 0은 전량이다.
        groups = answer_groups(RequestRepository(source_engine).find_changed_since(0))
    finally:
        source_engine.dispose()

    indexed = vectors.find_by_request_ids(model_type, list(groups))
    if len(indexed) < len(groups):
        raise SystemExit(
            f"정답 {len(groups)}건 중 {len(groups) - len(indexed)}건이 색인되지 않았습니다."
            " 색인을 먼저 돌리세요. 안 그러면 점수가 실제보다 낮게 나옵니다."
        )

    def neighbors(request_id: str) -> list[str]:
        request_vectors = vectors.search_top_k(model_type, indexed[request_id].embedding, K + 1)
        return [v.request_id for v in request_vectors]

    started = time.monotonic()
    score = evaluate(groups, neighbors, K)
    elapsed = time.monotonic() - started

    print(
        f"모델 {config.embedding_model} | 정답 그룹 {len(set(groups.values()))}개 | 질문 {score.questions}건"
    )
    print(f"재현율@{K} = {score.recall:.3f}  ({score.hits}/{score.questions})")
    print(f"MRR@{K}    = {score.mrr:.3f}")
    print(f"{elapsed:.1f}초, 질의당 {elapsed / score.questions * 1000:.0f}ms")


if __name__ == "__main__":
    main()
