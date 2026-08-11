import importlib
import os
import sys

import pytest
from fastapi.testclient import TestClient

ADMIN = "jian@bluesoft.co.kr"
USER = "siyu@bluesoft.co.kr"


@pytest.fixture()
def app_mod(tmp_path):
    """매 테스트마다 빈 DB와 임시 시크릿으로 앱을 새로 적재한다."""
    secret = tmp_path / "sso_secret.key"
    secret.write_text("test-secret-0123456789")
    os.environ["SSO_SECRET_PATH"] = str(secret)
    os.environ["DB_PATH"] = str(tmp_path / "test.db")
    os.environ["ADMIN_EMAILS"] = ADMIN
    os.environ["DEV_LOGIN"] = "0"
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import app as app_module
    importlib.reload(app_module)
    return app_module


@pytest.fixture()
def client(app_mod):
    return TestClient(app_mod.app)


def login(client, app_mod, email, name="테스터"):
    client.cookies.set("blueiwork_id", app_mod.make_cookie(email, name))


def test_seed_is_loaded(client, app_mod):
    login(client, app_mod, USER)
    rows = client.get("/magazineapi/topics").json()
    assert len(rows) == 31


def test_topics_requires_login(client):
    assert client.get("/magazineapi/topics").status_code == 401


def test_invalid_signature_is_rejected(client, app_mod):
    client.cookies.set("blueiwork_id", app_mod.make_cookie(USER) + "tampered")
    assert client.get("/magazineapi/topics").status_code == 401


def test_expired_cookie_is_rejected(client, app_mod):
    client.cookies.set("blueiwork_id", app_mod.make_cookie(USER, ttl=-10))
    assert client.get("/magazineapi/topics").status_code == 401


def test_whoami_marks_admin(client, app_mod):
    login(client, app_mod, ADMIN, "김지안")
    body = client.get("/magazineapi/whoami").json()
    assert body["email"] == ADMIN
    assert body["is_admin"] is True


def test_whoami_normal_user_is_not_admin(client, app_mod):
    login(client, app_mod, USER)
    assert client.get("/magazineapi/whoami").json()["is_admin"] is False


def test_status_is_derived_not_stored(client, app_mod):
    login(client, app_mod, USER)
    rows = client.get("/magazineapi/topics").json()
    assert {r["status"] for r in rows} <= {"미지정", "발표예정", "발표완료"}
    assert sum(1 for r in rows if r["status"] == "발표완료") == 16
    # 비고 원문은 상태와 별개로 보존된다
    assert any(r["note"] == "미지정" and r["status"] == "발표예정" for r in rows)


def test_devlogin_absent_when_disabled(client):
    assert client.post("/magazineapi/devlogin", json={"email": USER}).status_code == 404


# ---------- 관리자 CRUD ----------
NEW = {"field": "Trend", "title": "새 주제", "keywords": "AI",
       "magazine": "DI", "volume": "280", "page": "12", "year": 2026,
       "requirement": "required"}


def test_normal_user_cannot_create(client, app_mod):
    login(client, app_mod, USER)
    assert client.post("/magazineapi/topics", json=NEW).status_code == 403


def test_anonymous_cannot_create(client):
    assert client.post("/magazineapi/topics", json=NEW).status_code == 401


def test_admin_creates_topic_as_unassigned(client, app_mod):
    login(client, app_mod, ADMIN, "김지안")
    r = client.post("/magazineapi/topics", json=NEW)
    assert r.status_code == 201
    body = r.json()
    assert body["status"] == "미지정"
    assert body["requirement"] == "required"
    assert body["created_by"] == ADMIN


def test_created_topic_appears_in_list(client, app_mod):
    login(client, app_mod, ADMIN)
    client.post("/magazineapi/topics", json=NEW)
    titles = [t["title"] for t in client.get("/magazineapi/topics").json()]
    assert "새 주제" in titles


def test_title_is_required(client, app_mod):
    login(client, app_mod, ADMIN)
    assert client.post("/magazineapi/topics", json={"field": "Etc"}).status_code == 422


def test_admin_updates_requirement(client, app_mod):
    login(client, app_mod, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    r = client.put(f"/magazineapi/topics/{tid}", json={"requirement": "recommended"})
    assert r.status_code == 200
    assert r.json()["requirement"] == "recommended"
    # 보내지 않은 필드는 보존된다
    assert r.json()["title"] == "새 주제"


def test_normal_user_cannot_update_or_delete(client, app_mod):
    login(client, app_mod, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    login(client, app_mod, USER)
    assert client.put(f"/magazineapi/topics/{tid}", json={"title": "x"}).status_code == 403
    assert client.delete(f"/magazineapi/topics/{tid}").status_code == 403


def test_admin_deletes_topic(client, app_mod):
    login(client, app_mod, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    assert client.delete(f"/magazineapi/topics/{tid}").status_code == 200
    assert client.get(f"/magazineapi/topics/{tid}").status_code == 404


def test_update_missing_topic_is_404(client, app_mod):
    login(client, app_mod, ADMIN)
    assert client.put("/magazineapi/topics/99999", json={"title": "x"}).status_code == 404
