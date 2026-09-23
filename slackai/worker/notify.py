"""알림: Slack 인커밍 웹훅(선택) + 문의 스레드 글(ai_settings.post_to_slack 게이트, 기본 OFF)."""

import json
import logging
import urllib.request

from tools import php_cli

logger = logging.getLogger(__name__)


def webhook(settings, text: str) -> bool:
    url = settings.slack_webhook
    if not url:
        return False
    try:
        data = json.dumps({"text": text}, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
        if not url.startswith("https://"):
            return False
        with urllib.request.urlopen(req, timeout=15) as r:
            return 200 <= r.status < 300
    except Exception as e:
        logger.warning("웹훅 실패: %s", e)
        return False


def thread_post(ctx, request_id: str, text: str) -> dict | None:
    """post_to_slack 이 켜져 있을 때만 문의 스레드에 글을 남긴다."""
    if not ctx.eff.post_to_slack:
        return None
    res = php_cli.post_thread(ctx.runner, ctx.settings, request_id, text, ctx.job_dir)
    if not res.get("ok"):
        ctx.log.warning("스레드 글 실패 %s: %s", request_id, res.get("error"))
    return res
