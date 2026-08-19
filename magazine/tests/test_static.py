def test_static_files_must_revalidate(client):
    r = client.get("/static/core.js")
    assert r.status_code == 200
    assert r.headers.get("cache-control") == "no-cache"


def test_styles_must_revalidate(client):
    r = client.get("/styles/style.css")
    assert r.headers.get("cache-control") == "no-cache"


def test_api_responses_are_not_touched(client):
    r = client.get("/magazineapi/whoami")
    assert r.headers.get("cache-control") is None
