from conftest import ADMIN, OTHER, USER, login
from features.identity.auth import make_cookie


def test_seed_is_loaded(client, settings):
    login(client, settings, USER)
    rows = client.get("/magazineapi/topics").json()
    assert len(rows) == 31


def test_topics_requires_login(client):
    assert client.get("/magazineapi/topics").status_code == 401


def test_invalid_signature_is_rejected(client, settings):
    client.cookies.set("blueiwork_id", make_cookie(settings, USER) + "tampered")
    assert client.get("/magazineapi/topics").status_code == 401


def test_expired_cookie_is_rejected(client, settings):
    client.cookies.set("blueiwork_id", make_cookie(settings, USER, ttl=-10))
    assert client.get("/magazineapi/topics").status_code == 401


def test_whoami_marks_admin(client, settings):
    login(client, settings, ADMIN, "김지안")
    body = client.get("/magazineapi/whoami").json()
    assert body["email"] == ADMIN
    assert body["is_admin"] is True


def test_whoami_normal_user_is_not_admin(client, settings):
    login(client, settings, USER)
    assert client.get("/magazineapi/whoami").json()["is_admin"] is False


def test_status_is_derived_not_stored(client, settings):
    login(client, settings, USER)
    rows = client.get("/magazineapi/topics").json()
    assert {r["status"] for r in rows} <= {"미지정", "발표예정", "발표완료"}
    assert sum(1 for r in rows if r["status"] == "발표완료") == 16
    assert any(r["note"] == "미지정" and r["status"] == "발표예정" for r in rows)


def test_devlogin_absent_when_disabled(client):
    assert client.post("/magazineapi/devlogin", json={"email": USER}).status_code == 404
NEW = {"field": "Trend", "title": "새 주제", "keywords": "AI",
       "magazine": "DI", "volume": "280", "page": "12", "year": 2026,
       "requirement": "required"}


def test_normal_user_cannot_create(client, settings):
    login(client, settings, USER)
    assert client.post("/magazineapi/topics", json=NEW).status_code == 403


def test_anonymous_cannot_create(client):
    assert client.post("/magazineapi/topics", json=NEW).status_code == 401


def test_admin_creates_topic_as_unassigned(client, settings):
    login(client, settings, ADMIN, "김지안")
    r = client.post("/magazineapi/topics", json=NEW)
    assert r.status_code == 201
    body = r.json()
    assert body["status"] == "미지정"
    assert body["requirement"] == "required"
    assert body["created_by"] == ADMIN


def test_created_topic_appears_in_list(client, settings):
    login(client, settings, ADMIN)
    client.post("/magazineapi/topics", json=NEW)
    titles = [t["title"] for t in client.get("/magazineapi/topics").json()]
    assert "새 주제" in titles


