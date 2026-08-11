# BlueUP-DTI 발표 주제 관리 앱 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** xlsx로 운영하던 BlueUP-DTI 발표 주제 관리를 `magazine/`의 웹 앱으로 옮기고, Docker로 화면까지 확인할 수 있게 한다.

**Architecture:** `book/` 앱의 FastAPI + SQLite 단일 파일 구조와 포털 SSO 쿠키 검증을 그대로 재사용한다. 도서 전용 로직(가격·교보문고·카카오 도서검색)은 제거하고 `topics` 스키마와 선점(claim) 흐름으로 교체한다. 포털이 없는 환경에서 화면을 보기 위해 `DEV_LOGIN=1`일 때만 등록되는 개발 전용 로그인 라우트를 둔다.

**Tech Stack:** Python 3.12, FastAPI, uvicorn, SQLite(표준 라이브러리 `sqlite3`), 순수 JS SPA(빌드 도구 없음), Docker Compose

## Global Constraints

- 스펙: `docs/superpowers/specs/2026-08-11-dti-design.md`. 충돌 시 스펙이 우선한다.
- 라우트 접두사는 `/magazineapi`. 포트는 **8001** (`book/`이 8000 고정).
- 관리자: `ADMIN_EMAILS` 환경변수(콤마 구분), 기본값 `jian@bluesoft.co.kr`.
- 상태(`미지정`/`발표예정`/`발표완료`)는 **컬럼으로 저장하지 않고** `done_date`/`presenter_email`에서 파생한다.
- 권한 검사는 **항상 서버에서** 한다. 화면의 버튼 숨김은 편의일 뿐이다.
- 인증 검증 코드(`verify_sso_cookie`)는 운영 경로 그대로 둔다. `DEV_LOGIN`은 쿠키 **발급**만 대신한다.
- 개발 환경에 `fastapi`가 없다. 모든 Python 실행은 스크래치패드 venv를 쓴다:
  `/tmp/claude-1000/-home-user-workspace-slack/d820a4fd-371e-452d-9075-27346a6def82/scratchpad/venv/bin/python`
  (이하 `$VENV/bin/python`으로 표기. 각 태스크 시작 시 `VENV=...`로 export)
- `magazine/seed.json`과 `magazine/index.html`에는 현재 **실명·가격이 포함된 도서구매 데이터**가 남아 있다. 저장소가 public이므로 Task 1과 Task 7에서 각각 교체되기 전에는 해당 파일을 `git add` 하지 않는다.
- `.gitignore`가 `*.md`를 무시한다. 문서를 커밋할 때만 `git add -f`를 쓴다. 코드 파일에는 쓰지 않는다.
- 커밋은 태스크 단위로 한다. 푸시는 하지 않는다.

## File Structure

| 파일 | 책임 |
|---|---|
| `magazine/import_xlsx.py` | xlsx → `seed.json` 변환. 날짜 serial·숫자 아티팩트·팀/개인 분리 규칙을 코드로 보존 |
| `magazine/seed.json` | 초기 데이터 31건 (생성물) |
| `magazine/app.py` | FastAPI 앱. SSO 검증, `topics` 스키마, REST API, 개발 로그인 shim |
| `magazine/index.html` | 단일 파일 SPA |
| `magazine/styles/style.css` | 기존 재사용 + 배지 스타일 추가 |
| `magazine/test_app.py` | TestClient 기반 API 테스트 |
| `magazine/test_import.py` | 변환 규칙 단위 테스트 |
| `magazine/Dockerfile` | 실행 이미지 |
| `magazine/entrypoint.sh` | `sso_secret.key` 없으면 생성 후 CMD 실행 |
| `magazine/docker-compose.yml` | 8001 노출, 볼륨, 개발 환경변수 |
| `magazine/requirements.txt` | 런타임 의존성만 (pytest 등 개발 의존성은 넣지 않음) |

`app.py`를 단일 파일로 두는 것은 형제 앱 `book/app.py`와 같은 패턴을 따르기 위함이다. 약 350줄로 `book/app.py`(약 400줄)보다 작다.

**삭제:** `magazine/backfill_kyobo.py`, `magazine/backfill_thumbnail.py`, `magazine/kakao_keys.example.json`

---

### Task 1: xlsx → seed.json 변환기

**Files:**
- Create: `magazine/import_xlsx.py`
- Create: `magazine/test_import.py`
- Replace: `magazine/seed.json` (현재 도서 데이터 → DTI 데이터)

**Interfaces:**
- Produces: `excel_date(serial: str) -> str` (`"YYYY-MM-DD"` 또는 `""`), `numstr(v: str) -> str`, `read_rows(path: str) -> list[tuple[str, list[str]]]`, `to_topic(vals: list[str], done_sheet: bool) -> dict`
- `to_topic`이 만드는 dict의 키는 Task 2의 `topics` 컬럼명과 정확히 일치해야 한다: `field, title, keywords, magazine, volume, page, year, requirement, team, presenter, presenter_email, planned_date, done_date, note`

- [ ] **Step 1: 변환 규칙 테스트를 먼저 쓴다**

`magazine/test_import.py`:

```python
import import_xlsx as ix


def test_excel_date_uses_1899_12_30_epoch():
    assert ix.excel_date("45838") == "2025-06-30"
    assert ix.excel_date("45845") == "2025-07-07"
    assert ix.excel_date("") == ""
    assert ix.excel_date("발표예정") == ""


def test_numstr_strips_float_artifact():
    # xlsx 숫자 셀의 원시값. 화면 표시는 "20. 2025"
    assert ix.numstr("20.202500000000001") == "20.2025"
    assert ix.numstr("278") == "278"
    assert ix.numstr("275. Oct-Nov") == "275. Oct-Nov"
    assert ix.numstr("") == ""


def test_team_goes_to_team_not_presenter():
    vals = ["Trend", "제목", "키워드", "App", "", "", "DI", "178", "2025", "277", "미지정"]
    t = ix.to_topic(vals, done_sheet=False)
    assert t["team"] == "App"
    assert t["presenter"] == ""
    assert t["presenter_email"] == ""


def test_known_person_gets_email():
    vals = ["UI/UX", "제목", "키워드", "김태주", "45908", "", "DI", "52", "2025", "279", "미지정"]
    t = ix.to_topic(vals, done_sheet=False)
    assert t["presenter"] == "김태주"
    assert t["presenter_email"] == "pink@bluesoft.co.kr"
    assert t["planned_date"] == "2025-09-08"
    assert t["done_date"] == ""


def test_done_sheet_fills_done_date():
    vals = ["Trend", "제목", "키워드", "김호영", "45677", "45677", "MIT TR", "40", "2024", "16. Sep-Oct", "발표 완료"]
    t = ix.to_topic(vals, done_sheet=True)
    assert t["done_date"] == "2025-01-20"


def test_requirement_defaults_to_recommended():
    vals = ["Etc", "제목", "", "", "", "", "Etc", "", "", "", ""]
    assert ix.to_topic(vals, done_sheet=False)["requirement"] == "recommended"


def test_note_preserves_original_text():
    vals = ["UI/UX", "제목", "", "김태주", "45908", "", "DI", "52", "2025", "279", "미지정"]
    # 비고는 상태로 해석하지 않고 원문 보존
    assert ix.to_topic(vals, done_sheet=False)["note"] == "미지정"
```

- [ ] **Step 2: 실패를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_import.py -q
```
기대: `ModuleNotFoundError: No module named 'import_xlsx'`

- [ ] **Step 3: 변환기를 구현한다**

`magazine/import_xlsx.py`:

```python
# -*- coding: utf-8 -*-
"""
2025 BlueUP-DTI xlsx -> seed.json 변환.

openpyxl 없이 표준 라이브러리만 쓴다(개발 환경에 설치되어 있지 않음).
원본 xlsx는 저장소에 넣지 않는 외부 입력이고, 커밋되는 산출물은 seed.json이다.
이 스크립트는 변환 규칙을 코드로 남겨 두기 위해 존재한다.

사용:  python import_xlsx.py "<xlsx 경로>" seed.json
"""
import json
import re
import sys
import zipfile
import xml.etree.ElementTree as ET
from datetime import date, timedelta

NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
REL = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"

