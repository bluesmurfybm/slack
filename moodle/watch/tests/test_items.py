from datetime import UTC, datetime

from core.items import Item, clip, html_to_text, is_focus, iso, parse_rfc2822
from core.snapshot import week_key


def test_html_to_text_strips_tags_and_keeps_breaks():
    s = html_to_text("<p>Hello <b>world</b></p><script>x()</script><ul><li>a</li><li>b</li></ul>")
    assert s == "Hello world\na\nb"


def test_html_to_text_unescapes_entities():
    assert html_to_text("a &amp; b &lt;c&gt;") == "a & b <c>"


def test_clip_adds_ellipsis_only_when_needed():
    assert clip("abc", 3) == "abc"
    assert clip("abcdef", 4) == "abc…"


def test_iso_handles_jira_offsets_and_epochs():
    assert iso("2026-09-09T12:04:38.126+0800") == "2026-09-09T04:04:38+00:00"
    assert iso("2026-08-07T12:12:18Z") == "2026-08-07T12:12:18+00:00"
    epoch = int(datetime(2026, 9, 4, 14, 13, 20, tzinfo=UTC).timestamp())
    assert iso(epoch) == "2026-09-04T14:13:20+00:00"
    assert iso(None) == ""


def test_rfc2822_parses_rss_dates():
    dt = parse_rfc2822("Tue, 02 Sep 2026 09:00:00 +0000")
    assert dt == datetime(2026, 9, 2, 9, tzinfo=UTC)
    assert parse_rfc2822("garbage") is None


def test_focus_keywords_are_case_insensitive():
    assert is_focus("Adopt React for the dashboard")
    assert is_focus("MDL-1 Deprecate legacy callbacks", "")
    assert not is_focus("Fix typo in help string")


def test_item_round_trips_through_dict():
    it = Item("tracker", "issue", "t", "u", "2026-09-01T00:00:00+00:00", "e", {"k": 1}, True)
    assert Item.from_dict(it.to_dict()) == it
    assert Item.from_dict({"source": "x"}).meta == {}


def test_week_key_is_iso_week():
    assert week_key(datetime(2026, 9, 8, tzinfo=UTC)) == "2026-W37"
    assert week_key(datetime(2027, 1, 1, tzinfo=UTC)) == "2026-W53"
