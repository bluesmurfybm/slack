"""작업 사본 종류 판별 + 인스턴스 생성."""

from pathlib import Path

from vcs.git import Git
from vcs.svn import Svn


def detect(path: str) -> str | None:
    """'.svn' / '.git' 을 루트에서(그리고 svn 은 상위로도) 찾는다. 없으면 None."""
    p = Path(path)
    if not p.is_dir():
        return None
    if (p / ".git").exists():
        return "git"
    if (p / ".svn").is_dir():
        return "svn"
    # svn 1.7+ 는 WC 루트에만 .svn 이 있다 → 상위 탐색
    for parent in p.parents:
        if (parent / ".svn").is_dir():
            return "svn"
        if (parent / ".git").exists():
            return "git"
    return None


def open_vcs(kind: str, runner, root: str, settings) -> Svn | Git:
    if kind == "svn":
        return Svn(runner, root, settings.svn_bin)
    if kind == "git":
        return Git(runner, root, settings.git_bin)
    raise ValueError(f"알 수 없는 vcs: {kind}")