EXCEL_EPOCH = date(1899, 12, 30)
TEAMS = {"App", "LAB", "SQUARE"}

# book/app.py 의 NAME_TO_EMAIL 과 동일. 과거 데이터의 이름 -> 이메일 백필용.
NAME_TO_EMAIL = {
    "김호영": "kimhy@bluesoft.co.kr", "김지안": "jian@bluesoft.co.kr",
    "박성철": "scpark@bluesoft.co.kr", "김태주": "pink@bluesoft.co.kr",
    "안정민": "venus@bluesoft.co.kr", "조성훈": "akddd@bluesoft.co.kr",
    "진소현": "lenda83@bluesoft.co.kr", "김아랑": "amitoa@bluesoft.co.kr",
    "박화랑": "phr@bluesoft.co.kr", "유병문": "bnmmnbhj@bluesoft.co.kr",
    "유승인": "siyu@bluesoft.co.kr", "이한재": "hjlee@bluesoft.co.kr",
    "이준영": "jun0@bluesoft.co.kr",
}

# xlsx 컬럼 순서
C_FIELD, C_TITLE, C_KEYWORDS, C_PRESENTER, C_PLANNED, C_DONE, \
    C_MAGAZINE, C_PAGE, C_YEAR, C_VOLUME, C_NOTE = range(11)


def excel_date(v):
    """엑셀 날짜 serial -> 'YYYY-MM-DD'. 숫자가 아니면 빈 문자열."""
    s = (v or "").strip()
    if not s:
        return ""
    try:
        n = float(s)
    except ValueError:
        return ""
    return (EXCEL_EPOCH + timedelta(days=int(n))).isoformat()


def numstr(v):
    """숫자 셀의 부동소수 아티팩트를 정리한다. 숫자가 아니면 원문 유지.

    xlsx는 숫자 셀의 원시값을 갖고 있어 Volume 에 '20.202500000000001' 같은 값이
    들어온다(화면 표시는 '20. 2025'). '275. Oct-Nov' 처럼 문자열인 행과 섞여 있다.
    """
    s = (v or "").strip()
    if not s:
        return ""
    try:
        f = float(s)
    except ValueError:
        return s
    return f"{f:.10g}"


def _col_idx(ref):
    n = 0
    for ch in re.match(r"([A-Z]+)", ref).group(1):
        n = n * 26 + (ord(ch) - 64)
    return n - 1


def _shared_strings(z):
    if "xl/sharedStrings.xml" not in z.namelist():
        return []
    root = ET.fromstring(z.read("xl/sharedStrings.xml"))
    return ["".join(t.text or "" for t in si.iter(f"{NS}t"))
            for si in root.findall(f"{NS}si")]


def _sheets(z):
    wb = ET.fromstring(z.read("xl/workbook.xml"))
    rels = ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))
    rmap = {r.get("Id"): r.get("Target") for r in rels}
    out = []
    for sh in wb.find(f"{NS}sheets"):
        tgt = rmap.get(sh.get(f"{REL}id"), "")
        out.append((sh.get("name"), tgt if tgt.startswith("xl/") else "xl/" + tgt.lstrip("/")))
    return out


def read_rows(path):
    """[(시트명, [셀값...]), ...] 을 헤더 제외하고 돌려준다."""
    out = []
    with zipfile.ZipFile(path) as z:
        ss = _shared_strings(z)
        for name, tgt in _sheets(z):
            root = ET.fromstring(z.read(tgt))
            for row in root.iter(f"{NS}row"):
                cells = {}
                for c in row.findall(f"{NS}c"):
                    t, v, isel = c.get("t"), c.find(f"{NS}v"), c.find(f"{NS}is")
                    if t == "s" and v is not None:
                        val = ss[int(v.text)]
                    elif t == "inlineStr" and isel is not None:
                        val = "".join(x.text or "" for x in isel.iter(f"{NS}t"))
                    elif v is not None:
                        val = v.text
                    else:
                        continue
                    if val and str(val).strip():
                        cells[_col_idx(c.get("r"))] = str(val).strip()
                if cells:
                    out.append((name, [cells.get(i, "") for i in range(11)]))
    # 헤더 행(첫 컬럼이 '분야')은 버린다
    return [(n, v) for n, v in out if v[C_FIELD] != "분야"]


def to_topic(vals, done_sheet):
    """xlsx 한 행 -> topics 레코드 dict."""
    raw_presenter = vals[C_PRESENTER].strip()
    team = raw_presenter if raw_presenter in TEAMS else ""
    presenter = "" if team else raw_presenter
    year = numstr(vals[C_YEAR])
    return {
        "field":           vals[C_FIELD],
        "title":           vals[C_TITLE],
        "keywords":        vals[C_KEYWORDS],
        "magazine":        vals[C_MAGAZINE],
        "volume":          numstr(vals[C_VOLUME]),
        "page":            numstr(vals[C_PAGE]),
        "year":            int(float(year)) if year else None,
        "requirement":     "recommended",   # xlsx 에 없는 신규 항목
        "team":            team,
        "presenter":       presenter,
        "presenter_email": NAME_TO_EMAIL.get(presenter, ""),
        "planned_date":    excel_date(vals[C_PLANNED]),
        "done_date":       excel_date(vals[C_DONE]) if done_sheet else "",
        "note":            vals[C_NOTE],    # 상태로 해석하지 않고 원문 보존
    }


def main(xlsx_path, out_path):
    topics = [to_topic(v, done_sheet=("완료" in name))
              for name, v in read_rows(xlsx_path)]
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(topics, f, ensure_ascii=False, indent=1)
    print(f"{len(topics)}건 -> {out_path}")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "seed.json")
```

- [ ] **Step 4: 테스트 통과를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_import.py -q
```
기대: 7 passed

- [ ] **Step 5: seed.json 을 생성하고 내용을 검증한다**

```bash
cd magazine && $VENV/bin/python import_xlsx.py "../2025 BlueUP-DTI (Digital Technology Insight).xlsx" seed.json
$VENV/bin/python - <<'EOF'
import json
d = json.load(open('magazine/seed.json'))
assert len(d) == 31, len(d)
assert not any('0000000' in str(t['volume']) for t in d), "float 아티팩트 잔존"
assert all(t['requirement'] == 'recommended' for t in d)
assert sum(1 for t in d if t['done_date']) == 16, "발표완료 건수"
import re
for t in d:
    for k in ('planned_date', 'done_date'):
        assert t[k] == '' or re.fullmatch(r'\d{4}-\d{2}-\d{2}', t[k]), (k, t[k])
print("seed.json OK:", len(d), "건")
EOF
```
기대: `seed.json OK: 31 건`

- [ ] **Step 6: 커밋**

```bash
git add magazine/import_xlsx.py magazine/test_import.py magazine/seed.json
git commit -m "feat(magazine): xlsx -> seed.json 변환기와 DTI 초기 데이터 31건"
```

---

### Task 2: 앱 골격 — 스키마, 시드 적재, SSO, whoami

**Files:**
- Rewrite: `magazine/app.py`
- Create: `magazine/test_app.py`
- Modify: `magazine/requirements.txt`

**Interfaces:**
- Consumes: Task 1의 `seed.json` 키 이름
- Produces: `verify_sso_cookie(raw) -> dict|None`, `get_identity(request) -> dict|None`, `require_identity(request) -> dict`, `is_admin(email) -> bool`, `require_admin(identity) -> dict`, `derive_status(row) -> str`, `make_cookie(email, name, color="", ttl=3600) -> str`, FastAPI 인스턴스 `app`
- Task 3~6이 `app`, `require_identity`, `require_admin`, `get_db()`를 쓴다
- `test_app.py`가 `make_cookie`를 테스트 픽스처로 재사용한다

- [ ] **Step 1: 실패 테스트를 쓴다**

`magazine/test_app.py`:

