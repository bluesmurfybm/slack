from pydantic import BaseModel

from blue_chatbot.messages import Message
from blue_chatbot.repositories.conversation import ConversationMessage
from blue_chatbot.services.faq import FaqEntry
from blue_chatbot.services.llm import LLMClient
from blue_chatbot.services.prompt import build_prompt_system


class LLMAnswer(BaseModel):
    """LLM이 반환하는 구조화 출력."""

    content: str
    matched_id: str | None = None


def answer(
    llm_client: LLMClient, faqs: list[FaqEntry], messages: list[ConversationMessage]
) -> LLMAnswer:
    """FAQ를 근거로 답한다. 근거가 없으면 고정 문구로 대체한다."""
    raw = llm_client.generate(
        system=build_prompt_system(faqs),
        messages=_to_llm_messages(messages),
        output_format=LLMAnswer,
    )
    if raw is None:
        return _fallback()
    return _validate_answer(raw, faqs)


def _to_llm_messages(messages: list[ConversationMessage]) -> list[Message]:
    # role은 DB에 문자열로 있고, Message가 Literal로 검증한다.
    return [Message.model_validate(m, from_attributes=True) for m in messages]


def _fallback() -> LLMAnswer:
    return LLMAnswer(content="질문에 알맞은 대답을 찾을 수 없습니다.", matched_id=None)


def _validate_answer(raw: LLMAnswer, faqs: list[FaqEntry]) -> LLMAnswer:
    if raw.matched_id is None:
        return _fallback()
    if raw.matched_id not in {entry.id for entry in faqs}:
        return _fallback()
    return raw
