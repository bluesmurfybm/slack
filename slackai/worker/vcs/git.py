"""git 작업 사본. porcelain 파싱, diff HEAD, add/commit/rev-parse. push 는 SLACKAI_GIT_PUSH=1 + params.push 일 때만."""

import os

from core.text import decode_best
from vcs.base import StatusEntry, cap_diff, norm


def parse_porcelain(text: str) -> list[StatusEntry]:
    """`git status --porcelain=v1 -z` 또는 줄바꿈 형식. '??' → '?', 그 외 XY 중 유의미한 문자."""
    out: list[StatusEntry] = []
    raw = text or ""
    items = raw.split("\0") if "\0" in raw else raw.splitlines()
    skip_next = False
    for it in items:
        if skip_next:
            skip_next = False
            continue
        if len(it) < 4:
            continue
        xy, path = it[:2], it[3:]
        if xy == "??":
            out.append(StatusEntry("?", norm(path)))
            continue
        if xy == "!!":
            continue
        if xy[0] in "RC":
            # 'R  old -> new' (줄 형식) 또는 -z 에서 다음 항목이 원경로
            if " -> " in path:
                path = path.split(" -> ", 1)[1]
            else:
                skip_next = "\0" in raw
        code = xy[0] if xy[0] != " " else xy[1]
        if code == "?":
            code = "A"
        out.append(StatusEntry(code, norm(path)))
    return out


class Git:
    kind = "git"

    def __init__(self, runner, root: str, git_bin: str = "git", env: dict | None = None):
        self.runner = runner
        self.root = root
        self.git = git_bin
        self.env = env or {**os.environ, "LC_ALL": "C", "LANG": "C", "GIT_TERMINAL_PROMPT": "0",
                           "PYTHONUTF8": "1"}

    def _run(self, *args: str, timeout: float = 600, check: bool = True):
        c = self.runner.run([self.git, *args], cwd=self.root, env=self.env, timeout=timeout)
        if check and c.rc != 0:
            raise RuntimeError(f"git {args[0]} 실패(rc={c.rc}): {decode_best(c.stderr)[-1500:]}")
        return c

    def status(self) -> list[StatusEntry]:
        c = self._run("status", "--porcelain=v1", "--untracked-files=all")
        return parse_porcelain(decode_best(c.stdout))

    def add(self, paths: list[str]) -> None:
        """신규 파일을 intent-to-add 로 → diff HEAD 에 보이게(스테이징은 커밋 때)."""
        if paths:
            self._run("add", "-N", "--", *paths)

    def add_all(self, paths: list[str]) -> None:
        if paths:
            self._run("add", "--", *paths)

    def diff(self) -> str:
        c = self._run("diff", "HEAD", "--no-color", "--no-ext-diff", timeout=900, check=False)
        return cap_diff(decode_best(c.stdout, force_utf8=True))

    def commit(self, msgfile: str, paths: list[str]) -> str:
        if paths:
            self._run("add", "--", *paths)
        self._run("commit", "-F", msgfile, "--", *paths, timeout=600)
        return self.head_revision()

    def head_revision(self) -> str:
        c = self._run("rev-parse", "HEAD", check=False)
        return decode_best(c.stdout).strip()

    def branch(self) -> str:
        c = self._run("rev-parse", "--abbrev-ref", "HEAD", check=False)
        return decode_best(c.stdout).strip()

    def push(self) -> str:
        c = self._run("push", timeout=600)
        return decode_best(c.stdout) + decode_best(c.stderr)

    def pull_ff(self) -> str:
        c = self._run("pull", "--ff-only", timeout=600)
        return decode_best(c.stdout)

    def revert(self, paths: list[str]) -> None:
        if paths:
            self._run("checkout", "--", *paths, check=False)
