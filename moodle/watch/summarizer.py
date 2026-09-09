"""주간 diff 를 한국어 요약으로. anthropic SDK → claude CLI → 요약 없음 순으로 시도한다."""

import json
import logging
import re
import shutil
import subprocess
from dataclasses import asdict, dataclass, field

from core.config import Settings

logger = logging.getLogger(__name__)

IMPACTS = ["고", "중", "저"]

SYSTEM = """당신은 Bluesoft 의 무들(Moodle) 기술 동향 분석가다. 회사는 무들 기반 LMS 제품
'코스모스(Coursemos)' 를 만들며, 무들 HQ 의 Technical Transformation(PAG) 방향 —
React UI 프레임워크, Composer 기반 설치, REST API/OAuth2, OpenTelemetry, 기술부채·레거시 API
정리 —
을 따라갈지 판단하는 근거를 매주 필요로 한다.

입력은 지난 한 주 동안 moodle.org PAG 코스, Moodle Tracker(Jira), GitHub moodle/moodle,
moodledev.io, moodle.com 에서 새로 생긴 것만 모은 것이다.
반드시 한국어로, 이번 주 변경분만 쓴다. 지어내지 말고, 입력에 없는 사실은 쓰지 않는다. 각
항목에는 원문 링크를 반드시 붙인다. 링크는 입력에 있는 URL 을
그대로 쓴다.

summary_md 는 마크다운으로 쓰되 다음 문법만 쓴다: `##`/`###` 제목, `-` 불릿, `1.` 번호, **굵게**,
`코드`, [텍스트](URL). 표·이미지·HTML 은 쓰지 않는다. 구성은 이 순서로 한다.
1. `## 한눈에` — 이번 주를 3~5줄로. 첫 줄은 반드시 PAG 코스 소식이다.
2. `## PAG 동향` — **요약의 중심이다.** moodle.org Technical Transformation PAG 코스(포럼 글·페이지
   변경)를 항목별로 `### 제목` 아래 무엇이 올라왔는지, 코스모스에 어떤 뜻인지, 링크. 트래커·GitHub·
   moodledev.io 에서 PAG 주제(React, Composer, REST API/OAuth2, OpenTelemetry, 기술부채)와 이어지는
   것도 여기에 붙여 한 흐름으로 쓴다. 이 절은 생략하지 않는다 — 코스에서 수집된 게 없으면 그 사실과
   이유(skipped/failed/새 글 없음)를 한 줄로 쓰고, 다른 소스에서 잡힌 PAG 주제만 쓴다.
3. `## 주요 변화` — PAG 와 직접 이어지지 않지만 영향도 고/중인 항목별로 `### 제목` 아래 무엇이
   바뀌었는지, 왜 코스모스에 중요한지, 링크.
4. `## 그 외` — 영향도 저 항목을 불릿으로 짧게.
5. `## 후속 액션 제안` — 구체적 행동(예: 특정 API 폐기 대응 점검, 실험 브랜치 테스트,
   릴리스 노트 검토). PAG 관련 액션을 앞에 둔다.
트래커는 통계와 주목 이슈만 있으니, 컴포넌트 분포나 fixVersion 흐름처럼 통계에서 읽히는 것도 짚는다.
소스가 skipped/failed 면 그 사실을 '한눈에' 끝에 한 줄로 알린다. headline 도 PAG 소식이 있으면
그것을 앞세운다.
"""

SCHEMA = {
    "type": "object",
    "properties": {
        "headline": {"type": "string", "description": "이번 주 한 줄 요약(60자 이내)"},
        "summary_md": {"type": "string"},
        "impacts": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "url": {"type": "string", "description": "입력에 있던 원문 URL 그대로"},
                    "impact": {"type": "string", "enum": IMPACTS},
                    "reason": {"type": "string", "description": "코스모스 관점 이유, 한 문장"},
                },
                "required": ["url", "impact", "reason"],
                "additionalProperties": False,
            },
        },
        "actions": {"type": "array", "items": {"type": "string"}},
    },
    "required": ["headline", "summary_md", "impacts", "actions"],
    "additionalProperties": False,
}


@dataclass
class Summary:
    headline: str
    summary_md: str
    updates_md: str = ""
    impacts: list[dict] = field(default_factory=list)
    actions: list[str] = field(default_factory=list)
    backend: str = ""
    model: str = ""

    def to_dict(self) -> dict:
        return asdict(self)


UPDATE_SYSTEM = SYSTEM + """

지금은 같은 주차의 **갱신 실행**이다. 이미 요약한 본문은 그대로 두고, 입력에 있는 [NEW]
항목(이전 실행
이후 새로 들어온 것)만으로 "추가된 내용" 을 쓴다. updates_md 는 `##` 제목 없이 `###` 소제목과
`-` 불릿만
쓰고, 각 불릿에 원문 링크를 붙인다. PAG 코스 항목이 있으면 맨 앞에 둔다. 새 항목이 사소하면 한두
줄로
끝낸다. 이미 요약한 내용을 반복하지 않는다."""

UPDATE_SCHEMA = {
    "type": "object",
    "properties": {
        "updates_md": {"type": "string", "description": "[NEW] 항목만의 추가 요약(마크다운)"},
        "impacts": SCHEMA["properties"]["impacts"],
        "actions": {"type": "array", "items": {"type": "string"},
                    "description": "새 항목으로 생긴 후속 액션. 없으면 빈 배열"},
    },
    "required": ["updates_md", "impacts", "actions"],
    "additionalProperties": False,
}


