import re
from dataclasses import asdict, dataclass, field
from datetime import UTC, datetime
from html import unescape

from core.config import FOCUS_KEYWORDS

SOURCES = {
    "moodleorg": "moodle.org PAG",
    "tracker": "Tracker(Jira)",
    "github": "GitHub moodle/moodle",
    "devdocs": "moodledev.io",
    "moodlecom": "moodle.com 뉴스",
}


@dataclass
class Item:
    source: str # SOURCES 의 키
    kind: str # forum_post · page_change · issue · commit · release · branch · doc_change · news
    title: str
    url: str
    published_at: str = "" # ISO 8601, UTC
    excerpt: str = ""
    meta: dict = field(default_factory=dict)
    is_focus: bool = False

    def to_dict(self) -> dict:
        return asdict(self)

    @staticmethod
    def from_dict(d: dict) -> "Item":
        return Item(source=d["source"], kind=d.get("kind", ""), title=d.get("title", ""),
                    url=d.get("url", ""), published_at=d.get("published_at", ""),
                    excerpt=d.get("excerpt", ""), meta=d.get("meta") or {},
                    is_focus=bool(d.get("is_focus")))


def is_focus(*texts: str) -> bool:
    blob = " ".join(t for t in texts if t).lower()
    return any(k in blob for k in FOCUS_KEYWORDS)


_TAG = re.compile(r"<[^>]+>")
_WS = re.compile(r"[ \t\r\f\v]+")
_NL = re.compile(r"\n{3,}")


def html_to_text(html: str) -> str:
    if not html:
        return ""
    s = re.sub(r"(?is)<(script|style).*?</\1>", "", html)
    s = re.sub(r"(?i)<br\s*/?>|</p>|</div>|</li>|</h[1-6]>|</tr>", "\n", s)
    s = _TAG.sub("", s)
    s = unescape(s)
    s = _WS.sub(" ", s)
    s = "\n".join(line.strip() for line in s.splitlines())
    return _NL.sub("\n\n", s).strip()


def clip(s: str, n: int) -> str:
    s = s or ""
    return s if len(s) <= n else s[: n - 1].rstrip() + "…"


def iso(ts: datetime | float | str | None) -> str:
    """무엇이 오든 UTC ISO 문자열로. 저장·비교는 전부 이 형식으로만 한다."""
    if ts is None or ts == "":
        return ""
    if isinstance(ts, int | float):
        return datetime.fromtimestamp(ts, tz=UTC).isoformat(timespec="seconds")
    if isinstance(ts, str):
        return _parse_iso(ts).isoformat(timespec="seconds")
    if ts.tzinfo is None:
        ts = ts.replace(tzinfo=UTC)
    return ts.astimezone(UTC).isoformat(timespec="seconds")


def _parse_iso(s: str) -> datetime:
    s = s.strip()
    if s.endswith("Z"):
        s = s[:-1] + "+00:00"
    # Jira 는 "+0800" 처럼 콜론 없는 오프셋을 준다
    m = re.search(r"([+-]\d{2})(\d{2})$", s)
    if m and ":" not in s[-6:]:
        s = s[: m.start()] + f"{m.group(1)}:{m.group(2)}"
    dt = datetime.fromisoformat(s)
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=UTC)
    return dt.astimezone(UTC)


def parse_rfc2822(s: str) -> datetime | None:
    from email.utils import parsedate_to_datetime  # noqa: PLC0415

    try:
        dt = parsedate_to_datetime(s)
    except (TypeError, ValueError):
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=UTC)
    return dt.astimezone(UTC)
