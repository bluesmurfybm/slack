"""색인된 벡터로 검색 품질을 잰다."""

import re
from collections import defaultdict
from collections.abc import Callable
from dataclasses import dataclass

from blue_chatbot.repositories.workhub import RequestRead

LEADING_BRACKET = re.compile(r"^\[[^]]*\]")


@dataclass(frozen=True)
class Score:
    questions: int
    hits: int
    recall: float
    mrr: float


def title_without_bracket(title: str) -> str:
    """제목 앞 대괄호를 뗀다. 뒤에 나오는 대괄호는 건드리지 않는다."""
    return LEADING_BRACKET.sub("", title).strip()


def answer_groups(requests: list[RequestRead]) -> dict[str, str]:
    """요청 id -> 정답 그룹. 같은 제목이 둘 이상일 때만 정답이 있다."""
    by_title: dict[str, list[str]] = defaultdict(list)
    for request in requests:
        by_title[title_without_bracket(request.title)].append(request.id)
    return {id: title for title, ids in by_title.items() if len(ids) > 1 for id in ids}


def evaluate(groups: dict[str, str], neighbors: Callable[[str], list[str]], k: int) -> Score:
    """질문마다 가까운 요청을 찾아, 같은 그룹이 상위 k에 들어오는지 센다."""
    hits = 0
    reciprocal = 0.0
    for request_id, group in groups.items():
        found = [other for other in neighbors(request_id) if other != request_id][:k]
        for rank, other in enumerate(found, start=1):
            if groups.get(other) == group:
                hits += 1
                reciprocal += 1 / rank
                break

    questions = len(groups)
    if questions == 0:
        return Score(questions=0, hits=0, recall=0.0, mrr=0.0)
    return Score(
        questions=questions,
        hits=hits,
        recall=hits / questions,
        mrr=reciprocal / questions,
    )
