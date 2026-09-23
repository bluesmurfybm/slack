"""codex CLI 래퍼(검토 백엔드). 미설치면 available()=False.

argv(플랜 §2.3): codex exec --sandbox read-only -C <repo> --skip-git-repo-check --json --output-last-message <file> [-m MODEL] -
프롬프트는 stdin('-'). 마지막 메시지 파일에서 json_block() 으로 REVIEW JSON 을 뽑는다.
플래그는 설치 후 --selftest 로 확인해야 한다(문서 미확인). 실패 시 openai API 백엔드로 폴백.
"""

import json
import logging
import shutil
from dataclasses import dataclass
from pathlib import Path

from core.text import decode_best, json_block

logger = logging.getLogger(__name__)


def resolve_codex(name: str = "codex", which=shutil.which) -> list[str] | None:
    p = Path(name)
    if p.suffix and p.is_file():
        return [str(p)]
    exe = which(name) or which(name + ".cmd")
    return [exe] if exe else None


def available(name: str = "codex", which=shutil.which) -> bool:
    return resolve_codex(name, which) is not None


def review_argv(codex: list[str], *, local_path: str, out_file: str, model: str = "") -> list[str]:
    argv = [*codex, "exec", "--sandbox", "read-only", "-C", local_path, "--skip-git-repo-check", "--json",
            "--output-last-message", out_file]
    if model:
        argv += ["-m", model]
    argv.append("-")
    return argv


@dataclass
class CodexResult:
    rc: int
    data: dict | None
    text: str
    error: str = ""
    timed_out: bool = False

    @property
    def ok(self) -> bool:
        return self.data is not None and not self.timed_out


def run_codex(runner, argv: list[str], prompt: str, *, cwd: str | None, env: dict | None, timeout: float,
              out_file: Path, job_dir: Path | None = None) -> CodexResult:
    if job_dir:
        job_dir.mkdir(parents=True, exist_ok=True)
        (job_dir / "codex_prompt.txt").write_text(prompt, encoding="utf-8")
    c = runner.run(argv, cwd=cwd, env=env, input=prompt.encode("utf-8"), timeout=timeout)
    if job_dir:
        (job_dir / "codex.jsonl").write_bytes(c.stdout or b"")
        if c.stderr:
            (job_dir / "codex_stderr.txt").write_bytes(c.stderr)
    text = ""
    try:
        if out_file.is_file():
            text = out_file.read_text(encoding="utf-8", errors="replace")
    except OSError:
        text = ""
    if not text:
        text = _last_message_from_jsonl(decode_best(c.stdout))
    data = None
    try:
        data = json_block(text) if text.strip() else None
    except ValueError:
        data = None
    err = "" if data else (decode_best(c.stderr)[-2000:] or f"codex 종료코드 {c.rc}")
    return CodexResult(c.rc, data, text, err, c.timed_out)


def _last_message_from_jsonl(s: str) -> str:
    """--json 스트림에서 마지막 assistant/agent 메시지 텍스트를 찾는다(형식 변화에 관대하게)."""
    last = ""
    for raw in s.splitlines():
        line = raw.strip()
        if not line.startswith("{"):
            continue
        try:
            d = json.loads(line)
        except ValueError:
            continue
        for key in ("last_agent_message", "message", "text", "content"):
            v = d.get(key) if isinstance(d, dict) else None
            if isinstance(v, str) and v.strip():
                last = v
        item = d.get("item") if isinstance(d, dict) else None
        if isinstance(item, dict) and isinstance(item.get("text"), str):
            last = item["text"]
    return last
