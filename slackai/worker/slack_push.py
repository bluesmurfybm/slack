"""Slack 이벤트 수신(Socket Mode) — 기본 ON, 별도 스레드.

- @app.function("workhub_item_changed") : Workflow Builder 커스텀 스텝 → ai_jobs.ingest{list_id,item_id,event} enqueue → complete()
- @app.event("message") : 댓글 채널(SLACK_PUSH_COMMENT_CHANNELS) 의 스레드 답글 → ingest{event:'comment', channel}
- 연결 상태를 sync_meta.ai_push_connected('1'/'0') / ai_push_at 에 기록(UI aiDot).
토큰이 없거나 slack_bolt 가 없으면 워커를 죽이지 않고 로그 + 연결 안 됨 표시만 한다.
"""

import logging
import threading
import time

from core import store
from core.db import Db
from core.jobs import enqueue

logger = logging.getLogger("slack_push")


class PushService:
    def __init__(self, settings, db_params: dict):
        self.s = settings
        self.db_params = db_params
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self._handler = None
        self.connected = False
        self.last_error: str | None = None
        self._lock = threading.Lock()

    # ---- DB 는 스레드 전용 커넥션으로 짧게 쓴다
    def _with_db(self, fn):
        db = None
        try:
            db = Db(self.db_params)
            out = fn(db)
            db.commit()
            return out
        except Exception as e:
            logger.warning("push db 작업 실패: %s", e)
            return None
        finally:
            if db:
                db.close()

    def mark(self, connected: bool) -> None:
        self.connected = connected

        def _f(db):
            store.meta_set(db, "ai_push_connected", "1" if connected else "0")
            store.meta_set(db, "ai_push_at", int(time.time()))

        self._with_db(_f)

    def enqueue_ingest(self, request_id: str | None, params: dict, by: str) -> int | None:
        return self._with_db(lambda db: enqueue(db, "ingest", request_id, params, by))

    # ---- 생명주기
    def start(self) -> bool:
        if not self.s.slackai_push:
            logger.info("SLACKAI_PUSH=0 → 이벤트 수신 비활성")
            self.mark(False)
            return False
        if not (self.s.slack_app_token and self.s.slack_bot_token):
            logger.warning("SLACK_APP_TOKEN/SLACK_BOT_TOKEN 없음 → 이벤트 수신 안 함(연결 안 됨 표시). "
                           "수동 🔄/기동 시 증분 동기화만 동작")
            self.last_error = "no_tokens"
            self.mark(False)
            return False
        self._thread = threading.Thread(target=self._run, name="slack-push", daemon=True)
        self._thread.start()
        return True

    def stop(self) -> None:
        self._stop.set()
        h = self._handler
        if h is not None:
            try:
                h.close()
            except Exception:
                pass
        self.mark(False)

    def _run(self) -> None:
        try:
            from slack_bolt import App
            from slack_bolt.adapter.socket_mode import SocketModeHandler
        except ImportError as e:
            self.last_error = f"slack_bolt 없음: {e}"
            logger.error("%s → pip install slack_bolt slack_sdk", self.last_error)
            self.mark(False)
            return
        channels = set(self.s.push_channels)
        try:
            app = App(token=self.s.slack_bot_token, token_verification_enabled=False, logger=logger)
        except Exception as e:
            self.last_error = f"Bolt App 생성 실패: {e}"
            logger.error(self.last_error)
            self.mark(False)
            return

        svc = self

        @app.function("workhub_item_changed")
        def on_item(inputs, complete, fail, logger):
            try:
                item_id = str(inputs.get("item_id") or "").strip()
                list_id = str(inputs.get("list_id") or "").strip()
                event = str(inputs.get("event") or "updated").strip() or "updated"
                if not item_id:
                    fail(error="item_id 가 비어 있다")
                    return
                jid = svc.enqueue_ingest(item_id, {"list_id": list_id, "item_id": item_id, "event": event},
                                         "slack:workflow")
                logger.info("push item %s list=%s event=%s → job %s", item_id, list_id, event, jid)
                complete(outputs={"job_id": str(jid or "dup")})
            except Exception as e:
                logger.exception("function handler 실패")
                try:
                    fail(error=str(e)[:200])
                except Exception:
                    pass

        @app.event("message")
        def on_message(event, logger):
            ch = event.get("channel")
            if ch in channels and event.get("thread_ts") and not event.get("bot_id"):
                jid = svc.enqueue_ingest(None, {"event": "comment", "channel": ch, "thread_ts": event.get("thread_ts")},
                                         "slack:event")
                logger.info("push comment channel=%s → job %s", ch, jid)

        backoff = 5
        while not self._stop.is_set():
            handler = None
            try:
                handler = SocketModeHandler(app, self.s.slack_app_token, logger=logger)
                self._handler = handler
                client = handler.client
                try:
                    client.on_close_listeners.append(lambda *_a, **_k: svc.mark(False))
                except Exception:
                    pass
                handler.connect()
                self.mark(True)
                self.last_error = None
                logger.info("Socket Mode 연결됨 (댓글 채널 %s)", ",".join(sorted(channels)) or "-")
                backoff = 5
                while not self._stop.is_set():
                    self._stop.wait(30)
                    if self._stop.is_set():
                        break
                    try:
                        alive = bool(client.is_connected())
                    except Exception:
                        alive = False
                    if alive != self.connected:
                        self.mark(alive)
                    if not alive:
                        logger.warning("Socket Mode 끊김 → SDK 재접속 대기")
            except Exception as e:
                self.last_error = str(e)
                logger.error("Socket Mode 오류: %s (%.0f초 후 재시도)", e, backoff)
                self.mark(False)
                self._stop.wait(backoff)
                backoff = min(300, backoff * 2)
            finally:
                if handler is not None:
                    try:
                        handler.close()
                    except Exception:
                        pass
        self.mark(False)
