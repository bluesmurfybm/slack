import logging
import traceback
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import datetime

from core.config import Settings
from core.http import Http
from core.items import Item
from core.snapshot import State

logger = logging.getLogger(__name__)


@dataclass
class Result:
    source: str
    items: list[Item] = field(default_factory=list)
    stats: dict = field(default_factory=dict)
    status: str = "ok" # ok · skipped · failed
    note: str = ""

    def to_dict(self) -> dict:
        return {"source": self.source, "status": self.status, "note": self.note,
                "stats": self.stats, "count": len(self.items)}


@dataclass
class Context:
    settings: Settings
    http: Http
    state: State
    since: datetime
    until: datetime


Collector = Callable[[Context], Result]


def run_safely(source: str, fn: Collector, ctx: Context) -> Result:
    """수집기 하나가 죽어도 나머지는 계속 간다 — 실패는 결과에 남겨 화면과 슬랙에 보인다."""
    try:
        return fn(ctx)
    except Exception as e:
        logger.exception("[%s] 수집 실패", source)
        return Result(source, status="failed",
                      note=f"{type(e).__name__}: {e}\n{traceback.format_exc()[-1500:]}")
