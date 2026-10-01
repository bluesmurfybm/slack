# -*- coding: utf-8 -*-
"""기관명 표준화 · 담당자 매핑 · 분야 태깅. collect_slack.py 가 쓰는 변환 계층."""

from __future__ import annotations

import json
import re
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path


# =====================================================================
# 공통
# =====================================================================

def _norm(s: str | None) -> str:
    """비교용 정규화 — 유니코드 정규화 + 소문자 + 공백/구두점 제거.

    '한국기술교육대 ', 'Korea Tech', '한국기술교육대(KUT)' 처럼 흔들리는 표기를
    한 자로 맞춘다. 원본은 건드리지 않고 비교할 때만 쓴다.
    """
    if not s:
        return ""
    s = unicodedata.normalize("NFKC", str(s))
    s = s.lower()
    s = re.sub(r"[\s\-_·.,()\[\]{}/\\'\"]+", "", s)
    return s


def _load_json(path: Path) -> dict:
    with path.open(encoding="utf-8") as f:
        return json.load(f)


# =====================================================================
# 기관명 표준화
# =====================================================================

class OrgNormalizer:
    """기관 표기를 표준 이름 하나로 모은다.

    찾는 순서
      1. schools 표(운영 DB) 의 dev/ops URL 과 requests.lms 링크 대조 — 가장 정확
      2. orgs.json 의 domains 와 lms 링크 대조
      3. orgs.json 의 aliases 와 제목 대조
      4. 못 찾으면 None. 지어내지 않는다.

    4번이 중요하다. 억지로 붙이면 남의 기관 실적이 섞인다.
    """

    def __init__(self, orgs_path: Path, schools_rows: list[dict] | None = None):
        data = _load_json(orgs_path)
        self._by_alias: dict[str, str] = {}
        self._by_domain: dict[str, str] = {}

        for org in data.get("orgs", []):
            canon = org["canonical"]
            self._by_alias[_norm(canon)] = canon
            for a in org.get("aliases", []):
                self._by_alias[_norm(a)] = canon
            for d in org.get("domains", []):
                self._by_domain[d.lower().strip()] = canon

        # schools 표는 운영 DB 에만 데이터가 있다. 없으면 조용히 건너뛴다.
        self._school_hosts: list[tuple[str, str]] = []   # (host조각, 기관명)
        for row in (schools_rows or []):
            name = (row.get("name") or "").strip()
            if not name:
                continue
            self._by_alias.setdefault(_norm(name), name)
            for url_col in ("dev", "ops", "log"):
                host = self._host_of(row.get(url_col))
                if host:
                    self._school_hosts.append((host, name))

    @staticmethod
    def _host_of(url: str | None) -> str:
        if not url:
            return ""
        m = re.search(r"https?://([^/\s:?#]+)", str(url), re.I)
        return m.group(1).lower() if m else ""

    def from_url(self, url: str | None) -> str | None:
        """LMS 링크에서 기관을 찾는다."""
        host = self._host_of(url)
        if not host:
            return None
        # schools 표 우선 — 실제 운영 중인 주소라 가장 믿을 만하다
        for h, name in self._school_hosts:
            if h and (host == h or host.endswith("." + h) or h.endswith("." + host)):
                return name
        for dom, name in self._by_domain.items():
            if host == dom or host.endswith("." + dom):
                return name
        return None

    def from_text(self, text: str | None) -> str | None:
        """제목 등 글자에서 기관을 찾는다.

        긴 별칭부터 본다. '충북대' 와 '충북대학교' 가 둘 다 있을 때
        짧은 쪽이 먼저 걸려 엉뚱한 기관이 잡히는 것을 막는다.
        """
        n = _norm(text)
        if not n:
            return None
        for alias in sorted(self._by_alias, key=len, reverse=True):
            if alias and alias in n:
                return self._by_alias[alias]
        return None

    def resolve(self, lms_url: str | None, title: str | None,
                body: str | None = None) -> str | None:
        """링크 → 제목 → 본문 순. 본문은 다른 기관 얘기가 섞이기 쉬워 마지막이다."""
        return (self.from_url(lms_url)
                or self.from_text(title)
                or self.from_text(body))


