"""svn 작업 사본. 모든 명령 --non-interactive, 인증은 캐시된 자격증명(--password 절대 사용 안 함)."""

import os
import re

from core.text import decode_best
from vcs.base import StatusEntry, cap_diff, norm

# 'Committed revision 123.' / '커밋된 리비전 123.'
COMMIT_REV_RE = re.compile(r"(?:Committed revision|커밋된 리비전)\s+(\d+)")
# svn status 첫 열: 상태 코드, 8열 뒤 경로(EN/KR 동일 — 텍스트 라벨이 아니라 코드 열이다)
STATUS_LINE_RE = re.compile(r"^([ MADRC!~?IX])[ MC][ L][ +][ SX][ KOTB][ C]?\s+(.+?)\s*$")


def parse_status(text: str) -> list[StatusEntry]:
    out: list[StatusEntry] = []
    for raw in (text or "").splitlines():
        line = raw.rstrip("\r")
        if not line.strip() or line.startswith(("Status against", "---", "Summary", "        >")):
            continue
        if len(line) < 8:
            continue
        code = line[0]
        if code in (" ", "I", "X"):
            continue
        # 7~8열 이후가 경로. svn 1.14 는 8열 고정 폭이다.
        path = line[8:].strip() if len(line) > 8 else line[7:].strip()
        if not path:
            continue
        # 'M       path' 형식이 아니면 정규식으로 한 번 더
        if path.startswith(" ") or path == "":
            m = STATUS_LINE_RE.match(line)
            if m:
                path = m.group(2)
        out.append(StatusEntry(code, norm(path)))
    return out


def parse_commit_revision(text: str) -> str | None:
    m = COMMIT_REV_RE.search(text or "")
    return m.group(1) if m else None


class Svn:
    kind = "svn"

    def __init__(self, runner, root: str, svn_bin: str = "svn", env: dict | None = None):
        self.runner = runner
        self.root = root
        self.svn = svn_bin
        self.env = env or {**os.environ, "LC_ALL": "C", "LANG": "C", "PYTHONUTF8": "1"}

    def _run(self, *args: str, timeout: float = 600, check: bool = True):
        c = self.runner.run([self.svn, *args], cwd=self.root, env=self.env, timeout=timeout)
        if check and c.rc != 0:
            raise RuntimeError(f"svn {args[0]} 실패(rc={c.rc}): {decode_best(c.stderr)[-1500:]}")
        return c

    def status(self) -> list[StatusEntry]:
        c = self._run("status", "--non-interactive", "--ignore-externals")
        return parse_status(decode_best(c.stdout))

    def add(self, paths: list[str]) -> None:
        if paths:
            self._run("add", "--non-interactive", "--parents", "--no-auto-props", "--", *paths)

    def diff(self) -> str:
        c = self._run("diff", "--non-interactive", "--internal-diff", timeout=900, check=False)
        return cap_diff(decode_best(c.stdout, force_utf8=True))

    def commit(self, msgfile: str, paths: list[str]) -> str:
        c = self._run("commit", "--non-interactive", "--encoding", "UTF-8", "-F", msgfile, "--", *paths,
                      timeout=1800)
        rev = parse_commit_revision(decode_best(c.stdout) + decode_best(c.stderr))
        if not rev:
            rev = self.last_changed_revision()
        return rev or ""

    def last_changed_revision(self) -> str:
        c = self._run("info", "--non-interactive", "--show-item", "last-changed-revision", check=False)
        return decode_best(c.stdout).strip()

    def head_revision(self) -> str:
        c = self._run("info", "--non-interactive", "--show-item", "revision", check=False)
        return decode_best(c.stdout).strip()

    def info_url(self) -> str:
        c = self._run("info", "--non-interactive", "--show-item", "url", check=False)
        return decode_best(c.stdout).strip()

    def update(self) -> str:
        c = self._run("update", "--non-interactive", timeout=1800)
        return decode_best(c.stdout)

    def revert(self, paths: list[str]) -> None:
        if paths:
            self._run("revert", "--non-interactive", "-R", "--", *paths)

    @staticmethod
    def parent_dirs_for_new(paths: list[str], preexisting_dirs: set[str] | None = None) -> list[str]:
        """신규 파일의 부모 디렉터리(svn add --parents 로 함께 추가된 것)를 커밋 대상에 넣는다."""
        out: set[str] = set()
        for p in paths:
            parts = norm(p).split("/")
            for i in range(1, len(parts)):
                d = "/".join(parts[:i])
                if d and d not in (preexisting_dirs or set()):
                    out.add(d)
        return sorted(out)
