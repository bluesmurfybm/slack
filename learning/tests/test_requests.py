from sqlmodel import Session

from conftest import ADMIN, OTHER, USER, login
from core.db import LearningCert


def payload(**over):
    return {"site": "인프런", "category_large": "개발/프로그래밍",
            "category_medium": "백엔드", "level": "초급",
            "title": "테스트 강의", "url": "https://inf.run/x",
            "account_type": "개인계정", "duration_min": 130, "price": 100000,
            "start_date": "2026-01-01", "end_date": "2026-01-31", **over}


def add_cert(client, rid):
    with Session(client.app.state.engine) as session:
        session.add(LearningCert(request_id=rid, name="이수증.png",
                                 path=f"{rid}_stub.png"))
        session.commit()


def create(client, **over):
    r = client.post("/learningapi/requests", json=payload(**over))
    assert r.status_code == 201, r.text
    return r.json()


def approve(client, settings, rid):
    login(client, settings, ADMIN)
    r = client.post(f"/learningapi/requests/{rid}/approve")
    assert r.status_code == 200, r.text
    return r.json()


# ---------- 권한 ----------

def test_list_needs_a_login(client):
    assert client.get("/learningapi/requests").status_code == 401


def test_create_needs_a_login(client):
    assert client.post("/learningapi/requests", json=payload()).status_code == 401


