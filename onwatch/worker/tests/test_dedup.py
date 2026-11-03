import dedup


def raw(**kw):
    base = {"subject_key": "Debian:PHP7.4:Bullseye", "marker": "7.4.33-1+deb11u9", "title": "데비안 PHP 7.4 보안 패키지",
            "change_type": "new", "importance": "high", "confidence": "confirmed", "detail_md": "- 내용",
            "verification": "", "published": "2026-10-21",
            "sources": [{"url": "https://security-tracker.debian.org/x", "title": "트래커", "published": ""}]}
    base.update(kw)
    return base


def test_same_finding_same_fingerprint_regardless_of_case_and_spacing():
    a = dedup.clean(raw(), set())
    b = dedup.clean(raw(subject_key=" debian:php7.4:bullseye ", marker="7.4.33-1+DEB11U9"), set())
    assert a["fingerprint"] == b["fingerprint"]


def test_next_change_of_same_subject_is_new():
    a = dedup.clean(raw(), set())
    b = dedup.clean(raw(marker="7.4.33-1+deb11u10", change_type="updated"), set())
    assert a["fingerprint"] != b["fingerprint"]


def test_empty_marker_falls_back_to_first_url():
    a = dedup.clean(raw(marker="", sources=[{"url": "https://a.com/1", "title": "", "published": ""}]), set())
    b = dedup.clean(raw(marker="", sources=[{"url": "https://a.com/2", "title": "", "published": ""}]), set())
    assert a["fingerprint"] != b["fingerprint"]


def test_split_drops_known_and_in_batch_duplicates():
    a = dedup.clean(raw(), set())
    b = dedup.clean(raw(title="다른 제목 같은 소식"), set())
    c = dedup.clean(raw(marker="other"), set())
    fresh, dup = dedup.split([a, b, c], {c["fingerprint"]})
    assert fresh == [a]
    assert dup == 2


def test_seen_flag_and_confirmed_downgrade_when_no_source_opened():
    f = dedup.clean(raw(), set())
    assert f["sources"][0]["seen"] is False
    assert f["confidence"] == "reported" # 연 적 없는 주소뿐인 '원문 확인' 은 낮춘다
    g = dedup.clean(raw(), {"http://www.security-tracker.debian.org/x/"})
    assert g["sources"][0]["seen"] is True
    assert g["confidence"] == "confirmed"


def test_bad_values_are_cleaned():
    f = dedup.clean(raw(change_type="weird", importance="??", confidence="maybe",
                        sources=[{"url": "javascript:alert(1)", "title": "x", "published": ""}]), set())
    assert (f["change_type"], f["importance"], f["confidence"]) == ("other", "medium", "unverified")
    assert f["sources"] == []
    assert dedup.clean(raw(title="  "), set()) is None


def test_meets():
    assert dedup.meets("high", "medium")
    assert dedup.meets("medium", "medium")
    assert not dedup.meets("low", "medium")
    assert dedup.meets("low", "low")
