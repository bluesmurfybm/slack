from conftest import ADMIN, USER, login


def _new(client, **over):
    return client.post("/magazineapi/topics", json={"title": "베이스", **over}).json()["id"]


def _related(client, tid):
    return client.get(f"/magazineapi/topics/{tid}/related").json()


def test_related_requires_login(client):
    assert client.get("/magazineapi/topics/1/related").status_code == 401


def test_related_missing_topic_is_404(client, settings):
    login(client, settings, USER)
    assert client.get("/magazineapi/topics/99999/related").status_code == 404


def test_same_field_and_keyword_relate(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="에이전트 하나", field="AX", keywords="쌍둥이시험")
    b = _new(client, title="오브젝트 저장", field="AX", keywords="쌍둥이시험")
    rows = _related(client, a)
    assert any(r["id"] == b and r["score"] >= 62 for r in rows)


def test_keyword_spacing_variant_still_matches(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="하나", field="AX", keywords="생성형AI")
    b = _new(client, title="둘", field="AX", keywords="생성형 AI")
    assert any(r["id"] == b for r in _related(client, a))


def test_field_alone_is_below_threshold(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="사과 재배법", field="AX")
    b = _new(client, title="바다 건너기", field="AX")
    assert not any(r["id"] == b for r in _related(client, a))


def test_same_team_does_not_count(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="사과 재배법", field="AX", team="APP")
    b = _new(client, title="바다 건너기", field="AX", team="APP")
    assert not any(r["id"] == b for r in _related(client, a))


def test_same_magazine_does_not_count(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="사과 재배법", field="AX", magazine="DI")
    b = _new(client, title="바다 건너기", field="AX", magazine="DI")
    assert not any(r["id"] == b for r in _related(client, a))


def test_near_identical_title_pushes_over_threshold(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="AI 코딩의 미래", field="AX")
    b = _new(client, title="AI 코딩의 미래!", field="AX")
    assert any(r["id"] == b for r in _related(client, a))


def test_update_refreshes_scores(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="하나", field="AX", keywords="쌍둥이시험")
    b = _new(client, title="둘", field="AX", keywords="쌍둥이시험")
    client.put(f"/magazineapi/topics/{b}",
               json={"field": "Trend", "keywords": "전혀다른것"})
    assert not any(r["id"] == b for r in _related(client, a))


def test_delete_refreshes_scores(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="하나", field="AX", keywords="쌍둥이시험")
    b = _new(client, title="둘", field="AX", keywords="쌍둥이시험")
    client.delete(f"/magazineapi/topics/{b}")
    assert not any(r["id"] == b for r in _related(client, a))


def test_hidden_topic_is_excluded(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="하나", field="AX", keywords="쌍둥이시험")
    b = _new(client, title="둘", field="AX", keywords="쌍둥이시험")
    client.put(f"/magazineapi/topics/{b}", json={"active": 0})
    assert not any(r["id"] == b for r in _related(client, a))


def test_returns_at_most_three(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="기준", field="AX", keywords="쌍둥이시험")
    for n in range(4):
        _new(client, title=f"복제 {n}", field="AX", keywords="쌍둥이시험")
    assert len(_related(client, a)) == 3


def test_normal_user_can_read(client, settings):
    login(client, settings, ADMIN)
    a = _new(client, title="하나", field="AX", keywords="쌍둥이시험")
    login(client, settings, USER)
    assert client.get(f"/magazineapi/topics/{a}/related").status_code == 200
