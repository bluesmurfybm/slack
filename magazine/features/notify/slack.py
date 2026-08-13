from core.config import Settings
from core.db import Topic


def new_topic(settings: Settings, topic: Topic) -> None:
    if not settings.slack_webhook:
        return
    need = "필수" if topic.requirement == "required" else "권장"
    text = "\n".join([
        ":newspaper: *새 DTI 주제*",
        f"• 제목: {topic.title}",
        f"• 분야: {topic.field or '-'} / 발표 {need}",
        f"• 출처: {topic.magazine or '-'} {topic.volume or ''} p.{topic.page or '-'}",
    ])
    try:
        import requests
        requests.post(settings.slack_webhook, json={"text": text}, timeout=3)
    except Exception:
        pass
