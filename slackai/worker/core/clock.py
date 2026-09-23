"""KST 시각. 모든 ai_* DATETIME 은 KST 문자열로 쓰고, DB 세션도 time_zone=+09:00 이라 NOW() 와 일치한다."""

from datetime import datetime, timedelta, timezone

KST = timezone(timedelta(hours=9)) # 서머타임이 없어 고정 오프셋으로 충분하다(tzdata 불필요)


def now_kst() -> datetime:
    return datetime.now(KST)


def now_str(dt: datetime | None = None) -> str:
    """MySQL DATETIME 문자열 'YYYY-MM-DD HH:MM:SS' (KST)."""
    return (dt or now_kst()).strftime("%Y-%m-%d %H:%M:%S")


def today_str() -> str:
    return now_kst().strftime("%Y-%m-%d")


def stamp() -> str:
    """파일명용 타임스탬프."""
    return now_kst().strftime("%Y%m%d_%H%M%S")
