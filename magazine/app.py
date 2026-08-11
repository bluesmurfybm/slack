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

DEV_ACCOUNTS = [
    {"email": "jian@bluesoft.co.kr", "name": "김지안", "role": "관리자"},
    {"email": "siyu@bluesoft.co.kr", "name": "유승인", "role": "일반"},
    {"email": "pink@bluesoft.co.kr", "name": "김태주", "role": "일반"},
    {"email": "hjlee@bluesoft.co.kr", "name": "이한재", "role": "일반"},
]


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


# ---------- 알림 ----------
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
    base["dev_accounts"] = DEV_ACCOUNTS if DEV_LOGIN else []
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


class ClaimIn(BaseModel):
    planned_date: str = ""


class CompleteIn(BaseModel):
    done_date: str = ""


@app.post("/magazineapi/topics/{tid}/claim")
def claim_topic(tid: int, body: ClaimIn, identity: dict = Depends(require_identity)):
    conn = get_db()
    _fetch(conn, tid)   # 없으면 404
    # 조건부 UPDATE 한 방으로 동시 선점을 막는다.
    # - 임포트된 행의 presenter_email 은 NULL 이 아니라 빈 문자열이라 둘 다 본다.
    # - 발표까지 끝난 주제는 발표자가 비어 있어도 선점 대상이 아니다.
    cur = conn.execute(
        "UPDATE topics SET presenter_email=?, presenter=?, "
        "planned_date=CASE WHEN ?<>'' THEN ? ELSE planned_date END "
        "WHERE id=? AND (presenter_email IS NULL OR presenter_email='') "
        "AND (done_date IS NULL OR done_date='')",
        (identity["email"], identity.get("name") or "",
         body.planned_date, body.planned_date, tid))
    conn.commit()
    if cur.rowcount == 0:
        conn.close()
        raise HTTPException(status_code=409, detail="이미 선점되었거나 발표가 끝난 주제입니다")
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


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
