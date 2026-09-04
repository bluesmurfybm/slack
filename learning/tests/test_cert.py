import io
from pathlib import Path

from fastapi.testclient import TestClient
from sqlmodel import Session, select
from test_requests import approve, create

from app import create_app
from conftest import ADMIN, OTHER, USER, login, make_settings
from core.db import LearningCert

PNG = b"\x89PNG\r\n\x1a\n" + b"0" * 64


def upload(client, rid, name="이수증.png", data=PNG):
    return client.post(f"/learningapi/requests/{rid}/certs",
                       files={"file": (name, io.BytesIO(data), "application/octet-stream")})


def approved(client, settings, **over):
    login(client, settings, USER)
    rid = create(client, **over)["id"]
    approve(client, settings, rid)
    login(client, settings, USER)
    return rid


def test_upload_needs_a_login(client, settings):
    rid = approved(client, settings)
    client.cookies.clear()
    assert upload(client, rid).status_code == 401


def test_someone_else_cannot_upload(client, settings):
    rid = approved(client, settings)
    login(client, settings, OTHER)
    assert upload(client, rid).status_code == 403


def test_an_admin_can_upload_for_someone_else(client, settings):
    rid = approved(client, settings)
    login(client, settings, ADMIN)
    assert upload(client, rid).status_code == 201


def test_upload_before_approval_is_refused(client, settings):
    login(client, settings, USER)
    rid = create(client)["id"]
    assert upload(client, rid).status_code == 409


def test_a_free_course_takes_certificates_any_time(client, settings):
    login(client, settings, USER)
    rid = create(client, is_free=True)["id"]
    assert upload(client, rid).status_code == 201


def test_upload_then_list(client, settings):
    rid = approved(client, settings)
    body = upload(client, rid).json()
    assert len(body) == 1
    assert body[0]["name"] == "이수증.png"
    assert body[0]["uploaded_by"] == USER


def test_several_certificates_stack_up(client, settings):
    rid = approved(client, settings)
    upload(client, rid, "1.png")
    upload(client, rid, "2.pdf", b"%PDF-1.4 ...")
    assert len(client.get(f"/learningapi/requests/{rid}/certs").json()) == 2


def test_the_stored_name_is_not_the_original(client, settings):
    rid = approved(client, settings)
    upload(client, rid, "이수증.png")
    with Session(client.app.state.engine) as session:
        cert = session.exec(select(LearningCert)).one()
    assert cert.name == "이수증.png"
    assert cert.path.startswith(f"{rid}_")
    assert cert.path.endswith(".png")
    assert "이수증" not in cert.path


def test_an_executable_extension_is_refused(client, settings):
    rid = approved(client, settings)
    assert upload(client, rid, "shell.php", b"<?php echo 1; ?>").status_code == 422


def test_svg_is_refused(client, settings):
    # 같은 오리진에서 인라인으로 열리면 포털 세션을 노린 XSS 가 된다.
    rid = approved(client, settings)
    assert upload(client, rid, "x.svg", b"<svg onload=alert(1)>").status_code == 422


def test_a_refused_upload_leaves_nothing_behind(client, settings):
    rid = approved(client, settings)
    upload(client, rid, "shell.php", b"x")
    assert client.get(f"/learningapi/requests/{rid}/certs").json() == []


def test_download_serves_the_file(client, settings):
    rid = approved(client, settings)
    cid = upload(client, rid).json()[0]["id"]
    r = client.get(f"/learningapi/requests/{rid}/certs/{cid}/download")
    assert r.status_code == 200
    assert r.content == PNG
    assert r.headers["content-disposition"].startswith("inline")


def test_a_pdf_is_served_inline(client, settings):
    rid = approved(client, settings)
    cid = upload(client, rid, "증빙.pdf", b"%PDF-1.4").json()[0]["id"]
    r = client.get(f"/learningapi/requests/{rid}/certs/{cid}/download")
    assert r.headers["content-disposition"].startswith("inline")
    assert r.headers["content-type"].startswith("application/pdf")


def test_a_certificate_of_another_request_is_not_reachable(client, settings):
    rid = approved(client, settings)
    other = approved(client, settings)
    cid = upload(client, rid).json()[0]["id"]
    assert client.get(
        f"/learningapi/requests/{other}/certs/{cid}/download").status_code == 404


def test_delete_removes_the_row_and_the_file(client, settings):
    rid = approved(client, settings)
    cid = upload(client, rid).json()[0]["id"]
    stored = next(Path(settings.upload_dir).iterdir())
    assert client.delete(f"/learningapi/requests/{rid}/certs/{cid}").json() == []
    assert not stored.exists()


def test_deleting_the_request_deletes_its_files(client, settings):
    login(client, settings, USER)
    rid = create(client, is_free=True)["id"]
    upload(client, rid)
    assert list(Path(settings.upload_dir).iterdir())
    client.delete(f"/learningapi/requests/{rid}")
    assert not list(Path(settings.upload_dir).iterdir())


def test_a_certificate_unlocks_the_claim(client, settings):
    rid = approved(client, settings)
    assert client.post(f"/learningapi/requests/{rid}/claim").status_code == 409
    upload(client, rid)
    assert client.post(f"/learningapi/requests/{rid}/claim").status_code == 200


def test_the_detail_carries_the_certificates(client, settings):
    rid = approved(client, settings)
    upload(client, rid)
    body = client.get(f"/learningapi/requests/{rid}").json()
    assert len(body["certs"]) == 1
    assert body["cert_count"] == 1


def test_an_oversized_upload_is_refused(tmp_path):
    settings = make_settings(tmp_path, max_upload_mb=1)
    client = TestClient(create_app(settings))
    login(client, settings, USER)
    rid = create(client, is_free=True)["id"]
    assert upload(client, rid, "big.png", b"0" * (2 * 1024 * 1024)).status_code == 413
    assert not list(Path(settings.upload_dir).iterdir())