def test_a_normal_user_cannot_approve(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    assert client.post(f"/learningapi/requests/{rid}/approve").status_code == 403


def test_someone_else_cannot_edit(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, OTHER)
    r = client.put(f"/learningapi/requests/{rid}", json={"title": "가로채기"})
    assert r.status_code == 403


def test_the_applicant_comes_from_the_cookie(client, settings):
    login(client, settings, USER)
    body = create(client, applicant="남의이름", applicant_email=OTHER)
    assert body["applicant_email"] == USER
    assert body["applicant"] == "유승인"


# ---------- 입력 검증 ----------

def test_an_unknown_site_is_rejected(client, settings):
    login(client, settings, USER)
    r = client.post("/learningapi/requests", json=payload(site="클래스101"))
    assert r.status_code == 422


def test_a_blank_title_is_rejected(client, settings):
    login(client, settings, USER)
    assert client.post("/learningapi/requests",
                       json=payload(title="  ")).status_code == 422


def test_a_bad_date_is_rejected(client, settings):
    login(client, settings, USER)
    assert client.post("/learningapi/requests",
                       json=payload(start_date="2026/01/01")).status_code == 422


# ---------- 상태 파생 ----------

def test_a_new_request_waits_for_approval(client, settings):
    login(client, settings, USER)
    assert create(client)["status"] == "수강승인요청"


def test_the_owner_cannot_edit_after_approval(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    r = client.put(f"/learningapi/requests/{rid}", json={"title": "고친 제목"})
    assert r.status_code == 409


def test_reject_needs_a_reason(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, ADMIN)
    assert client.post(f"/learningapi/requests/{rid}/reject",
                       json={"reason": " "}).status_code == 422


def test_reject_stops_the_rail(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, ADMIN)
    body = client.post(f"/learningapi/requests/{rid}/reject",
                       json={"reason": "업무와 무관"}).json()
    assert body["status"] == "수강반려"
    assert body["reject_reason"] == "업무와 무관"


def test_claim_without_a_certificate_is_refused(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    assert client.post(f"/learningapi/requests/{rid}/claim").status_code == 409


def test_the_full_paid_flow(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)

    login(client, settings, USER)
    add_cert(client, rid)
    assert client.post(f"/learningapi/requests/{rid}/claim").json()["status"] == "수강료청구"

    login(client, settings, ADMIN)
    body = client.post(f"/learningapi/requests/{rid}/claim-approve").json()
    assert body["status"] == "청구승인"
    assert body["refund_amount"] == 100000
    assert client.post(f"/learningapi/requests/{rid}/refund").json()["status"] == "환급완료"


def test_reclaim_after_a_claim_rejection(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    client.post(f"/learningapi/requests/{rid}/claim")

    login(client, settings, ADMIN)
    body = client.post(f"/learningapi/requests/{rid}/claim-reject",
                       json={"reason": "이수증이 흐립니다"}).json()
    assert body["status"] == "청구반려"

    login(client, settings, USER)
    again = client.post(f"/learningapi/requests/{rid}/claim").json()
    assert again["status"] == "수강료청구"
    assert again["claim_reject_reason"] == "" # 지난 반려 사유가 현재 상태 옆에 남으면 안 된다

    memos = [h["memo"] for h in client.get(f"/learningapi/requests/{rid}").json()["history"]]
    assert "이수증이 흐립니다" in memos # 사유는 이력에 남는다


def test_refund_needs_the_claim_approved_first(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    login(client, settings, ADMIN)
    assert client.post(f"/learningapi/requests/{rid}/refund").status_code == 409


# ---------- 무료 강의 ----------

def test_a_free_course_has_no_request_status(client, settings):
    login(client, settings, USER)
    body = create(client, is_free=True, price=50000)
    assert body["status"] == ""
    assert body["is_free"] == 1
    assert body["price"] == 0 # 무료를 체크하면 금액은 버린다


def test_a_free_course_cannot_be_approved(client, settings):
    login(client, settings, USER)
    rid = create(client, is_free=True)["id"]
    login(client, settings, ADMIN)
    assert client.post(f"/learningapi/requests/{rid}/approve").status_code == 409


def test_a_free_course_stays_editable(client, settings):
    login(client, settings, USER)
    rid = create(client, is_free=True)["id"]
    r = client.put(f"/learningapi/requests/{rid}", json={"title": "고친 제목"})
    assert r.status_code == 200
    assert r.json()["title"] == "고친 제목"


# ---------- 회사계정 ----------

def test_a_company_account_ends_at_approval(client, settings):
    login(client, settings, USER)
    rid = create(client, account_type="회사계정")["id"]
    assert approve(client, settings, rid)["status"] == "환급불필요"


def test_a_company_account_cannot_claim(client, settings):
    login(client, settings, USER)
    rid = create(client, account_type="회사계정")["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    assert client.post(f"/learningapi/requests/{rid}/claim").status_code == 409


# ---------- 진행상태 ----------

def test_progress_starts_at_not_started(client, settings):
    login(client, settings, USER)
    assert create(client)["progress"] == "시작전"


def test_only_the_owner_moves_progress(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, ADMIN)
    r = client.post(f"/learningapi/requests/{rid}/progress", json={"progress": "진행중"})
    assert r.status_code == 403


def test_progress_records_when_it_changed(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    body = client.post(f"/learningapi/requests/{rid}/progress",
                       json={"progress": "진행중"}).json()
    assert body["progress"] == "진행중"
    assert body["progress_at"]


def test_an_unknown_progress_is_rejected(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    assert client.post(f"/learningapi/requests/{rid}/progress",
                       json={"progress": "대충함"}).status_code == 422


def test_claiming_finishes_the_progress(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    assert client.post(f"/learningapi/requests/{rid}/claim").json()["progress"] == "완료"


# ---------- 이력 ----------

def test_every_transition_leaves_a_history_row(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    add_cert(client, rid)
    client.post(f"/learningapi/requests/{rid}/claim")

    rows = client.get(f"/learningapi/requests/{rid}").json()["history"]
    assert [h["status"] for h in rows] == ["수강승인요청", "수강승인", "수강료청구"]
    assert rows[1]["actor_email"] == ADMIN


# ---------- 목록 ----------

def test_the_list_carries_the_certificate_count(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    add_cert(client, rid)
    add_cert(client, rid)
    row = next(r for r in client.get("/learningapi/requests").json() if r["id"] == rid)
    assert row["cert_count"] == 2


def test_delete_removes_the_history_too(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    assert client.delete(f"/learningapi/requests/{rid}").status_code == 200
    assert client.get(f"/learningapi/requests/{rid}").status_code == 404


# ---------- 보관함 ----------

def test_only_an_admin_archives(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    assert client.post(f"/learningapi/requests/{rid}/archive").status_code == 403


def test_archiving_hides_it_from_the_user_list(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, ADMIN)
    assert client.post(f"/learningapi/requests/{rid}/archive").json()["archived"] == 1
    assert any(r["id"] == rid for r in client.get("/learningapi/requests").json())
    login(client, settings, USER)
    assert not any(r["id"] == rid for r in client.get("/learningapi/requests").json())


def test_archiving_is_reversible(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, ADMIN)
    client.post(f"/learningapi/requests/{rid}/archive")
    assert client.post(f"/learningapi/requests/{rid}/unarchive").json()["archived"] == 0


# ---------- 관리자 삭제 ----------

def test_an_admin_deletes_at_any_status(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    approve(client, settings, rid)
    # 본인은 승인 후 못 지운다
    login(client, settings, USER)
    assert client.delete(f"/learningapi/requests/{rid}").status_code == 409
    login(client, settings, ADMIN)
    assert client.delete(f"/learningapi/requests/{rid}").status_code == 200
    assert client.get(f"/learningapi/requests/{rid}").status_code == 404


def test_a_stranger_still_cannot_delete(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    login(client, settings, OTHER)
    assert client.delete(f"/learningapi/requests/{rid}").status_code == 403
