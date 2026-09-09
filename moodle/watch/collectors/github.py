import re

from collectors.base import Context, Result
from core.items import Item, clip, iso

API = "https://api.github.com/repos/moodle/moodle"
WEB = "https://github.com/moodle/moodle"
# 폐기 예정 API 는 여기 적힌다. 커밋 단위로 diff 를 뽑아 요약에 넘긴다.
UPGRADE_TXT = "lib/upgrade.txt"
STABLE = re.compile(r"^MOODLE_\d+_STABLE$")


def _headers(ctx: Context) -> dict:
    h = {"Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"}
    if ctx.settings.github_token:
        h["Authorization"] = f"Bearer {ctx.settings.github_token}"
    return h


def _commits(ctx: Context, **params) -> list[dict]:
    out = []
    for page in range(1, ctx.settings.github_max_pages + 1):
        q = {"sha": "main", "since": ctx.since.strftime("%Y-%m-%dT%H:%M:%SZ"),
             "until": ctx.until.strftime("%Y-%m-%dT%H:%M:%SZ"), "per_page": 100, "page": page,
             **params}
        rows = ctx.http.get_json(f"{API}/commits", params=q, headers=_headers(ctx))
        out.extend(rows)
        if len(rows) < 100:
            break
    return out


def collect(ctx: Context) -> Result:
    items: list[Item] = []
    stats: dict = {}

    # 1) main 주간 커밋 수 + MDL 키 목록(요약에는 수만 넘긴다)
    commits = _commits(ctx)
    keys = sorted({m for c in commits for m in re.findall(r"MDL-\d+", c["commit"]["message"])})
    stats["main_commits"] = len(commits)
    stats["main_issue_keys"] = len(keys)
    stats["main_truncated"] = len(commits) >= 100 * ctx.settings.github_max_pages

    # 2) lib/upgrade.txt 변경 — deprecation/removal 감지
    for c in _commits(ctx, path=UPGRADE_TXT):
        detail = ctx.http.get_json(f"{API}/commits/{c['sha']}", headers=_headers(ctx))
        patch = next((f.get("patch", "") for f in detail.get("files", [])
                      if f.get("filename") == UPGRADE_TXT), "")
        added = "\n".join(line[1:] for line in patch.splitlines()
                          if line.startswith("+") and not line.startswith("+++"))
        title = c["commit"]["message"].splitlines()[0]
        items.append(Item(
            source="github", kind="upgrade_txt",
            title=f"lib/upgrade.txt: {title}",
            url=f"{WEB}/commit/{c['sha']}",
            published_at=iso(c["commit"]["committer"]["date"]),
            excerpt=clip(added.strip(), 3000),
            meta={"sha": c["sha"], "issue_keys": re.findall(r"MDL-\d+", title)},
            is_focus=True))
    stats["upgrade_txt_commits"] = len(items)

    # 3) 새 안정 브랜치(예: MOODLE_503_STABLE 생성 = 릴리스 준비 신호)
    branches = [b["name"] for b in ctx.http.get_json(
        f"{API}/branches", params={"per_page": 100}, headers=_headers(ctx))]
    stable = sorted(b for b in branches if STABLE.match(b))
    known = set(ctx.state.data.get("branches") or [])
    items.extend(
        Item(source="github", kind="branch", title=f"새 안정 브랜치 {b}",
             url=f"{WEB}/tree/{b}", published_at=iso(ctx.until),
             excerpt="새 메이저 릴리스가 갈라졌다. 릴리스 노트와 upgrade.txt 를 확인할 것.",
             meta={"branch": b}, is_focus=True)
        for b in stable if known and b not in known)
    ctx.state.data["branches"] = stable
    stats["latest_stable_branch"] = stable[-1] if stable else ""

    # 4) 새 태그(v5.2.2 같은 릴리스)
    tags = [t["name"] for t in ctx.http.get_json(
        f"{API}/tags", params={"per_page": 30}, headers=_headers(ctx))]
    known_tags = set(ctx.state.data.get("tags") or [])
    items.extend(
        Item(source="github", kind="release", title=f"릴리스 태그 {t}",
             url=f"{WEB}/releases/tag/{t}", published_at=iso(ctx.until),
             excerpt="", meta={"tag": t}, is_focus="rc" not in t.lower()) # rc 는 주목 아님
        for t in tags if known_tags and t not in known_tags)
    ctx.state.data["tags"] = tags
    stats["latest_tag"] = tags[0] if tags else ""

    return Result("github", items, stats)
