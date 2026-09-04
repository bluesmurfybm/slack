import re
from pathlib import Path

from conftest import USER, login
from core.config import BASE

INDEX = Path(BASE / "web" / "index.html").read_text(encoding="utf-8")


def test_static_files_must_revalidate(client):
    r = client.get("/static/core.js")
    assert r.status_code == 200
    assert r.headers.get("cache-control") == "no-cache"


def test_styles_must_revalidate(client):
    r = client.get("/styles/style.css")
    assert r.headers.get("cache-control") == "no-cache"


def test_api_responses_are_not_touched(client):
    r = client.get("/learningapi/whoami")
    assert r.headers.get("cache-control") is None


def test_every_script_the_page_loads_exists(client, settings):
    login(client, settings, USER)
    srcs = re.findall(r'<script src="([^"]+)"', INDEX)
    assert srcs
    for src in srcs:
        assert client.get(f"/{src}").status_code == 200, src


def test_every_stylesheet_the_page_loads_exists(client):
    local = [h for h in re.findall(r'<link[^>]+href="([^"]+)"', INDEX)
             if not h.startswith("http")]
    assert local
    for href in local:
        assert client.get(f"/{href}").status_code == 200, href


def test_every_inline_handler_has_a_function():
    # onclick="foo(...)" 로 부르는 함수가 어느 js 에도 없으면 화면에서 조용히 죽는다
    handlers = set(re.findall(r'on\w+="(\w+)\(', INDEX))
    scripts = "\n".join(
        p.read_text(encoding="utf-8") for p in (BASE / "web" / "static").glob("*.js"))
    missing = [h for h in handlers
               if not re.search(rf"\b(function\s+{h}\b|const\s+{h}\s*=|let\s+{h}\s*=)", scripts)]
    assert not missing, missing
