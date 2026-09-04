from conftest import ADMIN, USER, login


def names(client, **params):
    return [s["name"] for s in client.get("/learningapi/sites", params=params).json()]


def test_list_needs_a_login(client):
    assert client.get("/learningapi/sites").status_code == 401


def test_the_two_seeded_sites_are_listed(client, settings):
    login(client, settings, USER)
    assert names(client) == ["인프런", "패스트캠퍼스"]


def test_a_normal_user_cannot_add_a_site(client, settings):
    login(client, settings, USER)
    assert client.post("/learningapi/sites",
                       json={"name": "클래스101"}).status_code == 403


def test_an_admin_adds_a_site(client, settings):
    login(client, settings, ADMIN)
    r = client.post("/learningapi/sites",
                    json={"name": "클래스101", "url": "https://class101.net",
                          "sort_order": 9})
    assert r.status_code == 201
    login(client, settings, USER)
    assert "클래스101" in names(client)


def test_a_duplicate_site_is_refused(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/learningapi/sites",
                       json={"name": "인프런"}).status_code == 409


def test_a_blank_name_is_refused(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/learningapi/sites", json={"name": " "}).status_code == 422


def test_a_bad_url_is_refused(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/learningapi/sites",
                       json={"name": "새사이트", "url": "class101.net"}).status_code == 422


def test_deactivating_hides_it_from_users_only(client, settings):
    login(client, settings, ADMIN)
    sid = next(s["id"] for s in client.get("/learningapi/sites").json()
               if s["name"] == "패스트캠퍼스")
    assert client.delete(f"/learningapi/sites/{sid}").json()["active"] == 0
    assert "패스트캠퍼스" in names(client) # 관리자는 계속 본다
    login(client, settings, USER)
    assert "패스트캠퍼스" not in names(client)


def test_a_deactivated_site_cannot_be_chosen(client, settings):
    login(client, settings, ADMIN)
    sid = next(s["id"] for s in client.get("/learningapi/sites").json()
               if s["name"] == "인프런")
    client.delete(f"/learningapi/sites/{sid}")
    login(client, settings, USER)
    r = client.post("/learningapi/requests", json={"site": "인프런", "title": "강의"})
    assert r.status_code == 422


def test_renaming_a_site_moves_its_categories(client, settings):
    login(client, settings, ADMIN)
    sid = next(s["id"] for s in client.get("/learningapi/sites").json()
               if s["name"] == "인프런")
    client.put(f"/learningapi/sites/{sid}", json={"name": "인프런(구)"})
    assert client.get("/learningapi/categories",
                      params={"site": "인프런"}).json() == []
    assert client.get("/learningapi/categories",
                      params={"site": "인프런(구)"}).json()


def test_renaming_leaves_past_requests_alone(client, settings):
    login(client, settings, USER)
    rid = client.post("/learningapi/requests",
                      json={"site": "인프런", "title": "강의"}).json()["id"]
    login(client, settings, ADMIN)
    sid = next(s["id"] for s in client.get("/learningapi/sites").json()
               if s["name"] == "인프런")
    client.put(f"/learningapi/sites/{sid}", json={"name": "인프런(구)"})
    # 지난 신청 건은 당시 이름을 그대로 들고 있어야 한다
    assert client.get(f"/learningapi/requests/{rid}").json()["site"] == "인프런"


def test_renaming_onto_an_existing_name_is_refused(client, settings):
    login(client, settings, ADMIN)
    sid = next(s["id"] for s in client.get("/learningapi/sites").json()
               if s["name"] == "인프런")
    assert client.put(f"/learningapi/sites/{sid}",
                      json={"name": "패스트캠퍼스"}).status_code == 409


def test_reactivating_a_site(client, settings):
    login(client, settings, ADMIN)
    sid = next(s["id"] for s in client.get("/learningapi/sites").json()
               if s["name"] == "패스트캠퍼스")
    client.delete(f"/learningapi/sites/{sid}")
    assert client.put(f"/learningapi/sites/{sid}",
                      json={"active": True}).json()["active"] == 1
