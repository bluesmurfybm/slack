# -*- coding: utf-8 -*-
"""알림 도메인: 새 주제가 올라온 것을 슬랙에 알린다.

선점을 유도하는 용도라 등록 시에만 보낸다.
웹훅이 설정되지 않으면 아무것도 하지 않는다.
"""
from typing import Optional

from config import Settings


def _webhook(settings: Settings) -> Optional[str]:
    if settings.slack_webhook:
        return settings.slack_webhook
    try:
        from config_local import SLACK_WEBHOOK_URL
        return SLACK_WEBHOOK_URL
    except ImportError:
        return None


def new_topic(settings: Settings, row) -> None:
    url = _webhook(settings)
    if not url:
        return
    need = "필수" if row["requirement"] == "required" else "권장"
    text = "\n".join([
        ":newspaper: *새 DTI 주제*",
        f"• 제목: {row['title']}",
        f"• 분야: {row['field'] or '-'} / 발표 {need}",
        f"• 출처: {row['magazine'] or '-'} {row['volume'] or ''} p.{row['page'] or '-'}",
    ])
    try:
        import requests
        requests.post(url, json={"text": text}, timeout=3)
    except Exception:
        pass   # 알림 실패가 등록을 막으면 안 된다
