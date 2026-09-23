from typing import Any

from pydantic import BaseModel

from blue_chatbot.messages import Message
from blue_chatbot.repositories.conversation import ConversationMessage
from blue_chatbot.services.faq import FaqEntry
from blue_chatbot.services.llm import LLMClient
from blue_chatbot.services.prompt import build_prompt_system
from blue_chatbot.tools.base import Evidence, Tool

FAQ_SOURCE = "faq"


class LLMOutput(BaseModel):
    content: str
    matched_id: str | None = None


class LLMAnswer(BaseModel):
    content: str
    matched_source: str | None = None
    matched_id: str | None = None


def answer(
    llm_client: LLMClient,
    faqs: list[FaqEntry],
    tools: list[Tool[Any]],
    messages: list[ConversationMessage],
) -> LLMAnswer:
    """FAQ와 도구가 찾은 것을 근거로 답한다. 근거가 없으면 고정 문구로 대체한다."""
    output, evidence = llm_client.generate(
        system=build_prompt_system(faqs, tools),
        messages=_to_llm_messages(messages),
        output_format=LLMOutput,
        tools=tools,
    )
    if output is None:
        return _fallback()
    return _validate_answer(output, _citable(faqs, evidence))


def _to_llm_messages(messages: list[ConversationMessage]) -> list[Message]:
    # role은 DB에 문자열로 있고, Message가 Literal로 검증한다.
    return [Message.model_validate(m, from_attributes=True) for m in messages]


def _citable(faqs: list[FaqEntry], evidence: list[Evidence]) -> set[tuple[str, str]]:
    """FAQ 전체와 이번 호출에서 도구가 돌려준 것."""
    return {(FAQ_SOURCE, entry.id) for entry in faqs} | {
        (item.source, item.id) for item in evidence
    }


def _fallback() -> LLMAnswer:
    return LLMAnswer(content="질문에 알맞은 대답을 찾을 수 없습니다.")


def _validate_answer(raw: LLMOutput, citable: set[tuple[str, str]]) -> LLMAnswer:
    matched = [(source, id) for source, id in citable if id == raw.matched_id]
    if len(matched) != 1:
        return _fallback()
    source, id = matched[0]
    return LLMAnswer(content=raw.content, matched_source=source, matched_id=id)
