"""claude CLI 래퍼(2.1.210 기준 플래그).

- resolve_claude(): which('claude') 가 Windows 에서 .cmd 심이면 파일을 읽어 실제 실행 파일을 찾는다.
  npm 심은 두 형태가 있다 — `"%dp0%\\node_modules\\@anthropic-ai\\claude-code\\bin\\claude.exe"` (네이티브) 또는
  `"%~dp0\\node_modules\\@anthropic-ai\\claude-code\\cli.js"` (node). cli.js 면 [node, cli.js], exe 면 [exe].
  cmd.exe 재파싱과 kill 시 node 고아를 피한다.
- plan_argv/exec_argv/review_argv/llm_argv: 플랜 §2.3 그대로. 시스템 프롬프트는 --append-system-prompt <텍스트> 인라인
  (2.1.210 에는 --append-system-prompt-file 이 없다). Windows argv 한도 때문에 20KB 이하로 캡.
- env: os.environ 통과(OAuth 재사용) + NO_COLOR/PYTHONUTF8, ANTHROPIC_API_KEY 는 CLAUDE_USE_API_KEY=1 이 아니면 제거. --bare 금지.
- parse_result(): stdout 끝에서부터 "type":"result" JSON 을 찾는다(경고 줄 선행 허용).
"""

import json
import logging
import os
import re
import shutil
import uuid
from dataclasses import dataclass, field
from pathlib import Path

from core.text import decode_best

logger = logging.getLogger(__name__)

SYSTEM_PROMPT_CAP = 20_000 # bytes 아님, 문자 수(한글 3바이트라도 argv 32767 자 한도 안)

READ_ONLY = ["Bash(svn log *)", "Bash(svn diff *)", "Bash(svn info *)", "Bash(svn status *)",
             "Bash(svn blame *)", "Bash(svn cat *)",
             "Bash(git log *)", "Bash(git diff *)", "Bash(git show *)", "Bash(git status *)",
             "Bash(git blame *)"]   # git grep 제외: 파일 내용을 그대로 보여줘 보호 파일(config.php) 값이 샌다 → Grep 도구 사용
DENY = ["Bash(svn commit *)", "Bash(git commit *)", "Bash(git push *)", "Bash(svn revert *)",
        "Bash(svn update *)", "Bash(git checkout *)", "Bash(git reset *)", "Bash(git clean *)",
        "Bash(rm *)", "Bash(del *)", "Bash(rmdir *)", "WebFetch", "WebSearch"]
EXEC_ALLOW = ["Edit", "Write", "MultiEdit", "Bash(php -l *)", "Bash(svn status *)", "Bash(svn diff *)",
              "Bash(svn add *)", "Bash(svn info *)", "Bash(git status *)", "Bash(git diff *)",
              "Bash(git add *)"]
PLAN_TOOLS = "Read,Grep,Glob,Bash"
EXEC_TOOLS = "Read,Edit,Write,MultiEdit,Grep,Glob,Bash"
REVIEW_TOOLS = "Read,Grep,Glob"

_CMD_PATH_RE = re.compile(r'"?%~?dp0%?\\?([^"\r\n]*?(?:cli\.js|claude\.exe|claude\.js|\.js))"?', re.IGNORECASE)


def resolve_claude(name: str = "claude", node_bin: str = "node", which=shutil.which,
                   read_text=None) -> list[str]:
    """실행 argv 접두. 못 찾으면 FileNotFoundError."""
    p = Path(name)
    exe = str(p) if (p.suffix and p.is_file()) else which(name)
    if not exe and os.name == "nt":
        exe = which(name + ".cmd") or which(name + ".exe")
    if not exe:
        raise FileNotFoundError(f"claude CLI({name}) 를 찾을 수 없다")
    if exe.lower().endswith((".cmd", ".bat")):
        text = (read_text or _read)(exe)
        target = _cmd_target(text, Path(exe).parent)
        if target:
            if target.lower().endswith(".js"):
                node = which(node_bin) or node_bin
                return [node, target]
            return [target]
        # 심을 해석 못 하면 .cmd 그대로(동작은 하지만 kill 시 고아 가능)
        return [exe]
    return [exe]


def _read(path: str) -> str:
    try:
        return Path(path).read_text(encoding="utf-8", errors="replace")
    except OSError:
        return ""