```python
import importlib
import os
import sys

import pytest
from fastapi.testclient import TestClient

ADMIN = "jian@bluesoft.co.kr"
USER = "siyu@bluesoft.co.kr"


@pytest.fixture()
def app_mod(tmp_path):
    """매 테스트마다 빈 DB와 임시 시크릿으로 앱을 새로 적재한다."""
    secret = tmp_path / "sso_secret.key"
    secret.write_text("test-secret-0123456789")
    os.environ["SSO_SECRET_PATH"] = str(secret)
    os.environ["DB_PATH"] = str(tmp_path / "test.db")
    os.environ["ADMIN_EMAILS"] = ADMIN
    os.environ["DEV_LOGIN"] = "0"
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import app as app_module
    importlib.reload(app_module)
    return app_module


@pytest.fixture()
def client(app_mod):
    return TestClient(app_mod.app)


def login(client, app_mod, email, name="테스터"):
    client.cookies.set("blueiwork_id", app_mod.make_cookie(email, name))


def test_seed_is_loaded(client, app_mod):
    login(client, app_mod, USER)
    rows = client.get("/magazineapi/topics").json()
    assert len(rows) == 31


def test_topics_requires_login(client):
    assert client.get("/magazineapi/topics").status_code == 401


def test_invalid_signature_is_rejected(client, app_mod):
    client.cookies.set("blueiwork_id", app_mod.make_cookie(USER) + "tampered")
    assert client.get("/magazineapi/topics").status_code == 401


def test_expired_cookie_is_rejected(client, app_mod):
    client.cookies.set("blueiwork_id", app_mod.make_cookie(USER, ttl=-10))
    assert client.get("/magazineapi/topics").status_code == 401


def test_whoami_marks_admin(client, app_mod):
    login(client, app_mod, ADMIN, "김지안")
    body = client.get("/magazineapi/whoami").json()
    assert body["email"] == ADMIN
    assert body["is_admin"] is True


def test_whoami_normal_user_is_not_admin(client, app_mod):
    login(client, app_mod, USER)
    assert client.get("/magazineapi/whoami").json()["is_admin"] is False


def test_status_is_derived_not_stored(client, app_mod):
    login(client, app_mod, USER)
    rows = client.get("/magazineapi/topics").json()
    assert {r["status"] for r in rows} <= {"미지정", "발표예정", "발표완료"}
    assert sum(1 for r in rows if r["status"] == "발표완료") == 16
    # 비고 원문은 상태와 별개로 보존된다
    assert any(r["note"] == "미지정" and r["status"] == "발표예정" for r in rows)


def test_devlogin_absent_when_disabled(client):
    assert client.post("/magazineapi/devlogin", json={"email": USER}).status_code == 404
```

- [ ] **Step 2: 실패를 확인한다**

```bash
cd magazine && $VENV/bin/pip install -q pytest && $VENV/bin/python -m pytest test_app.py -q
```
기대: `ModuleNotFoundError` 또는 `AttributeError: module 'app' has no attribute 'make_cookie'`

- [ ] **Step 3: `app.py`를 재작성한다 (골격 부분)**

`book/app.py`에서 가져오는 것: `_sso_secret`, `_b64url_decode`, `verify_sso_cookie`, `get_identity`, `require_identity`, `get_db`. 가져오지 않는 것: 교보(`resolve_kyobo_url`), 카카오(`search_kakao_books`, `_load_kakao_key`), 가격 관련 전부.

```python
# -*- coding: utf-8 -*-
"""
BlueUP-DTI 발표 주제 관리 서버 (FastAPI + SQLite)

실행:
    pip install -r requirements.txt
    python app.py            ->  http://localhost:8001

- 로그인 화면이 없다. 포털(PHP auth.php)이 심는 blueiwork_id 서명 쿠키를 검증만 한다.
  따라서 포털과 같은 호스트에서 서빙되어야 한다(포트는 달라도 됨).
- 데이터는 magazine.db 에 저장된다. 비어 있으면 seed.json 을 자동 적재한다.
"""
import base64
import hashlib
import hmac
import json
import os
import sqlite3
import time
from typing import Optional

from fastapi import Depends, FastAPI, HTTPException, Request, Response
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

BASE = os.path.dirname(os.path.abspath(__file__))
DB = os.environ.get("DB_PATH", os.path.join(BASE, "magazine.db"))
INDEX = os.path.join(BASE, "index.html")
SEED = os.path.join(BASE, "seed.json")

PORTAL_URL = os.environ.get("PORTAL_URL", "/")
SLACK_URL = os.environ.get("SLACK_URL", "/slack/lists.php")
# book/ 과 달리 경로를 환경변수로 뺀다. 개발 환경에는 포털이 만드는 이 파일이 없어
# 테스트가 불가능하기 때문이다. 운영 기본값은 book/ 과 동일하다.
SSO_SECRET_PATH = os.environ.get("SSO_SECRET_PATH", os.path.join(BASE, "..", "sso_secret.key"))
ADMIN_EMAILS = {e.strip().lower() for e in
                os.environ.get("ADMIN_EMAILS", "jian@bluesoft.co.kr").split(",") if e.strip()}
DEV_LOGIN = os.environ.get("DEV_LOGIN") == "1"

FIELDS = ("field", "title", "keywords", "magazine", "volume", "page", "year",
          "requirement", "team", "presenter", "presenter_email",
          "planned_date", "done_date", "note")


# ---------- 인증 (검증 경로는 book/ 과 동일) ----------
def _sso_secret() -> str:
    with open(SSO_SECRET_PATH, "r", encoding="utf-8") as f:
        return f.read().strip()


def _b64url_decode(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def _b64url_encode(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).decode("ascii").rstrip("=")


def make_cookie(email: str, name: str = "", color: str = "", ttl: int = 3600) -> str:
    """포털 auth.php 의 issue_sso_cookie 와 같은 형식으로 서명 쿠키 값을 만든다.
    개발 로그인(DEV_LOGIN)과 테스트에서만 쓴다."""
    inner = f"{email}\t{name}\t{color}\t{int(time.time()) + ttl}"
    payload = _b64url_encode(inner.encode("utf-8"))
    sig = hmac.new(_sso_secret().encode("utf-8"), payload.encode("utf-8"),
                   hashlib.sha256).hexdigest()
    return f"{payload}.{sig}"


def verify_sso_cookie(raw: str):
    try:
        payload, sig = raw.rsplit(".", 1)
        expected = hmac.new(_sso_secret().encode("utf-8"), payload.encode("utf-8"),
                            hashlib.sha256).hexdigest()
        if not hmac.compare_digest(sig, expected):
            return None
        email, name, color, exp_s = _b64url_decode(payload).decode("utf-8").split("\t")
        if int(exp_s) < time.time():
            return None
        return {"email": email, "name": name, "color": color}
    except Exception:
        return None


def get_identity(request: Request):
    raw = request.cookies.get("blueiwork_id")
    return verify_sso_cookie(raw) if raw else None


def require_identity(request: Request) -> dict:
    ident = get_identity(request)
    if not ident:
        raise HTTPException(status_code=401, detail="로그인이 필요합니다")
    return ident


def is_admin(email: Optional[str]) -> bool:
    return bool(email) and email.lower() in ADMIN_EMAILS


def require_admin(identity: dict = Depends(require_identity)) -> dict:
    if not is_admin(identity.get("email")):
        raise HTTPException(status_code=403, detail="관리자만 할 수 있습니다")
    return identity


# ---------- DB ----------
def get_db():
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = get_db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS topics(
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            field           TEXT,
            title           TEXT NOT NULL,
            keywords        TEXT,
            magazine        TEXT,
            volume          TEXT,
            page            TEXT,
            year            INTEGER,
            requirement     TEXT DEFAULT 'recommended',
            team            TEXT,
            presenter       TEXT,
            presenter_email TEXT,
            planned_date    TEXT,
            done_date       TEXT,
            note            TEXT,
            created_by      TEXT,
            created_at      TEXT
        )""")
    conn.commit()
    if conn.execute("SELECT COUNT(*) FROM topics").fetchone()[0] == 0 and os.path.exists(SEED):
        with open(SEED, "r", encoding="utf-8") as f:
            rows = json.load(f)
        cols = ",".join(FIELDS)
        marks = ",".join("?" * len(FIELDS))
        conn.executemany(f"INSERT INTO topics({cols}) VALUES({marks})",
                         [tuple(r.get(k) for k in FIELDS) for r in rows])
        conn.commit()
        print(f"[seed] {len(rows)}건 초기 데이터를 적재했습니다.")
    conn.close()


def derive_status(row) -> str:
    """상태는 저장하지 않고 파생한다. xlsx 처럼 비고와 실제 값이 어긋나는 일을 막는다."""
    if row["done_date"]:
        return "발표완료"
    if row["presenter_email"]:
        return "발표예정"
    return "미지정"


def row_to_dict(row) -> dict:
    d = dict(row)
    d["status"] = derive_status(row)
    return d


# ---------- 앱 ----------
app = FastAPI(title="BlueUP-DTI 발표 주제")
init_db()
app.mount("/styles", StaticFiles(directory=os.path.join(BASE, "styles")), name="styles")

# 포털 공용 스타일. 컨테이너에는 없을 수 있으므로 있을 때만 마운트한다
# (StaticFiles 는 디렉터리가 없으면 기동 시점에 바로 예외를 던진다).
_SHARED_STYLES = os.path.join(BASE, "..", "styles")
if os.path.isdir(_SHARED_STYLES):
    app.mount("/shared-styles", StaticFiles(directory=_SHARED_STYLES), name="shared-styles")


@app.get("/")
def index(request: Request):
    if not DEV_LOGIN and not get_identity(request):
        return RedirectResponse(PORTAL_URL)
    return FileResponse(INDEX)


@app.get("/magazineapi/whoami")
def whoami(request: Request):
    ident = get_identity(request)
    base = {"email": None, "name": None, "color": None}
    base.update(ident or {})
    base["is_admin"] = is_admin(base.get("email"))
    base["dev_login"] = DEV_LOGIN
    base["portal_url"] = PORTAL_URL
    base["slack_url"] = SLACK_URL
    return base


@app.get("/magazineapi/topics")
def list_topics(identity: dict = Depends(require_identity)):
    conn = get_db()
    rows = conn.execute(
        "SELECT * FROM topics ORDER BY (done_date IS NULL OR done_date='') DESC, "
        "COALESCE(NULLIF(planned_date,''), '9999') ASC, id ASC").fetchall()
    conn.close()
    return [row_to_dict(r) for r in rows]


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
```

