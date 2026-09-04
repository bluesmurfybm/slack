from test_requests import add_cert, approve, create

from conftest import ADMIN, USER, login

OFF = {
    "partial_enabled": False, "partial_cap": 0,
    "annual_amount_enabled": False, "annual_amount_limit": 0,
    "annual_count_enabled": False, "annual_count_limit": 0,
    "claim_deadline_enabled": False, "claim_deadline_days": 0,
}


def set_policy(client, settings, **over):
    login(client, settings, ADMIN)
    r = client.put("/learningapi/policy", json={**OFF, **over})
    assert r.status_code == 200, r.text
    return r.json()


def claim_approve(client, settings, rid):
    login(client, settings, ADMIN)
    r = client.post(f"/learningapi/requests/{rid}/claim-approve")
    assert r.status_code == 200, r.text
    return r.json()


def run_to_claim(client, settings, **over):
    login(client, settings, USER)
    rid = create(client, **over)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    r = client.post(f"/learningapi/requests/{rid}/claim")
    assert r.status_code == 200, r.text
    return rid


# ---------- 기본값 ----------

def test_everything_is_off_by_default(client, settings):
    login(client, settings, USER)
    body = client.get("/learningapi/policy").json()
    assert body["partial_enabled"] == 0
    assert body["annual_amount_enabled"] == 0
    assert body["annual_count_enabled"] == 0
    assert body["claim_deadline_enabled"] == 0


def test_a_normal_user_cannot_change_the_policy(client, settings):
    login(client, settings, USER)
    assert client.put("/learningapi/policy", json=OFF).status_code == 403


def test_enabling_without_a_value_is_rejected(client, settings):
    login(client, settings, ADMIN)
    r = client.put("/learningapi/policy", json={**OFF, "partial_enabled": True})
    assert r.status_code == 422


# ---------- 부분환급 ----------

def test_the_whole_price_is_refunded_when_partial_is_off(client, settings):
    rid = run_to_claim(client, settings, price=600000)
    assert claim_approve(client, settings, rid)["refund_amount"] == 600000


def test_a_price_under_the_cap_is_refunded_in_full(client, settings):
    set_policy(client, settings, partial_enabled=True, partial_cap=500000)
    rid = run_to_claim(client, settings, price=400000)
    assert claim_approve(client, settings, rid)["refund_amount"] == 400000


def test_a_price_over_the_cap_is_trimmed(client, settings):
    set_policy(client, settings, partial_enabled=True, partial_cap=500000)
    rid = run_to_claim(client, settings, price=600000)
    assert claim_approve(client, settings, rid)["refund_amount"] == 500000


def test_the_cap_is_snapshotted_at_request_time(client, settings):
    # 관리자가 나중에 상한을 낮춰도 이미 신청한 건의 환급액이 뒤에서 움직이면 안 된다.
    set_policy(client, settings, partial_enabled=True, partial_cap=500000)
    rid = run_to_claim(client, settings, price=600000)
    set_policy(client, settings, partial_enabled=True, partial_cap=300000)
    assert claim_approve(client, settings, rid)["refund_amount"] == 500000


def test_the_cap_is_not_applied_to_requests_made_while_it_was_off(client, settings):
    rid = run_to_claim(client, settings, price=600000)
    set_policy(client, settings, partial_enabled=True, partial_cap=100000)
    assert claim_approve(client, settings, rid)["refund_amount"] == 600000


# ---------- 연간 건수 ----------

def test_the_count_limit_blocks_the_next_request(client, settings):
    set_policy(client, settings, annual_count_enabled=True, annual_count_limit=2)
    login(client, settings, USER)
    create(client)
    create(client)
    r = client.post("/learningapi/requests", json={
        "site": "인프런", "title": "세 번째", "price": 1000})
    assert r.status_code == 409
    assert "2건" in r.json()["detail"]


