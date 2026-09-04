import logging

import requests

from core.config import Settings
from core.db import Presentation, Topic

logger = logging.getLogger(__name__)


def new_presenter(settings: Settings, topic: Topic, pres: Presentation) -> None:
    if not settings.slack_webhook:
        return
    text = "\n".join([
        ":studio_microphone: *DTI 발표자 등록*",
        f"• 아티클: {topic.title}",
        f"• 발표자: {pres.presenter}",
        f"• 예정일: {pres.planned_date or '미정'}",
    ])
    try:
        requests.post(settings.slack_webhook, json={"text": text}, timeout=3)
    except requests.RequestException:
        logger.warning("슬랙 알림 전송 실패", exc_info=True)
