from test_requests import add_cert, approve, create

from conftest import ADMIN, OTHER, USER, login


def review(client, rid, **body):
    return client.post(f"/learningapi/requests/{rid}/review", json=body)


def approved(client, settings, **over):
    login(client, settings, USER)
    rid = create(client, **over)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    return rid


def test_a_new_request_has_no_stars(client, settings):
    login(client, settings, USER)
    body = create(client)
    assert body["rating"] is None
    assert body["recommend"] is None


def test_review_needs_a_login(client, settings):
    rid = approved(client, settings)
    client.cookies.clear()
    assert review(client, rid, rating=4).status_code == 401


def test_only_the_owner_reviews(client, settings):
    rid = approved(client, settings)
    login(client, settings, OTHER)
    assert review(client, rid, rating=4).status_code == 403


def test_an_admin_cannot_review_for_someone_else(client, settings):
    rid = approved(client, settings)
    login(client, settings, ADMIN)
    assert review(client, rid, rating=4).status_code == 403


def test_review_before_approval_is_refused(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    assert review(client, rid, rating=4).status_code == 409


def test_a_free_course_can_be_reviewed_any_time(client, settings):
    login(client, settings, USER)
    rid = create(client, is_free=True)["id"]
    assert review(client, rid, rating=5).status_code == 200


def test_half_steps_are_accepted(client, settings):
    rid = approved(client, settings)
    body = review(client, rid, rating=3.5, recommend=4.5).json()
    assert body["rating"] == 3.5
    assert body["recommend"] == 4.5


def test_an_off_step_score_is_refused(client, settings):
    rid = approved(client, settings)
    assert review(client, rid, rating=3.3).status_code == 422


def test_a_score_out_of_range_is_refused(client, settings):
    rid = approved(client, settings)
    assert review(client, rid, rating=0).status_code == 422
    assert review(client, rid, rating=5.5).status_code == 422
    assert review(client, rid, rating=-1).status_code == 422


def test_only_one_of_the_two_can_be_set(client, settings):
    rid = approved(client, settings)
    body = review(client, rid, rating=4).json()
    assert body["rating"] == 4
    assert body["recommend"] is None


def test_a_note_can_be_left(client, settings):
    rid = approved(client, settings)
    assert review(client, rid, review_note="실무에 바로 썼습니다"
                  ).json()["review_note"] == "실무에 바로 썼습니다"


def test_the_review_survives_the_refund(client, settings):
    rid = approved(client, settings)
    review(client, rid, rating=4, recommend=5)
    add_cert(client, rid)
    client.post(f"/learningapi/requests/{rid}/claim")
    login(client, settings, ADMIN)
    client.post(f"/learningapi/requests/{rid}/claim-approve")
    body = client.post(f"/learningapi/requests/{rid}/refund").json()
    assert body["status"] == "환급완료"
    assert body["rating"] == 4


def test_the_review_is_editable_after_the_refund(client, settings):
    rid = approved(client, settings)
    add_cert(client, rid)
    client.post(f"/learningapi/requests/{rid}/claim")
    login(client, settings, ADMIN)
    client.post(f"/learningapi/requests/{rid}/claim-approve")
    client.post(f"/learningapi/requests/{rid}/refund")
    login(client, settings, USER)
    assert review(client, rid, rating=2).json()["rating"] == 2


def test_a_score_can_be_cleared_back_to_unrated(client, settings):
    rid = approved(client, settings)
    review(client, rid, rating=4)
    assert review(client, rid, rating=None).json()["rating"] is None
