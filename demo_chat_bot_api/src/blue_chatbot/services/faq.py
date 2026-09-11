from dataclasses import dataclass
from pathlib import Path

import yaml


class FaqError(Exception):
    """FAQ 파일이 잘못되었을 때 발생한다."""


@dataclass(frozen=True)
class FaqEntry:
    id: str
    question: str
    answer: str


REQUIRED_FIELDS = ("id", "question", "answer")

def load(path: Path) -> list[FaqEntry]:
    raw = yaml.safe_load(path.read_text(encoding="utf-8"))

    if raw is None:
        raise FaqError(f"FAQ 파일이 비어 있습니다: {path}")
    if not isinstance(raw, list):
        raise FaqError(f"FAQ 파일의 최상위는 목록이어야 합니다: {path}")

    entries: dict[str, FaqEntry] = {}

    for index, item in enumerate(raw):
        if not isinstance(item, dict):
            raise FaqError(f"{index}번째 항목이 매핑이 아닙니다: {item!r}")

        for field in REQUIRED_FIELDS:
            if not item.get(field):
                raise FaqError(f"{index}번째 항목에 {field}가 없습니다: {item!r}")

        entry_id = str(item["id"])
        if entry_id in entries:
            raise FaqError(f"id가 중복되었습니다: {entry_id}")
        else:
            entries[entry_id] = FaqEntry(
                            id=entry_id,
                            question=str(item["question"]),
                            answer=str(item["answer"]),
                        )

    return list(entries.values())