# =====================================================================
# 담당자 매핑
# =====================================================================

@dataclass
class MemberResolution:
    member_id: int | None = None
    email: str | None = None
    reason: str = ""          # 왜 그렇게 정했는지 (로그용)


class MemberResolver:
    """담당자 문자열을 ba_member 행으로 잇는다.

    못 찾으면 **건너뛴다**(member_id=None). 비슷한 이름에 억지로 붙이지 않는다.
    엉뚱한 사람에게 실적이 붙으면 역량 점수가 틀리고, 그 점수로 배정이 나간다.
    CLAUDE.md 의 "모든 점수는 근거로 역추적 가능해야 한다" 와도 어긋난다.
    """

    def __init__(self, aliases_path: Path, members: list[dict]):
        data = _load_json(aliases_path)

        self._alias_to_email: dict[str, str] = {}
        self._slack_to_email: dict[str, str] = {}
        for m in data.get("members", []):
            email = m["email"].strip().lower()
            self._alias_to_email[_norm(email)] = email
            self._alias_to_email[_norm(email.split("@")[0])] = email
            for a in m.get("aliases", []):
                self._alias_to_email[_norm(a)] = email
            for sid in m.get("slack_ids", []):
                if sid:
                    self._slack_to_email[sid.strip()] = email

        # 매칭할 필요가 없는 값들 ('—', '미지정' 등)
        self._ignore = {_norm(x) for x in data.get("ignore", [])}

        # ba_member 현황 — user_id(이메일) 와 이름 양쪽으로 찾을 수 있게
        self._by_email: dict[str, dict] = {}
        self._by_name: dict[str, dict] = {}
        for row in members:
            uid = (row.get("user_id") or "").strip().lower()
            if uid:
                self._by_email[uid] = row
            nm = _norm(row.get("emp_name"))
            if nm:
                # 동명이인이면 이름 경로를 막는다. 둘 중 누구인지 알 수 없다.
                self._by_name[nm] = None if nm in self._by_name else row

        self.unmatched: dict[str, int] = {}   # 못 찾은 문자열 → 건수

    def resolve(self, asg_name: str | None, asg_id: str | None = None) -> MemberResolution:
        raw = (asg_name or "").strip()
        n = _norm(raw)

        if not n or n in self._ignore:
            return MemberResolution(reason="담당자 없음")

        # 1) 슬랙 사용자 ID — 이름이 바뀌어도 안 흔들리는 가장 단단한 열쇠
        if asg_id:
            email = self._slack_to_email.get(asg_id.strip())
            if email and email in self._by_email:
                row = self._by_email[email]
                return MemberResolution(int(row["id"]), email, "슬랙 ID 일치")

        # 2) 별칭표
        email = self._alias_to_email.get(n)
        if email:
            row = self._by_email.get(email)
            if row:
                return MemberResolution(int(row["id"]), email, "별칭표 일치")
            # 별칭표에는 있는데 ba_member 에 없다 — 구성원 동기화가 안 된 것
            self._mark(raw)
            return MemberResolution(reason=f"별칭표에는 있으나 ba_member 에 없음({email})")

        # 3) ba_member.emp_name 과 정확히 일치
        row = self._by_name.get(n)
        if row:
            return MemberResolution(int(row["id"]), row.get("user_id"), "이름 일치")
        if n in self._by_name:   # 값이 None = 동명이인
            self._mark(raw)
            return MemberResolution(reason="동명이인이라 특정 불가")

        # 4) 모르면 건너뛴다
        self._mark(raw)
        return MemberResolution(reason="매칭 실패")

    def _mark(self, raw: str) -> None:
        self.unmatched[raw] = self.unmatched.get(raw, 0) + 1


# =====================================================================
# 분야 태깅
# =====================================================================

@dataclass
class DomainRule:
    domain_id: int
    code: str
    name: str
    keywords: list[str] = field(default_factory=list)


