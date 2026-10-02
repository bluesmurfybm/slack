from typing import Literal

from pydantic import BaseModel, Field

from blui.classifier import Classifier
from blui.prompt import build_system, entries_for
from blui.tools.handoff import HandoffParams, HandoffTool
from common.guard import Guard
from common.knowledge import KnowledgeEntry, answerable
from common.llm.client import LLMClient
from common.messages import Message
from common.repositories.conversation import ConversationMessage

OUT_OF_SCOPE = "블루소프트 서비스 안내만 도와드릴 수 있어요."
FALLBACK = "지금은 답변을 드리기 어렵습니다."
INQUIRY_GUIDE = "견적문의는 {inquiry_url}에서 남겨 주시면 담당자가 연락드리겠습니다."
REQUEST_GUIDE = "상담 요청은 {request_url}에서 남겨 주시면 담당자가 연락드리겠습니다."
UNKNOWN = "죄송합니다. 문의하신 내용은 현재 안내가 어렵습니다."


class LLMOutput(BaseModel):
    content: str
    matched_ids: list[str] = Field(default_factory=list)
    form: Literal["inquiry", "request", "none"] = Field(
        description=(
            "본문 뒤에 붙일 폼 안내. inquiry는 홈페이지·쇼핑몰의 견적문의,"
            " request는 COURSEMOS·Moodle·SHEblue·디지털 마케팅의 상담 요청, none은 본문만"
        )
    )
    handoff: str = Field(
        default="",
        description="담당자에게 이관할 때만 담당자에게 전달할 유저의 요청사항 요약을 적고, 그 외에는 빈 문자열",
    )


class Answer(BaseModel):
    content: str
    matched_ids: list[str]


def answer(
    llm_client: LLMClient,
    guard: Guard,
    classifier: Classifier,
    entries: list[KnowledgeEntry],
    inquiry_url: str,
    request_url: str,
    handoff: HandoffTool,
    messages: list[ConversationMessage],
) -> Answer:
    """상담 범위 안의 질문에 지식을 근거로 답하고, 담당자에게 이관하거나 근거가 없거나 잘못되면 정해진 문구를 반환합니다."""
    llm_messages = _to_llm_messages(messages)
    if guard.is_forbidden_message(llm_messages):
        return Answer(content=OUT_OF_SCOPE, matched_ids=[])
    fallback = Answer(
        content=FALLBACK.format(inquiry_url=inquiry_url, request_url=request_url),
        matched_ids=[],
    )
    domain = classifier.classify(llm_messages)
    if domain is None:
        return fallback
    entries = entries_for(entries, domain)
    generated = llm_client.generate(
        system=build_system(entries, domain),
        messages=llm_messages,
        output_format=LLMOutput,
        tools=[],
    )
    output = generated.output
    if output is None:
        return fallback
    if output.handoff:
        result = handoff.run(HandoffParams(request=output.handoff))
        if not output.content.strip():
            return Answer(content=result.reply or fallback.content, matched_ids=[])
        request_guide = REQUEST_GUIDE.format(request_url=request_url)
        return Answer(content=f"{output.content}\n\n{request_guide}", matched_ids=[])
    if not output.content.strip():
        return fallback if output.matched_ids else Answer(content=UNKNOWN, matched_ids=[])
    if not set(output.matched_ids) <= {entry.id for entry in answerable(entries)}:
        return fallback
    guides = {
        "inquiry": INQUIRY_GUIDE.format(inquiry_url=inquiry_url),
        "request": REQUEST_GUIDE.format(request_url=request_url),
    }
    content = output.content
    if guide := guides.get(output.form):
        content = f"{content}\n\n{guide}"
    return Answer(content=content, matched_ids=output.matched_ids)


def _to_llm_messages(messages: list[ConversationMessage]) -> list[Message]:
    return [Message.model_validate(m, from_attributes=True) for m in messages]