`magazine/requirements.txt`는 그대로 둔다(`fastapi`, `uvicorn[standard]`, `requests`). `requests`는 Task 5의 Slack 알림에서 쓴다.

- [ ] **Step 4: 테스트 통과를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py -q
```
기대: 8 passed

- [ ] **Step 5: 커밋**

```bash
git add magazine/app.py magazine/test_app.py
git commit -m "feat(magazine): topics 스키마·SSO 검증·목록 API 골격"
```

---

### Task 3: 관리자 CRUD

**Files:**
- Modify: `magazine/app.py` (라우트 추가)
- Modify: `magazine/test_app.py` (테스트 추가)

**Interfaces:**
- Consumes: Task 2의 `app`, `require_identity`, `require_admin`, `get_db`, `row_to_dict`
- Produces: `POST /magazineapi/topics` → 201 + 생성된 레코드, `PUT /magazineapi/topics/{id}` → 수정된 레코드, `DELETE /magazineapi/topics/{id}` → `{"ok": true}`
- 모델: `TopicIn`(생성, `title` 필수), `TopicPatch`(수정, 전 필드 Optional)

- [ ] **Step 1: 실패 테스트를 쓴다**

`magazine/test_app.py` 끝에 추가:

```python
NEW = {"field": "Trend", "title": "새 주제", "keywords": "AI",
       "magazine": "DI", "volume": "280", "page": "12", "year": 2026,
       "requirement": "required"}


def test_normal_user_cannot_create(client, app_mod):
    login(client, app_mod, USER)
    assert client.post("/magazineapi/topics", json=NEW).status_code == 403


def test_anonymous_cannot_create(client):
    assert client.post("/magazineapi/topics", json=NEW).status_code == 401


def test_admin_creates_topic_as_unassigned(client, app_mod):
    login(client, app_mod, ADMIN, "김지안")
    r = client.post("/magazineapi/topics", json=NEW)
    assert r.status_code == 201
    body = r.json()
    assert body["status"] == "미지정"
    assert body["requirement"] == "required"
    assert body["created_by"] == ADMIN


def test_created_topic_appears_in_list(client, app_mod):
    login(client, app_mod, ADMIN)
    client.post("/magazineapi/topics", json=NEW)
    titles = [t["title"] for t in client.get("/magazineapi/topics").json()]
    assert "새 주제" in titles


def test_title_is_required(client, app_mod):
    login(client, app_mod, ADMIN)
    assert client.post("/magazineapi/topics", json={"field": "Etc"}).status_code == 422


