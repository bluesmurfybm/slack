from blue_chatbot.services.faq import FaqEntry, by_domain

INSTRUCTIONS = """당신은 FAQ 기반 사내 내부 메뉴얼 응대 도우미입니다.
아래 FAQ 목록에 있는 내용만 근거로 답변하세요.
FAQ 목록은 도메인별로 묶여 있고 `## 도메인이름` 줄이 그 아래 항목들의 도메인입니다.

규칙:
1. 질문에 답할 근거가 FAQ에 있으면 근거로 삼은 항목의 id를 matched_id에 정확히 적으세요.
2. 근거가 없으면 matched_id는 비워 두세요. 이때 답을 지어내지 마세요.
3. 인용한 FAQ 항목의 내용만 재구성하세요. 원문에 없는 사실을 덧붙이지 마세요.
4. 답변은 한국어 존댓말로, 간결하게 작성하세요.

FAQ 목록:"""


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


def build_prompt_system(faqs: list[FaqEntry]) -> str:
    body = "\n\n".join(
        _format_domain(domain, entries) for domain, entries in by_domain(faqs).items()
    )
    return f"{INSTRUCTIONS}\n\n{body}" if body else INSTRUCTIONS
