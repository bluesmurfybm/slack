import logging

import requests

from core.config import Settings

logger = logging.getLogger(__name__)


def send(settings: Settings, text: str) -> None:
    if not settings.slack_webhook:
        return
    try:
        requests.post(settings.slack_webhook, json={"text": text}, timeout=5)
    except requests.RequestException:
        logger.warning("슬랙 알림 전송 실패", exc_info=True)


def report_done(settings: Settings, report: dict, counts: dict[str, int],
                problems: list[str]) -> None:
    week, status = report["week"], report["status"]
    icon = {"ok": ":white_check_mark:", "partial": ":warning:"}.get(status, ":x:")
    lines = [f"{icon} *MoodleUp 주간 리포트 {week}* — {status}"]
    if report.get("headline"):
        lines.append(f"> {report['headline']}")
    lines.append("• 수집: " + ", ".join(f"{k} {v}건" for k, v in counts.items()))
    lines.extend(f"• :small_red_triangle: {p}" for p in problems)
    lines.append(f"• 보기: {settings.portal_url.rstrip('/')}/moodle/?week={week}")
    send(settings, "\n".join(lines))
