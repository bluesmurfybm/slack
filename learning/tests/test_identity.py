from fastapi.testclient import TestClient

from app import create_app
from conftest import ADMIN, OTHER, USER, login, make_settings


def test_whoami_is_anonymous_without_a_cookie(client):
    body = client.get("/learningapi/whoami").json()
    assert body["email"] is None
    assert body["is_admin"] is False


def test_whoami_reads_the_portal_cookie(client, settings):
    login(client, settings, USER, "유승인")
    body = client.get("/learningapi/whoami").json()
    assert body["email"] == USER
    assert body["name"] == "유승인"
    assert body["is_admin"] is False


def test_admin_is_recognised(client, settings):
    login(client, settings, ADMIN)
    assert client.get("/learningapi/whoami").json()["is_admin"] is True


def test_a_tampered_cookie_is_rejected(client, settings):
    login(client, settings, ADMIN)
    payload = client.cookies["blueiwork_id"].split(".")[0]
    client.cookies.set("blueiwork_id", f"{payload}.deadbeef")
    assert client.get("/learningapi/whoami").json()["email"] is None


def test_members_needs_a_login(client):
    assert client.get("/learningapi/members").status_code == 401


def test_members_lists_the_roster(client, settings):
    login(client, settings, USER)
    names = [m["name"] for m in client.get("/learningapi/members").json()]
    assert "유승인" in names
    assert "이승민" not in names # 퇴사자는 명단에 없다


def test_devlogin_is_absent_unless_enabled(client):
    assert client.post("/learningapi/devlogin", json={"email": USER}).status_code == 404


def test_devlogin_issues_a_cookie(dev_client):
    body = dev_client.post("/learningapi/devlogin", json={"email": ADMIN}).json()
    assert body["is_admin"] is True
    assert dev_client.get("/learningapi/whoami").json()["email"] == ADMIN


def test_index_redirects_to_the_portal_without_a_login(client):
    r = client.get("/", follow_redirects=False)
    assert r.status_code == 307


def test_index_is_served_after_login(client, settings):
    login(client, settings, USER)
    assert client.get("/").status_code == 200


# ---------- 관리자 명단 ----------

OWNER = "kimhy@bluesoft.co.kr"
OWNER2 = "venus@bluesoft.co.kr"
BOTH = {OWNER, OWNER2}


def admins(client):
    return {m["email"] for m in client.get("/learningapi/admins").json()["members"]
            if m["is_admin"]}


def test_the_owner_is_always_an_admin(client, settings):
    # 설정에 없어도(conftest 는 jian 만 넣는다) 코드에 고정된 사람은 관리자다
    assert OWNER not in settings.admin_emails
    login(client, settings, OWNER)
    assert client.get("/learningapi/whoami").json()["is_admin"] is True


def test_only_an_admin_reads_the_roster(client, settings):
    login(client, settings, USER)
    assert client.get("/learningapi/admins").status_code == 403


def test_a_plain_admin_cannot_change_the_roster(client, settings):
    login(client, settings, ADMIN) # 관리자지만 최고 관리자는 아니다
    assert client.get("/learningapi/admins").json()["can_manage"] is False
    assert client.put("/learningapi/admins", json={"emails": []}).status_code == 403


def test_the_owner_grants_and_revokes(client, settings):
    login(client, settings, OWNER)
    assert client.put("/learningapi/admins",
                      json={"emails": [USER]}).status_code == 200
    assert admins(client) == BOTH | {USER} # jian 은 빠지고 고정 관리자는 남는다

    login(client, settings, USER)
    assert client.get("/learningapi/whoami").json()["is_admin"] is True
    login(client, settings, ADMIN)
    assert client.get("/learningapi/whoami").json()["is_admin"] is False


def test_the_owner_survives_an_empty_save(client, settings):
    login(client, settings, OWNER)
    client.put("/learningapi/admins", json={"emails": []})
    assert admins(client) == BOTH
    assert client.get("/learningapi/whoami").json()["is_admin"] is True


def test_an_unknown_email_is_refused(client, settings):
    login(client, settings, OWNER)
    r = client.put("/learningapi/admins", json={"emails": ["nobody@bluesoft.co.kr"]})
    assert r.status_code == 422
    assert admins(client) == set(settings.admin_emails) | BOTH # 아무것도 안 바뀐다


def test_whoami_says_who_the_owner_is(client, settings):
    for email in BOTH:
        login(client, settings, email)
        assert client.get("/learningapi/whoami").json()["is_owner"] is True
    login(client, settings, ADMIN)
    assert client.get("/learningapi/whoami").json()["is_owner"] is False


def test_both_owners_can_manage(client, settings):
    for email in BOTH:
        login(client, settings, email)
        body = client.get("/learningapi/admins").json()
        assert body["can_manage"] is True
        assert set(body["owners"]) == BOTH
    # 한쪽 고정 관리자가 다른 쪽을 빼려 해도 남는다
    login(client, settings, OWNER2)
    client.put("/learningapi/admins", json={"emails": [OWNER2]})
    assert admins(client) == BOTH


def test_the_roster_survives_a_restart(tmp_path):
    settings = make_settings(tmp_path)
    client = TestClient(create_app(settings))
    login(client, settings, OWNER)
    client.put("/learningapi/admins", json={"emails": [OTHER]})

    again = TestClient(create_app(settings))
    login(again, settings, OTHER)
    assert again.get("/learningapi/whoami").json()["is_admin"] is True
