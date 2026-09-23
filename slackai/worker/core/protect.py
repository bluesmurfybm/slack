"""환경별 설정 파일 보호 — AI 가 보지도, 판단 근거로 쓰지도, 고치지도, 커밋하지도 않게 한다.

대상(PROTECTED_PATHS, 기본): 저장소 루트의 config.php, local/ubion/config.php
  → 테스트 서버·운영 서버마다 값이 달라서(DB 접속, wwwroot, 키 등) 작업 사본의 값으로 원인을 추정하면 틀리고,
    diff/커밋에 섞이면 사고가 난다. 로컬 개발용으로 수정돼 있는 경우도 많다.

적용 지점
  1. Claude 권한 규칙(claude_rules): Read/Edit 거부(Grep·Glob 도 Read 규칙을 따른다 — 실측 확인),
     그 경로를 언급하는 Bash(svn cat/diff/blame, git show/diff …) 거부, 경로 없는 전체 diff(`svn diff`, `git diff`) 거부.
  2. 프롬프트 규칙(prompt_rule): 열지 말 것, 값에 기대지 말 것, files 에 넣지 말 것, 필요하면 '환경별 설정 확인 필요' 로 적을 것.
  3. 플랜 정리(strip_plan): files/steps 에서 제거하고 risks 에 한 줄 남긴다.
  4. 실행·커밋: 작업사본 dirty 판정·변경 파일·diff·커밋 대상에서 제외(filter_entries, filter_diff).
"""

from __future__ import annotations

import hashlib
import os
import re

DEFAULT_PATHS = ("config.php", "local/ubion/config.php")


def parse(value: str | None) -> list[str]:
    """'a;b,c' → 정규화된 저장소 상대 경로 목록('/' 구분, 앞의 ./ 와 / 제거)."""
    out: list[str] = []
    for raw in re.split(r"[;,\n]", value or ""):
        p = norm(raw)
        if p and p not in out:
            out.append(p)
    return out


def norm(p: str | None) -> str:
    p = (p or "").strip().replace("\\", "/")
    while p.startswith("./"):
        p = p[2:]
    return p.lstrip("/")


def is_protected(path: str | None, paths: list[str] | tuple[str, ...]) -> bool:
    n = norm(path).lower()
    return bool(n) and any(n == q.lower() for q in paths)


def claude_rules(paths: list[str] | tuple[str, ...], remote_url: str | None = None,
                 local_path: str | None = None) -> list[str]:
    """claude --disallowedTools 에 붙일 규칙."""
    if not paths:
        return []
    rules: list[str] = []
    add = rules.append
    lp = (local_path or "").rstrip("\\/")
    url = (remote_url or "").rstrip("/")
    for p in paths:
        win = p.replace("/", "\\")
        add(f"Read(./{p})")
        add(f"Edit(./{p})")
        if "/" in p:
            # 하위 경로는 경로 문자열 자체가 충분히 고유하다
            add(f"Bash(*{p}*)")
            add(f"Bash(*{win}*)")
        else:
            # 루트 파일(config.php)은 같은 이름의 하위 파일(theme/x/config.php)을 막지 않도록 루트를 가리키는 형태만
            add(f"Bash(* {p}*)")          # svn diff config.php / ls config.php
            add(f"Bash(*:{p}*)")          # git show HEAD:config.php
            add(f"Bash(*./{p}*)")         # svn cat ./config.php
            add(f"Bash(*.\\{p}*)")
        if url:
            add(f"Bash(*{url}/{p}*)")     # svn cat svn://host/repo/moodle/config.php
        if lp:
            add(f"Bash(*{lp}\\{win}*)")
            add(f"Bash(*{lp.replace(chr(92), '/')}/{p}*)")
    # 경로 없는 전체 diff 는 로컬에서 고친 설정 파일 내용까지 보여준다 → 파일을 지정한 diff 만 허용
    rules += ["Bash(svn diff)", "Bash(git diff)", "Bash(git diff HEAD)", "Bash(svn diff .)", "Bash(git diff .)"]
    out: list[str] = []
    for r in rules:
        if r not in out:
            out.append(r)
    return out


