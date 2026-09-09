import xml.etree.ElementTree as ET

from collectors.base import Context, Result
from core.items import Item, clip, html_to_text, is_focus, iso, parse_rfc2822

FEED = "https://moodle.com/feed/"


def collect(ctx: Context) -> Result:
    raw = ctx.http.get_bytes(FEED)
    root = ET.fromstring(raw) # noqa: S314 위와 같은 이유
    items = []
    total = 0
    for node in root.findall("./channel/item"):
        total += 1
        pub = parse_rfc2822(node.findtext("pubDate") or "")
        if pub is None or pub < ctx.since or pub > ctx.until:
            continue
        title = (node.findtext("title") or "").strip()
        desc = html_to_text(node.findtext("description") or "")
        cats = [c.text.strip() for c in node.findall("category") if c.text]
        items.append(Item(
            source="moodlecom", kind="news", title=title,
            url=(node.findtext("link") or "").strip(),
            published_at=iso(pub), excerpt=clip(desc, 600),
            meta={"categories": cats},
            is_focus=is_focus(title, desc, " ".join(cats))))
    return Result("moodlecom", items, {"feed_items": total, "in_period": len(items)})
