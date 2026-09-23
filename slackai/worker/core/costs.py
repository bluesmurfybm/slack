"""LLM 비용 단가표(USD / 1M 토큰)와 일일 합계. LLM_PRICING_JSON 으로 덧씌울 수 있다."""

import json
import logging

logger = logging.getLogger(__name__)

# Anthropic 1st-party 단가(2026-06 기준). {"in": 입력, "out": 출력} USD/Mtok
PRICING: dict[str, dict[str, float]] = {
    "claude-haiku-4-5": {"in": 1.00, "out": 5.00},
    "claude-sonnet-5": {"in": 2.00, "out": 10.00},
    "claude-sonnet-4-6": {"in": 3.00, "out": 15.00},
    "claude-opus-5": {"in": 5.00, "out": 25.00},
    "claude-opus-4-8": {"in": 5.00, "out": 25.00},
    "claude-opus-4-7": {"in": 5.00, "out": 25.00},
    "claude-opus-4-6": {"in": 5.00, "out": 25.00},
    "claude-fable-5-1": {"in": 10.00, "out": 50.00},
    "claude-fable-5": {"in": 10.00, "out": 50.00},
    # OpenAI (대략치 — 검토 폴백용)
    "gpt-5": {"in": 1.25, "out": 10.00},
    "gpt-5-mini": {"in": 0.25, "out": 2.00},
}
_overlay_loaded = False


def load_overlay(pricing_json: str | None) -> None:
    global _overlay_loaded
    if _overlay_loaded or not pricing_json:
        return
    try:
        extra = json.loads(pricing_json)
        for k, v in extra.items():
            if isinstance(v, dict) and "in" in v and "out" in v:
                PRICING[k] = {"in": float(v["in"]), "out": float(v["out"])}
    except Exception:
        logger.warning("LLM_PRICING_JSON 파싱 실패(무시)")
    _overlay_loaded = True


def _lookup(model: str | None) -> dict[str, float] | None:
    if not model:
        return None
    m = model.lower()
    if m in PRICING:
        return PRICING[m]
    # 별칭/날짜 접미어: 가장 긴 접두 일치
    best = None
    for k, v in PRICING.items():
        if m.startswith(k) and (best is None or len(k) > len(best[0])):
            best = (k, v)
    return best[1] if best else None


def estimate(model: str | None, tokens_in: int | None, tokens_out: int | None) -> float:
    """토큰 수 → USD. 단가를 모르는 모델은 0."""
    p = _lookup(model)
    if not p:
        return 0.0
    return round(((tokens_in or 0) * p["in"] + (tokens_out or 0) * p["out"]) / 1_000_000, 6)


DAILY_TOTAL_SQL = ("SELECT COALESCE(SUM(cost_usd), 0) AS total FROM ai_jobs "
                   "WHERE finished_at >= CURDATE()")


def daily_total(db) -> float:
    """오늘(KST, 세션 time_zone 기준) 끝난 잡의 비용 합."""
    v = db.scalar(DAILY_TOTAL_SQL)
    return float(v or 0)