def test_the_count_limit_ignores_free_courses(client, settings):
    set_policy(client, settings, annual_count_enabled=True, annual_count_limit=1)
    login(client, settings, USER)
    create(client)
    assert client.post("/learningapi/requests",
                       json={"site": "인프런", "title": "무료", "is_free": True}
                       ).status_code == 201


def test_the_count_limit_ignores_rejected_requests(client, settings):
    set_policy(client, settings, annual_count_enabled=True, annual_count_limit=1)
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, ADMIN)
    client.post(f"/learningapi/requests/{rid}/reject", json={"reason": "반려"})
    login(client, settings, USER)
    assert client.post("/learningapi/requests", json={"site": "인프런",
                                                     "title": "다시"}).status_code == 201


def test_the_count_limit_is_per_person(client, settings):
    set_policy(client, settings, annual_count_enabled=True, annual_count_limit=1)
    login(client, settings, USER)
    create(client)
    login(client, settings, "hjlee@bluesoft.co.kr")
    assert client.post("/learningapi/requests",
                       json={"site": "인프런", "title": "남의 신청"}).status_code == 201


# ---------- 연간 금액 ----------

def test_the_amount_limit_blocks_the_next_request(client, settings):
    set_policy(client, settings, annual_amount_enabled=True, annual_amount_limit=500000)
    login(client, settings, USER)
    create(client, price=400000)
    r = client.post("/learningapi/requests",
                    json={"site": "인프런", "title": "초과", "price": 200000})
    assert r.status_code == 409
    assert "100,000원" in r.json()["detail"]


def test_the_amount_limit_counts_the_capped_amount(client, settings):
    # 부분환급 상한이 걸리면 실제로 나가는 돈만 한도에서 깎여야 한다.
    set_policy(client, settings, partial_enabled=True, partial_cap=100000,
               annual_amount_enabled=True, annual_amount_limit=300000)
    login(client, settings, USER)
    create(client, price=1000000)
    create(client, price=1000000)
    assert client.post("/learningapi/requests",
                       json={"site": "인프런", "title": "세 번째",
                             "price": 1000000}).status_code == 201


def test_the_amount_limit_ignores_company_accounts(client, settings):
    set_policy(client, settings, annual_amount_enabled=True, annual_amount_limit=100000)
    login(client, settings, USER)
    create(client, account_type="회사계정", price=9000000)
    assert client.post("/learningapi/requests",
                       json={"site": "인프런", "title": "개인",
                             "price": 100000}).status_code == 201


# ---------- 청구 기한 ----------

def test_a_late_claim_is_refused(client, settings):
    set_policy(client, settings, claim_deadline_enabled=True, claim_deadline_days=7)
    login(client, settings, USER)
    rid = create(client, end_date="2026-01-31")["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    r = client.post(f"/learningapi/requests/{rid}/claim")
    assert r.status_code == 409
    assert "2026-02-07" in r.json()["detail"]


def test_the_deadline_does_nothing_while_it_is_off(client, settings):
    login(client, settings, USER)
    rid = create(client, end_date="2020-01-01")["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    assert client.post(f"/learningapi/requests/{rid}/claim").status_code == 200


def test_a_long_deadline_still_allows_the_claim(client, settings):
    set_policy(client, settings, claim_deadline_enabled=True, claim_deadline_days=36500)
    login(client, settings, USER)
    rid = create(client, end_date="2026-01-31")["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    assert client.post(f"/learningapi/requests/{rid}/claim").status_code == 200


# ---------- 꺼져 있으면 아무것도 막지 않는다 ----------

def test_nothing_blocks_while_the_policy_is_untouched(client, settings):
    login(client, settings, USER)
    for _ in range(5):
        create(client, price=9000000, end_date="2000-01-01")
    rid = run_to_claim(client, settings, price=9000000, end_date="2000-01-01")
    assert claim_approve(client, settings, rid)["refund_amount"] == 9000000
