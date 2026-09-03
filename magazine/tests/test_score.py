import pytest

from conftest import ADMIN, OTHER, USER, login, make_settings
from core.config import MEMBERS

NEW = {"title": "점수 검증", "field": "AX"}


@pytest.fixture
def settings(tmp_path):
    # 시드에 실제 발표 기록이 있어 점수가 섞인다
    return make_settings(tmp_path, seed_path=str(tmp_path / "no-seed.json"))


def _topic(client, settings, **over):
    login(client, settings, ADMIN)
    return client.post("/magazineapi/topics", json={**NEW, **over}).json()["id"]


def _done(client, settings, email=USER, done_date="2026-09-01", **over):
    tid = _topic(client, settings, **over)
    login(client, settings, email)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, ADMIN)
    client.post(f"/magazineapi/topics/{tid}/complete", json={"done_date": done_date})
    return tid


def _scores(client, **params):
    return client.get("/magazineapi/score", params=params).json()


def _of(rows, email):
    return next(r for r in rows if r["email"] == email)


def test_score_requires_admin(client, settings):
    assert client.get("/magazineapi/score").status_code == 401
    login(client, settings, USER)
    assert client.get("/magazineapi/score").status_code == 403


def test_done_presentation_scores_ten(client, settings):
    _done(client, settings)
    login(client, settings, ADMIN)
    me = _of(_scores(client), USER)
    assert me["total"] == 10
    assert me["breakdown"]["done"] == 10


def test_required_topic_adds_bonus(client, settings):
    _done(client, settings, requirement="required")
    login(client, settings, ADMIN)
    me = _of(_scores(client), USER)
    assert me["breakdown"]["required"] == 5
    assert me["total"] == 15


def _attach_material(client, settings, tid):
    login(client, settings, ADMIN)
    r = client.post(f"/magazineapi/topics/{tid}/material/link",
                    json={"url": "https://x", "name": "슬라이드"})
    assert r.status_code == 200


def test_material_on_presentation_adds_three(client, settings):
    tid = _done(client, settings)
    _attach_material(client, settings, tid)
    login(client, settings, ADMIN)
    me = _of(_scores(client), USER)
    assert me["breakdown"]["material"] == 3
    assert me["total"] == 13


def _react(client, settings, tid, email, kinds):
    login(client, settings, email)
    for kind in kinds:
        assert client.post(f"/magazineapi/topics/{tid}/emotions/{kind}").status_code == 200


def test_reactions_score_one_each_capped_per_day(client, settings):
    tid = _topic(client, settings)
    client.post(f"/magazineapi/topics/{tid}/complete", json={})
    _react(client, settings, tid, USER, ("like", "apply", "easy", "new"))
    login(client, settings, ADMIN)
    me = _of(_scores(client), USER)
    assert me["breakdown"]["reaction"] == 3
    assert me["total"] == 3


def test_range_filters_by_date(client, settings):
    _done(client, settings, done_date="2025-12-31")
    _done(client, settings, done_date="2026-01-01")
    login(client, settings, ADMIN)
    assert _of(_scores(client), USER)["total"] == 20
    assert _of(_scores(client, start="2026-01-01"), USER)["total"] == 10
    assert _of(_scores(client, end="2025-12-31"), USER)["total"] == 10
    assert _of(_scores(client, start="2026-02-01", end="2026-02-28"), USER)["total"] == 0


def test_malformed_range_is_422(client, settings):
    login(client, settings, ADMIN)
    assert client.get("/magazineapi/score", params={"start": "2026"}).status_code == 422
    assert client.get("/magazineapi/score", params={"end": "2026-1-1"}).status_code == 422


def test_completion_without_presenter_scores_nobody(client, settings):
    tid = _topic(client, settings)
    client.post(f"/magazineapi/topics/{tid}/complete", json={})
    assert all(r["total"] == 0 for r in _scores(client))


def test_unlisted_presenter_still_appears(client, settings):
    ghost = "ghost@bluesoft.co.kr"
    _done(client, settings, email=ghost)
    login(client, settings, ADMIN)
    rows = _scores(client)
    assert len(rows) == len(MEMBERS) + 1
    assert _of(rows, ghost)["total"] == 10


def test_every_member_listed_and_sorted(client, settings):
    _done(client, settings, email=OTHER)
    login(client, settings, ADMIN)
    rows = _scores(client)
    assert {r["email"] for r in rows} == {m["email"] for m in MEMBERS}
    assert rows[0]["email"] == OTHER
    assert [r["total"] for r in rows] == sorted((r["total"] for r in rows), reverse=True)
