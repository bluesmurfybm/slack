import difflib
import hashlib

from collectors.base import Context, Result
from core.config import PAG_BOOKS, PAG_COURSE_ID, PAG_FORUMS, PAG_PAGES
from core.items import Item, clip, html_to_text, is_focus, iso

WS = "https://moodle.org/webservice/rest/server.php"
DISCUSS = "https://moodle.org/mod/forum/discuss.php"
ANNOUNCEMENTS = 8863
POST_CLIP = 1500
DIFF_CLIP = 3000


class WsError(RuntimeError):
    pass


def call(ctx: Context, function: str, **params):
    q = {"wstoken": ctx.settings.moodle_org_token, "wsfunction": function,
         "moodlewsrestformat": "json", **params}
    data = ctx.http.get_json(WS, params=q)
    if isinstance(data, dict) and data.get("exception"):
        raise WsError(f"{function}: {data.get('errorcode')} {data.get('message')}")
    return data


def _text_hash(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _page_contents(ctx: Context) -> dict[int, str]:
    """페이지 본문은 mod_page_get_pages_by_courses 가 JSON 으로 준다. cmid → HTML."""
    data = call(ctx, "mod_page_get_pages_by_courses", **{"courseids[0]": PAG_COURSE_ID})
    return {int(p["coursemodule"]): p.get("content") or "" for p in data.get("pages", [])}


def _module_text(ctx: Context, module: dict, pages: dict[int, str]) -> str:
    if module.get("modname") == "page":
        return html_to_text(pages.get(int(module["id"]), ""))
    # 책 챕터는 WS 가 본문을 주지 않아 pluginfile 로 받는다. moodle.org 는 이 경로를
    # Cloudflare 가 봇 차단(403)하는 경우가 있어, 실패하면 그 모듈만 건너뛴다(호출 쪽에서 처리).
    parts = []
    for c in module.get("contents") or []:
        if c.get("type") != "file" or not str(c.get("filename", "")).endswith(".html"):
            continue
        url = c["fileurl"]
        sep = "&" if "?" in url else "?"
        html = ctx.http.get_text(f"{url}{sep}token={ctx.settings.moodle_org_token}")
        parts.append(html_to_text(html))
    return "\n\n".join(parts).strip()


def _forum_items(ctx: Context, module: dict, since_ts: int, until_ts: int) -> list[Item]:
    forum_cmid = module["id"]
    forum_name = module.get("name") or PAG_FORUMS.get(forum_cmid, "")
    items = []
    data = call(ctx, "mod_forum_get_forum_discussions", forumid=module["instance"],
                sortorder=1, page=0, perpage=50) # sortorder 1 = 최근 수정 순
    for d in data.get("discussions", []):
        if int(d.get("timemodified", 0)) < since_ts:
            continue
        did = d["discussion"]
        posts = call(ctx, "mod_forum_get_discussion_posts", discussionid=did).get("posts", [])
        for p in posts:
            created = int(p.get("timecreated", 0))
            if created < since_ts or created > until_ts:
                continue
            body = html_to_text(p.get("message", ""))
            subject = p.get("subject") or d.get("name", "")
            is_new_thread = p.get("id") == d.get("id")
            items.append(Item(
                source="moodleorg", kind="forum_post",
                title=f"[{forum_name}] {subject}",
                url=f"{DISCUSS}?d={did}#p{p.get('id')}",
                published_at=iso(created), excerpt=clip(body, POST_CLIP),
                meta={"forum": forum_name, "forum_cmid": forum_cmid, "discussion": did,
                      "author": (p.get("author") or {}).get("fullname", ""),
                      "new_thread": is_new_thread},
                is_focus=forum_cmid == ANNOUNCEMENTS or is_focus(subject, body)))
    return items


def _page_changes(ctx: Context, modules: dict, stats: dict) -> list[Item]:
    """페이지·책 본문을 해시로 비교해 바뀐 것만 diff 로 낸다. 첫 실행은 기준만 잡는다."""
    items: list[Item] = []
    hashes = ctx.state.data.setdefault("page_hashes", {})
    pages_dir = ctx.settings.data_path / "pages"
    pages_dir.mkdir(parents=True, exist_ok=True)
    page_html = _page_contents(ctx)
    for cmid in PAG_PAGES + PAG_BOOKS:
        m = modules.get(cmid)
        if not m or m.get("modname") not in ("page", "book"):
            continue
        stats["pages_checked"] += 1
        try:
            text = _module_text(ctx, m, page_html)
        except Exception as e:  # noqa: BLE001 모듈 하나(주로 책의 pluginfile 403)가 전체를 막지 않게 한다
            stats["unavailable"].append(f"{m.get('name', cmid)}: {type(e).__name__}")
            continue
        if not text:
            continue
        h = _text_hash(text)
        store = pages_dir / f"{cmid}.txt"
        old_hash = hashes.get(str(cmid))
        if old_hash is None:
            stats["baseline"] += 1 # 첫 실행은 기준만 잡고 변경으로 치지 않는다
        elif old_hash != h:
            stats["pages_changed"] += 1
            old_text = store.read_text(encoding="utf-8") if store.is_file() else ""
            diff = "\n".join(difflib.unified_diff(
                old_text.splitlines(), text.splitlines(), "이전", "현재", lineterm="", n=2))
            items.append(Item(
                source="moodleorg", kind="page_change",
                title=f"[{m.get('modname')}] {m.get('name', '')} 내용 변경",
                url=m.get("url") or f"https://moodle.org/mod/{m.get('modname')}/view.php?id={cmid}",
                published_at=iso(ctx.until), excerpt=clip(diff, DIFF_CLIP),
                meta={"cmid": cmid, "modname": m.get("modname")}, is_focus=True))
        hashes[str(cmid)] = h
        store.write_text(text, encoding="utf-8")
    return items


def collect(ctx: Context) -> Result:
    if not ctx.settings.moodle_org_token:
        return Result("moodleorg", status="skipped",
                      note="MOODLE_ORG_TOKEN 이 없어 moodle.org PAG 코스는 건너뛰었다")

    since_ts, until_ts = int(ctx.since.timestamp()), int(ctx.until.timestamp())
    sections = call(ctx, "core_course_get_contents", courseid=PAG_COURSE_ID)
    modules = {m["id"]: m for s in sections for m in s.get("modules", [])}

    items: list[Item] = []
    stats = {"modules": len(modules), "forums": 0, "posts": 0,
             "pages_checked": 0, "pages_changed": 0, "baseline": 0, "unavailable": []}

    for cmid in PAG_FORUMS:
        m = modules.get(cmid)
        if not m or m.get("modname") != "forum":
            continue
        stats["forums"] += 1
        found = _forum_items(ctx, m, since_ts, until_ts)
        stats["posts"] += len(found)
        items.extend(found)

    items.extend(_page_changes(ctx, modules, stats))

    note = ""
    if stats["unavailable"]:
        # 책(book) 챕터는 REST 가 본문을 주지 않고 파일 다운로드는 moodle.org 의
        # Cloudflare 가 막는다. 재시도로 풀리는 문제가 아니라 요약에서도 "다음에 재시도"
        # 같은 제안을 하지 않게 적어 준다.
        note = ("책(book) 모듈은 moodle.org 가 파일 다운로드를 차단해 본문을 받을 수 없음"
                "(구조적 제약, 재시도 무의미 — 코스에서 직접 읽어야 함): "
                + ", ".join(stats["unavailable"]))
    return Result("moodleorg", items, stats, note=note)