def _cmd_target(text: str, base: Path) -> str | None:
    for line in text.splitlines():
        m = _CMD_PATH_RE.search(line)
        if m:
            rel = m.group(1).lstrip("\\/")
            return str(base / rel)
    return None


def claude_env(base: dict | None = None, *, use_api_key: bool = False,
               oauth_token: str | None = None) -> dict:
    env = dict(os.environ if base is None else base)
    env["NO_COLOR"] = "1"
    env["PYTHONUTF8"] = "1"
    env.setdefault("PYTHONIOENCODING", "utf-8")
    if not use_api_key:
        env.pop("ANTHROPIC_API_KEY", None) # 구독(OAuth) 과금으로
    if oauth_token:
        env["CLAUDE_CODE_OAUTH_TOKEN"] = oauth_token
    return env


def _cap_system(text: str | None) -> str:
    text = text or ""
    if len(text) > SYSTEM_PROMPT_CAP:
        logger.warning("system prompt %d자 → %d자로 캡", len(text), SYSTEM_PROMPT_CAP)
        text = text[: SYSTEM_PROMPT_CAP - 20] + "\n…(시스템 프롬프트 생략)…"
    return text


def _common_tail(model: str, budget: float, system_md: str | None, fallback_model: str = "") -> list[str]:
    tail = ["--model", model, "--max-budget-usd", f"{budget:.2f}", "--setting-sources", "user"]
    if fallback_model:
        tail += ["--fallback-model", fallback_model]
    if system_md:
        tail += ["--append-system-prompt", _cap_system(system_md)]
    return tail


def plan_argv(claude: list[str], *, schema: dict, session_id: str, model: str, budget: float,
              system_md: str, fallback_model: str = "", protect: list[str] | tuple = ()) -> list[str]:
    return [*claude, "-p", "--output-format", "json", "--json-schema", json.dumps(schema, ensure_ascii=False),
            "--permission-mode", "dontAsk", "--tools", PLAN_TOOLS,
            "--allowedTools", *READ_ONLY,
            "--disallowedTools", "Edit", "Write", "MultiEdit", "NotebookEdit", *DENY, *protect,
            "--session-id", session_id,
            *_common_tail(model, budget, system_md, fallback_model)]


def exec_argv(claude: list[str], *, session_id: str, resume: bool, model: str, budget: float,
              system_md: str, fallback_model: str = "", protect: list[str] | tuple = ()) -> list[str]:
    sess = ["--resume", session_id] if resume else ["--session-id", session_id]
    return [*claude, "-p", *sess, "--output-format", "json", "--permission-mode", "acceptEdits",
            "--tools", EXEC_TOOLS, "--allowedTools", *EXEC_ALLOW, "--disallowedTools", *DENY, *protect,
            *_common_tail(model, budget, system_md, fallback_model)]


def review_argv(claude: list[str], *, schema: dict, model: str, budget: float, system_md: str,
                fallback_model: str = "", protect: list[str] | tuple = ()) -> list[str]:
    return [*claude, "-p", "--output-format", "json", "--json-schema", json.dumps(schema, ensure_ascii=False),
            "--permission-mode", "dontAsk", "--tools", REVIEW_TOOLS, "--disallowedTools", *DENY, *protect,
            "--session-id", str(uuid.uuid4()),
            *_common_tail(model, budget, system_md, fallback_model)]


def llm_argv(claude: list[str], *, schema: dict, model: str, budget: float, system_md: str,
             fallback_model: str = "") -> list[str]:
    """도구 없는 단발 구조화 호출(cli_llm 폴백). --tools "" 로 모든 도구를 끈다."""
    return [*claude, "-p", "--output-format", "json", "--json-schema", json.dumps(schema, ensure_ascii=False),
            "--tools", "", "--permission-mode", "dontAsk", "--no-session-persistence",
            *_common_tail(model, budget, system_md, fallback_model)]


@dataclass
class ClaudeResult:
    rc: int
    session_id: str | None = None
    subtype: str = ""
    is_error: bool = False
    result: str = ""
    structured_output: dict | None = None
    total_cost_usd: float = 0.0
    num_turns: int = 0
    duration_ms: int = 0
    permission_denials: list = field(default_factory=list)
    input_tokens: int = 0
    output_tokens: int = 0
    model: str | None = None
    budget_hit: bool = False
    timed_out: bool = False
    raw: dict | None = None
    stderr: str = ""
    error: str = ""

    @property
    def ok(self) -> bool:
        return not self.timed_out and self.raw is not None and (not self.is_error or self.structured_output is not None)

    @property
    def not_found_session(self) -> bool:
        blob = f"{self.result} {self.stderr} {self.error}".lower()
        return "no conversation found" in blob or ("session" in blob and "not found" in blob)


