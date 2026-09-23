"""프롬프트·로그용 텍스트 유틸.

펜스: 문의·댓글·유사 사례·diff 같은 신뢰할 수 없는 텍스트는 랜덤 토큰 펜스로 감싼다.
  <<<DATA:label:tok
  …
  >>>DATA:label:tok
데이터 안의 '<<<'/'>>>' 는 전각으로 바꿔 구분자를 무력화하고, 제어문자를 지우고, 길이를 캡한다.
"""

import hashlib
import json
import re
import secrets

_CTL_RE = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
_SECRET_RE = re.compile(
    r"(xox[pbaers]-[A-Za-z0-9-]{8,}|xapp-[A-Za-z0-9-]{8,}|sk-[A-Za-z0-9_-]{8,}"
    r"|sk-ant-[A-Za-z0-9_-]{8,}|ghp_[A-Za-z0-9]{8,})"
)


def strip_ctl(s: str) -> str:
    """탭·줄바꿈을 뺀 제어문자 제거."""
    return _CTL_RE.sub("", s or "")


def cap(s: str, n: int, marker: str = "\n…(생략)…") -> str:
    """n 자를 넘으면 잘라 표시한다."""
    s = s or ""
    if len(s) <= n:
        return s
    return s[: max(0, n - len(marker))] + marker


def new_token() -> str:
    return secrets.token_hex(3)


def fence(label: str, text: str, limit: int | None = None, tok: str | None = None) -> str:
    """신뢰할 수 없는 텍스트를 DATA 펜스로 감싼다. 결과만 프롬프트에 넣는다."""
    tok = tok or new_token()
    body = strip_ctl(text or "")
    body = body.replace("<<<", "＜＜＜").replace(">>>", "＞＞＞")
    if limit is not None:
        body = cap(body, limit)
    label = re.sub(r"[^A-Za-z0-9_.-]", "_", label)[:40] or "data"
    return f"<<<DATA:{label}:{tok}\n{body}\n>>>DATA:{label}:{tok}"


def src_hash(title: str | None, body: str | None) -> str:
    """requests 제목+본문 md5 (변경 감지). PHP 쪽과 같은 정의: md5(title . body)."""
    return hashlib.md5(((title or "") + (body or "")).encode("utf-8")).hexdigest() # noqa: S324


def json_block(text: str) -> dict:
    """LLM 출력에서 JSON 객체 하나를 뽑는다(```json 펜스 / 앞뒤 잡말 허용)."""
    raw = (text or "").strip()
    m = re.search(r"```(?:json)?\s*(\{.*\})\s*```", raw, re.DOTALL)
    if m:
        raw = m.group(1)
    elif not raw.startswith("{"):
        start, end = raw.find("{"), raw.rfind("}")
        if start >= 0 and end > start:
            raw = raw[start : end + 1]
    return json.loads(raw)


def decode_best(b: bytes | None, *, force_utf8: bool = False) -> str:
    """서브프로세스 바이트 출력 → 문자열. utf-8 → cp949 → utf-8(replace). diff 는 항상 utf-8 replace."""
    if not b:
        return ""
    if force_utf8:
        return b.decode("utf-8", "replace")
    for enc in ("utf-8", "cp949"):
        try:
            return b.decode(enc)
        except UnicodeDecodeError:
            continue
    return b.decode("utf-8", "replace")


def mask_secrets(s: str) -> str:
    """로그에 토큰이 찍히지 않게 접두어만 남긴다."""
    if not s:
        return s

    def _m(m: re.Match) -> str:
        v = m.group(0)
        head = v.split("-", 1)[0] if "-" in v else v[:3]
        return f"{head}-****"

    return _SECRET_RE.sub(_m, s)


def tokens(s: str) -> set[str]:
    """교훈 중복 판정용 토큰 집합(한글/영문/숫자 2자 이상)."""
    return {t.lower() for t in re.findall(r"[0-9A-Za-z가-힣_]{2,}", s or "")}


def jaccard(a: set, b: set) -> float:
    if not a and not b:
        return 1.0
    if not a or not b:
        return 0.0
    return len(a & b) / len(a | b)


def normalize_path(p: str) -> str | None:
    """플랜 파일 경로 정규화: '\\'→'/', './' 제거, 절대경로·'..' 는 폐기(None)."""
    if not p or not isinstance(p, str):
        return None
    q = p.strip().replace("\\", "/")
    while q.startswith("./"):
        q = q[2:]
    if not q or q.startswith("/") or re.match(r"^[A-Za-z]:/", q) or q.startswith("~"):
        return None
    parts = [x for x in q.split("/") if x not in ("", ".")]
    if not parts or any(x == ".." for x in parts):
        return None
    return "/".join(parts)


def ellipsis(s: str, n: int) -> str:
    s = (s or "").replace("\n", " ").strip()
    return s if len(s) <= n else s[: n - 1] + "…"
