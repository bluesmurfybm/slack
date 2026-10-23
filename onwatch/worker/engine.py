"""조사·판정 엔진. Anthropic API(웹 검색·읽기 서버 도구)가 기본, 키가 없으면 claude CLI.

왜 API 가 기본인가
- 검색 횟수(max_uses)·제외 도메인을 도구에 그대로 걸 수 있어 비용 상한이 실제로 지켜진다.
- 응답의 사용량으로 비용을 정확히 계산해 감시별 월 상한을 지킬 수 있다.
- 실제로 연 주소를 블록에서 뽑아 '미열람 링크' 를 가려낼 수 있다.
claude CLI 는 키 없이 서버에 로그인된 계정으로 돌릴 수 있어(MoodleUp? 과 같은 방식) 대안으로 둔다.
CLI 에서는 검색 횟수를 걸 수 없고 열어 본 주소도 알 수 없다 — 지시문으로만 당부한다.
"""

import json
import logging
import re
import shutil
import subprocess
from dataclasses import dataclass, field

from core.config import Settings

logger = logging.getLogger("onwatch.engine")

MAX_CONTINUATIONS = 6 # pause_turn(서버 도구 반복 한도)이 나면 이어 달라고 다시 보내는 횟수
URL_RE = re.compile(r"https?://[^\s<>()\[\]\"'`]+")


class EngineError(RuntimeError):
    pass


@dataclass
class Usage:
    input_tokens: int = 0
    output_tokens: int = 0
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    searches: int = 0
    cost_usd: float | None = None # CLI 처럼 엔진이 비용을 직접 알려 주면 그 값

    def add(self, other: "Usage") -> None:
        self.input_tokens += other.input_tokens
        self.output_tokens += other.output_tokens
        self.cache_read_tokens += other.cache_read_tokens
        self.cache_write_tokens += other.cache_write_tokens
        self.searches += other.searches
        if other.cost_usd is not None:
            self.cost_usd = (self.cost_usd or 0.0) + other.cost_usd


@dataclass
class Research:
    notes: str
    seen_urls: list[str] = field(default_factory=list)
    usage: Usage = field(default_factory=Usage)


def cost_of(settings: Settings, model: str, u: Usage) -> float:
    if u.cost_usd is not None:
        return round(u.cost_usd, 4)
    p_in, p_out, p_cache = settings.price(model)
    tokens = (u.input_tokens * p_in + u.cache_write_tokens * p_in * 1.25
              + u.cache_read_tokens * p_cache + u.output_tokens * p_out) / 1e6
    return round(tokens + u.searches * settings.web_search_usd_per_1k / 1000, 4)


def _get(obj, name, default=None):
    if obj is None:
        return default
    if isinstance(obj, dict):
        return obj.get(name, default)
    return getattr(obj, name, default)


def parse_json(text: str) -> dict:
    """모델 답에서 JSON 객체 하나. CLI 는 앞뒤에 말이나 ``` 를 붙일 수 있다."""
    t = (text or "").strip()
    t = re.sub(r"^```(?:json)?\s*|\s*```$", "", t)
    try:
        return json.loads(t)
    except ValueError:
        a, b = t.find("{"), t.rfind("}")
        if a >= 0 and b > a:
            return json.loads(t[a:b + 1])
        raise


# =====================================================================
# Anthropic API
# =====================================================================