def _find_result_json(text: str) -> dict | None:
    """stdout 마지막에서부터 '"type":"result"' 가 든 JSON 객체를 찾는다(경고 줄 선행 허용)."""
    lines = text.strip().splitlines()
    for line in reversed(lines):
        s = line.strip()
        if s.startswith("{") and '"type"' in s:
            try:
                d = json.loads(s)
            except ValueError:
                continue
            if isinstance(d, dict) and d.get("type") == "result":
                return d
    # 여러 줄에 걸친 JSON: 마지막 '{' 블록
    idx = text.rfind('{"type":"result"')
    if idx < 0:
        idx = text.rfind('"type": "result"')
        if idx >= 0:
            idx = text.rfind("{", 0, idx)
    if idx >= 0:
        try:
            d = json.loads(text[idx:])
            if isinstance(d, dict):
                return d
        except ValueError:
            pass
    return None


def parse_result(stdout: str, rc: int, stderr: str = "", timed_out: bool = False) -> ClaudeResult:
    d = _find_result_json(stdout or "")
    r = ClaudeResult(rc=rc, stderr=stderr or "", timed_out=timed_out)
    if d is None:
        r.error = (stderr or stdout or "").strip()[-2000:] or f"claude 종료코드 {rc}"
        return r
    r.raw = d
    r.session_id = d.get("session_id")
    r.subtype = str(d.get("subtype") or "")
    r.is_error = bool(d.get("is_error"))
    res = d.get("result")
    r.result = res if isinstance(res, str) else (json.dumps(res, ensure_ascii=False) if res is not None else "")
    so = d.get("structured_output")
    if isinstance(so, str):
        try:
            so = json.loads(so)
        except ValueError:
            so = None
    r.structured_output = so if isinstance(so, dict) else None
    r.total_cost_usd = float(d.get("total_cost_usd") or 0)
    r.num_turns = int(d.get("num_turns") or 0)
    r.duration_ms = int(d.get("duration_ms") or 0)
    r.permission_denials = list(d.get("permission_denials") or [])
    usage = d.get("usage") or {}
    r.input_tokens = int(usage.get("input_tokens") or 0) + int(usage.get("cache_read_input_tokens") or 0) \
        + int(usage.get("cache_creation_input_tokens") or 0)
    r.output_tokens = int(usage.get("output_tokens") or 0)
    mu = d.get("modelUsage") or {}
    if isinstance(mu, dict) and mu:
        r.model = max(mu.items(), key=lambda kv: (kv[1] or {}).get("outputTokens", 0)
                      if isinstance(kv[1], dict) else 0)[0]
    r.budget_hit = rc == 2 or "budget" in r.subtype.lower() or "budget" in r.result.lower()[:300]
    if r.is_error and not r.structured_output:
        r.error = r.result[-2000:] or r.subtype
    return r


def run_claude(runner, argv: list[str], prompt: str, *, cwd: str | None, env: dict, timeout: float,
               job_dir: Path | None = None, tag: str = "claude") -> ClaudeResult:
    """프롬프트는 stdin. 원문은 var/jobs/<id>/<tag>_stdout.json 에 남긴다."""
    if job_dir:
        job_dir.mkdir(parents=True, exist_ok=True)
        (job_dir / f"{tag}_prompt.txt").write_text(prompt, encoding="utf-8")
        (job_dir / f"{tag}_argv.json").write_text(json.dumps(argv, ensure_ascii=False, indent=1), encoding="utf-8")
    c = runner.run(argv, cwd=cwd, env=env, input=prompt.encode("utf-8"), timeout=timeout)
    out = decode_best(c.stdout)
    err = decode_best(c.stderr)
    if job_dir:
        (job_dir / f"{tag}_stdout.json").write_bytes(c.stdout or b"")
        if c.stderr:
            (job_dir / f"{tag}_stderr.txt").write_bytes(c.stderr)
    r = parse_result(out, c.rc, err, c.timed_out)
    if c.timed_out:
        r.error = f"타임아웃 {int(timeout)}초"
    return r