def test_title_is_required(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/magazineapi/topics", json={"field": "Etc"}).status_code == 422


def test_admin_updates_requirement(client, settings):
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    r = client.put(f"/magazineapi/topics/{tid}", json={"requirement": "recommended"})
    assert r.status_code == 200
    assert r.json()["requirement"] == "recommended"
    assert r.json()["title"] == "새 주제"


def test_normal_user_cannot_update_or_delete(client, settings):
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    login(client, settings, USER)
    assert client.put(f"/magazineapi/topics/{tid}", json={"title": "x"}).status_code == 403
    assert client.delete(f"/magazineapi/topics/{tid}").status_code == 403


def test_admin_deletes_topic(client, settings):
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    assert client.delete(f"/magazineapi/topics/{tid}").status_code == 200
    assert client.get(f"/magazineapi/topics/{tid}").status_code == 404


def test_update_missing_topic_is_404(client, settings):
    login(client, settings, ADMIN)
    assert client.put("/magazineapi/topics/99999", json={"title": "x"}).status_code == 404
OTHER = "hjlee@bluesoft.co.kr"


def _open_topic_id(client, settings):
    login(client, settings, ADMIN)
    return client.post("/magazineapi/topics", json=NEW).json()["id"]


def test_user_claims_open_topic(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER, "유승인")
    r = client.post(f"/magazineapi/topics/{tid}/claim", json={"planned_date": "2026-09-01"})
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "발표예정"
    assert body["presenter_email"] == USER
    assert body["presenter"] == "유승인"
    assert body["planned_date"] == "2026-09-01"


def test_second_claim_conflicts(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, OTHER)
    assert client.post(f"/magazineapi/topics/{tid}/claim", json={}).status_code == 409


def test_claim_missing_topic_is_404(client, settings):
    login(client, settings, USER)
    assert client.post("/magazineapi/topics/99999/claim", json={}).status_code == 404


def test_completed_topic_cannot_be_claimed(client, settings):
    login(client, settings, USER)
    rows = client.get("/magazineapi/topics").json()
    done = next(r for r in rows if r["status"] == "발표완료" and not r["presenter_email"])
    assert client.post(f"/magazineapi/topics/{done['id']}/claim", json={}).status_code == 409


def test_owner_releases_own_claim(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    r = client.post(f"/magazineapi/topics/{tid}/release")
    assert r.status_code == 200
    assert r.json()["status"] == "미지정"
    assert r.json()["presenter_email"] in ("", None)


def test_third_party_cannot_release(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, OTHER)
    assert client.post(f"/magazineapi/topics/{tid}/release").status_code == 403


def test_admin_can_release_anyones_claim(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, ADMIN)
    assert client.post(f"/magazineapi/topics/{tid}/release").status_code == 200


def test_admin_completes_topic(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, ADMIN)
    r = client.post(f"/magazineapi/topics/{tid}/complete", json={"done_date": "2026-09-01"})
    assert r.status_code == 200
    assert r.json()["status"] == "발표완료"


def test_normal_user_cannot_complete(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    assert client.post(f"/magazineapi/topics/{tid}/complete", json={}).status_code == 403


def test_claim_works_on_seeded_row_with_empty_email(client, settings):
    login(client, settings, USER)
    rows = client.get("/magazineapi/topics").json()
    tid = next(r["id"] for r in rows if r["status"] == "미지정")
    assert client.post(f"/magazineapi/topics/{tid}/claim", json={}).status_code == 200
def _claimed_topic_id(client, settings, by=USER, name="유승인"):
    tid = _open_topic_id(client, settings)
    login(client, settings, by, name)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    return tid


def test_owner_sets_planned_date_after_claiming(client, settings):
    tid = _claimed_topic_id(client, settings)
    r = client.post(f"/magazineapi/topics/{tid}/schedule",
                    json={"planned_date": "2026-10-15"})
    assert r.status_code == 200
    assert r.json()["planned_date"] == "2026-10-15"
    assert r.json()["status"] == "발표예정"


def test_owner_changes_existing_planned_date(client, settings):
    tid = _claimed_topic_id(client, settings)
    client.post(f"/magazineapi/topics/{tid}/schedule", json={"planned_date": "2026-10-15"})
    r = client.post(f"/magazineapi/topics/{tid}/schedule", json={"planned_date": "2026-11-02"})
    assert r.json()["planned_date"] == "2026-11-02"


def test_owner_can_clear_planned_date(client, settings):
    tid = _claimed_topic_id(client, settings)
    client.post(f"/magazineapi/topics/{tid}/schedule", json={"planned_date": "2026-10-15"})
    r = client.post(f"/magazineapi/topics/{tid}/schedule", json={"planned_date": ""})
    assert r.json()["planned_date"] == ""


def test_third_party_cannot_schedule(client, settings):
    tid = _claimed_topic_id(client, settings)
    login(client, settings, OTHER)
    assert client.post(f"/magazineapi/topics/{tid}/schedule",
                       json={"planned_date": "2026-10-15"}).status_code == 403


def test_admin_can_schedule_anyones_topic(client, settings):
    tid = _claimed_topic_id(client, settings)
    login(client, settings, ADMIN)
    assert client.post(f"/magazineapi/topics/{tid}/schedule",
                       json={"planned_date": "2026-10-15"}).status_code == 200


def test_schedule_requires_login(client, settings):
    tid = _claimed_topic_id(client, settings)
    client.cookies.clear()
    assert client.post(f"/magazineapi/topics/{tid}/schedule",
                       json={"planned_date": "2026-10-15"}).status_code == 401


def test_schedule_missing_topic_is_404(client, settings):
    login(client, settings, USER)
    assert client.post("/magazineapi/topics/99999/schedule",
                       json={"planned_date": "2026-10-15"}).status_code == 404


def test_completed_topic_cannot_be_rescheduled(client, settings):
    tid = _claimed_topic_id(client, settings)
    login(client, settings, ADMIN)
    client.post(f"/magazineapi/topics/{tid}/complete", json={"done_date": "2026-09-01"})
    login(client, settings, USER)
    assert client.post(f"/magazineapi/topics/{tid}/schedule",
                       json={"planned_date": "2026-10-15"}).status_code == 409
def test_devlogin_sets_working_cookie(dev_client):
    r = dev_client.post("/magazineapi/devlogin", json={"email": ADMIN, "name": "김지안"})
    assert r.status_code == 200
    assert r.json()["is_admin"] is True
    who = dev_client.get("/magazineapi/whoami").json()
    assert who["email"] == ADMIN
    assert who["is_admin"] is True
    assert dev_client.get("/magazineapi/topics").status_code == 200


def test_devlogin_switch_to_normal_user(dev_client):
    dev_client.post("/magazineapi/devlogin", json={"email": ADMIN})
    dev_client.post("/magazineapi/devlogin", json={"email": USER, "name": "유승인"})
    assert dev_client.get("/magazineapi/whoami").json()["is_admin"] is False
    assert dev_client.post("/magazineapi/topics", json=NEW).status_code == 403


def test_whoami_exposes_dev_accounts(dev_client):
    who = dev_client.get("/magazineapi/whoami").json()
    assert who["dev_login"] is True
    assert any(a["email"] == ADMIN for a in who["dev_accounts"])
def test_members_list_is_available_to_logged_in(client, settings):
    login(client, settings, USER)
    r = client.get("/magazineapi/members")
    assert r.status_code == 200
    members = r.json()
    assert any(m["email"] == ADMIN for m in members)
    assert all("name" in m and "email" in m for m in members)


def test_members_requires_login(client):
    assert client.get("/magazineapi/members").status_code == 401


def test_admin_assigns_presenter(client, settings):
    tid = _open_topic_id(client, settings)
    r = client.post(f"/magazineapi/topics/{tid}/assign",
                    json={"email": USER, "planned_date": "2026-11-03"})
    assert r.status_code == 200
    body = r.json()
    assert body["presenter_email"] == USER
    assert body["presenter"] == "유승인"          # 명단에서 이름을 채운다
    assert body["planned_date"] == "2026-11-03"
    assert body["status"] == "발표예정"


def test_admin_reassigns_already_claimed_topic(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER, "유승인")
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, settings, ADMIN)
    r = client.post(f"/magazineapi/topics/{tid}/assign", json={"email": OTHER})
    assert r.status_code == 200
    assert r.json()["presenter_email"] == OTHER


def test_admin_unassigns_with_empty_email(client, settings):
    tid = _open_topic_id(client, settings)
    client.post(f"/magazineapi/topics/{tid}/assign", json={"email": USER})
    r = client.post(f"/magazineapi/topics/{tid}/assign", json={"email": ""})
    assert r.status_code == 200
    assert r.json()["status"] == "미지정"
    assert r.json()["presenter_email"] in ("", None)


def test_unknown_email_is_rejected(client, settings):
    tid = _open_topic_id(client, settings)
    r = client.post(f"/magazineapi/topics/{tid}/assign",
                    json={"email": "nobody@bluesoft.co.kr"})
    assert r.status_code == 422


def test_normal_user_cannot_assign(client, settings):
    tid = _open_topic_id(client, settings)
    login(client, settings, USER)
    assert client.post(f"/magazineapi/topics/{tid}/assign",
                       json={"email": OTHER}).status_code == 403


def test_anonymous_cannot_assign(client, settings):
    tid = _open_topic_id(client, settings)
    client.cookies.clear()
    assert client.post(f"/magazineapi/topics/{tid}/assign",
                       json={"email": USER}).status_code == 401


def test_assign_missing_topic_is_404(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/magazineapi/topics/99999/assign",
                       json={"email": USER}).status_code == 404


def test_assign_keeps_planned_date_when_omitted(client, settings):
    tid = _open_topic_id(client, settings)
    client.post(f"/magazineapi/topics/{tid}/assign",
                json={"email": USER, "planned_date": "2026-11-03"})
    r = client.post(f"/magazineapi/topics/{tid}/assign", json={"email": OTHER})
    assert r.json()["planned_date"] == "2026-11-03"
