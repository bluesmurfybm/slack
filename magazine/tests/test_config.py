from core.config import Settings


def test_admin_emails_from_env(monkeypatch):
    monkeypatch.setenv("ADMIN_EMAILS", "a@x.com, B@X.com ,")
    assert Settings().admin_emails == frozenset({"a@x.com", "b@x.com"})


def test_admin_emails_default(monkeypatch):
    monkeypatch.delenv("ADMIN_EMAILS", raising=False)
    assert Settings().admin_emails == frozenset({"jian@bluesoft.co.kr"})


def test_dev_login_from_env(monkeypatch):
    monkeypatch.setenv("DEV_LOGIN", "1")
    assert Settings().dev_login is True
    monkeypatch.setenv("DEV_LOGIN", "0")
    assert Settings().dev_login is False


def test_max_upload_bytes_from_mb(monkeypatch):
    monkeypatch.setenv("MAX_UPLOAD_MB", "3")
    assert Settings().max_upload_bytes == 3 * 1024 * 1024


def test_paths_from_env(monkeypatch, tmp_path):
    monkeypatch.setenv("DB_PATH", str(tmp_path / "a.db"))
    monkeypatch.setenv("UPLOAD_DIR", str(tmp_path / "up"))
    monkeypatch.setenv("SSO_SECRET_PATH", str(tmp_path / "k"))
    s = Settings()
    assert s.db_path == str(tmp_path / "a.db")
    assert s.upload_dir == str(tmp_path / "up")
    assert s.sso_secret_path == str(tmp_path / "k")


def test_slack_webhook_from_env(monkeypatch):
    monkeypatch.setenv("SLACK_WEBHOOK_URL", "https://hooks.slack.com/services/x")
    assert Settings().slack_webhook == "https://hooks.slack.com/services/x"


def test_settings_is_frozen(monkeypatch):
    monkeypatch.delenv("ADMIN_EMAILS", raising=False)
    s = Settings()
    try:
        s.dev_login = True
    except Exception:
        return
    raise AssertionError("frozen 이어야 한다")
