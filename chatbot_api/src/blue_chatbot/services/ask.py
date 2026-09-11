import logging

import anthropic
from pydantic import BaseModel

from blue_chatbot.configs.core import config
from blue_chatbot.services.faq import FaqEntry
from blue_chatbot.services.prompt import build_prompt_system

logger = logging.getLogger(__name__)


class Answer(BaseModel):
    content: str
    matched_id: str | None = None


def _fallback() -> Answer:
    return Answer(content="질문에 알맞은 대답을 찾을 수 없습니다.", matched_id=None)


def _validate_answer(raw: Answer, faq: list[FaqEntry]) -> Answer:
    if raw.matched_id is None:
        return _fallback()
    if raw.matched_id not in {entry.id for entry in faq}:
        return _fallback()
    return raw


def answer(
    client: anthropic.Anthropic, faqs: list[FaqEntry], question: str
) -> Answer:
    message = client.messages.parse(
        model=config.claude_model,
        max_tokens=config.max_tokens,
        system=build_prompt_system(faqs),
        messages=[{"role": "user", "content": question}],
        output_format=Answer,
    )

    if message.stop_reason == "refusal":
        return _fallback()

    if message.parsed_output is None:
        # TODO: 502 예외를 던져야 하는데, 구조를 확장해야 해서 그대로 둔다.
        logger.warning("구조화 출력 파싱 실패 (stop_reason=%s)", message.stop_reason)
        return _fallback()

    return _validate_answer(message.parsed_output, faqs)
