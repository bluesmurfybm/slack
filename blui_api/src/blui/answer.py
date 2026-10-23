from typing import Any, Literal, NotRequired, TypedDict, get_args

from langchain_core.messages import BaseMessage, SystemMessage, convert_to_messages
from langgraph.graph import END, START, StateGraph
from langgraph.graph.state import CompiledStateGraph
from pydantic import BaseModel, Field

from blui.agents import Agent, structured_response
from blui.classifier import ClassifierOutput, Domain
from blui.guard import GuardOutput
from blui.prompt import build_system, entries_for
from blui.tools.handoff import HandoffParams, HandoffTool
from common.knowledge import KnowledgeEntry, answerable
from common.repositories.conversation import ConversationMessage

OUT_OF_SCOPE = "블루소프트 서비스 안내만 도와드릴 수 있어요."
FALLBACK = (
    "지금은 답변을 드리기 어렵습니다. 견적 문의는 {inquiry_url}, 상담 요청은 {request_url}에서"
    " 남겨 주시면 담당자가 연락드리겠습니다."
)
UNKNOWN = "죄송합니다. 문의하신 내용은 현재 안내가 어렵습니다."

Form = Literal["inquiry", "request", "none"]
type Route = Literal["fallback", "unknown", "handoff", "reply"]


class LLMOutput(BaseModel):
    content: str
    matched_ids: list[str] = Field(default_factory=list)
    form: Form = Field(
        description=(
            "본문에서 안내한 폼. inquiry는 홈페이지·쇼핑몰의 견적문의,"
            " request는 COURSEMOS·Moodle·SHEblue·디지털 마케팅의 상담 요청과 이관, none은 안내 없음"
        )
    )
    handoff: str = Field(
        default="",
        description="담당자에게 이관할 때만 담당자에게 전달할 유저의 요청사항 요약을 적고, 그 외에는 빈 문자열",
    )


class Answer(BaseModel):
    content: str
    matched_ids: list[str]


class State(TypedDict):
    messages: list[BaseMessage]
    forbidden: NotRequired[bool]
    domain: NotRequired[Domain]
    output: NotRequired[LLMOutput]
    answer: NotRequired[Answer]


type Graph = CompiledStateGraph[State, None, State, State]

DOMAINS: tuple[Domain, ...] = get_args(Domain)


def build(
    guard: Agent,
    classifier: Agent,
    generator: Agent,
    entries: list[KnowledgeEntry],
    inquiry_url: str,
    request_url: str,
    handoff: HandoffTool,
) -> Graph:
    """금지 판정과 분야 판정을 거쳐 판정된 분야의 서비스 그래프로 답하고, 판정에서 끝나면 정해진 문구를 반환하는 그래프를 생성합니다."""
    fallback = _fallback(inquiry_url, request_url)

    def check(state: State) -> dict[str, Any]:
        output = structured_response(guard, state["messages"], GuardOutput)
        return {"forbidden": output is not None and output.forbidden}

    def classify(state: State) -> dict[str, Any]:
        output = structured_response(classifier, state["messages"], ClassifierOutput)
        return {} if output is None else {"domain": output.domain}

    def out_of_scope(state: State) -> dict[str, Any]:
        return {"answer": Answer(content=OUT_OF_SCOPE, matched_ids=[])}

    def fail(state: State) -> dict[str, Any]:
        return {"answer": fallback}

    def after_guard(state: State) -> str:
        return "out_of_scope" if state["forbidden"] else "classify"

    def after_classify(state: State) -> str:
        return state.get("domain", "fallback")

    graph = StateGraph(State)
    graph.add_node("guard", check)
    graph.add_node("classify", classify)
    graph.add_node("out_of_scope", out_of_scope)
    graph.add_node("fallback", fail)
    for domain in DOMAINS:
        service = build_service(domain, generator, entries, inquiry_url, request_url, handoff)
        graph.add_node(domain, service)
        graph.add_edge(domain, END)
    graph.add_edge(START, "guard")
    graph.add_conditional_edges("guard", after_guard, ["out_of_scope", "classify"])
    graph.add_conditional_edges("classify", after_classify, [*DOMAINS, "fallback"])
    graph.add_edge("out_of_scope", END)
    graph.add_edge("fallback", END)
    return graph.compile()


def build_service(
    domain: Domain,
    generator: Agent,
    entries: list[KnowledgeEntry],
    inquiry_url: str,
    request_url: str,
    handoff: HandoffTool,
) -> Graph:
    """한 분야의 지식을 근거로 답하고, 이관하거나 근거가 없거나 잘못되면 정해진 문구를 반환하는 그래프를 생성합니다."""
    service_entries = entries_for(entries, domain)
    system = SystemMessage(
        content=[
            {
                "type": "text",
                "text": build_system(service_entries, domain, inquiry_url, request_url),
                "cache_control": {"type": "ephemeral"},
            }
        ]
    )
    citable = citable_ids(entries, domain)
    fallback = _fallback(inquiry_url, request_url)

    def generate(state: State) -> dict[str, Any]:
        output = structured_response(generator, [system, *state["messages"]], LLMOutput)
        return {} if output is None else {"output": output}

    def fail(state: State) -> dict[str, Any]:
        return {"answer": fallback}

    def unknown(state: State) -> dict[str, Any]:
        return {"answer": Answer(content=UNKNOWN, matched_ids=[])}

    def hand_off(state: State) -> dict[str, Any]:
        output = state["output"]
        result = handoff.run(HandoffParams(request=output.handoff))
        if not output.content.strip():
            return {"answer": Answer(content=result.reply or fallback.content, matched_ids=[])}
        return {"answer": Answer(content=output.content, matched_ids=[])}

    def reply(state: State) -> dict[str, Any]:
        output = state["output"]
        return {"answer": Answer(content=output.content, matched_ids=output.matched_ids)}

    def after_generate(state: State) -> Route:
        if "output" not in state:
            return "fallback"
        return route(state["output"], citable)

    graph = StateGraph(State)
    graph.add_node("generate", generate)
    graph.add_node("fallback", fail)
    graph.add_node("unknown", unknown)
    graph.add_node("handoff", hand_off)
    graph.add_node("reply", reply)
    graph.add_edge(START, "generate")
    graph.add_conditional_edges(
        "generate", after_generate, ["fallback", "unknown", "handoff", "reply"]
    )
    for node in ("fallback", "unknown", "handoff", "reply"):
        graph.add_edge(node, END)
    return graph.compile()


def citable_ids(entries: list[KnowledgeEntry], domain: Domain) -> set[str]:
    """분야의 답변이 근거로 인용할 수 있는 지식베이스 항목 id를 반환합니다."""
    return {entry.id for entry in answerable(entries_for(entries, domain))}


def route(output: LLMOutput, citable: set[str]) -> Route:
    """모델 출력을 받은 서비스 그래프가 이어 갈 노드를 반환합니다."""
    if output.handoff:
        return "handoff"
    if not output.content.strip():
        return "fallback" if output.matched_ids else "unknown"
    if not set(output.matched_ids) <= citable:
        return "fallback"
    return "reply"


def answer(graph: Graph, messages: list[ConversationMessage]) -> Answer:
    """대화 이력의 마지막 유저 메시지에 대한 블리의 응답을 반환합니다."""
    state: State = {"messages": convert_to_messages([(m.role, m.content) for m in messages])}
    answered: Answer = graph.invoke(state)["answer"]
    return answered


def _fallback(inquiry_url: str, request_url: str) -> Answer:
    return Answer(
        content=FALLBACK.format(inquiry_url=inquiry_url, request_url=request_url),
        matched_ids=[],
    )
