from fastapi.testclient import TestClient

from app import create_app
from conftest import ADMIN, OTHER, USER, login, make_settings


NEW = {"title": "자료 붙일 주제", "requirement": "recommended"}


def _claimed(client, settings):
    """관리자가 주제를 만들고 USER 가 선점한 상태의 id."""
    login(client, settings, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    login(client, settings, USER, "유승인")
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    return tid


def _url(tid, tail=""):
    return f"/magazineapi/topics/{tid}/material{tail}"


# ---------- 링크 ----------
def test_presenter_attaches_link(client, settings):
    tid = _claimed(client, settings)
    r = client.post(_url(tid, "/link"),
                    json={"url": "https://example.com/deck.pdf", "name": "발표자료"})
    assert r.status_code == 200
    body = r.json()
    assert body["material_kind"] == "link"
    assert body["material_url"] == "https://example.com/deck.pdf"
    assert body["material_name"] == "발표자료"


def test_link_shows_up_in_list(client, settings):
    tid = _claimed(client, settings)
    client.post(_url(tid, "/link"), json={"url": "https://example.com/a"})
    row = next(t for t in client.get("/magazineapi/topics").json() if t["id"] == tid)
    assert row["material_kind"] == "link"


def test_third_party_cannot_attach(client, settings):
    tid = _claimed(client, settings)
    login(client, settings, OTHER)
    assert client.post(_url(tid, "/link"),
                       json={"url": "https://example.com/a"}).status_code == 403


def test_admin_can_attach_to_anyones_topic(client, settings):
    tid = _claimed(client, settings)
    login(client, settings, ADMIN)
    assert client.post(_url(tid, "/link"),
                       json={"url": "https://example.com/a"}).status_code == 200


def test_anonymous_cannot_attach(client, settings):
    tid = _claimed(client, settings)
    client.cookies.clear()
    assert client.post(_url(tid, "/link"),
                       json={"url": "https://example.com/a"}).status_code == 401


def test_non_http_scheme_rejected(client, settings):
    tid = _claimed(client, settings)
    for bad in ("javascript:alert(1)", "file:///etc/passwd", "ftp://x/y"):
        assert client.post(_url(tid, "/link"), json={"url": bad}).status_code == 422


# ---------- 파일 ----------
def test_presenter_uploads_file(client, settings):
    tid = _claimed(client, settings)
    r = client.post(_url(tid, "/file"),
                    files={"file": ("발표.pdf", b"%PDF-1.4 fake", "application/pdf")})
    assert r.status_code == 200
    body = r.json()
    assert body["material_kind"] == "file"
    assert body["material_name"] == "발표.pdf"
    assert body["material_path"]           # 저장 이름은 서버가 만든다
    assert "발표.pdf" != body["material_path"]


def test_uploaded_file_downloads_with_content(client, settings):
    tid = _claimed(client, settings)
    client.post(_url(tid, "/file"),
                files={"file": ("발표.pdf", b"%PDF-1.4 fake", "application/pdf")})
    r = client.get(_url(tid, "/download"))
    assert r.status_code == 200
    assert r.content == b"%PDF-1.4 fake"
    assert r.headers["content-disposition"].startswith("inline")


def test_svg_and_html_are_forced_to_download(client, settings):
    """같은 오리진에서 인라인으로 열면 스크립트가 돌아 세션을 노릴 수 있다."""
    for name, ctype in (("x.svg", "image/svg+xml"), ("x.html", "text/html")):
        tid = _claimed(client, settings)
        client.post(_url(tid, "/file"), files={"file": (name, b"<svg onload=1>", ctype)})
        r = client.get(_url(tid, "/download"))
        assert r.headers["content-disposition"].startswith("attachment"), name
        assert r.headers["content-type"].startswith("application/octet-stream"), name


def test_upload_over_limit_is_rejected(tmp_path):
    small = make_settings(tmp_path, max_upload_bytes=1024)
    c = TestClient(create_app(small))
    login(c, small, ADMIN)
    tid = c.post("/magazineapi/topics", json=NEW).json()["id"]
    r = c.post(_url(tid, "/file"),
               files={"file": ("big.bin", b"x" * 5000, "application/octet-stream")})
    assert r.status_code == 413


def test_download_404_when_nothing_attached(client, settings):
    tid = _claimed(client, settings)
    assert client.get(_url(tid, "/download")).status_code == 404


def test_anonymous_cannot_download(client, settings):
    tid = _claimed(client, settings)
    client.post(_url(tid, "/file"), files={"file": ("a.txt", b"hello", "text/plain")})
    client.cookies.clear()
    assert client.get(_url(tid, "/download")).status_code == 401


# ---------- 교체 / 삭제 ----------
def test_new_upload_replaces_previous_file(client, settings):
    import os
    tid = _claimed(client, settings)
    first = client.post(_url(tid, "/file"),
                        files={"file": ("a.txt", b"one", "text/plain")}).json()["material_path"]
    client.post(_url(tid, "/file"), files={"file": ("b.txt", b"two", "text/plain")})
    assert not os.path.exists(os.path.join(settings.upload_dir, first))
    assert client.get(_url(tid, "/download")).content == b"two"


def test_link_replaces_file_and_removes_it(client, settings):
    import os
    tid = _claimed(client, settings)
    stored = client.post(_url(tid, "/file"),
                         files={"file": ("a.txt", b"one", "text/plain")}).json()["material_path"]
    r = client.post(_url(tid, "/link"), json={"url": "https://example.com/a"})
    assert r.json()["material_kind"] == "link"
    assert r.json()["material_path"] is None
    assert not os.path.exists(os.path.join(settings.upload_dir, stored))


def test_presenter_detaches(client, settings):
    tid = _claimed(client, settings)
    client.post(_url(tid, "/link"), json={"url": "https://example.com/a"})
    r = client.delete(_url(tid))
    assert r.status_code == 200
    assert r.json()["material_kind"] is None
    assert r.json()["material_url"] is None


def test_third_party_cannot_detach(client, settings):
    tid = _claimed(client, settings)
    client.post(_url(tid, "/link"), json={"url": "https://example.com/a"})
    login(client, settings, OTHER)
    assert client.delete(_url(tid)).status_code == 403
