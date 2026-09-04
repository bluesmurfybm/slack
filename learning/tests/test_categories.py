from conftest import ADMIN, USER, login

DEV = "개발 · 프로그래밍"


def rows(client, **params):
    return client.get("/learningapi/categories", params=params).json()


def find(client, large, medium=""):
    return next(r for r in rows(client) if r["large"] == large and r["medium"] == medium)


def test_list_needs_a_login(client):
    assert client.get("/learningapi/categories").status_code == 401


def test_the_seed_is_listed_as_flat_rows(client, settings):
    login(client, settings, USER)
    all_rows = rows(client)
    inflearn = [r for r in all_rows if r["site"] == "인프런"]
    assert len([r for r in inflearn if not r["medium"]]) == 13
    assert len(all_rows) == 142


def test_the_middle_dot_notation_survives_the_api(client, settings):
    login(client, settings, USER)
    mediums = [r["medium"] for r in rows(client)]
    assert "알고리즘 · 자료구조" in mediums


def test_filtering_by_site(client, settings):
    login(client, settings, USER)
    inflearn = rows(client, site="인프런")
    fast = rows(client, site="패스트캠퍼스")
    assert len(inflearn) == 96
    assert len(fast) == 46
    assert {r["id"] for r in inflearn} & {r["id"] for r in fast} == set()


def test_a_normal_user_cannot_add_a_category(client, settings):
    login(client, settings, USER)
    assert client.post("/learningapi/categories",
                       json={"site": "인프런", "large": "새분류"}).status_code == 403


def test_an_admin_adds_a_large_and_a_medium(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/learningapi/categories",
                       json={"site": "패스트캠퍼스", "large": "자격증"}).status_code == 201
    assert client.post("/learningapi/categories",
                       json={"site": "패스트캠퍼스", "large": "자격증",
                             "medium": "SQLD"}).status_code == 201


def test_a_medium_without_its_large_is_refused(client, settings):
    login(client, settings, ADMIN)
    r = client.post("/learningapi/categories",
                    json={"site": "패스트캠퍼스", "large": "없는대분류", "medium": "SQL"})
    assert r.status_code == 422


def test_a_duplicate_category_is_refused(client, settings):
    login(client, settings, ADMIN)
    assert client.post("/learningapi/categories",
                       json={"site": "인프런", "large": DEV,
                             "medium": "백엔드"}).status_code == 409


def test_recommended_can_be_set(client, settings):
    login(client, settings, ADMIN)
    cid = find(client, DEV, "백엔드")["id"]
    assert client.put(f"/learningapi/categories/{cid}",
                      json={"recommended": True}).json()["recommended"] == 1


def test_renaming_a_large_drags_its_mediums(client, settings):
    login(client, settings, ADMIN)
    cid = find(client, DEV)["id"]
    client.put(f"/learningapi/categories/{cid}", json={"large": "개발"})
    mediums = [r["medium"] for r in rows(client) if r["large"] == "개발" and r["medium"]]
    assert "백엔드" in mediums
    assert not [r for r in rows(client) if r["large"] == DEV]


def test_renaming_a_medium_leaves_its_siblings_alone(client, settings):
    login(client, settings, ADMIN)
    cid = find(client, DEV, "백엔드")["id"]
    client.put(f"/learningapi/categories/{cid}", json={"medium": "서버"})
    mediums = [r["medium"] for r in rows(client) if r["large"] == DEV]
    assert "서버" in mediums
    assert "프론트엔드" in mediums


def test_deactivating_a_large_hides_its_mediums(client, settings):
    login(client, settings, ADMIN)
    cid = find(client, DEV)["id"]
    client.delete(f"/learningapi/categories/{cid}")
    login(client, settings, USER)
    assert not [r for r in rows(client) if r["large"] == DEV]


def test_an_admin_still_sees_deactivated_rows(client, settings):
    login(client, settings, ADMIN)
    cid = find(client, DEV)["id"]
    client.delete(f"/learningapi/categories/{cid}")
    assert find(client, DEV)["active"] == 0


