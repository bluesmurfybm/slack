"""분류 실제 삭제(purge) — 안 쓰는 것만 지워지고, 쓰는 것은 숨기기로 보낸다."""
from conftest import ADMIN, USER, login


def rows(client):
    return client.get("/learningapi/categories").json()


def site_of(client):
    return client.get("/learningapi/sites").json()[0]["name"]


def test_purge_needs_admin(client, settings):
    login(client, settings, USER)
    cid = rows(client)[0]["id"]
    assert client.delete(f"/learningapi/categories/{cid}/purge").status_code == 403


def test_purge_removes_an_unused_medium(client, settings):
    login(client, settings, ADMIN)
    site = site_of(client)
    large = client.post("/learningapi/categories",
                        json={"site": site, "large": "지울대분류"}).json()
    med = client.post("/learningapi/categories",
                      json={"site": site, "large": "지울대분류",
                            "medium": "안쓰는중분류"}).json()

    got = client.delete(f"/learningapi/categories/{med['id']}/purge")
    assert got.status_code == 200, got.text
    assert got.json()["deleted"] == 1

    ids = [c["id"] for c in rows(client)]
    assert med["id"] not in ids
    assert large["id"] in ids


def test_purging_a_large_takes_its_children(client, settings):
    login(client, settings, ADMIN)
    site = site_of(client)
    large = client.post("/learningapi/categories",
                        json={"site": site, "large": "통째로"}).json()
    kid = client.post("/learningapi/categories",
                      json={"site": site, "large": "통째로", "medium": "딸린것"}).json()

    got = client.delete(f"/learningapi/categories/{large['id']}/purge")
    assert got.status_code == 200, got.text
    assert got.json()["deleted"] == 2

    ids = [c["id"] for c in rows(client)]
    assert large["id"] not in ids
    assert kid["id"] not in ids


def test_purge_refuses_a_category_in_use(client, settings):
    login(client, settings, ADMIN)
    site = site_of(client)
    client.post("/learningapi/categories", json={"site": site, "large": "쓰는대분류"})
    med = client.post("/learningapi/categories",
                      json={"site": site, "large": "쓰는대분류",
                            "medium": "쓰는중분류"}).json()
    made = client.post("/learningapi/requests", json={
        "title": "분류를 쓰는 신청", "site": site,
        "category_large": "쓰는대분류", "category_medium": "쓰는중분류",
        "account_type": "개인계정", "price": 10000,
    })
    assert made.status_code in (200, 201), made.text

    got = client.delete(f"/learningapi/categories/{med['id']}/purge")
    assert got.status_code == 409, got.text
    assert "1건" in got.json()["detail"]

    # 지우지는 못해도 숨기기는 여전히 된다
    off = client.delete(f"/learningapi/categories/{med['id']}")
    assert off.status_code == 200
    assert off.json()["active"] == 0


def test_purging_a_large_sees_usage_under_it(client, settings):
    """중분류를 쓰는 신청이 있으면 그 대분류도 지워지지 않는다."""
    login(client, settings, ADMIN)
    site = site_of(client)
    large = client.post("/learningapi/categories",
                        json={"site": site, "large": "부모대분류"}).json()
    client.post("/learningapi/categories",
                json={"site": site, "large": "부모대분류", "medium": "자식중분류"})
    client.post("/learningapi/requests", json={
        "title": "자식 중분류를 쓰는 신청", "site": site,
        "category_large": "부모대분류", "category_medium": "자식중분류",
        "account_type": "개인계정", "price": 10000,
    })

    got = client.delete(f"/learningapi/categories/{large['id']}/purge")
    assert got.status_code == 409, got.text
