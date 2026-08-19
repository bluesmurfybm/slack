import pytest
from fastapi.testclient import TestClient

from app import create_app
from conftest import ADMIN, USER, login, make_settings
from features.notify import slack


@pytest.fixture
def settings(tmp_path):
    return make_settings(tmp_path, slack_webhook="https://hooks.slack.example/x")


@pytest.fixture
def client(settings):
    return TestClient(create_app(settings))


@pytest.fixture
def sent(monkeypatch):
    calls = []
    monkeypatch.setattr(slack.requests, "post",
                        lambda _url, **kw: calls.append(kw["json"]))
    return calls


def _new_topic_id(client, settings):
    login(client, settings, ADMIN)
    return client.post("/magazineapi/topics", json={"title": "새 주제"}).json()["id"]


def test_topic_creation_sends_nothing(client, settings, sent):
    _new_topic_id(client, settings)
    assert sent == []


def test_claim_sends_presenter_notice(client, settings, sent):
    tid = _new_topic_id(client, settings)
    login(client, settings, USER, "유승인")
    r = client.post(f"/magazineapi/topics/{tid}/claim",
                    json={"planned_date": "2026-09-01"})
    assert r.status_code == 200
    assert len(sent) == 1
    assert "유승인" in sent[0]["text"]
    assert "새 주제" in sent[0]["text"]
    assert "2026-09-01" in sent[0]["text"]


def test_assign_sends_presenter_notice(client, settings, sent):
    tid = _new_topic_id(client, settings)
    r = client.post(f"/magazineapi/topics/{tid}/assign", json={"email": USER})
    assert r.status_code == 200
    assert len(sent) == 1
    assert "유승인" in sent[0]["text"]
    assert "새 주제" in sent[0]["text"]


def test_unassign_sends_nothing(client, settings, sent):
    tid = _new_topic_id(client, settings)
    client.post(f"/magazineapi/topics/{tid}/assign", json={"email": USER})
    sent.clear()
    r = client.post(f"/magazineapi/topics/{tid}/assign", json={"email": ""})
    assert r.status_code == 200
    assert sent == []


def test_failed_claim_sends_nothing(client, settings, sent):
    tid = _new_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    sent.clear()
    login(client, settings, "hjlee@bluesoft.co.kr", "이하진")
    r = client.post(f"/magazineapi/topics/{tid}/claim", json={})
    assert r.status_code == 409
    assert sent == []