def test_a_deactivated_category_does_not_block_past_requests(client, settings):
    # 분류는 이름 문자열로 저장한다 — 지워도 지난 신청 건의 표시가 깨지면 안 된다.
    login(client, settings, USER)
    rid = client.post("/learningapi/requests",
                      json={"site": "인프런", "large": DEV, "title": "강의",
                            "category_large": DEV, "category_medium": "백엔드"}).json()["id"]
    login(client, settings, ADMIN)
    client.delete(f"/learningapi/categories/{find(client, DEV)['id']}")
    body = client.get(f"/learningapi/requests/{rid}").json()
    assert body["category_large"] == DEV
    assert body["category_medium"] == "백엔드"


# ---------- 추천 일괄 저장 ----------

def ids_of(client, *pairs):
    return [find(client, large, medium)["id"] for large, medium in pairs]


def recommended_of(client, site):
    return {r["id"] for r in rows(client, site=site) if r["recommended"]}


def test_bulk_recommend_needs_an_admin(client, settings):
    login(client, settings, USER)
    assert client.put("/learningapi/categories/recommended",
                      json={"site": "인프런", "ids": []}).status_code == 403


def test_bulk_recommend_sets_many_at_once(client, settings):
    login(client, settings, ADMIN)
    picked = ids_of(client, (DEV, "백엔드"), (DEV, "프론트엔드"), ("외국어", "영어"))
    r = client.put("/learningapi/categories/recommended",
                   json={"site": "인프런", "ids": picked})
    assert r.status_code == 200
    assert recommended_of(client, "인프런") == set(picked)


def test_bulk_recommend_clears_what_is_left_out(client, settings):
    # 화면 상태를 그대로 반영한다 — 체크를 푼 것도 같이 저장돼야 한다
    login(client, settings, ADMIN)
    first = ids_of(client, (DEV, "백엔드"), (DEV, "프론트엔드"))
    client.put("/learningapi/categories/recommended", json={"site": "인프런", "ids": first})
    client.put("/learningapi/categories/recommended",
               json={"site": "인프런", "ids": first[:1]})
    assert recommended_of(client, "인프런") == {first[0]}


def test_bulk_recommend_with_an_empty_list_clears_the_site(client, settings):
    login(client, settings, ADMIN)
    client.put("/learningapi/categories/recommended",
               json={"site": "인프런", "ids": ids_of(client, (DEV, "백엔드"))})
    client.put("/learningapi/categories/recommended", json={"site": "인프런", "ids": []})
    assert recommended_of(client, "인프런") == set()


def test_bulk_recommend_does_not_touch_other_sites(client, settings):
    login(client, settings, ADMIN)
    fast = ids_of(client, ("개발/데이터", "백엔드 개발"))
    client.put("/learningapi/categories/recommended",
               json={"site": "패스트캠퍼스", "ids": fast})
    client.put("/learningapi/categories/recommended",
               json={"site": "인프런", "ids": ids_of(client, (DEV, "백엔드"))})
    assert recommended_of(client, "패스트캠퍼스") == set(fast)


def test_bulk_recommend_rejects_an_id_from_another_site(client, settings):
    login(client, settings, ADMIN)
    stray = ids_of(client, ("개발/데이터", "백엔드 개발"))
    r = client.put("/learningapi/categories/recommended",
                   json={"site": "인프런", "ids": stray})
    assert r.status_code == 422


def test_bulk_recommend_on_an_unknown_site(client, settings):
    login(client, settings, ADMIN)
    assert client.put("/learningapi/categories/recommended",
                      json={"site": "없는사이트", "ids": []}).status_code == 404


def test_bulk_recommend_covers_a_large_row_too(client, settings):
    login(client, settings, ADMIN)
    picked = ids_of(client, (DEV, ""))
    client.put("/learningapi/categories/recommended", json={"site": "인프런", "ids": picked})
    assert find(client, DEV)["recommended"] == 1
