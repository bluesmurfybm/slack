from datetime import UTC, datetime


def utc_now() -> datetime:
    """시간대 정보가 없는 UTC 현재 시각. MySQL DATETIME에 그대로 저장한다."""
    return datetime.now(UTC).replace(tzinfo=None)