class DomainTagger:
    """ba_domain.keywords 로 업무 이력의 분야를 가른다.

    confidence 는 '이 분야 키워드가 몇 개나 걸렸나' 를 0~1 로 누른 값이다.
    한 건이 여러 분야에 걸리는 것은 정상이다(출석부 연동 + 성적부 반영 등).
    """

    # 이 아래로는 분야로 치지 않는다. 한두 낱말 스친 것까지 실적으로 세면
    # 처리 건수가 부풀어 역량 점수가 왜곡된다.
    MIN_CONFIDENCE = 0.2

    def __init__(self, domain_rows: list[dict]):
        self.rules: list[DomainRule] = []
        for row in domain_rows:
            kws = [k.strip() for k in (row.get("keywords") or "").split(",")]
            kws = [k for k in kws if len(k) >= 2]      # 한 글자는 아무데나 걸린다
            if not kws:
                continue
            self.rules.append(DomainRule(
                domain_id=int(row["id"]),
                code=row.get("code", ""),
                name=row.get("name", ""),
                keywords=kws,
            ))

    def tag(self, title: str | None, body: str | None = None) -> dict[int, float]:
        """@return {domain_id: confidence}"""
        # 제목을 본문보다 무겁게 본다. 본문에는 로그·스택트레이스가 섞여
        # 관계없는 낱말이 흔히 나온다.
        t_norm = _norm(title)
        b_norm = _norm(body)
        if not t_norm and not b_norm:
            return {}

        out: dict[int, float] = {}
        for rule in self.rules:
            hits_t = sum(1 for k in rule.keywords if _norm(k) and _norm(k) in t_norm)
            hits_b = sum(1 for k in rule.keywords if _norm(k) and _norm(k) in b_norm)
            if not hits_t and not hits_b:
                continue

            # 제목 1건 = 본문 3건 정도의 무게
            score = hits_t * 1.0 + hits_b * 0.34
            conf = min(1.0, score / 3.0)
            if conf >= self.MIN_CONFIDENCE:
                out[rule.domain_id] = round(conf, 3)

        return out


# =====================================================================
# 난이도 (규칙 기반)
# =====================================================================

# slack/difficulty/difficulty.php 의 difficultyOf() 와 같은 생각으로 만든 규칙.
# 그쪽이 화면에서 쓰는 값과 여기서 적재하는 값이 갈리면 같은 건이 다른 난이도를
# 갖게 된다. 규칙을 고칠 때는 양쪽을 함께 보라.
_DIFF_RULES: list[tuple[float, list[str]]] = [
    (+0.6, ["마이그레이션", "이관", "업그레이드", "버전업", "이중화", "성능", "튜닝",
            "부하", "장애", "아키텍처", "설계", "신규개발", "신규구축"]),
    (+0.4, ["연동", "api", "sso", "인증", "배치", "스케줄", "암호화", "보안", "취약점",
            "학사연동", "결제", "트랜잭션"]),
    (+0.2, ["통계", "리포트", "대시보드", "권한", "정합성", "동시성"]),
    (-0.4, ["문구", "오탈자", "오타", "띄어쓰기", "색상", "정렬", "이미지교체",
            "링크수정", "문의", "확인요청", "단순"]),
    (-0.8, ["계정생성", "비밀번호초기화", "권한부여", "데이터조회", "엑셀추출"]),
]


def rule_difficulty(title: str | None, body: str | None, team: str | None) -> int:
    """1~5. ai_stars 가 없을 때만 쓴다."""
    text = _norm((title or "") + " " + (body or ""))
    total = 0.0
    for pts, words in _DIFF_RULES:
        for w in words:
            if _norm(w) in text:
                total += pts

    if (team or "").strip() == "시스템개발":
        total += 0.3

    blen = len(body or "")
    if blen >= 1200:
        total += 0.5
    elif blen >= 600:
        total += 0.25

    # 장문이나 키워드 과다로 튀는 것을 막는다(원본 difficulty.php 와 같은 폭)
    total = max(-2.2, min(1.4, total))

    lvl = round(3 + total)
    return max(1, min(5, int(lvl)))