def test_admin_updates_requirement(client, app_mod):
    login(client, app_mod, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    r = client.put(f"/magazineapi/topics/{tid}", json={"requirement": "recommended"})
    assert r.status_code == 200
    assert r.json()["requirement"] == "recommended"
    # 보내지 않은 필드는 보존된다
    assert r.json()["title"] == "새 주제"


def test_normal_user_cannot_update_or_delete(client, app_mod):
    login(client, app_mod, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    login(client, app_mod, USER)
    assert client.put(f"/magazineapi/topics/{tid}", json={"title": "x"}).status_code == 403
    assert client.delete(f"/magazineapi/topics/{tid}").status_code == 403


def test_admin_deletes_topic(client, app_mod):
    login(client, app_mod, ADMIN)
    tid = client.post("/magazineapi/topics", json=NEW).json()["id"]
    assert client.delete(f"/magazineapi/topics/{tid}").status_code == 200
    assert client.get(f"/magazineapi/topics/{tid}").status_code == 404


def test_update_missing_topic_is_404(client, app_mod):
    login(client, app_mod, ADMIN)
    assert client.put("/magazineapi/topics/99999", json={"title": "x"}).status_code == 404
```

- [ ] **Step 2: 실패를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py -q -k "create or update or delete or required"
```
기대: 405/404 로 인한 다수 실패

- [ ] **Step 3: 라우트를 구현한다**

`app.py`의 `list_topics` 아래에 추가:

```python
class TopicIn(BaseModel):
    title: str
    field: str = ""
    keywords: str = ""
    magazine: str = ""
    volume: str = ""
    page: str = ""
    year: Optional[int] = None
    requirement: str = "recommended"
    team: str = ""
    planned_date: str = ""
    note: str = ""


class TopicPatch(BaseModel):
    title: Optional[str] = None
    field: Optional[str] = None
    keywords: Optional[str] = None
    magazine: Optional[str] = None
    volume: Optional[str] = None
    page: Optional[str] = None
    year: Optional[int] = None
    requirement: Optional[str] = None
    team: Optional[str] = None
    presenter: Optional[str] = None
    presenter_email: Optional[str] = None
    planned_date: Optional[str] = None
    done_date: Optional[str] = None
    note: Optional[str] = None


def _fetch(conn, tid):
    row = conn.execute("SELECT * FROM topics WHERE id=?", (tid,)).fetchone()
    if not row:
        conn.close()
        raise HTTPException(status_code=404, detail="없는 주제입니다")
    return row


@app.get("/magazineapi/topics/{tid}")
def get_topic(tid: int, identity: dict = Depends(require_identity)):
    conn = get_db()
    row = _fetch(conn, tid)
    conn.close()
    return row_to_dict(row)


@app.post("/magazineapi/topics", status_code=201)
def create_topic(body: TopicIn, identity: dict = Depends(require_admin)):
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO topics(field,title,keywords,magazine,volume,page,year,"
        "requirement,team,planned_date,note,created_by,created_at) "
        "VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
        (body.field, body.title, body.keywords, body.magazine, body.volume,
         body.page, body.year, body.requirement, body.team, body.planned_date,
         body.note, identity["email"], time.strftime("%Y-%m-%d %H:%M:%S")))
    conn.commit()
    row = _fetch(conn, cur.lastrowid)
    conn.close()
    notify_slack_new_topic(row)
    return row_to_dict(row)


@app.put("/magazineapi/topics/{tid}")
def update_topic(tid: int, body: TopicPatch, identity: dict = Depends(require_admin)):
    conn = get_db()
    _fetch(conn, tid)
    patch = body.model_dump(exclude_unset=True)
    if patch:
        sets = ",".join(f"{k}=?" for k in patch)
        conn.execute(f"UPDATE topics SET {sets} WHERE id=?", (*patch.values(), tid))
        conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return row_to_dict(row)


@app.delete("/magazineapi/topics/{tid}")
def delete_topic(tid: int, identity: dict = Depends(require_admin)):
    conn = get_db()
    _fetch(conn, tid)
    conn.execute("DELETE FROM topics WHERE id=?", (tid,))
    conn.commit()
    conn.close()
    return {"ok": True}
```

`notify_slack_new_topic`은 Task 5에서 구현한다. 그때까지 임시로 아래를 `app.py`의 DB 섹션 아래에 둔다:

```python
def notify_slack_new_topic(row):
    """새 주제 등록을 슬랙에 알린다. Task 5에서 채운다."""
    return
```

- [ ] **Step 4: 테스트 통과를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py -q
```
기대: 17 passed

- [ ] **Step 5: 커밋**

```bash
git add magazine/app.py magazine/test_app.py
git commit -m "feat(magazine): 관리자 전용 주제 등록·수정·삭제"
```

---

### Task 4: 선점 / 취소 / 발표완료

**Files:**
- Modify: `magazine/app.py`
- Modify: `magazine/test_app.py`

**Interfaces:**
- Consumes: Task 3의 `_fetch`, `TopicPatch`
- Produces: `POST /magazineapi/topics/{id}/claim` (body `{planned_date?}`), `POST .../release`, `POST .../complete` (body `{done_date?}`)

- [ ] **Step 1: 실패 테스트를 쓴다**

`magazine/test_app.py` 끝에 추가:

```python
OTHER = "hjlee@bluesoft.co.kr"


def _open_topic_id(client, app_mod):
    login(client, app_mod, ADMIN)
    return client.post("/magazineapi/topics", json=NEW).json()["id"]


def test_user_claims_open_topic(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER, "유승인")
    r = client.post(f"/magazineapi/topics/{tid}/claim", json={"planned_date": "2026-09-01"})
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "발표예정"
    assert body["presenter_email"] == USER
    assert body["presenter"] == "유승인"
    assert body["planned_date"] == "2026-09-01"


def test_second_claim_conflicts(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, app_mod, OTHER)
    assert client.post(f"/magazineapi/topics/{tid}/claim", json={}).status_code == 409


def test_claim_missing_topic_is_404(client, app_mod):
    login(client, app_mod, USER)
    assert client.post("/magazineapi/topics/99999/claim", json={}).status_code == 404


def test_owner_releases_own_claim(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    r = client.post(f"/magazineapi/topics/{tid}/release")
    assert r.status_code == 200
    assert r.json()["status"] == "미지정"
    assert r.json()["presenter_email"] in ("", None)


def test_third_party_cannot_release(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, app_mod, OTHER)
    assert client.post(f"/magazineapi/topics/{tid}/release").status_code == 403


def test_admin_can_release_anyones_claim(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, app_mod, ADMIN)
    assert client.post(f"/magazineapi/topics/{tid}/release").status_code == 200


def test_admin_completes_topic(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    login(client, app_mod, ADMIN)
    r = client.post(f"/magazineapi/topics/{tid}/complete", json={"done_date": "2026-09-01"})
    assert r.status_code == 200
    assert r.json()["status"] == "발표완료"


def test_normal_user_cannot_complete(client, app_mod):
    tid = _open_topic_id(client, app_mod)
    login(client, app_mod, USER)
    client.post(f"/magazineapi/topics/{tid}/claim", json={})
    assert client.post(f"/magazineapi/topics/{tid}/complete", json={}).status_code == 403


def test_claim_works_on_seeded_row_with_empty_email(client, app_mod):
    """임포트된 행의 presenter_email 은 NULL 이 아니라 빈 문자열이다."""
    login(client, app_mod, USER)
    rows = client.get("/magazineapi/topics").json()
    tid = next(r["id"] for r in rows if r["status"] == "미지정")
    assert client.post(f"/magazineapi/topics/{tid}/claim", json={}).status_code == 200
```

- [ ] **Step 2: 실패를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py -q -k "claim or release or complete"
```
기대: 404(라우트 없음)로 실패

- [ ] **Step 3: 구현한다**

`app.py`에 추가:

```python
class ClaimIn(BaseModel):
    planned_date: str = ""


class CompleteIn(BaseModel):
    done_date: str = ""


@app.post("/magazineapi/topics/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, identity: dict = Depends(require_identity)):
    conn = get_db()
    _fetch(conn, tid)   # 없으면 404
    # 조건부 UPDATE 한 방으로 동시 선점을 막는다. 임포트된 행은 NULL 이 아니라
    # 빈 문자열이므로 두 경우를 모두 본다.
    cur = conn.execute(
        "UPDATE topics SET presenter_email=?, presenter=?, "
        "planned_date=CASE WHEN ?<>'' THEN ? ELSE planned_date END "
        "WHERE id=? AND (presenter_email IS NULL OR presenter_email='')",
        (identity["email"], identity.get("name") or "",
         body.planned_date, body.planned_date, tid))
    conn.commit()
    if cur.rowcount == 0:
        conn.close()
        raise HTTPException(status_code=409, detail="이미 다른 사람이 선점한 주제입니다")
    row = _fetch(conn, tid)
    conn.close()
    return row_to_dict(row)


@app.post("/magazineapi/topics/{tid}/release")
def release_topic(tid: int, identity: dict = Depends(require_identity)):
    conn = get_db()
    row = _fetch(conn, tid)
    if row["presenter_email"] != identity["email"] and not is_admin(identity["email"]):
        conn.close()
        raise HTTPException(status_code=403, detail="본인이 선점한 주제만 취소할 수 있습니다")
    conn.execute("UPDATE topics SET presenter_email='', presenter='', planned_date='' "
                 "WHERE id=?", (tid,))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return row_to_dict(row)


@app.post("/magazineapi/topics/{tid}/complete")
def complete_topic(tid: int, body: CompleteIn, identity: dict = Depends(require_admin)):
    conn = get_db()
    _fetch(conn, tid)
    done = body.done_date or time.strftime("%Y-%m-%d")
    conn.execute("UPDATE topics SET done_date=? WHERE id=?", (done, tid))
    conn.commit()
    row = _fetch(conn, tid)
    conn.close()
    return row_to_dict(row)
```

- [ ] **Step 4: 테스트 통과를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py -q
```
기대: 26 passed

- [ ] **Step 5: 커밋**

```bash
git add magazine/app.py magazine/test_app.py
git commit -m "feat(magazine): 주제 선점·취소·발표완료 (동시 선점은 409)"
```

---

### Task 5: 개발 전용 로그인 + Slack 알림

**Files:**
- Modify: `magazine/app.py`
- Modify: `magazine/test_app.py`
- Delete: `magazine/backfill_kyobo.py`, `magazine/backfill_thumbnail.py`, `magazine/kakao_keys.example.json`

**Interfaces:**
- Produces: `POST /magazineapi/devlogin` (body `{email, name?}`) → 쿠키 설정 + `{ok, email, name, is_admin}`. `DEV_LOGIN=1`일 때만 등록된다.
- Produces: `DEV_ACCOUNTS: list[dict]` — 화면의 계정 전환 셀렉터가 `whoami`를 통해 받아 쓴다

- [ ] **Step 1: 실패 테스트를 쓴다**

`magazine/test_app.py` 끝에 추가:

```python
@pytest.fixture()
def dev_client(tmp_path):
    secret = tmp_path / "sso_secret.key"
    secret.write_text("test-secret-0123456789")
    os.environ["SSO_SECRET_PATH"] = str(secret)
    os.environ["DB_PATH"] = str(tmp_path / "dev.db")
    os.environ["ADMIN_EMAILS"] = ADMIN
    os.environ["DEV_LOGIN"] = "1"
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import app as app_module
    importlib.reload(app_module)
    return TestClient(app_module.app)


def test_devlogin_sets_working_cookie(dev_client):
    r = dev_client.post("/magazineapi/devlogin", json={"email": ADMIN, "name": "김지안"})
    assert r.status_code == 200
    assert r.json()["is_admin"] is True
    # 발급된 쿠키가 운영 검증 경로를 그대로 통과한다
    who = dev_client.get("/magazineapi/whoami").json()
    assert who["email"] == ADMIN
    assert who["is_admin"] is True
    assert dev_client.get("/magazineapi/topics").status_code == 200


def test_devlogin_switch_to_normal_user(dev_client):
    dev_client.post("/magazineapi/devlogin", json={"email": ADMIN})
    dev_client.post("/magazineapi/devlogin", json={"email": USER, "name": "유승인"})
    assert dev_client.get("/magazineapi/whoami").json()["is_admin"] is False
    assert dev_client.post("/magazineapi/topics", json=NEW).status_code == 403


def test_whoami_exposes_dev_accounts(dev_client):
    who = dev_client.get("/magazineapi/whoami").json()
    assert who["dev_login"] is True
    assert any(a["email"] == ADMIN for a in who["dev_accounts"])
```

- [ ] **Step 2: 실패를 확인한다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py -q -k dev
```
기대: 404 / KeyError로 실패

- [ ] **Step 3: 구현한다**

`app.py`에 추가. `notify_slack_new_topic`의 임시 구현을 아래로 교체한다:

```python
DEV_ACCOUNTS = [
    {"email": "jian@bluesoft.co.kr", "name": "김지안", "role": "관리자"},
    {"email": "siyu@bluesoft.co.kr", "name": "유승인", "role": "일반"},
    {"email": "pink@bluesoft.co.kr", "name": "김태주", "role": "일반"},
    {"email": "hjlee@bluesoft.co.kr", "name": "이한재", "role": "일반"},
]


def _slack_webhook() -> Optional[str]:
    url = os.environ.get("SLACK_WEBHOOK_URL")
    if url:
        return url
    try:
        from config_local import SLACK_WEBHOOK_URL
        return SLACK_WEBHOOK_URL
    except ImportError:
        return None


def notify_slack_new_topic(row):
    """새 주제가 올라온 것을 알린다. 선점을 유도하는 용도라 등록 시에만 보낸다."""
    url = _slack_webhook()
    if not url:
        return
    need = "필수" if row["requirement"] == "required" else "권장"
    text = "\n".join([
        ":newspaper: *새 DTI 주제*",
        f"• 제목: {row['title']}",
        f"• 분야: {row['field'] or '-'} / 발표 {need}",
        f"• 출처: {row['magazine'] or '-'} {row['volume'] or ''} p.{row['page'] or '-'}",
    ])
    try:
        import requests
        requests.post(url, json={"text": text}, timeout=3)
    except Exception:
        pass   # 알림 실패가 등록을 막으면 안 된다
```

`whoami`의 반환에 `dev_accounts`를 추가한다:

```python
    base["dev_accounts"] = DEV_ACCOUNTS if DEV_LOGIN else []
```

파일 맨 끝(`if __name__ == "__main__":` 바로 위)에 개발 로그인 라우트를 둔다:

```python
# ---------- 개발 전용 로그인 ----------
# 포털이 없는 환경(도커·로컬)에서 화면을 보기 위한 스텁이다.
# DEV_LOGIN=1 일 때만 라우트가 등록되므로 운영에는 존재하지 않는다.
# 쿠키 "발급"만 대신하고, 이후 검증·권한 경로는 운영과 완전히 동일하다.
if DEV_LOGIN:
    class DevLoginIn(BaseModel):
        email: str
        name: str = ""

    @app.post("/magazineapi/devlogin")
    def dev_login(body: DevLoginIn, response: Response):
        name = body.name or next(
            (a["name"] for a in DEV_ACCOUNTS if a["email"] == body.email), body.email)
        response.set_cookie("blueiwork_id", make_cookie(body.email, name, ttl=86400),
                            httponly=True, samesite="lax", path="/")
        return {"ok": True, "email": body.email, "name": name,
                "is_admin": is_admin(body.email)}
```

- [ ] **Step 4: 테스트 통과를 확인하고 도서 전용 파일을 지운다**

```bash
cd magazine && $VENV/bin/python -m pytest test_app.py test_import.py -q
git rm -f magazine/backfill_kyobo.py magazine/backfill_thumbnail.py magazine/kakao_keys.example.json 2>/dev/null \
  || rm -f magazine/backfill_kyobo.py magazine/backfill_thumbnail.py magazine/kakao_keys.example.json
```
기대: 36 passed (`test_app.py` 29 + `test_import.py` 7)

- [ ] **Step 5: 커밋**

```bash
git add -A magazine/
git commit -m "feat(magazine): 개발 전용 로그인 shim, 주제 등록 슬랙 알림, 도서 전용 파일 제거"
```

---

### Task 6: 화면 재작성

**Files:**
- Rewrite: `magazine/index.html`
- Modify: `magazine/styles/style.css`

**Interfaces:**
- Consumes: `/magazineapi/whoami`(`is_admin`, `dev_login`, `dev_accounts`), `/magazineapi/topics`, 그리고 Task 3~5의 전 라우트

**재사용할 마크업** — 현재 `index.html`에서 그대로 두는 블록: `topbar`(17~38행), `header.site`(39~52행), 시트 모달 껍데기(`.sheet`/`.sheet-head`/`.sheet-body`/`.sheet-foot`), 토스트, 확인 다이얼로그, 유저 메뉴. **`const SEED = [...]` (148행)은 통째로 삭제한다** — 도서 신청 실명·가격 데이터이고, 데이터는 이제 API로만 온다.

**삭제할 JS**: `displayPrice`, `formatPriceInput`, `parsePriceInput`, `buildApplicantFilter`, `filterByApplicant`, `checkDup`, `onTitleInput`, `searchBooks`, `pickBook`, `onEditTitleInput`, `searchEditBooks`, `pickEditBook`, `setGubun`, `buildPicker`.

- [ ] **Step 1: 목록·필터·선점 흐름을 구현한다**

`index.html`의 `<script>`에서 데이터·렌더 부분을 아래로 교체한다:

```javascript
let ME = {};            // whoami 결과
let TOPICS = [];        // 서버 목록

const STATUS_ORDER = ["미지정", "발표예정", "발표완료"];

async function api(path, opts) {
  const r = await fetch(path, Object.assign({credentials: "same-origin"}, opts || {}));
  if (!r.ok) {
    let msg = "요청이 실패했습니다";
    try { msg = (await r.json()).detail || msg; } catch (e) {}
    throw new Error(msg);
  }
  return r.status === 204 ? null : r.json();
}

async function loadAll() {
  ME = await api("/magazineapi/whoami");
  document.body.classList.toggle("is-admin", !!ME.is_admin);
  buildDevBar();
  TOPICS = await api("/magazineapi/topics");
  buildFilters();
  render();
}

function visible() {
  const f = id => document.getElementById(id).value;
  const q = f("q").trim().toLowerCase();
  const mine = document.getElementById("f-mine").checked;
  return TOPICS.filter(t =>
    (!f("f-field")    || t.field === f("f-field")) &&
    (!f("f-magazine") || t.magazine === f("f-magazine")) &&
    (!f("f-status")   || t.status === f("f-status")) &&
    (!f("f-req")      || t.requirement === f("f-req")) &&
    (!mine            || t.presenter_email === ME.email) &&
    (!q || (t.title + " " + t.keywords + " " + t.presenter).toLowerCase().includes(q))
  );
}

function buildFilters() {
  const fill = (id, vals) => {
    const el = document.getElementById(id);
    const keep = el.value;
    el.innerHTML = '<option value="">전체</option>' +
      vals.filter(Boolean).sort().map(v => `<option>${esc(v)}</option>`).join("");
    el.value = keep;
  };
  fill("f-field", [...new Set(TOPICS.map(t => t.field))]);
  fill("f-magazine", [...new Set(TOPICS.map(t => t.magazine))]);
}

function render() {
  const list = document.getElementById("list");
  const rows = visible();
  if (!rows.length) {
    list.innerHTML = '<div class="empty"><div class="big">해당하는 주제가 없어요</div></div>';
    return;
  }
  list.innerHTML = rows.map(rowHtml).join("");
  document.getElementById("count").textContent = `${rows.length}건`;
}

function rowHtml(t) {
  const need = t.requirement === "required"
    ? '<span class="badge req">필수</span>'
    : '<span class="badge rec">권장</span>';
  const st = `<span class="badge st-${STATUS_ORDER.indexOf(t.status)}">${t.status}</span>`;
  const who = t.presenter || t.team || "-";
  const src = [t.magazine, t.volume && `Vol.${t.volume}`, t.page && `p.${t.page}`]
    .filter(Boolean).join(" ");
  return `<div class="row" data-id="${t.id}">
    <div class="row-main">
      <div class="row-title">${esc(t.title)} ${need} ${st}</div>
      <div class="row-sub">
        <span class="chip">${esc(t.field || "-")}</span>
        ${t.keywords ? `<span class="chip ghost">${esc(t.keywords)}</span>` : ""}
        <span class="muted">${esc(src || "-")}</span>
      </div>
    </div>
    <div class="row-side">
      <div class="who">${esc(who)}</div>
      <div class="muted">${esc(t.planned_date || t.done_date || "")}</div>
    </div>
    <div class="row-act">${actionsHtml(t)}</div>
  </div>`;
}

function actionsHtml(t) {
  const out = [];
  if (t.status === "미지정") {
    out.push(`<button class="btn primary" onclick="claim(${t.id})">내가 발표할게요</button>`);
  } else if (t.presenter_email === ME.email && t.status !== "발표완료") {
    out.push(`<button class="btn" onclick="release(${t.id})">취소</button>`);
  }
  if (ME.is_admin) {
    if (t.status === "발표예정") {
      out.push(`<button class="btn" onclick="complete(${t.id})">발표완료</button>`);
    }
    out.push(`<button class="btn ghost" onclick="openEdit(${t.id})">수정</button>`);
    out.push(`<button class="btn ghost danger" onclick="askDelete(${t.id})">삭제</button>`);
  }
  return out.join("");
}

async function claim(id) {
  const d = prompt("발표 예정일 (YYYY-MM-DD, 비워도 됩니다)", "") || "";
  try {
    await api(`/magazineapi/topics/${id}/claim`, {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({planned_date: d.trim()}),
    });
    showToast("선점했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function release(id) {
  try {
    await api(`/magazineapi/topics/${id}/release`, {method: "POST"});
    showToast("선점을 취소했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function complete(id) {
  const d = prompt("발표일 (YYYY-MM-DD, 비우면 오늘)", "") || "";
  try {
    await api(`/magazineapi/topics/${id}/complete`, {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({done_date: d.trim()}),
    });
    showToast("발표완료 처리했습니다");
    await reload();
  } catch (e) { showToast(e.message); }
}

async function reload() {
  TOPICS = await api("/magazineapi/topics");
  buildFilters();
  render();
}

function buildDevBar() {
  const bar = document.getElementById("devbar");
  if (!ME.dev_login) { bar.style.display = "none"; return; }
  bar.style.display = "";
  bar.innerHTML = '<span class="muted">개발 모드 — 계정 전환:</span> ' +
    ME.dev_accounts.map(a =>
      `<button class="btn tiny${a.email === ME.email ? " on" : ""}"
        onclick="devLogin('${a.email}')">${esc(a.name)} (${esc(a.role)})</button>`
    ).join("");
}

async function devLogin(email) {
  await api("/magazineapi/devlogin", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({email}),
  });
  await loadAll();
}

window.addEventListener("DOMContentLoaded", loadAll);
```

툴바 마크업을 아래로 교체한다:

```html
<div class="toolbar">
  <input id="q" type="text" placeholder="제목·키워드·발표자 검색" oninput="render()">
  <select id="f-field"    onchange="render()"></select>
  <select id="f-magazine" onchange="render()"></select>
  <select id="f-status"   onchange="render()">
    <option value="">전체 상태</option>
    <option>미지정</option><option>발표예정</option><option>발표완료</option>
  </select>
  <select id="f-req" onchange="render()">
    <option value="">필수·권장</option>
    <option value="required">필수</option>
    <option value="recommended">권장</option>
  </select>
  <label class="check"><input id="f-mine" type="checkbox" onchange="render()"> 내가 선점한 것만</label>
  <span id="count" class="muted"></span>
  <button class="btn primary admin-only" onclick="openForm()">주제 등록</button>
</div>
<div id="devbar" class="devbar" style="display:none"></div>
```

- [ ] **Step 2: 등록/수정 시트를 구현한다**

기존 `.sheet` 껍데기를 재사용하고 본문 필드만 교체한다:

```html
<div class="sheet-body">
  <label>제목 (주제)</label>
  <input id="f-title" type="text">
  <label>분야</label>
  <input id="f-field-in" type="text" placeholder="UI/UX · Trend · Marketing · Etc">
  <label>중요 키워드</label>
  <input id="f-keywords" type="text">
  <label>발표</label>
  <div class="seg">
    <button type="button" id="req-recommended" class="on" onclick="setReq('recommended')">권장</button>
    <button type="button" id="req-required" onclick="setReq('required')">필수</button>
  </div>
  <label>매거진 / Volume / Page</label>
  <div class="triple">
    <input id="f-magazine-in" type="text" placeholder="DI">
    <input id="f-volume" type="text" placeholder="280">
    <input id="f-page" type="text" placeholder="46">
  </div>
  <label>년도</label>
  <input id="f-year" type="number" placeholder="2026">
  <label>배정 팀 (선택)</label>
  <input id="f-team" type="text" placeholder="App · LAB · SQUARE">
  <label>비고</label>
  <input id="f-note" type="text">
</div>
```

```javascript
let REQ = "recommended";
let EDIT_ID = null;
let PENDING_DELETE = null;

// 기존 index.html 의 openForm/closeForm 은 시트를 여닫는 일만 하던 함수다.
// 여기서 openForm 을 "등록 폼 초기화"로 다시 쓰므로, 여닫기는 이름을 분리한다.
function openSheet()  { document.getElementById("sheet").classList.add("show"); }
function closeSheet() { document.getElementById("sheet").classList.remove("show"); }

function setReq(v) {
  REQ = v;
  document.getElementById("req-recommended").classList.toggle("on", v === "recommended");
  document.getElementById("req-required").classList.toggle("on", v === "required");
}

function formValues() {
  const g = id => document.getElementById(id).value.trim();
  return {
    title: g("f-title"), field: g("f-field-in"), keywords: g("f-keywords"),
    magazine: g("f-magazine-in"), volume: g("f-volume"), page: g("f-page"),
    year: g("f-year") ? Number(g("f-year")) : null,
    requirement: REQ, team: g("f-team"), note: g("f-note"),
  };
}

function openForm() {
  EDIT_ID = null;
  ["f-title","f-field-in","f-keywords","f-magazine-in","f-volume","f-page","f-year","f-team","f-note"]
    .forEach(id => document.getElementById(id).value = "");
  setReq("recommended");
  document.getElementById("sheet-title").textContent = "새 주제 등록";
  openSheet();
}

function openEdit(id) {
  const t = TOPICS.find(x => x.id === id);
  EDIT_ID = id;
  const s = (el, v) => document.getElementById(el).value = v == null ? "" : v;
  s("f-title", t.title); s("f-field-in", t.field); s("f-keywords", t.keywords);
  s("f-magazine-in", t.magazine); s("f-volume", t.volume); s("f-page", t.page);
  s("f-year", t.year); s("f-team", t.team); s("f-note", t.note);
  setReq(t.requirement);
  document.getElementById("sheet-title").textContent = "주제 수정";
  openSheet();
}

async function submitForm() {
  const v = formValues();
  if (!v.title) { showToast("제목을 입력해 주세요"); return; }
  try {
    if (EDIT_ID) {
      await api(`/magazineapi/topics/${EDIT_ID}`, {
        method: "PUT", headers: {"Content-Type": "application/json"},
        body: JSON.stringify(v)});
      showToast("수정했습니다");
    } else {
      await api("/magazineapi/topics", {
        method: "POST", headers: {"Content-Type": "application/json"},
        body: JSON.stringify(v)});
      showToast("등록했습니다");
    }
    closeSheet();
    await reload();
  } catch (e) { showToast(e.message); }
}

// 기존 확인 다이얼로그는 askDelete 로 열고 doDelete 가 인자 없이 확정하는 구조다.
// 그 계약을 그대로 지킨다.
function askDelete(id) {
  PENDING_DELETE = id;
  document.getElementById("confirm").classList.add("show");
}

async function doDelete() {
  try {
    await api(`/magazineapi/topics/${PENDING_DELETE}`, {method: "DELETE"});
    showToast("삭제했습니다");
    closeConfirm();
    await reload();
  } catch (e) { showToast(e.message); }
}
```

- [ ] **Step 3: 배지·개발바 스타일을 추가한다**

`magazine/styles/style.css`에서 `.booksearch` 두 줄(152~153행)을 지우고 아래를 덧붙인다:

```css
.badge{display:inline-block;padding:1px 7px;border-radius:2px;font-size:11px;font-weight:700;vertical-align:middle}
.badge.req{background:#fdecec;color:#c0392b}
.badge.rec{background:#eef3fb;color:#3d6fb4}
.badge.st-0{background:#f1f1f1;color:#666}
.badge.st-1{background:#eaf5ec;color:#2c7a3f}
.badge.st-2{background:#f4f0fa;color:#6b4fa0}
.chip{display:inline-block;padding:1px 7px;border:1px solid var(--line);border-radius:2px;font-size:11px;margin-right:4px}
.chip.ghost{border-style:dashed;color:#777}
.check{display:inline-flex;align-items:center;gap:4px;font-size:13px}
.seg{display:inline-flex;border:1.5px solid var(--line);border-radius:2px;overflow:hidden}
.seg button{padding:6px 16px;background:#fff;border:0;cursor:pointer;font-size:13px}
.seg button.on{background:#2f6fd0;color:#fff}
.triple{display:grid;grid-template-columns:2fr 1fr 1fr;gap:6px}
.devbar{padding:6px 10px;background:#fff8e1;border:1px dashed #e0c069;border-radius:2px;margin:8px 0;font-size:12px}
.btn.tiny{padding:3px 9px;font-size:12px}
.btn.tiny.on{background:#2f6fd0;color:#fff}
.admin-only{display:none}
body.is-admin .admin-only{display:inline-block}
```

- [ ] **Step 4: 잔존물이 없는지 확인한다**

```bash
cd magazine
grep -nE 'SEED *=|kyobo|kakao|booksearch|price|가격|도서' index.html && echo "!! 잔존물 있음" || echo "정리 완료"
grep -c '주문처' index.html || true
```
기대: `정리 완료`, `주문처` 0건

- [ ] **Step 5: 커밋**

```bash
git add magazine/index.html magazine/styles/style.css
git commit -m "feat(magazine): DTI 주제 목록·선점·관리자 등록 화면"
```

---

### Task 7: Docker

**Files:**
- Create: `magazine/Dockerfile`, `magazine/entrypoint.sh`, `magazine/docker-compose.yml`, `magazine/.dockerignore`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: Task 2의 `DB_PATH` / `SSO_SECRET_PATH` / `DEV_LOGIN` / `ADMIN_EMAILS` 환경변수

- [ ] **Step 1: Dockerfile 과 엔트리포인트를 쓴다**

`magazine/Dockerfile`:

```dockerfile
FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# 데이터와 시크릿은 볼륨에 둔다
ENV DB_PATH=/app/data/magazine.db \
    SSO_SECRET_PATH=/app/data/sso_secret.key

EXPOSE 8001
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8001"]
```

`magazine/entrypoint.sh`:

```sh
#!/bin/sh
# 포털 auth.php 의 sso_secret() 과 같은 동작 — 시크릿 파일이 없으면 만든다.
set -e
mkdir -p "$(dirname "$SSO_SECRET_PATH")"
if [ ! -s "$SSO_SECRET_PATH" ]; then
    python -c "import secrets,os; open(os.environ['SSO_SECRET_PATH'],'w').write(secrets.token_hex(32))"
    echo "[entrypoint] sso_secret.key 생성"
fi
exec "$@"
```

`magazine/.dockerignore`:

```
__pycache__/
*.pyc
*.db
data/
test_*.py
.pytest_cache/
```

`magazine/docker-compose.yml`:

```yaml
services:
  magazine:
    build: .
    ports:
      - "8001:8001"
    environment:
      DEV_LOGIN: "1"                      # 포털이 없으므로 개발 로그인을 켠다
      ADMIN_EMAILS: "jian@bluesoft.co.kr"
      PORTAL_URL: "/"
    volumes:
      - magazine-data:/app/data
      # 포털 공용 스타일. BASE 가 /app 이므로 ../styles 는 /styles 다.
      - ../styles:/styles:ro

volumes:
  magazine-data:
```

- [ ] **Step 2: 실행 권한을 주고 빌드한다**

```bash
chmod +x magazine/entrypoint.sh
cd magazine && docker compose up --build -d
```
기대: 빌드 성공, 컨테이너 起動

- [ ] **Step 3: 컨테이너를 실제로 두드려 확인한다**

```bash
sleep 5
# 1) 화면이 뜬다
curl -s -o /dev/null -w "GET / -> %{http_code}\n" http://localhost:8001/
# 2) 미로그인은 401
curl -s -o /dev/null -w "topics(익명) -> %{http_code}\n" http://localhost:8001/magazineapi/topics
# 3) 관리자로 개발 로그인
curl -s -c /tmp/ck.txt -X POST http://localhost:8001/magazineapi/devlogin \
     -H 'Content-Type: application/json' -d '{"email":"jian@bluesoft.co.kr"}'
echo
# 4) 시드 31건
curl -s -b /tmp/ck.txt http://localhost:8001/magazineapi/topics | python3 -c "import json,sys;print('topics:',len(json.load(sys.stdin)))"
# 5) 관리자는 등록 가능
curl -s -o /dev/null -w "관리자 등록 -> %{http_code}\n" -b /tmp/ck.txt \
     -X POST http://localhost:8001/magazineapi/topics \
     -H 'Content-Type: application/json' -d '{"title":"도커 확인용","requirement":"required"}'
# 6) 일반 사용자는 403
curl -s -c /tmp/ck2.txt -o /dev/null -X POST http://localhost:8001/magazineapi/devlogin \
     -H 'Content-Type: application/json' -d '{"email":"siyu@bluesoft.co.kr"}'
curl -s -o /dev/null -w "일반 등록 -> %{http_code}\n" -b /tmp/ck2.txt \
     -X POST http://localhost:8001/magazineapi/topics \
     -H 'Content-Type: application/json' -d '{"title":"막혀야 함"}'
```
기대: `GET / -> 200`, `topics(익명) -> 401`, `topics: 31`, `관리자 등록 -> 201`, `일반 등록 -> 403`

- [ ] **Step 4: 재시작 후에도 데이터가 남는지 본다**

```bash
cd magazine && docker compose restart && sleep 5
curl -s -b /tmp/ck.txt http://localhost:8001/magazineapi/topics \
  | python3 -c "import json,sys;d=json.load(sys.stdin);print('재시작 후:',len(d),'건');assert any(t['title']=='도커 확인용' for t in d)"
```
기대: `재시작 후: 32 건`

- [ ] **Step 5: `.gitignore`를 갱신하고 커밋한다**

`.gitignore`의 `# book(도서구매신청) 로컬 전용 설정/데이터` 블록 아래에 추가:

```
# magazine(DTI 발표주제) 로컬 전용 설정/데이터
magazine/config_local.py
magazine/magazine.db
magazine/data/
```

```bash
git add magazine/Dockerfile magazine/entrypoint.sh magazine/docker-compose.yml \
        magazine/.dockerignore .gitignore
git commit -m "feat(magazine): docker compose 로 로컬 확인 환경 구성"
```

---

### Task 8: 최종 점검

**Files:** 없음 (검증만)

- [ ] **Step 1: 전체 테스트를 돌린다**

```bash
cd magazine && $VENV/bin/python -m pytest -q
```
기대: 36 passed

- [ ] **Step 2: 개인정보 잔존물이 없는지 확인한다**

```bash
cd /home/user/workspace/slack
grep -rlE '주문처|예스24|알라딘|13500|교보문구' magazine/ && echo "!! 도서 데이터 잔존" || echo "정리 완료"
git status --short magazine/
```
기대: `정리 완료`. `magazine/magazine.db`·`data/`가 `git status`에 뜨지 않을 것

- [ ] **Step 3: 두 앱이 포트 충돌 없이 공존하는지 본다**

```bash
grep -n 'port=' book/app.py magazine/app.py
```
기대: `book/app.py` 8000, `magazine/app.py` 8001

- [ ] **Step 4: 커밋할 것이 남았으면 커밋한다**

```bash
git status --short
git log --oneline -8
```
