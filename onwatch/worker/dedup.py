"""판정 결과 정리와 중복 거르기.

같은 소식인지는 fingerprint = sha1(대상 키 | 변화 종류 | 구별 표식) 로 본다. 모델이 '이미 알린
목록' 을 놓쳐도 같은 키·표식을 다시 내면 여기서 막힌다. 표식이 비면 대표 원문 주소로 대신한다 —
아무것도 없이 키만으로 보면 같은 대상의 다음 변화까지 막힌다.
"""

import hashlib
import re

CHANGE = {"new", "updated", "retracted", "defect", "other"}
IMPORTANCE = {"high": 3, "medium": 2, "low": 1}
CONFIDENCE = {"confirmed", "reported", "unverified"}


def norm_key(s: str) -> str:
    s = re.sub(r"\s+", "-", (s or "").strip().lower())
    return s[:300] or "unknown"


def norm_url(u: str) -> str:
    u = (u or "").strip()
    u = u.split("#", 1)[0]
    u = re.sub(r"^http://", "https://", u, flags=re.IGNORECASE)
    u = re.sub(r"^(https://)www\.", r"\1", u, flags=re.IGNORECASE)
    return u.rstrip("/").lower()


def fingerprint(key: str, change_type: str, marker: str, first_url: str = "") -> str:
    mark = (marker or "").strip().lower() or norm_url(first_url)
    raw = f"{norm_key(key)}|{change_type}|{mark}"
    return hashlib.sha1(raw.encode("utf-8")).hexdigest() # noqa: S324 보안용이 아니라 같은지 보는 키


def clean(raw: dict, seen_urls: set[str]) -> dict | None:
    """판정 결과 한 건 → 저장할 모양. 제목이 없으면 버린다."""
    title = str(raw.get("title") or "").strip()
    if not title:
        return None
    sources = []
    seen_norm = {norm_url(u) for u in seen_urls}
    for s in raw.get("sources") or []:
        url = str((s or {}).get("url") or "").strip()
        if not re.match(r"^https?://", url, re.IGNORECASE):
            continue
        sources.append({"url": url[:700], "title": str(s.get("title") or "")[:300],
                        "published": str(s.get("published") or "")[:40],
                        "seen": norm_url(url) in seen_norm})
    change = raw.get("change_type") if raw.get("change_type") in CHANGE else "other"
    imp = raw.get("importance") if raw.get("importance") in IMPORTANCE else "medium"
    conf = raw.get("confidence") if raw.get("confidence") in CONFIDENCE else "unverified"
    # 연 적 없는 주소뿐인 '확인됨' 은 믿지 않는다
    if conf == "confirmed" and sources and not any(s["seen"] for s in sources):
        conf = "reported"
    key = norm_key(str(raw.get("subject_key") or title))
    marker = str(raw.get("marker") or "").strip()[:200]
    return {
        "subject_key": key,
        "marker": marker,
        "title": title[:300],
        "change_type": change,
        "importance": imp,
        "confidence": conf,
        "detail_md": str(raw.get("detail_md") or "").strip(),
        "verification": str(raw.get("verification") or "").strip(),
        "published": str(raw.get("published") or "").strip()[:40],
        "sources": sources[:12],
        "fingerprint": fingerprint(key, change, marker, sources[0]["url"] if sources else ""),
    }


def split(findings: list[dict], known: set[str]) -> tuple[list[dict], int]:
    """(새 것, 버린 수). 이미 알린 것과 같은 실행 안의 겹침을 함께 거른다."""
    fresh, seen, dup = [], set(known), 0
    for f in findings:
        if f["fingerprint"] in seen:
            dup += 1
            continue
        seen.add(f["fingerprint"])
        fresh.append(f)
    return fresh, dup


def meets(importance: str, minimum: str) -> bool:
    return IMPORTANCE.get(importance, 2) >= IMPORTANCE.get(minimum, 1)
