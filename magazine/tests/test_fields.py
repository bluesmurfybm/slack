from conftest import ADMIN, USER, login


def _names(client):
    return [f["name"] for f in client.get("/magazineapi/fields").json()]


def test_fields_require_login(client):
    assert client.get("/magazineapi/fields").status_code == 401


def test_default_fields_are_seeded(client, settings):
    login(client, settings, USER)
    assert _names(client) == ["UI/UX", "Marketing", "Trend", "AX", "Etc"]


def test_admin_adds_field(client, settings):
    login(client, settings, ADMIN)
    r = client.post("/magazineapi/fields", json={"name": "Data"})
    assert r.status_code == 201
    assert r.json()["name"] == "Data"
    assert "Data" in _names(client)


def test_added_name_is_trimmed(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/magazineapi/fields",
                       json={"name": " Data "}).json()["name"] == "Data"


def test_duplicate_field_is_conflict(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/magazineapi/fields", json={"name": "AX"}).status_code == 409


def test_blank_field_is_rejected(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/magazineapi/fields", json={"name": "  "}).status_code == 422


def test_normal_user_cannot_add(client, settings):
    login(client, settings, USER)
    assert client.post("/magazineapi/fields", json={"name": "Data"}).status_code == 403


def test_admin_deletes_field(client, settings):
    login(client, settings, ADMIN)
    fid = client.post("/magazineapi/fields", json={"name": "Data"}).json()["id"]
    assert client.delete(f"/magazineapi/fields/{fid}").status_code == 200
    assert "Data" not in _names(client)


def test_deleting_used_field_keeps_topic_value(client, settings):
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics",
                      json={"title": "t", "field": "AX"}).json()["id"]
    fid = next(f["id"] for f in client.get("/magazineapi/fields").json()
               if f["name"] == "AX")
    assert client.delete(f"/magazineapi/fields/{fid}").status_code == 200
    assert client.get(f"/magazineapi/topics/{tid}").json()["field"] == "AX"


def test_delete_missing_field_is_404(client, settings):
    login(client, settings, ADMIN)
    assert client.delete("/magazineapi/fields/99999").status_code == 404


def test_normal_user_cannot_delete(client, settings):
    login(client, settings, USER)
    assert client.delete("/magazineapi/fields/1").status_code == 403
