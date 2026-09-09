from datetime import UTC, datetime

import pytest

from collectors.base import Context
from core.config import Settings
from core.snapshot import State

SINCE = datetime(2026, 9, 1, tzinfo=UTC)
UNTIL = datetime(2026, 9, 8, tzinfo=UTC)


def make_settings(tmp_path, **over) -> Settings:
    return Settings().model_copy(update={
        "data_dir": str(tmp_path / "var"),
        "slack_webhook": None,
        "summarizer": "none",
        "anthropic_api_key": None,
        "moodle_org_token": None,
        "github_token": None,
        **over,
    })


class FakeHttp:
    """URL(+path 파라미터) → 응답. 부른 URL 을 기록해 호출 수도 검증한다."""

    def __init__(self, routes: dict):
        self.routes = routes
        self.calls: list[tuple[str, dict]] = []

    def _find(self, url, params):
        self.calls.append((url, dict(params or {})))
        key_full = url + (("?" + "&".join(f"{k}={v}" for k, v in sorted((params or {}).items())))
                          if params else "")
        for k in (key_full, url):
            if k in self.routes:
                v = self.routes[k]
                return v(params) if callable(v) else v
        for k, v in self.routes.items():
            if url.startswith(k):
                return v(params) if callable(v) else v
        raise AssertionError(f"준비되지 않은 URL: {url} {params}")

    def get_json(self, url, params=None, headers=None):
        return self._find(url, params)

    def get_text(self, url, params=None):
        return self._find(url, params)

    def get_bytes(self, url, params=None):
        v = self._find(url, params)
        return v if isinstance(v, bytes) else v.encode("utf-8")


@pytest.fixture
def settings(tmp_path):
    return make_settings(tmp_path)


def make_ctx(settings, routes, since=SINCE, until=UNTIL) -> Context:
    return Context(settings, FakeHttp(routes), State(settings), since, until)