class AnthropicEngine:
    name = "anthropic"

    def __init__(self, settings: Settings, client=None):
        self.settings = settings
        self.model = settings.model
        if client is None:
            import anthropic  # noqa: PLC0415 CLI 만 쓰는 서버에서는 import 조차 필요 없다
            client = anthropic.Anthropic(api_key=settings.anthropic_api_key) \
                if settings.anthropic_api_key else anthropic.Anthropic()
        self.client = client

    def _stream(self, **kw):
        # fallbacks: 안전 분류기가 거부하면 서버가 대체 모델로 다시 해 본다(moodle/watch 와 같은 설정).
        with self.client.beta.messages.stream(
            model=self.model,
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",
            thinking={"type": "adaptive"},
            **kw,
        ) as stream:
            return stream.get_final_message()

    @staticmethod
    def _usage(msg) -> Usage:
        u = _get(msg, "usage")
        stu = _get(u, "server_tool_use")
        return Usage(
            input_tokens=int(_get(u, "input_tokens", 0) or 0),
            output_tokens=int(_get(u, "output_tokens", 0) or 0),
            cache_read_tokens=int(_get(u, "cache_read_input_tokens", 0) or 0),
            cache_write_tokens=int(_get(u, "cache_creation_input_tokens", 0) or 0),
            searches=int(_get(stu, "web_search_requests", 0) or 0),
        )

    @staticmethod
    def _check(msg) -> None:
        if _get(msg, "stop_reason") == "refusal":
            detail = _get(msg, "stop_details")
            raise EngineError(f"모델이 요청을 거부했습니다: {_get(detail, 'category', '')}")

    @staticmethod
    def collect_urls(content) -> list[str]:
        """검색 결과·가져온 문서·인용에 나온 주소 — 모델이 실제로 본 것."""
        urls: list[str] = []
        for b in content or []:
            t = _get(b, "type")
            if t == "web_search_tool_result":
                inner = _get(b, "content")
                if isinstance(inner, list):
                    urls += [_get(r, "url") for r in inner if _get(r, "url")]
            elif t == "web_fetch_tool_result":
                inner = _get(b, "content")
                if _get(inner, "url"):
                    urls.append(_get(inner, "url"))
            elif t == "text":
                for c in _get(b, "citations") or []:
                    if _get(c, "url"):
                        urls.append(_get(c, "url"))
        return urls

    def research(self, system: str, user: str, *, max_searches: int, blocked: list[str]) -> Research:
        search = {"type": "web_search_20260209", "name": "web_search", "max_uses": max_searches}
        fetch = {"type": "web_fetch_20260209", "name": "web_fetch", "max_uses": max_searches}
        if blocked:
            search["blocked_domains"] = blocked
            fetch["blocked_domains"] = blocked
        messages: list[dict] = [{"role": "user", "content": user}]
        usage = Usage()
        texts: list[str] = []
        urls: list[str] = []
        for _ in range(MAX_CONTINUATIONS):
            msg = self._stream(
                max_tokens=32000,
                system=system,
                messages=messages,
                tools=[search, fetch],
                output_config={"effort": self.settings.research_effort},
            )
            usage.add(self._usage(msg))
            self._check(msg)
            content = list(_get(msg, "content") or [])
            urls += self.collect_urls(content)
            for b in content:
                if _get(b, "type") == "text" and (_get(b, "text") or "").strip() and _get(b, "text") not in texts:
                    texts.append(_get(b, "text"))
            if _get(msg, "stop_reason") != "pause_turn":
                break
            # 서버 도구가 반복 한도에 걸렸다. 사용자 말 + 지금까지의 답을 다시 보내면 이어서 한다.
            messages = [{"role": "user", "content": user}, {"role": "assistant", "content": content}]
        notes = "".join(texts).strip()
        if not notes:
            raise EngineError("조사 노트가 비었습니다")
        return Research(notes=notes, seen_urls=_uniq(urls), usage=usage)

    def judge(self, system: str, user: str, schema: dict) -> tuple[dict, Usage]:
        msg = self._stream(
            max_tokens=16000,
            system=system,
            messages=[{"role": "user", "content": user}],
            output_config={"effort": self.settings.judge_effort,
                           "format": {"type": "json_schema", "schema": schema}},
        )
        self._check(msg)
        if _get(msg, "stop_reason") == "max_tokens":
            raise EngineError("판정 답이 길이 한도에서 잘렸습니다")
        text = "".join(_get(b, "text") or "" for b in _get(msg, "content") or [] if _get(b, "type") == "text")
        return parse_json(text), self._usage(msg)


