import json

from tools import claude_cli
from tools.claude_cli import (
    DENY,
    READ_ONLY,
    claude_env,
    exec_argv,
    llm_argv,
    parse_result,
    plan_argv,
    resolve_claude,
    review_argv,
)

SCHEMA = {"type": "object", "properties": {"a": {"type": "string"}}, "required": ["a"], "additionalProperties": False}


def test_resolve_cmd_shim_cli_js_uses_node(tmp_path):
    cmd = tmp_path / "claude.cmd"
    cmd.write_text('@ECHO off\r\n"%~dp0\\node_modules\\@anthropic-ai\\claude-code\\cli.js" %*\r\n')
    def which(n):
        return str(cmd) if n == "claude" else ("C:/nodejs/node.exe" if n == "node" else None)

    argv = resolve_claude("claude", "node", which=which)
    assert argv[0] == "C:/nodejs/node.exe"
    assert argv[1].replace("\\", "/").endswith("node_modules/@anthropic-ai/claude-code/cli.js")


def test_resolve_cmd_shim_native_exe(tmp_path):
    cmd = tmp_path / "claude.cmd"
    cmd.write_text('@ECHO off\r\nSET dp0=%~dp0\r\n"%dp0%\\node_modules\\@anthropic-ai\\claude-code\\bin\\claude.exe"   %*\r\n')
    argv = resolve_claude("claude", "node", which=lambda n: str(cmd) if n == "claude" else None)
    assert len(argv) == 1
    assert argv[0].replace("\\", "/").endswith("node_modules/@anthropic-ai/claude-code/bin/claude.exe")


def test_resolve_plain_executable():
    argv = resolve_claude("claude", which=lambda n: "/usr/bin/claude" if n == "claude" else None)
    assert argv == ["/usr/bin/claude"]


def test_resolve_missing_raises():
    import pytest

    with pytest.raises(FileNotFoundError):
        resolve_claude("nope", which=lambda n: None)


def test_plan_argv_flags():
    argv = plan_argv(["claude"], schema=SCHEMA, session_id="11111111-1111-1111-1111-111111111111",
                     model="claude-sonnet-5", budget=3, system_md="SYS")
    s = " ".join(argv)
    assert argv[:3] == ["claude", "-p", "--output-format"]
    assert "--json-schema" in argv and json.loads(argv[argv.index("--json-schema") + 1]) == SCHEMA
    assert argv[argv.index("--permission-mode") + 1] == "dontAsk"
    assert argv[argv.index("--tools") + 1] == "Read,Grep,Glob,Bash"
    for t in READ_ONLY:
        assert t in argv
    for d in DENY + ["Edit", "Write", "MultiEdit", "NotebookEdit"]:
        assert d in argv[argv.index("--disallowedTools"):]
    assert argv[argv.index("--session-id") + 1] == "11111111-1111-1111-1111-111111111111"
    assert argv[argv.index("--max-budget-usd") + 1] == "3.00"
    assert argv[argv.index("--setting-sources") + 1] == "user"
    assert argv[argv.index("--append-system-prompt") + 1] == "SYS"
    assert "--append-system-prompt-file" not in s
    assert "--bare" not in argv
    assert "--fallback-model" not in argv


def test_plan_argv_fallback_model():
    argv = plan_argv(["claude"], schema=SCHEMA, session_id="x", model="m", budget=1, system_md="S", fallback_model="sonnet")
    assert argv[argv.index("--fallback-model") + 1] == "sonnet"


def test_exec_argv_resume_vs_fresh():
    a = exec_argv(["claude"], session_id="sid", resume=True, model="m", budget=10, system_md="S")
    assert a[a.index("--resume") + 1] == "sid" and "--session-id" not in a
    assert a[a.index("--permission-mode") + 1] == "acceptEdits"
    assert "Bash(php -l *)" in a and "Bash(svn commit *)" in a[a.index("--disallowedTools"):]
    b = exec_argv(["claude"], session_id="new", resume=False, model="m", budget=10, system_md="S")
    assert b[b.index("--session-id") + 1] == "new" and "--resume" not in b


def test_review_and_llm_argv():
    r = review_argv(["claude"], schema=SCHEMA, model="claude-opus-5", budget=2, system_md="S")
    assert r[r.index("--tools") + 1] == "Read,Grep,Glob" and r[r.index("--model") + 1] == "claude-opus-5"
    la = llm_argv(["claude"], schema=SCHEMA, model="claude-haiku-4-5", budget=0.5, system_md="S")
    assert la[la.index("--tools") + 1] == "" and "--no-session-persistence" in la


def test_system_prompt_capped():
    big = "가" * 30000
    argv = plan_argv(["claude"], schema=SCHEMA, session_id="x", model="m", budget=1, system_md=big)
    sysm = argv[argv.index("--append-system-prompt") + 1]
    assert len(sysm) <= claude_cli.SYSTEM_PROMPT_CAP and sysm.endswith("…(시스템 프롬프트 생략)…")


def test_claude_env_drops_api_key_by_default():
    env = claude_env({"ANTHROPIC_API_KEY": "sk-ant-xxx", "PATH": "p"})
    assert "ANTHROPIC_API_KEY" not in env and env["NO_COLOR"] == "1" and env["PYTHONUTF8"] == "1"
    env2 = claude_env({"ANTHROPIC_API_KEY": "sk-ant-xxx"}, use_api_key=True, oauth_token="tok")
    assert env2["ANTHROPIC_API_KEY"] == "sk-ant-xxx" and env2["CLAUDE_CODE_OAUTH_TOKEN"] == "tok"


def _result(**over):
    d = {"type": "result", "subtype": "success", "is_error": False, "duration_ms": 1234, "num_turns": 3,
         "result": "done", "session_id": "abc-123", "total_cost_usd": 0.0123,
         "usage": {"input_tokens": 10, "cache_read_input_tokens": 90, "output_tokens": 20},
         "modelUsage": {"claude-sonnet-5": {"inputTokens": 100, "outputTokens": 20}},
         "permission_denials": [], "structured_output": {"a": "b"}}
    d.update(over)
    return json.dumps(d, ensure_ascii=False)


def test_parse_success_with_structured_output():
    r = parse_result(_result(), 0)
    assert r.ok and r.structured_output == {"a": "b"} and r.session_id == "abc-123"
    assert r.total_cost_usd == 0.0123 and r.input_tokens == 100 and r.output_tokens == 20
    assert r.model == "claude-sonnet-5" and r.num_turns == 3 and not r.budget_hit


def test_parse_warning_lines_before_json():
    out = "Warning: something\n(node) deprecation\n" + _result()
    r = parse_result(out, 0)
    assert r.ok and r.structured_output == {"a": "b"}


def test_parse_budget_exceeded_rc2_with_partial_output():
    r = parse_result(_result(subtype="error_max_budget_usd", is_error=True), 2)
    assert r.budget_hit and r.structured_output == {"a": "b"} and r.ok # 부분 성공으로 채택


def test_parse_error_without_output():
    r = parse_result(_result(is_error=True, subtype="error_during_execution", result="boom", structured_output=None), 1)
    assert not r.ok and r.error.startswith("boom")


def test_parse_no_json():
    r = parse_result("garbage", 1, "stderr text")
    assert r.raw is None and not r.ok and "stderr text" in r.error


def test_parse_session_not_found():
    r = parse_result(_result(is_error=True, result="No conversation found with session ID: x", structured_output=None), 1)
    assert r.not_found_session
