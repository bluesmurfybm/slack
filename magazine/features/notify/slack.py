from typing import Optional

from core.config import Settings


def _webhook(settings: Settings) -> Optional[str]:
    if settings.slack_webhook:
        return settings.slack_webhook
    try:
        from config_local import SLACK_WEBHOOK_URL
        return SLACK_WEBHOOK_URL
    except ImportError:
        return None


def new_topic(settings: Settings, row) -> None:
    # 선점을 유도하는 용도라 등록 시에만 보낸다. 웹훅이 없으면 아무것도 안 한다.
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