# =====================================================================
# claude CLI
# =====================================================================

class CliEngine:
    name = "cli"

    def __init__(self, settings: Settings, runner=None):
        self.settings = settings
        self.model = settings.model
        self.runner = runner or self._run

    def _run(self, args: list[str], stdin: str) -> str:
        exe = shutil.which(self.settings.claude_cli)
        if not exe:
            raise EngineError(f"claude CLI({self.settings.claude_cli}) 를 찾을 수 없습니다")
        out = subprocess.run( # noqa: S603 실행 파일은 설정값, 인자는 상수다
            [exe, *args], input=stdin, capture_output=True, text=True, encoding="utf-8",
            check=False, timeout=self.settings.cli_timeout)
        if out.returncode != 0:
            raise EngineError(f"claude CLI 실패({out.returncode}): {(out.stderr or out.stdout)[-500:]}")
        return out.stdout

    def _call(self, system: str, prompt: str, tools: str | None) -> tuple[str, Usage]:
        args = ["-p", "--output-format", "json", "--append-system-prompt", system]
        if self.model:
            args += ["--model", self.model]
        if tools:
            args += ["--allowedTools", tools]
        raw = self.runner(args, prompt)
        try:
            d = json.loads(raw)
        except ValueError as e:
            raise EngineError(f"claude CLI 출력이 JSON 이 아닙니다: {raw[:200]}") from e
        if d.get("is_error"):
            raise EngineError(f"claude CLI 오류: {str(d.get('result'))[:300]}")
        u = d.get("usage") or {}
        usage = Usage(
            input_tokens=int(u.get("input_tokens") or 0),
            output_tokens=int(u.get("output_tokens") or 0),
            cache_read_tokens=int(u.get("cache_read_input_tokens") or 0),
            cache_write_tokens=int(u.get("cache_creation_input_tokens") or 0),
            searches=int((u.get("server_tool_use") or {}).get("web_search_requests") or 0),
            cost_usd=float(d["total_cost_usd"]) if d.get("total_cost_usd") is not None else None,
        )
        return str(d.get("result") or ""), usage

    def research(self, system: str, user: str, *, max_searches: int, blocked: list[str]) -> Research:
        extra = f"\n\n웹 검색은 {max_searches}회를 넘기지 않는다."
        if blocked:
            extra += " 다음 도메인은 보지 않는다: " + ", ".join(blocked)
        text, usage = self._call(system + extra, user, "WebSearch,WebFetch")
        if not text.strip():
            raise EngineError("조사 노트가 비었습니다")
        # CLI 는 연 주소를 알려 주지 않는다. 노트에 적힌 주소를 '본 것' 으로 친다 — 확인 표시가 덜 엄격해진다.
        return Research(notes=text.strip(), seen_urls=_uniq(URL_RE.findall(text)), usage=usage)

    def judge(self, system: str, user: str, schema: dict) -> tuple[dict, Usage]:
        full = (user + "\n\n반드시 아래 JSON 스키마에 맞는 JSON 하나만 출력한다. 다른 말은 쓰지 않는다.\n"
                + json.dumps(schema, ensure_ascii=False))
        text, usage = self._call(system, full, None)
        return parse_json(text), usage


def _uniq(urls: list[str]) -> list[str]:
    seen, out = set(), []
    for raw in urls:
        u = (raw or "").strip().rstrip(".,;")
        if u and u not in seen:
            seen.add(u)
            out.append(u)
    return out


def make_engine(settings: Settings):
    mode = settings.engine
    if mode == "anthropic" or (mode == "auto" and settings.anthropic_api_key):
        return AnthropicEngine(settings)
    if mode in ("auto", "cli"):
        return CliEngine(settings)
    raise EngineError(f"알 수 없는 ONWATCH_ENGINE: {mode}")
