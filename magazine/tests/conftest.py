import dataclasses

import pytest
from fastapi.testclient import TestClient

from app import create_app
from core.config import Settings
from features.identity.auth import make_cookie

ADMIN = "jian@bluesoft.co.kr"
USER = "siyu@bluesoft.co.kr"
OTHER = "hjlee@bluesoft.co.kr"


def make_settings(tmp_path, dev_login=False, **over):
    secret = tmp_path / "sso_secret.key"
    secret.write_text("test-secret-0123456789")
    return dataclasses.replace(
        Settings.from_env(),
        db_path=str(tmp_path / "test.db"),
        upload_dir=str(tmp_path / "uploads"),
        sso_secret_path=str(secret),
        admin_emails=frozenset({ADMIN}),
        dev_login=dev_login,
        slack_webhook=None,
        **over,
    )


@pytest.fixture()
def settings(tmp_path):
    return make_settings(tmp_path)


@pytest.fixture()
def client(settings):
    return TestClient(create_app(settings))


@pytest.fixture()
def dev_client(tmp_path):
    return TestClient(create_app(make_settings(tmp_path, dev_login=True)))


def login(client, settings, email, name="테스터"):
    client.cookies.set("blueiwork_id", make_cookie(settings, email, name))
