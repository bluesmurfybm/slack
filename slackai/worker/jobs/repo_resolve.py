"""문의 → ai_repos 결정적 매핑(LLM 보다 우선).

① requests.lms 호스트 = schools.dev|ops 호스트 → 그 학교의 활성 레포가 1개면 conf 1.0 'lms_url'
② ai_repos.match_rules.lms_patterns(와일드카드/부분 문자열) 일치 → 0.95 'url_pattern'
③ 제목 '[고객사]' 접두 = schools.name 또는 ai_repos.name(부분 일치) / match_rules.title_keywords → 0.9 'title_customer'
school_access 는 여기서 조회하지 않는다(자격증명 포함). school_access.repo 주소는 discover_repos 잡이
ai_repos(school_id·remote_url) 로 옮겨 두므로, 여기서는 ai_repos.school_id 만 보면 된다.
"""

import fnmatch
import json
import re
from dataclasses import dataclass, field
from urllib.parse import urlparse

_TITLE_CUST_RE = re.compile(r"^\s*\[([^\]]{1,40})\]")


@dataclass
class RepoGuess:
    repo_id: int | None = None
    confidence: float = 0.0
    reason: str | None = None
    candidates: list[dict] = field(default_factory=list)

    def to_hint(self) -> dict:
        return {"repo_id": self.repo_id, "confidence": self.confidence, "reason": self.reason,
                "candidates": self.candidates}


def host_of(url: str | None) -> str:
    if not url:
        return ""
    u = url.strip()
    if "://" not in u:
        u = "http://" + u
    try:
        h = (urlparse(u).hostname or "").lower()
    except ValueError:
        return ""
    return h[4:] if h.startswith("www.") else h


def _rules(repo: dict) -> dict:
    v = repo.get("match_rules")
    if not v:
        return {}
    if isinstance(v, dict):
        return v
    try:
        d = json.loads(v)
        return d if isinstance(d, dict) else {}
    except (TypeError, ValueError):
        return {}


def title_customer(title: str | None) -> str:
    m = _TITLE_CUST_RE.match(title or "")
    return m.group(1).strip() if m else ""


def _norm_name(s: str) -> str:
    return re.sub(r"[\s·\-_()（）]", "", (s or "").lower())


def resolve(req: dict, repos: list[dict], schools: list[dict]) -> RepoGuess:
    active = [r for r in repos if int(r.get("active", 1) or 0) == 1]
    if not active:
        return RepoGuess()
    cands: dict[int, dict] = {}

    def add(repo_id: int, score: float, reason: str) -> None:
        cur = cands.get(repo_id)
        if cur is None or score > cur["score"]:
            cands[repo_id] = {"id": repo_id, "score": round(score, 3), "reason": reason}

    lms = req.get("lms") or ""
    host = host_of(lms)
    if host:
        school_ids = {s["id"] for s in schools
                      if host and (host_of(s.get("dev")) == host or host_of(s.get("ops")) == host)}
        matched = [r for r in active if r.get("school_id") in school_ids]
        if len(matched) == 1:
            add(int(matched[0]["id"]), 1.0, "lms_url")
        elif len(matched) > 1:
            for r in matched:
                add(int(r["id"]), 0.9, "lms_url")
    for r in active:
        for pat in _rules(r).get("lms_patterns") or []:
            p = str(pat).lower()
            if not p:
                continue
            target = lms.lower()
            if fnmatch.fnmatch(target, p) or fnmatch.fnmatch(host, p) or p in target:
                add(int(r["id"]), 0.95, "url_pattern")
    cust = title_customer(req.get("title"))
    if cust:
        nc = _norm_name(cust)
        school_ids = {s["id"] for s in schools if nc and (_norm_name(s.get("name")) == nc
                                                          or nc in _norm_name(s.get("name"))
                                                          or _norm_name(s.get("name")) in nc)}
        for r in active:
            if r.get("school_id") in school_ids:
                add(int(r["id"]), 0.9, "title_customer")
            rn = _norm_name(r.get("name"))
            if nc and rn and (nc in rn or rn in nc):
                add(int(r["id"]), 0.9, "title_customer")
    title_l = (req.get("title") or "").lower()
    for r in active:
        for kw in _rules(r).get("title_keywords") or []:
            if kw and str(kw).lower() in title_l:
                add(int(r["id"]), 0.85, "title_customer")

    ordered = sorted(cands.values(), key=lambda c: (-c["score"], c["id"]))
    if not ordered:
        return RepoGuess()
    best = ordered[0]
    # 1위가 유일하게 최고점일 때만 확정
    if len(ordered) == 1 or ordered[1]["score"] < best["score"]:
        return RepoGuess(best["id"], best["score"], best["reason"], ordered)
    return RepoGuess(None, best["score"], None, ordered)
