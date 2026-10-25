"""엔진 — 실제 API·CLI 는 부르지 않는다. 가짜 클라이언트로 요청 모양과 응답 해석을 본다."""

import json
from types import SimpleNamespace as NS

import pytest

from core.config import Settings
from engine import AnthropicEngine, CliEngine, EngineError, Usage, cost_of, parse_json


def settings(**kw):
    return Settings(_env_file=None, anthropic_api_key="test", **kw)


def msg(content, stop="end_turn", searches=0, inp=1000, out=200):
    return NS(content=content, stop_reason=stop, stop_details=None,
              usage=NS(input_tokens=inp, output_tokens=out, cache_read_input_tokens=0, cache_creation_input_tokens=0,
                       server_tool_use=NS(web_search_requests=searches, web_fetch_requests=0)))


class FakeStream:
    def __init__(self, m):
        self.m = m

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def get_final_message(self):
        return self.m


class FakeClient:
    def __init__(self, replies):
        self.replies = list(replies)
        self.calls = []
        self.beta = NS(messages=NS(stream=self._stream))

    def _stream(self, **kw):
        self.calls.append(kw)
        return FakeStream(self.replies.pop(0))


def test_research_collects_urls_and_resumes_pause_turn():
    first = msg([NS(type="server_tool_use"),
                 NS(type="web_search_tool_result", content=[NS(url="https://php.net/a"), NS(url="https://b.org")])],
                stop="pause_turn", searches=2)
    second = msg([NS(type="web_fetch_tool_result", content=NS(url="https://github.com/php/php-src")),
                  NS(type="text", text="## 살펴본 곳\n- php.net", citations=[NS(url="https://php.net/a")])],
                 searches=1)
    client = FakeClient([first, second])
    eng = AnthropicEngine(settings(), client=client)
    r = eng.research("sys", "user", max_searches=7, blocked=["spam.com"])

    assert r.notes.startswith("## 살펴본 곳")
    assert r.seen_urls == ["https://php.net/a", "https://b.org", "https://github.com/php/php-src"]
    assert r.usage.searches == 3
    assert r.usage.input_tokens == 2000
    tools = client.calls[0]["tools"]
    assert tools[0]["type"] == "web_search_20260209"
    assert tools[0]["max_uses"] == 7
    assert tools[0]["blocked_domains"] == ["spam.com"]
    assert tools[1]["type"] == "web_fetch_20260209"
    assert client.calls[0]["fallbacks"] == "default"
    # 이어 달라고 보낼 때는 사용자 말 + 멈춘 답
    assert [m["role"] for m in client.calls[1]["messages"]] == ["user", "assistant"]


def test_research_refusal_raises():
    m = msg([], stop="refusal")
    m.stop_details = NS(category="cyber")
    with pytest.raises(EngineError, match="거부"):
        AnthropicEngine(settings(), client=FakeClient([m])).research("s", "u", max_searches=1, blocked=[])


def test_judge_uses_structured_output():
    client = FakeClient([msg([NS(type="text", text='{"summary":"변화 없음","findings":[],"baseline_hint":""}')])])
    out, _ = AnthropicEngine(settings(), client=client).judge("s", "u", {"type": "object"})
    assert out["summary"] == "변화 없음"
    assert client.calls[0]["output_config"]["format"]["type"] == "json_schema"
    assert "tools" not in client.calls[0]


def test_cost():
    s = settings()
    u = Usage(input_tokens=1_000_000, output_tokens=100_000, searches=10)
    assert cost_of(s, "claude-opus-5-5", u) == pytest.approx(4.0 + 2.0 + 0.1)
    assert cost_of(s, "x", Usage(cost_usd=0.1234)) == 0.1234


def test_parse_json_tolerates_fences_and_chatter():
    assert parse_json('```json\n{"a":1}\n```') == {"a": 1}
    assert parse_json('결과입니다: {"a": {"b": 2}} 끝') == {"a": {"b": 2}}


def test_cli_engine_parses_result_and_cost():
    seen = {}

    def runner(args, stdin):
        seen["args"], seen["stdin"] = args, stdin
        return json.dumps({"result": "노트 https://php.net/x.", "total_cost_usd": 0.42,
                           "usage": {"input_tokens": 10, "output_tokens": 5}})

    eng = CliEngine(settings(), runner=runner)
    r = eng.research("sys", "user", max_searches=5, blocked=["a.com"])
    assert r.seen_urls == ["https://php.net/x"]
    assert r.usage.cost_usd == 0.42
    assert "--allowedTools" in seen["args"]
    assert "WebSearch,WebFetch" in seen["args"]
    assert "a.com" in seen["args"][seen["args"].index("--append-system-prompt") + 1]


def test_cli_error_result():
    eng = CliEngine(settings(), runner=lambda a, s: json.dumps({"is_error": True, "result": "로그인 필요"}))
    with pytest.raises(EngineError, match="로그인 필요"):
        eng.judge("s", "u", {})
