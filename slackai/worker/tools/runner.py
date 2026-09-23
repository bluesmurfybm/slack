"""서브프로세스 실행기. shell=False, bytes I/O, Windows 는 CREATE_NO_WINDOW, 타임아웃 시 프로세스 트리 kill.

FakeRunner 는 테스트용: argv 접두 일치로 준비된 Completed 를 재생한다.
"""

import logging
import os
import subprocess
import sys
import threading
import time
from collections.abc import Callable
from dataclasses import dataclass

from core.text import decode_best, mask_secrets

logger = logging.getLogger(__name__)
IS_WIN = sys.platform.startswith("win")


@dataclass
class Completed:
    argv: list[str]
    rc: int
    stdout: bytes = b""
    stderr: bytes = b""
    timed_out: bool = False
    duration_ms: int = 0

    @property
    def out(self) -> str:
        return decode_best(self.stdout)

    @property
    def err(self) -> str:
        return decode_best(self.stderr)

    @property
    def ok(self) -> bool:
        return self.rc == 0 and not self.timed_out


def kill_tree(proc: subprocess.Popen) -> None:
    """자식(node 등)까지 함께 죽인다."""
    try:
        if IS_WIN:
            subprocess.run(["taskkill", "/T", "/F", "/PID", str(proc.pid)], capture_output=True, check=False,
                           timeout=30, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        proc.kill()
    except Exception:
        logger.debug("kill_tree 실패(무시)", exc_info=True)


class SubprocessRunner:
    def __init__(self, log: logging.Logger | None = None):
        self.log = log or logger
        self._current: subprocess.Popen | None = None
        self._lock = threading.Lock()

    def kill_current(self) -> None:
        with self._lock:
            p = self._current
        if p and p.poll() is None:
            self.log.warning("현재 서브프로세스 kill (pid %s)", p.pid)
            kill_tree(p)

    def run(self, argv: list[str], *, cwd: str | None = None, env: dict | None = None,
            input: bytes | str | None = None, timeout: float | None = None) -> Completed:
        t0 = time.monotonic()
        data = input.encode("utf-8") if isinstance(input, str) else input
        flags = getattr(subprocess, "CREATE_NO_WINDOW", 0) if IS_WIN else 0
        self.log.debug("run: %s (cwd=%s)", mask_secrets(" ".join(argv[:12])), cwd)
        try:
            proc = subprocess.Popen(
                argv, cwd=cwd, env=env, stdin=subprocess.PIPE if data is not None else subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, creationflags=flags)
        except FileNotFoundError as e:
            return Completed(argv, 127, b"", str(e).encode("utf-8"), False, 0)
        with self._lock:
            self._current = proc
        timed_out = False
        try:
            out, err = proc.communicate(input=data, timeout=timeout)
        except subprocess.TimeoutExpired:
            timed_out = True
            kill_tree(proc)
            try:
                out, err = proc.communicate(timeout=15)
            except Exception:
                out, err = b"", b""
        finally:
            with self._lock:
                self._current = None
        ms = int((time.monotonic() - t0) * 1000)
        rc = proc.returncode if proc.returncode is not None else -9
        return Completed(argv, rc, out or b"", err or b"", timed_out, ms)


@dataclass
class FakeRoute:
    prefix: list[str]
    result: Completed | Callable[[list[str], dict], Completed]


class FakeRunner:
    """argv 접두(또는 부분 문자열) 매칭으로 Completed 를 재생한다. 호출 기록으로 검증한다."""

    def __init__(self):
        self.routes: list[FakeRoute] = []
        self.calls: list[dict] = []

    def on(self, *prefix: str, rc: int = 0, stdout: str | bytes = "", stderr: str | bytes = "",
           fn: Callable | None = None, timed_out: bool = False) -> "FakeRunner":
        so = stdout.encode("utf-8") if isinstance(stdout, str) else stdout
        se = stderr.encode("utf-8") if isinstance(stderr, str) else stderr
        res = fn or Completed(list(prefix), rc, so, se, timed_out, 1)
        self.routes.append(FakeRoute(list(prefix), res))
        return self

    def kill_current(self) -> None:
        self.calls.append({"argv": ["<kill>"]})

    def run(self, argv: list[str], *, cwd=None, env=None, input=None, timeout=None) -> Completed:
        call = {"argv": list(argv), "cwd": cwd, "env": env, "input": input, "timeout": timeout}
        self.calls.append(call)
        for r in self.routes:
            if _match(argv, r.prefix):
                res = r.result(argv, call) if callable(r.result) else r.result
                return Completed(list(argv), res.rc, res.stdout, res.stderr, res.timed_out, res.duration_ms)
        raise AssertionError(f"준비되지 않은 명령: {argv}")

    def argv_of(self, needle: str) -> list[list[str]]:
        return [c["argv"] for c in self.calls if any(needle in str(a) for a in c["argv"])]


def _match(argv: list[str], prefix: list[str]) -> bool:
    """접두 각 항목이 argv 의 같은 위치 값의 basename/부분 문자열과 맞으면 일치. '*' 는 아무 값."""
    if len(prefix) > len(argv):
        return False
    for want, got in zip(prefix, argv, strict=False):
        if want == "*":
            continue
        g = str(got)
        if want == g or os.path.basename(g).lower().startswith(want.lower()) or want in g:
            continue
        return False
    return True
