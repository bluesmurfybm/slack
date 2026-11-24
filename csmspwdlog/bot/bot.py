"""csmspwdlog 슬랙 봇 — Socket Mode 로 지정 채널의 글을 받아 handle.php 에 넘긴다.

    python bot.py              상주 실행
    python bot.py --selftest   설정·토큰·php 점검 후 종료

받기만 한다. 대학 찾기·복호화·DM·기록은 전부 handle.php(PHP) 가 한다.
iWorks 서버는 밖에서 들어올 수 없어서, 여기서 Slack 으로 나가는 웹소켓(Socket Mode)만 쓴다.
한 곳에서만 띄운다 — 두 곳에서 띄우면 이벤트가 둘로 갈린다.
로그에는 메시지 본문을 찍지 않는다.
"""

import json
import logging
import os
import shutil
import signal
import subprocess
import sys
import threading
from logging.handlers import RotatingFileHandler
from pathlib import Path

BOT_DIR = Path(__file__).resolve().parent
REPO_ROOT = BOT_DIR.parent.parent
HANDLE = BOT_DIR / "handle.php"
VAR = BOT_DIR / "var"

log = logging.getLogger("csmspwdlog")


def load_env() -> dict:
    """bot/.env 를 읽는다. 같은 이름의 환경변수가 있으면 그쪽이 우선."""
    cfg = {}
    f = BOT_DIR / ".env"
    if f.is_file():
        for line in f.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            cfg[k.strip()] = v.strip().strip('"').strip("'")
    for k in ("SLACK_APP_TOKEN", "SLACK_BOT_TOKEN", "CSMSPWDLOG_CHANNELS", "PHP_BIN"):
        if os.environ.get(k):
            cfg[k] = os.environ[k]
    return cfg


def resolve_php(php_bin: str) -> str | None:
    p = Path(php_bin)
    if p.suffix and p.is_file():
        return str(p)
    return shutil.which(php_bin)


def setup_logging() -> None:
    VAR.mkdir(exist_ok=True)
    fmt = logging.Formatter("%(asctime)s %(levelname)s %(message)s")
    fh = RotatingFileHandler(VAR / "bot.log", maxBytes=1_000_000, backupCount=3, encoding="utf-8")
    fh.setFormatter(fmt)
    sh = logging.StreamHandler()
    sh.setFormatter(fmt)
    logging.basicConfig(level=logging.INFO, handlers=[fh, sh])


def run_handle(php: str, bot_token: str, event: dict, dry_run: bool = False) -> dict:
    payload = json.dumps({"channel": event.get("channel"), "ts": event.get("ts"), "user": event.get("user"),
                          "text": event.get("text") or ""}, ensure_ascii=False).encode("utf-8")
    env = dict(os.environ)
    env["SLACK_BOT_TOKEN"] = bot_token
    argv = [php, str(HANDLE)] + (["--dry-run"] if dry_run else [])
    try:
        c = subprocess.run(argv, input=payload, capture_output=True, timeout=60, cwd=str(REPO_ROOT), env=env,
                           creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "php timeout"}
    except OSError as e:
        return {"ok": False, "error": f"php 실행 실패: {e}"}
    out = c.stdout.decode("utf-8", "replace").strip()
    for line in reversed(out.splitlines()):   # PHP 경고가 앞에 붙을 수 있다
        s = line.strip()
        if s.startswith("{"):
            try:
                return json.loads(s)
            except ValueError:
                continue
    return {"ok": False, "error": f"php rc={c.returncode}"}


