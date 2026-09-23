"""VCS 공통 타입·순수 파서(테스트 가능). svn.py / git.py 가 구현한다."""

import re
from dataclasses import dataclass
from typing import Protocol

DIFF_CAP = 2 * 1024 * 1024 # 2MB
TRACKED_CODES = set("MADRC!~") # svn 의 tracked 변경 코드(untracked '?' 제외)


@dataclass
class StatusEntry:
    code: str # svn: M A D R C ! ~ ? ; git: porcelain XY 의 유의미한 문자('??' 는 untracked)
    path: str # 저장소(WC) 루트 상대, '/' 구분

    @property
    def untracked(self) -> bool:
        return self.code == "?"


@dataclass
class DiffStat:
    path: str
    add: int
    dele: int
    status: str # M A D

    def to_dict(self) -> dict:
        return {"path": self.path, "add": self.add, "del": self.dele, "status": self.status}


class VCS(Protocol):
    kind: str
    root: str

    def status(self) -> list[StatusEntry]: ...
    def add(self, paths: list[str]) -> None: ...
    def diff(self) -> str: ...
    def commit(self, msgfile: str, paths: list[str]) -> str: ...
    def head_revision(self) -> str: ...
    def revert(self, paths: list[str]) -> None: ...


def norm(p: str) -> str:
    return p.strip().replace("\\", "/").lstrip("./") if p.strip() not in (".", "./") else p.strip()


def tracked_changes(entries: list[StatusEntry]) -> list[StatusEntry]:
    return [e for e in entries if not e.untracked]


def untracked(entries: list[StatusEntry]) -> list[str]:
    return [e.path for e in entries if e.untracked]


def changed_files(entries: list[StatusEntry], preexisting: set[str] | None = None) -> list[str]:
    """변경 파일 = tracked 변경 + (preexisting 에 없는) untracked."""
    pre = preexisting or set()
    out = []
    for e in entries:
        if e.untracked and e.path in pre:
            continue
        out.append(e.path)
    return sorted(set(out))


_DIFF_FILE_RE = re.compile(r"^(?:Index: (.+)|diff --git a/(.+?) b/(.+)|\+\+\+ (?:b/)?(.+?)(?:\t.*)?)$")


def diff_stat(diff_text: str) -> list[DiffStat]:
    """unified diff → 파일별 +/- 수. svn 은 'Index:' 헤더, git 은 'diff --git'."""
    stats: list[DiffStat] = []
    cur: DiffStat | None = None
    new_file = deleted = False
    for line in (diff_text or "").splitlines():
        if line.startswith("Index: "):
            cur = DiffStat(norm(line[7:]), 0, 0, "M")
            stats.append(cur)
            new_file = deleted = False
            continue
        if line.startswith("diff --git "):
            m = re.match(r"diff --git a/(.+?) b/(.+)$", line)
            cur = DiffStat(norm(m.group(2) if m else line[11:]), 0, 0, "M")
            stats.append(cur)
            new_file = deleted = False
            continue
        if cur is None:
            continue
        if line.startswith("--- ") and ("(nonexistent)" in line or line.startswith("--- /dev/null")
                                        or "(revision 0)" in line):
            new_file = True
            cur.status = "A"
            continue
        if line.startswith("+++ ") and ("(nonexistent)" in line or line.startswith("+++ /dev/null")):
            deleted = True
            cur.status = "D"
            continue
        if line.startswith("new file mode"):
            cur.status = "A"
            continue
        if line.startswith("deleted file mode"):
            cur.status = "D"
            continue
        if line.startswith("+") and not line.startswith("+++"):
            cur.add += 1
        elif line.startswith("-") and not line.startswith("---"):
            cur.dele += 1
    _ = (new_file, deleted)
    return stats


def sum_stat(stats: list[DiffStat]) -> tuple[int, int, int]:
    return len(stats), sum(s.add for s in stats), sum(s.dele for s in stats)


def cap_diff(diff_text: str, cap: int = DIFF_CAP) -> str:
    if len(diff_text) <= cap:
        return diff_text
    return diff_text[:cap] + f"\n…(diff {len(diff_text)} 자 중 {cap} 자까지만 저장)…\n"
