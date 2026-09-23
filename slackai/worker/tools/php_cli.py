"""slackai 의 PHP CLI 호출(php -l / similar_cli / comments_cli / post_cli / sync.php).

cwd 는 항상 저장소 루트(slackai/*.php 가 ../config.php 를 require 한다). 토큰은 env SLACK_TOKEN/SLACK_BOT_TOKEN 으로만 넘긴다.
"""

import json
import logging
import os
import shutil
from pathlib import Path

from core.config import REPO_ROOT
from core.text import decode_best

logger = logging.getLogger(__name__)


def resolve_php(php_bin: str) -> str | None:
    p = Path(php_bin)
    if p.suffix and p.is_file():
        return str(p)
    return shutil.which(php_bin)


def php_env(settings) -> dict:
    env = dict(os.environ)
    env["PYTHONUTF8"] = "1"
    tok = settings.slack_cli_token
    if tok:
        env["SLACK_TOKEN"] = tok
    if settings.slack_bot_token:
        env["SLACK_BOT_TOKEN"] = settings.slack_bot_token
    return env


def _json(c) -> dict:
    out = decode_best(c.stdout).strip()
    if not out:
        return {"ok": False, "error": decode_best(c.stderr).strip()[-500:] or f"php rc={c.rc}"}
    # 마지막 JSON 줄(PHP 경고가 앞에 붙을 수 있다)
    for line in reversed(out.splitlines()):
        s = line.strip()
        if s.startswith("{"):
            try:
                return json.loads(s)
            except ValueError:
                continue
    return {"ok": False, "error": out[-500:]}


def php_lint(runner, php_bin: str, path: str, timeout: float = 60) -> tuple[bool, str]:
    exe = resolve_php(php_bin) or php_bin
    c = runner.run([exe, "-l", path], timeout=timeout)
    text = (decode_best(c.stdout) + decode_best(c.stderr)).strip()
    return (c.rc == 0 and "No syntax errors" in text), text


def similar(runner, settings, request_id: str, limit: int = 20, min_score: float = 0.15,
            timeout: float = 120) -> dict:
    exe = resolve_php(settings.php_bin)
    if not exe:
        return {"ok": False, "error": "php 없음"}
    argv = [exe, str(REPO_ROOT / "slackai" / "similar" / "similar_cli.php"), "--id", request_id,
            "--limit", str(int(limit)), "--min", f"{float(min_score):.3f}"]
    return _json(runner.run(argv, cwd=str(REPO_ROOT), env=php_env(settings), timeout=timeout))


def comments(runner, settings, request_id: str, max_messages: int = 60, timeout: float = 120) -> dict:
    exe = resolve_php(settings.php_bin)
    if not exe:
        return {"ok": False, "error": "php 없음"}
    if not settings.slack_cli_token:
        return {"ok": False, "error": "no_token"}
    argv = [exe, str(REPO_ROOT / "slackai" / "tools" / "comments_cli.php"), "--id", request_id,
            "--max", str(int(max_messages))]
    return _json(runner.run(argv, cwd=str(REPO_ROOT), env=php_env(settings), timeout=timeout))


def post_thread(runner, settings, request_id: str, text: str, tmp_dir: Path, timeout: float = 60) -> dict:
    exe = resolve_php(settings.php_bin)
    if not exe:
        return {"ok": False, "error": "php 없음"}
    if not settings.slack_cli_token:
        return {"ok": False, "error": "no_token"}
    tmp_dir.mkdir(parents=True, exist_ok=True)
    f = tmp_dir / "slack_post.txt"
    f.write_text(text, encoding="utf-8")
    argv = [exe, str(REPO_ROOT / "slackai" / "tools" / "post_cli.php"), "--id", request_id, "--text-file", str(f)]
    return _json(runner.run(argv, cwd=str(REPO_ROOT), env=php_env(settings), timeout=timeout))


def sync(runner, settings, args: list[str], timeout: float = 600) -> dict:
    """php slackai/sync.php <args…> json"""
    exe = resolve_php(settings.php_bin)
    if not exe:
        return {"ok": False, "error": "php 없음"}
    argv = [exe, str(REPO_ROOT / "slackai" / "sync.php"), *args]
    if "json" not in args:
        argv.append("json")
    return _json(runner.run(argv, cwd=str(REPO_ROOT), env=php_env(settings), timeout=timeout))