def _json_block(text: str) -> dict:
    raw = text.strip()
    m = re.search(r"```(?:json)?\s*(\{.*\})\s*```", raw, re.DOTALL)
    if m:
        raw = m.group(1)
    elif not raw.startswith("{"):
        start, end = raw.find("{"), raw.rfind("}")
        if start >= 0 and end > start:
            raw = raw[start:end + 1]
    return json.loads(raw)


def _impacts(d: dict) -> list[dict]:
    return [i for i in d.get("impacts") or []
            if isinstance(i, dict) and i.get("url") and i.get("impact") in IMPACTS]


def parse(text: str, backend: str, model: str) -> Summary:
    d = _json_block(text)
    return Summary(headline=str(d.get("headline", "")).strip(),
                   summary_md=str(d.get("summary_md", "")).strip(),
                   updates_md=str(d.get("updates_md") or "").strip(),
                   impacts=_impacts(d),
                   actions=[str(a) for a in d.get("actions") or []],
                   backend=backend, model=model)


def parse_update(text: str, backend: str, model: str) -> Summary:
    d = _json_block(text)
    return Summary(headline="", summary_md="",
                   updates_md=str(d.get("updates_md") or "").strip(),
                   impacts=_impacts(d),
                   actions=[str(a) for a in d.get("actions") or []],
                   backend=backend, model=model)


def _user_prompt(digest: str, week: str) -> str:
    return (f"대상 주차: {week}\n\n아래가 이번 주 수집 결과다. 지침대로 JSON 으로 답한다.\n\n"
            f"{digest}")


def with_anthropic(settings: Settings, system: str, schema: dict, prompt: str) -> tuple[str, str]:
    """(응답 텍스트, 모델 id)."""
    import anthropic  # noqa: PLC0415 키가 없는 서버에서는 import 조차 필요 없다

    client = anthropic.Anthropic(api_key=settings.anthropic_api_key) \
        if settings.anthropic_api_key else anthropic.Anthropic()
    # 입력이 길어 스트리밍으로 받는다.
    # fallbacks 는 안전 분류기가 거부하면 서버가 대체 모델로 재시도하는 옵션이다.
    with client.beta.messages.stream(
        model=settings.anthropic_model,
        max_tokens=32000,
        system=system,
        messages=[{"role": "user", "content": prompt}],
        output_config={"effort": settings.summary_effort,
                       "format": {"type": "json_schema", "schema": schema}},
        betas=["server-side-fallback-2026-07-01"],
        fallbacks="default",
    ) as stream:
        msg = stream.get_final_message()
    if msg.stop_reason == "refusal":
        detail = getattr(msg, "stop_details", None)
        raise RuntimeError(f"모델이 요약을 거부했다: {getattr(detail, 'category', '')}")
    return "".join(b.text for b in msg.content if b.type == "text"), msg.model


def with_cli(settings: Settings, system: str, schema: dict, prompt: str) -> tuple[str, str]:
    exe = shutil.which(settings.claude_cli)
    if not exe:
        raise FileNotFoundError(f"claude CLI({settings.claude_cli}) 를 찾을 수 없다")
    full = (system
            + "\n\n반드시 아래 JSON 스키마에 맞는 JSON 하나만 출력한다. 다른 말은 쓰지 않는다.\n"
            + json.dumps(schema, ensure_ascii=False) + "\n\n" + prompt)
    out = subprocess.run( # noqa: S603 실행 파일은 설정값, 인자는 상수다
        [exe, "-p", "--output-format", "text"],
        input=full, capture_output=True, text=True, encoding="utf-8",
        check=True, timeout=1800)
    return out.stdout, ""


def _run(settings: Settings, system: str, schema: dict, prompt: str,
         parser) -> tuple[Summary | None, str]:
    """anthropic → claude CLI 순으로 시도. 어느 백엔드도 못 쓰면 (None, 이유)."""
    mode = settings.summarizer
    if mode == "none":
        return None, "SUMMARIZER=none"
    errors = []
    if mode in ("auto", "anthropic") and (settings.anthropic_api_key or mode == "anthropic"):
        try:
            text, model = with_anthropic(settings, system, schema, prompt)
            return parser(text, "anthropic", model), ""
        except Exception as e:
            logger.exception("anthropic 요약 실패")
            errors.append(f"anthropic: {type(e).__name__}: {e}")
            if mode == "anthropic":
                return None, "; ".join(errors)
    if mode in ("auto", "cli"):
        try:
            text, model = with_cli(settings, system, schema, prompt)
            return parser(text, "claude-cli", model), ""
        except Exception as e:
            logger.exception("claude CLI 요약 실패")
            errors.append(f"cli: {type(e).__name__}: {e}")
    if not errors:
        errors.append("ANTHROPIC_API_KEY 도 claude CLI 도 없어 요약을 만들지 않았다")
    return None, "; ".join(errors)


def summarize(settings: Settings, digest: str, week: str) -> tuple[Summary | None, str]:
    """주차 전체 요약(1회차)."""
    return _run(settings, SYSTEM, SCHEMA, _user_prompt(digest, week), parse)


def summarize_update(settings: Settings, digest: str, week: str) -> tuple[Summary | None, str]:
    """갱신 실행: [NEW] 항목만의 추가 요약. 기존 본문은 건드리지 않는다."""
    return _run(settings, UPDATE_SYSTEM, UPDATE_SCHEMA, _user_prompt(digest, week), parse_update)
