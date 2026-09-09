from collectors.base import Context, Result
from core.items import Item, clip, is_focus, iso

API = "https://api.github.com/repos/moodle/devdocs"
WEB = "https://github.com/moodle/devdocs"
SITE = "https://moodledev.io"

# 릴리스 노트·개발자 업데이트·로드맵. 없는 경로는 빈 목록이 돌아와 무해하다.
WATCH_PATHS = ["general/releases", "general/releases.md", "docs/devupdate.md",
               "general/community/roadmap.md", "general/development/policies.md"]
MAX_DETAIL = 12
PATCH_CLIP = 4000


def _headers(ctx: Context) -> dict:
    h = {"Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"}
    if ctx.settings.github_token:
        h["Authorization"] = f"Bearer {ctx.settings.github_token}"
    return h


def _site_url(filename: str) -> str:
    if filename.endswith(".md"):
        path = filename[:-3]
        path = path.removeprefix("docs/")
        return f"{SITE}/{path}"
    return f"{WEB}/blob/main/{filename}"


def collect(ctx: Context) -> Result:
    shas: dict[str, dict] = {}
    for path in WATCH_PATHS:
        rows = ctx.http.get_json(f"{API}/commits", params={
            "path": path, "since": ctx.since.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "until": ctx.until.strftime("%Y-%m-%dT%H:%M:%SZ"), "per_page": 50,
        }, headers=_headers(ctx))
        for c in rows:
            shas.setdefault(c["sha"], c)

    ordered = sorted(shas.values(), key=lambda c: c["commit"]["committer"]["date"], reverse=True)
    items = []
    for c in ordered[:MAX_DETAIL]:
        detail = ctx.http.get_json(f"{API}/commits/{c['sha']}", headers=_headers(ctx))
        files = [f for f in detail.get("files", [])
                 if f.get("filename", "").endswith(".md")
                 and any(f["filename"].startswith(p.split(".md")[0]) for p in WATCH_PATHS)]
        if not files:
            continue
        added = []
        for f in files:
            plus = [line[1:] for line in (f.get("patch") or "").splitlines()
                    if line.startswith("+") and not line.startswith("+++")]
            if plus:
                added.append(f"### {f['filename']} ({f.get('status')})\n" + "\n".join(plus))
        title = c["commit"]["message"].splitlines()[0]
        names = [f["filename"] for f in files]
        items.append(Item(
            source="devdocs", kind="doc_change",
            title=title,
            url=_site_url(names[0]) if len(names) == 1 else f"{WEB}/commit/{c['sha']}",
            published_at=iso(c["commit"]["committer"]["date"]),
            excerpt=clip("\n\n".join(added), PATCH_CLIP),
            meta={"sha": c["sha"], "files": names, "commit_url": f"{WEB}/commit/{c['sha']}"},
            is_focus=is_focus(title, " ".join(names)) or any("releases" in n for n in names)))

    return Result("devdocs", items, {"commits": len(ordered), "detailed": len(items),
                                     "truncated": len(ordered) > MAX_DETAIL})
