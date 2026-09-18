from dataclasses import dataclass
from pathlib import Path

import yaml

from blue_chatbot.configs.core import config


class FaqError(Exception):
    """FAQ 파일이 잘못되었을 때 발생한다."""


@dataclass(frozen=True)
class FaqEntry:
    id: str
    question: str
    answer: str
    domain: str = ""


REQUIRED_FIELDS = ("id", "question", "answer")

def load(path: Path, domain: str = "") -> list[FaqEntry]:
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
                domain=domain,
            )

    return list(entries.values())


def load_domains(directory: Path) -> list[FaqEntry]:
    """디렉터리 안의 yaml을 도메인별로 읽는다. 파일 이름이 도메인 이름이다."""
    paths = sorted(directory.glob("*.yaml"))

    if not paths:
        raise FaqError(f"FAQ 파일이 없습니다: {directory}")

    loaded: list[FaqEntry] = []
    seen: set[str] = set()

    for path in paths:
        for entry in load(path, domain=path.stem):
            if entry.id in seen:
                raise FaqError(f"id가 중복되었습니다: {entry.id}")
            seen.add(entry.id)
            loaded.append(entry)

    return loaded


def by_domain(faqs: list[FaqEntry]) -> dict[str, list[FaqEntry]]:
    """도메인별로 묶는다. 도메인 순서와 항목 순서는 읽은 순서를 따른다."""
    grouped: dict[str, list[FaqEntry]] = {}

    for entry in faqs:
        grouped.setdefault(entry.domain, []).append(entry)

    return grouped


entries = load_domains(config.faq_dir)
