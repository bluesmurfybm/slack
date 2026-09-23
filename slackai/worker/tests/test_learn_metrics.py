from core.text import jaccard, tokens
from jobs.learn import DEDUPE_JACCARD, _ensure_sections, metrics


def test_metrics_jaccard_missed_unexpected():
    m = metrics(["a.php", "b.php", "c.php"], ["a.php", "b.php", "d.php"], 30, 45.0, "pass", 2)
    assert m["jaccard"] == 0.5 and m["precision"] == round(2 / 3, 3) and m["recall"] == round(2 / 3, 3)
    assert m["missed"] == ["d.php"] and m["unexpected"] == ["c.php"]
    assert m["est_vs_actual"] == 1.5 and m["verdict"] == "pass" and m["plan_versions"] == 2


def test_metrics_edge_cases():
    m = metrics([], [], None, None, None, 1)
    assert m["jaccard"] == 1.0 and m["precision"] == 1.0 and m["recall"] == 1.0 and m["est_vs_actual"] is None
    m2 = metrics(["a"], [], 10, 5, None, 1)
    assert m2["precision"] == 0.0 and m2["recall"] == 1.0 and m2["unexpected"] == ["a"]
    m3 = metrics([], ["a"], 10, None, None, 1)
    assert m3["recall"] == 0.0 and m3["missed"] == ["a"]


def test_lesson_dedupe_threshold():
    a = tokens("ubattend 수정 시 progress 캐시 테이블도 함께 확인한다.")
    b = tokens("ubattend 수정 시 progress 캐시 테이블을 함께 확인한다.")
    c = tokens("테마 변경 시 css 캐시를 비운다.")
    assert jaccard(a, b) >= DEDUPE_JACCARD
    assert jaccard(a, c) < DEDUPE_JACCARD
    assert jaccard(set(), set()) == 1.0 and jaccard(a, set()) == 0.0


def test_ensure_sections_adds_missing_and_caps():
    md = _ensure_sections("## 저장소 개요\n- x\n" + "\n".join(f"- l{i}" for i in range(400)))
    for sec in ("## 디렉터리·모듈 지도", "## 코딩 규칙", "## 자주 놓치는 것", "## 테스트 방법"):
        assert sec in md
    assert md.count("\n") <= 230
