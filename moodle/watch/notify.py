import json
import logging
import shutil
import subprocess
import tempfile
from pathlib import Path

import requests

from core.config import PORTAL_ROOT, Settings

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
    lines = [f"{icon} *MoodleUp? 주간 리포트 {week}* — {status}"]
    if report.get("headline"):
        lines.append(f"> {report['headline']}")
    lines.append("• 수집: " + ", ".join(f"{k} {v}건" for k, v in counts.items()))
    lines.extend(f"• :small_red_triangle: {p}" for p in problems)
    lines.append(f"• 보기: {settings.portal_url.rstrip('/')}/moodle/?week={week}")
    send(settings, "\n".join(lines))


def push_report(settings: Settings, report: dict) -> None:
    """새 주차가 처음 저장됐을 때만 포털 알림함·웹 푸시(core/push.php, moodle.report)로 알린다."""
    if not settings.web_push or not report.get("id") or report.get("run_no") != 1:
        return
    exe = shutil.which(settings.php_bin)
    if not exe:
        logger.warning("웹 푸시 건너뜀: php 없음")
        return
    week = report["week"]
    data = {"kind": "moodle.report", "title": f"MoodleUp? {week} 주간 요약",
            "body": (report.get("headline") or "")[:200], "url": f"moodle/?week={week}",
            "tag": f"moodle-{week}"}
    try:
        with tempfile.TemporaryDirectory() as d:
            f = Path(d) / "push.json"
            f.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
            subprocess.run( # noqa: S603 인자는 우리가 정한 상수와 저장소 안의 파일 경로다
                [exe, str(PORTAL_ROOT / "dev" / "push_cli.php"), "--json-file", str(f)],
                capture_output=True, check=False, cwd=str(PORTAL_ROOT), timeout=120)
    except (OSError, subprocess.SubprocessError):
        logger.warning("웹 푸시 실패", exc_info=True)