def selftest(cfg: dict) -> int:
    ok = True

    def check(label: str, passed: bool, note: str = "") -> None:
        nonlocal ok
        ok = ok and passed
        print(("[ OK ] " if passed else "[FAIL] ") + label + (f" — {note}" if note else ""))

    app_tok, bot_tok = cfg.get("SLACK_APP_TOKEN", ""), cfg.get("SLACK_BOT_TOKEN", "")
    channels = [c for c in cfg.get("CSMSPWDLOG_CHANNELS", "").replace(" ", "").split(",") if c]
    check("SLACK_APP_TOKEN (xapp-)", app_tok.startswith("xapp-"))
    check("SLACK_BOT_TOKEN (xoxb-)", bot_tok.startswith("xoxb-"))
    check("CSMSPWDLOG_CHANNELS", bool(channels), ",".join(channels))
    php = resolve_php(cfg.get("PHP_BIN", "php"))
    check("php 실행 파일", bool(php), php or cfg.get("PHP_BIN", "php"))
    try:
        from slack_sdk import WebClient
        import slack_bolt  # noqa: F401
        check("slack_bolt / slack_sdk", True)
    except ImportError as e:
        check("slack_bolt / slack_sdk", False, f"{e} → pip install -r requirements.txt")
        return 1
    if bot_tok:
        try:
            r = WebClient(token=bot_tok).auth_test()
            check("slack.com 연결 · 봇 토큰(auth.test)", True, f"{r.get('team')} / {r.get('user')}")
        except Exception as e:
            check("slack.com 연결 · 봇 토큰(auth.test)", False, str(e)[:200])
    if php:
        r = run_handle(php, bot_tok, {"channel": "C0SELFTEST", "ts": "0.0", "user": "U0SELFTEST",
                                      "text": "selftest.invalid"}, dry_run=True)
        check("handle.php · DB 연결", bool(r.get("ok")), "" if r.get("ok") else str(r.get("error"))[:200])
    return 0 if ok else 1


def serve(cfg: dict) -> int:
    app_tok, bot_tok = cfg.get("SLACK_APP_TOKEN", ""), cfg.get("SLACK_BOT_TOKEN", "")
    channels = {c for c in cfg.get("CSMSPWDLOG_CHANNELS", "").replace(" ", "").split(",") if c}
    php = resolve_php(cfg.get("PHP_BIN", "php"))
    if not (app_tok and bot_tok and channels and php):
        log.error("설정이 모자란다(SLACK_APP_TOKEN/SLACK_BOT_TOKEN/CSMSPWDLOG_CHANNELS/PHP_BIN). --selftest 로 확인")
        return 2

    from slack_bolt import App
    from slack_bolt.adapter.socket_mode import SocketModeHandler

    stop = threading.Event()
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_a: stop.set())

    app = App(token=bot_tok, token_verification_enabled=False, logger=log)

    @app.event("message")
    def on_message(event):
        if event.get("channel") not in channels or event.get("subtype") or event.get("bot_id"):
            return
        if not event.get("user") or "." not in (event.get("text") or ""):
            return
        r = run_handle(php, bot_tok, event)
        if r.get("ok"):
            for x in r.get("results") or []:
                log.info("channel=%s ts=%s host=%s → %s", event.get("channel"), event.get("ts"), x.get("host"), x.get("result"))
        else:
            log.error("channel=%s ts=%s 처리 실패: %s", event.get("channel"), event.get("ts"), r.get("error"))

    backoff = 5
    while not stop.is_set():
        handler = None
        try:
            handler = SocketModeHandler(app, app_tok, logger=log)
            handler.connect()
            log.info("Socket Mode 연결됨 (채널 %s)", ",".join(sorted(channels)))
            backoff = 5
            while not stop.is_set():
                stop.wait(30)
                if not stop.is_set() and not handler.client.is_connected():
                    log.warning("Socket Mode 끊김 → SDK 재접속 대기")
        except Exception as e:
            log.error("Socket Mode 오류: %s (%d초 후 재시도)", e, backoff)
            stop.wait(backoff)
            backoff = min(300, backoff * 2)
        finally:
            if handler is not None:
                try:
                    handler.close()
                except Exception:
                    pass
    log.info("종료")
    return 0


def main() -> int:
    cfg = load_env()
    if "--selftest" in sys.argv:
        return selftest(cfg)
    setup_logging()
    return serve(cfg)


if __name__ == "__main__":
    sys.exit(main())
