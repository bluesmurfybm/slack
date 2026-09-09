import logging

import requests

from core.config import Settings

logger = logging.getLogger(__name__)


class Http:
    """수집기 공용 HTTP. 세션 하나, User-Agent 고정, 재시도 없음.

    주 1회 배치라 실패는 그대로 보고한다.
    """

    def __init__(self, settings: Settings):
        self.timeout = settings.http_timeout
        self.session = requests.Session()
        self.session.headers["User-Agent"] = settings.user_agent

    def get_json(self, url: str, params: dict | None = None, headers: dict | None = None):
        r = self.session.get(url, params=params, headers=headers, timeout=self.timeout)
        r.raise_for_status()
        return r.json()

    def get_text(self, url: str, params: dict | None = None) -> str:
        r = self.session.get(url, params=params, timeout=self.timeout)
        r.raise_for_status()
        r.encoding = r.encoding or "utf-8"
        return r.text

    def get_bytes(self, url: str, params: dict | None = None) -> bytes:
        r = self.session.get(url, params=params, timeout=self.timeout)
        r.raise_for_status()
        return r.content
