import json
from datetime import UTC, datetime, timedelta, timezone
from pathlib import Path

from core.config import Settings

KST = timezone(timedelta(hours=9)) # 서머타임이 없어 고정 오프셋으로 충분하다(tzdata 불필요)


def week_key(dt: datetime) -> str:
    """ISO 주차. 배치는 KST 월요일 새벽에 도는데 그 시각의 UTC 는 아직 전주 일요일이라, UTC 기준으로
    계산하면 방금 막 시작된 새 주차를 전주로 잘못 라벨링하게 된다 — KST 로 변환해서 본다.
    """
    y, w, _ = dt.astimezone(KST).isocalendar()
    return f"{y}-W{w:02d}"


class State:
    """이전 실행의 기준점. 다음 주 diff 를 위해 마지막 실행 시각과 페이지 해시 등을 남긴다."""

    def __init__(self, settings: Settings):
        self.dir = settings.data_path
        self.path = self.dir / "state.json"
        self.snap_dir = self.dir / "snapshots"
        self.settings = settings
        self.data = self._load()

    def _load(self) -> dict:
        if self.path.is_file():
            return json.loads(self.path.read_text(encoding="utf-8"))
        return {"last_run": None, "page_hashes": {}, "branches": [], "tags": [],
                "discussions": {}}

    def save(self) -> None:
        self.dir.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps(self.data, ensure_ascii=False, indent=1), encoding="utf-8")
        tmp.replace(self.path)

    def period(self, now: datetime, since: datetime | None = None) -> tuple[datetime, datetime]:
        """수집 구간. 마지막 실행 이후이되, 너무 오래 쉬었으면 max_lookback 까지만 본다."""
        if since is None:
            last = self.data.get("last_run")
            since = (datetime.fromisoformat(last) if last
                     else now - timedelta(days=self.settings.lookback_days))
        floor = now - timedelta(days=self.settings.max_lookback_days)
        since = max(since, floor)
        if since >= now:
            since = now - timedelta(days=self.settings.lookback_days)
        return since.astimezone(UTC), now.astimezone(UTC)

    def mark_run(self, now: datetime) -> None:
        self.data["last_run"] = now.astimezone(UTC).isoformat(timespec="seconds")

    def write_snapshot(self, week: str, payload: dict) -> Path:
        self.snap_dir.mkdir(parents=True, exist_ok=True)
        p = self.snap_dir / f"{week}.json"
        p.write_text(json.dumps(payload, ensure_ascii=False, indent=1), encoding="utf-8")
        return p
