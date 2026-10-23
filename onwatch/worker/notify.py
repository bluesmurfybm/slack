"""알림. 두 갈래다.

- 포털: dev/push_cli.php --json-file 을 php CLI 로 불러 core/push.php::push_send() 에 맡긴다(slackai 워커와
  같은 길 — 한글이 Windows 코드페이지에서 깨지지 않게 파일로 넘긴다). 그 한 번으로
  알림함(portal_notification)·포털 종 숫자·OnWatch 카드 배지·기기 웹 푸시가 모두 처리된다.
  받는 사람은 그 감시를 받는(구독한) 사람 전원이다.
- 슬랙: 웹훅으로 채널에 한 번. 감시 전용 웹훅이 있으면 그것, 없으면 ONWATCH_SLACK_WEBHOOK_URL.

알림은 곁가지다 — 실패해도 예외를 던지지 않는다. 결과는 로그에 남기고 실행은 성공으로 둔다.
"""

import json
import logging
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

import requests

from core.config import PORTAL_ROOT, Settings

logger = logging.getLogger("onwatch.notify")

IMP_LABEL = {"high": "높음", "medium": "보통", "low": "낮음"}
IMP_ICON = {"high": ":red_circle:", "medium": ":large_orange_circle:", "low": ":large_green_circle:"}
CHANGE_LABEL = {"new": "새로 나옴", "updated": "바뀜", "retracted": "회수·철회", "defect": "결함", "other": "기타"}
CONF_LABEL = {"confirmed": "원문 확인", "reported": "신뢰할 만한 보도", "unverified": "미확인"}


def watch_url(watch_id: int) -> str:
    """포털 루트 기준 주소. onwatch/inc/repo.php::ow_watch_url() 과 같아야 읽음 처리가 맞물린다."""
    return f"onwatch/view.php?id={watch_id}"


