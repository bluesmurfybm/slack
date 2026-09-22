from typing import Any

from blue_chatbot.services.faq import FaqEntry, by_domain
from blue_chatbot.tools.base import Tool

INSTRUCTIONS = """당신은 blue-iWorks 사내 시스템 응대 도우미입니다.
아래 FAQ 목록과 도구로 찾은 내용만 근거로 답변하세요.
FAQ 목록은 도메인별로 묶여 있고 `## 도메인이름` 줄이 그 아래 항목들의 도메인입니다.

규칙:
1. 근거로 삼은 항목의 id를 matched_id에 정확히 적으세요. FAQ 항목은 [id] 줄의 값, 도구 결과는 각 항목 앞 [id]의 값입니다.
2. 근거가 없으면 matched_id를 비워 두세요. 이때 답을 지어내지 마세요.
3. 인용한 항목의 내용만 재구성하세요. 원문에 없는 사실을 덧붙이지 마세요.
4. 답변은 한국어 존댓말로, 간결하게 작성하세요.

FAQ 목록:"""

TOOL_RULES = """도구가 이번 대화에서 실제로 돌려준 것만 인용할 수 있습니다.
부르지 않은 도구의 자료는 인용하지 마세요."""


def _format_entry(entry: FaqEntry) -> str:
    """
    [workhub-priority-list]
    Q: 요청 우선순위는 어떻게 구분하나요?
    A: 일반, 긴급, 중요 세 가지입니다.
    """
    return f"[{entry.id}]\nQ: {entry.question}\nA: {entry.answer}"


def _format_domain(domain: str, faqs: list[FaqEntry]) -> str:
    entries = "\n\n".join(_format_entry(faq) for faq in faqs)
    return f"## {domain}\n\n{entries}" if domain else entries


def _format_tools(tools: list[Tool[Any]]) -> str:
    lines = "\n".join(f"- {tool.name}: {tool.description}" for tool in tools)
    return f"쓸 수 있는 도구:\n\n{lines}\n\n{TOOL_RULES}"


def build_prompt_system(faqs: list[FaqEntry], tools: list[Tool[Any]] | None = None) -> str:
    body = "\n\n".join(
        _format_domain(domain, entries) for domain, entries in by_domain(faqs).items()
    )
    parts = [f"{INSTRUCTIONS}\n\n{body}" if body else INSTRUCTIONS]
    if tools:
        parts.append(_format_tools(tools))
    return "\n\n".join(parts)
