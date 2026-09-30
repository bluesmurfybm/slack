"""시스템 프롬프트에 넣을 지식 항목"""

from dataclasses import dataclass
from pathlib import Path
from xml.sax.saxutils import escape

import yaml

REQUIRED_FIELDS = ("id", "question")


@dataclass(frozen=True, eq=False)
class KnowledgeEntry:
    """지식 항목, id가 같으면 같은 객체로 취급합니다."""

    id: str
    question: str
    domain: str
    answer: str = ""
    aliases: tuple[str, ...] = ()
    handoff: bool = False  # 인간에게 넘길지 말지 여부

    def __eq__(self, other: object) -> bool:
        return isinstance(other, KnowledgeEntry) and other.id == self.id

    def __hash__(self) -> int:
        return hash(self.id)


def load(path: Path, domain: str) -> list[KnowledgeEntry]:
    raw = yaml.safe_load(path.read_text(encoding="utf-8"))

    if raw is None:
        raise KnowledgeError(f"지식 파일이 비어 있습니다: {path}")
    if not isinstance(raw, list):
        raise KnowledgeError(f"지식 파일의 최상위는 목록이어야 합니다: {path}")

    entries: set[KnowledgeEntry] = set()

    for index, item in enumerate(raw):
        entry = _to_entry(index, item, domain)
        if entry in entries:
            raise KnowledgeError(f"id가 중복되었습니다: {entry.id}")
        entries.add(entry)

    return list(entries)


def load_from_dir(directory: Path) -> list[KnowledgeEntry]:
    """여러개의 파일을 디렉터리 단위로 한번에 불러옵니다"""
    paths = sorted(directory.glob("*.yaml"))

    if not paths:
        raise KnowledgeError(f"지식 파일이 없습니다: {directory}")

    entries: set[KnowledgeEntry] = set()

    for path in paths:
        for entry in load(path, domain=path.stem):
            if entry in entries:
                raise KnowledgeError(f"id가 중복되었습니다: {entry.id}")
            entries.add(entry)

    return list(entries)


def answerable(entries: list[KnowledgeEntry]) -> list[KnowledgeEntry]:
    """모델이 답변의 근거로 인용할 수 있는 항목입니다."""
    return [entry for entry in entries if not entry.handoff]


def format_entries(entries: list[KnowledgeEntry]) -> str:
    """지식 엔트리를 프롬프트에 넣을 <knowledge> XML 본문으로 만듭니다."""
    if not entries:
        return ""
    parts = [
        _format_domain(domain, items)
        for domain, items in sorted(_by_domain(answerable(entries)).items())
    ]
    handoff = sorted((entry for entry in entries if entry.handoff), key=lambda e: e.id)
    if handoff:
        questions = "\n".join(_format_handoff_entry(entry) for entry in handoff)
        parts.append(f"<handoff>\n{questions}\n</handoff>")
    return "<knowledge>\n" + "\n".join(parts) + "\n</knowledge>"


def _to_entry(index: int, item: object, domain: str) -> KnowledgeEntry:
    if not isinstance(item, dict):
        raise KnowledgeError(f"{index}번째 항목이 딕셔너리 형태가 아닙니다: {item!r}")

    for field in REQUIRED_FIELDS:
        if not item.get(field):
            raise KnowledgeError(f"{index}번째 항목에 {field}가 없습니다: {item!r}")

    handoff = item.get("handoff", False)
    if not isinstance(handoff, bool):
        raise KnowledgeError(f"{index}번째 항목의 handoff는 불리언이어야 합니다: {handoff!r}")

    answer = item.get("answer") or ""
    if not handoff and not answer:
        raise KnowledgeError(f"{index}번째 항목에 answer가 없습니다: {item!r}")

    aliases = item.get("aliases", [])
    if not isinstance(aliases, list) or not all(isinstance(alias, str) for alias in aliases):
        raise KnowledgeError(f"{index}번째 항목의 aliases는 문자열 목록이어야 합니다: {aliases!r}")

    return KnowledgeEntry(
        id=str(item["id"]),
        question=str(item["question"]),
        answer=str(answer),
        domain=domain,
        aliases=tuple(aliases),
        handoff=handoff,
    )


def _by_domain(entries: list[KnowledgeEntry]) -> dict[str, list[KnowledgeEntry]]:
    """지식 엔트리를 도메인별로 묶습니다."""
    grouped: dict[str, list[KnowledgeEntry]] = {}

    for entry in entries:
        grouped.setdefault(entry.domain, []).append(entry)

    return grouped


def _format_handoff_entry(entry: KnowledgeEntry) -> str:
    aliases = f"<aliases>{escape(' / '.join(entry.aliases))}</aliases>" if entry.aliases else ""
    return (
        f'<entry id="{escape(entry.id)}"><question>{escape(entry.question)}</question>'
        f"{aliases}</entry>"
    )


def _format_entry(entry: KnowledgeEntry) -> str:
    lines = [
        f'<entry id="{escape(entry.id)}">',
        f"<question>{escape(entry.question)}</question>",
    ]
    if entry.aliases:
        lines.append(f"<aliases>{escape(' / '.join(entry.aliases))}</aliases>")
    lines.append(f"<answer>{escape(entry.answer)}</answer>")
    lines.append("</entry>")
    return "\n".join(lines)


def _format_domain(domain: str, entries: list[KnowledgeEntry]) -> str:
    body = "\n".join(_format_entry(entry) for entry in sorted(entries, key=lambda e: e.id))
    return f'<domain name="{escape(domain)}">\n{body}\n</domain>'


class KnowledgeError(Exception):
    """지식 파일이 잘못되었을 때 발생합니다."""
