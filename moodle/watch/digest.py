"""수집 결과를 요약 모델에 넘길 한 덩어리 텍스트로 만든다.

트래커는 주당 수백 건이라 주목(is_focus) 항목만 넘기고 나머지는 통계로 대신한다.
같은 주차를 다시 돌릴 때는 이전 실행에 없던 항목에 [NEW] 를 붙여 '이번 갱신에서 추가된 것'을
따로 쓰게 한다.
"""

import json

from collectors.base import Result
from core.items import SOURCES, Item, clip

CAPS = {"moodleorg": 60, "tracker": 80, "github": 30, "devdocs": 12, "moodlecom": 20}
TOTAL_CHARS = 140_000


def _line(it: Item, excerpt_chars: int, *, new: bool) -> str:
    when = it.published_at[:10] if it.published_at else ""
    head = f"- {'[NEW] ' if new else ''}[{it.kind}] {it.title} ({when}) <{it.url}>"
    if it.is_focus:
        head += " ★"
    body = clip(it.excerpt, excerpt_chars).replace("\n", "\n    ")
    return head + (f"\n    {body}" if body else "")


def build_update(results: list[Result], new_items: list[Item], since: str, until: str,
                 run_no: int) -> str:
    """갱신 실행용. 이전 실행 이후 새로 들어온 항목만 [NEW] 로 나열하고 통계는 참고로 붙인다."""
    out = [f"수집 구간: {since[:10]} ~ {until[:10]} (UTC) — {run_no}회차 갱신",
           f"이전 실행 이후 새로 들어온 항목 {len(new_items)}건. 아래 [NEW] 항목만 요약한다.", ""]
    by_src: dict[str, list[Item]] = {}
    for it in new_items:
        by_src.setdefault(it.source, []).append(it)
    for r in results:
        label = SOURCES.get(r.source, r.source)
        if r.source == "moodleorg":
            label += " (핵심 소스 — 요약의 중심)"
        rows = by_src.get(r.source, [])
        out.append(f"## {label} — {r.status}, 새 항목 {len(rows)}건"
                   + (f" ({r.note.splitlines()[0]})" if r.note else ""))
        if r.source == "tracker" and r.stats:
            out.append("통계(참고): " + json.dumps(r.stats, ensure_ascii=False))
            rows = [i for i in rows if i.is_focus]
            out.append(f"주목 이슈 {len(rows)}건만 나열")
        rows = sorted(rows, key=lambda i: (not i.is_focus, i.published_at))
        excerpt_chars = 3000 if r.source in ("devdocs", "github", "moodleorg") else 500
        out.extend(_line(it, excerpt_chars, new=True) for it in rows[:CAPS.get(r.source, 30)])
        out.append("")
    return clip("\n".join(out), TOTAL_CHARS)


def build(results: list[Result], since: str, until: str,
          known_urls: set[str] | None = None, run_no: int = 1) -> str:
    out = [f"수집 구간: {since[:10]} ~ {until[:10]} (UTC)"]
    if run_no > 1:
        out.append(f"이 주차의 {run_no}회차 갱신이다. [NEW] 가 붙은 항목이 이전 실행 이후"
                   " 새로 들어온 것이고, 나머지는 이미 요약했던 항목이다.")
    out.append("")
    known = known_urls or set()
    for r in results:
        label = SOURCES.get(r.source, r.source)
        if r.source == "moodleorg":
            label += " (핵심 소스 — 요약의 중심)"
        out.append(f"## {label} — {r.status}" + (f" ({r.note.splitlines()[0]})" if r.note else ""))
        if r.stats:
            out.append("통계: " + json.dumps(r.stats, ensure_ascii=False))
        items = r.items
        if r.source == "tracker":
            items = [i for i in items if i.is_focus]
            out.append(f"주목 이슈 {len(items)}건만 나열 (전체 {len(r.items)}건은 통계 참고)")
        items = sorted(items, key=lambda i: (not i.is_focus, i.published_at), reverse=False)
        cap = CAPS.get(r.source, 30)
        excerpt_chars = 3000 if r.source in ("devdocs", "github", "moodleorg") else 500
        out.extend(_line(it, excerpt_chars, new=run_no > 1 and it.url not in known)
                   for it in items[:cap])
        if len(items) > cap:
            out.append(f"... 외 {len(items) - cap}건 생략")
        out.append("")
    text = "\n".join(out)
    return clip(text, TOTAL_CHARS)