def prompt_rule(paths: list[str] | tuple[str, ...]) -> str:
    if not paths:
        return ""
    lst = ", ".join(f"`{p}`" for p in paths)
    return (
        "\n\n## 환경별 설정 파일 — 조회·판단·수정 금지\n"
        f"- {lst} 는 테스트 서버와 운영 서버마다 값이 다른 환경 설정 파일이다. 작업 사본에 있는 값은 로컬 개발용일 수 있다.\n"
        "- 이 파일들은 열거나(Read/Grep/svn cat/git show/diff) 검색하지 말고, 그 안의 값을 원인 추정이나 계획의 근거로 쓰지 않는다.\n"
        "- 전체 diff(`svn diff`, `git diff`) 대신 파일을 지정해서 본다(`svn status` 로 목록 확인 후 필요한 파일만).\n"
        "- 계획의 files/steps 에 넣지 않고, 실행 시 수정하지 않는다.\n"
        "- 설정값이 원인일 수 있으면 확인할 설정 키 이름만 적고 '환경별 설정 확인 필요(테스트/운영)' 로 questions 또는 risks 에 남긴다."
    )


def strip_plan(plan: dict, paths: list[str] | tuple[str, ...]) -> dict:
    """정규화된 플랜에서 보호 파일을 제거. 제거했으면 risks 에 안내 한 줄."""
    if not paths:
        return plan
    out = dict(plan)
    dropped = [f.get("path") for f in plan.get("files") or [] if is_protected(f.get("path"), paths)]
    out["files"] = [f for f in plan.get("files") or [] if not is_protected(f.get("path"), paths)]
    steps = []
    for step in plan.get("steps") or []:
        st = dict(step)
        st["files"] = [x for x in st.get("files") or [] if not is_protected(x, paths)]
        steps.append(st)
    out["steps"] = steps
    if dropped:
        note = ("환경별 설정 파일(" + ", ".join(sorted(set(dropped))) + ")은 계획에서 제외했습니다 — "
                "필요한 설정은 테스트/운영 서버에서 사람이 직접 확인·반영하세요.")
        out["risks"] = list(plan.get("risks") or []) + [note]
    return out


def filter_entries(entries: list, paths: list[str] | tuple[str, ...]) -> list:
    """vcs status 항목(StatusEntry: .path)에서 보호 파일 제외."""
    return [e for e in entries if not is_protected(getattr(e, "path", None), paths)]


_SECTION_START = re.compile(r"^(?:Index: (?P<svn>.+)|diff --git a/(?P<a>.+?) b/(?P<b>.+))$")


def filter_diff(diff: str, paths: list[str] | tuple[str, ...]) -> str:
    """unified diff 에서 보호 파일 구간(svn 'Index:' / git 'diff --git' 부터 다음 헤더 전까지)을 통째로 뺀다."""
    if not diff or not paths:
        return diff or ""
    out: list[str] = []
    skip = False
    for line in diff.splitlines(keepends=True):
        m = _SECTION_START.match(line.rstrip("\r\n"))
        if m:
            p = m.group("svn") or m.group("b") or m.group("a")
            skip = is_protected(p, paths)
        if not skip:
            out.append(line)
    return "".join(out)


def fingerprint(root: str, paths: list[str] | tuple[str, ...]) -> dict[str, str | None]:
    """보호 파일 내용 해시(실행 전후 비교용 — Claude 가 거부 규칙을 우회해 바꿨는지 감지)."""
    fp: dict[str, str | None] = {}
    for p in paths:
        f = os.path.join(root, *p.split("/"))
        try:
            with open(f, "rb") as h:
                fp[p] = hashlib.sha256(h.read()).hexdigest()
        except OSError:
            fp[p] = None
    return fp
