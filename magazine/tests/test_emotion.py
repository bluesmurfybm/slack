import sqlite3

from conftest import ADMIN, OTHER, USER, login

NEW = {"title": "반응 달 주제", "requirement": "recommended"}


def _done(client, settings):
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    client.post(f"/magazineapi/topics/{tid}/complete", json={})
    return tid


def _row(client, tid):
    return next(r for r in client.get("/magazineapi/topics").json() if r["id"] == tid)


def _react(client, tid, kind="like"):
    return client.post(f"/magazineapi/topics/{tid}/emotions/{kind}")


def test_reaction_toggles_on_and_off(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    assert _react(client, tid).json() == {"kind": "like", "count": 1, "mine": True}
    assert _react(client, tid).json() == {"kind": "like", "count": 0, "mine": False}


def test_each_person_counts_once(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    _react(client, tid)
    _react(client, tid)
    _react(client, tid)
    login(client, settings, OTHER)
    assert _react(client, tid).json()["count"] == 2


def test_kinds_are_counted_apart(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    _react(client, tid, "like")
    _react(client, tid, "apply")
    _react(client, tid, "new")
    row = _row(client, tid)
    assert row["emotions"] == {"like": 1, "apply": 1, "easy": 0, "new": 1}
    assert sorted(row["my_emotions"]) == ["apply", "like", "new"]


def test_turning_one_kind_off_keeps_the_others(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    _react(client, tid, "like")
    _react(client, tid, "apply")
    _react(client, tid, "like")
    row = _row(client, tid)
    assert row["emotions"]["like"] == 0
    assert row["emotions"]["apply"] == 1


def test_someone_elses_reaction_is_not_mine(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    _react(client, tid)
    login(client, settings, OTHER)
    assert _row(client, tid)["emotions"]["like"] == 1
    assert _row(client, tid)["my_emotions"] == []


def test_untouched_topic_has_no_reactions(client, settings):
    login(client, settings, USER)
    row = client.get("/magazineapi/topics").json()[0]
    assert row["emotions"] == {"like": 0, "apply": 0, "easy": 0, "new": 0}
    assert row["my_emotions"] == []


def test_reaction_is_rejected_before_the_talk_is_done(client, settings):
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    login(client, settings, USER)
    assert _react(client, tid).status_code == 409


def test_unknown_kind_is_rejected(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    assert _react(client, tid, "hate").status_code == 422


def test_reaction_on_missing_topic_is_404(client, settings):
    login(client, settings, USER)
    assert _react(client, 99999).status_code == 404


def test_anonymous_cannot_react(client, settings):
    tid = _done(client, settings)
    client.cookies.clear()
    assert _react(client, tid).status_code == 401


def test_deleting_a_topic_takes_its_reactions_with_it(client, settings):
    tid = _done(client, settings)
    login(client, settings, USER)
    _react(client, tid, "like")
    _react(client, tid, "easy")
    login(client, settings, ADMIN)
    client.delete(f"/magazineapi/topics/{tid}")

    conn = sqlite3.connect(settings.db_path)
    left = conn.execute("SELECT COUNT(*) FROM topic_emotions WHERE topic_id = ?",
                        (tid,)).fetchone()[0]
    conn.close()
    assert left == 0