class Notifier:
    def __init__(self, settings: Settings, *, php_runner=None, http_post=None):
        self.settings = settings
        self._php = php_runner or self._run_php
        self._post = http_post or requests.post
        self._slack_on: bool | None = None
        self._slack_at = 0.0

    # ------------------------------------------------------------ 바탕
    def _run_php(self, args: list[str], stdin: str = "") -> str:
        """args[0] 은 포털 루트 기준 스크립트 경로."""
        exe = shutil.which(self.settings.php_bin)
        if not exe:
            raise RuntimeError(f"php CLI({self.settings.php_bin}) 를 찾을 수 없습니다")
        out = subprocess.run( # noqa: S603 실행 파일은 설정값, 스크립트는 저장소 안의 고정 경로
            [exe, str(PORTAL_ROOT / args[0]), *args[1:]], input=stdin, capture_output=True, text=True,
            encoding="utf-8", check=False, timeout=60, cwd=str(PORTAL_ROOT))
        if out.returncode != 0:
            raise RuntimeError(f"{args[0]} 실패({out.returncode}): {out.stderr[-300:]}")
        return out.stdout

    def portal(self, *, kind: str, title: str, body: str, url: str, to: list[int], tag: str = "") -> dict: # noqa: PLR0913 push_send 의 칸 그대로
        if not to: # 빈 목록을 넘기면 push_send 가 '켜 둔 전원' 으로 읽을 수 있다
            return {"notified": 0}
        payload = {"kind": kind, "title": title, "body": body, "url": url, "to": to, "tag": tag}
        path = None
        try:
            with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as fp:
                json.dump(payload, fp, ensure_ascii=False)
                path = fp.name
            out = self._php(["dev/push_cli.php", "--json-file", path])
            return json.loads(out.strip().splitlines()[-1]) if out.strip() else {}
        except Exception: # 알림 실패로 실행을 실패시키지 않는다
            logger.exception("포털 알림 실패")
            return {"error": "포털 알림 실패"}
        finally:
            if path:
                Path(path).unlink(missing_ok=True)

    def slack_enabled(self) -> bool:
        """관리자의 슬랙 전체 스위치(onwatch/inc/settings.php 의 slack_enabled).
        1분 동안 기억한다 — --serve 로 오래 떠 있어도 설정을 바꾸면 곧 따른다.
        읽지 못하면 끈 것으로 본다. 끄라고 해 둔 것을 모르고 보내는 쪽이 더 나쁘다."""
        now = time.monotonic()
        if self._slack_on is None or now - self._slack_at > 60:
            try:
                self._slack_on = bool(json.loads(self._php(["onwatch/bin/settings.php"]))["slack_enabled"])
            except Exception:
                logger.exception("슬랙 스위치를 읽지 못해 이번에는 슬랙을 보내지 않는다")
                self._slack_on = False
            self._slack_at = now
        return self._slack_on

    def webhook_for(self, w: dict) -> str | None:
        if not w.get("notify_slack") or not self.slack_enabled():
            return None
        if w.get("slack_webhook_enc"):
            try:
                url = self._php(["onwatch/bin/secret.php"], str(w["slack_webhook_enc"])).strip()
                if url:
                    return url
            except Exception:
                logger.exception("전용 웹훅 복호화 실패 — 기본 채널로 보낸다")
        return self.settings.slack_webhook

    def slack(self, webhook: str | None, text: str) -> bool:
        if not webhook:
            return False
        try:
            r = self._post(webhook, json={"text": text, "unfurl_links": False}, timeout=10)
        except requests.RequestException:
            logger.warning("슬랙 알림 전송 실패", exc_info=True)
            return False
        ok = 200 <= r.status_code < 300
        if not ok:
            logger.warning("슬랙 응답 %s: %s", r.status_code, r.text[:200])
        return ok

    def link(self, watch_id: int) -> str:
        return self.settings.portal_url.rstrip("/") + "/" + watch_url(watch_id)

    # ------------------------------------------------------------ 소식
    def findings(self, w: dict, findings: list[dict], summary: str, to: list[int]) -> bool:
        """새 소식(또는 '매번 알림' 감시의 변화 없음) 알림. 하나라도 보냈으면 True."""
        n = len(findings)
        if n:
            title = f"📡 {w['title']} — 새 소식 {n}건"
            lines = [f"[{IMP_LABEL.get(f['importance'], '')}] {f['title']}" for f in findings[:5]]
            if n > 5:
                lines.append(f"외 {n - 5}건")
            body = "\n".join(lines)
        else:
            title = f"📡 {w['title']} — 변화 없음"
            body = summary or "이번 실행에서 알릴 만한 변화를 찾지 못했습니다."
        r = self.portal(kind="onwatch.finding", title=title, body=body, url=watch_url(w["id"]), to=to,
                        tag=f"onwatch-{w['id']}")
        sent = bool(r.get("notified"))
        hook = self.webhook_for(w)
        if hook:
            sent = self.slack(hook, self.slack_text(w, findings, summary)) or sent
        return sent

    def slack_text(self, w: dict, findings: list[dict], summary: str) -> str:
        head = f"*:satellite_antenna: OnWatch · {w['title']}*"
        if not findings:
            return f"{head} — 변화 없음\n> {summary}\n<{self.link(w['id'])}|자세히 보기>"
        lines = [f"{head} — 새 소식 {len(findings)}건"]
        if summary:
            lines.append(f"> {summary}")
        for f in findings[:8]:
            src = f["sources"][0]["url"] if f["sources"] else ""
            meta = f"{CHANGE_LABEL.get(f['change_type'], '')} · {CONF_LABEL.get(f['confidence'], '')}"
            if f.get("published"):
                meta += f" · {f['published']}"
            lines.append(f"{IMP_ICON.get(f['importance'], '•')} *{_esc(f['title'])}*  _{meta}_"
                         + (f"  <{src}|원문>" if src else ""))
        if len(findings) > 8:
            lines.append(f"…외 {len(findings) - 8}건")
        lines.append(f"<{self.link(w['id'])}|OnWatch 에서 자세히 보기>")
        return "\n".join(lines)

    # ------------------------------------------------------------ 감시 상태
    def system(self, w: dict, title: str, body: str, to: list[int]) -> None:
        """종료 임박·종료·연속 실패·예산 초과. 등록한 사람에게만, 슬랙에는 보내지 않는다(채널이 시끄러워진다)."""
        self.portal(kind="onwatch.system", title=f"📡 {w['title']} — {title}", body=body,
                    url=watch_url(w["id"]), to=to, tag=f"onwatch-sys-{w['id']}")


def _esc(s: str) -> str:
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
