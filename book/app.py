# -*- coding: utf-8 -*-
"""
도서구매신청 서버 (FastAPI + SQLite)

실행 방법 (Windows, D:\\company\\book 에서):
    1) 최초 1회:  pip install fastapi "uvicorn[standard]"
    2) 서버 시작:  python app.py
    3) 다른 PC에서 접속:  http://<내PC의 IPv4주소>:8000
       (내 IP 확인은 명령창에서 ipconfig -> IPv4 주소)

- 같은 폴더에 index.html, seed.json 이 있어야 합니다.
- 데이터는 같은 폴더의 book.db 파일에 저장됩니다. (백업은 이 파일만 복사)
- book.db 가 비어 있으면 seed.json 의 기존 내역을 자동으로 넣어줍니다.
"""
import os, json, sqlite3, re, time, hmac, hashlib, base64, urllib.parse, requests
from typing import Optional
from fastapi import FastAPI, HTTPException, Request, Depends
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

BASE = os.path.dirname(os.path.abspath(__file__))
DB    = os.path.join(BASE, "book.db")
INDEX = os.path.join(BASE, "index.html")
SEED  = os.path.join(BASE, "seed.json")

FIELDS = ("year", "date", "applicant", "gubun", "title",
          "author", "publisher", "price", "done", "note", "kyobo_url")

# blue-iwork 포털과 신원 공유(SSO). 포털(PHP)이 로그인 시 심는 서명 쿠키를 여기서 검증만 한다.
# 전제: 포털·book이 같은 호스트(포트만 달라도 됨)에서 서빙되어야 브라우저가 쿠키를 같이 보낸다.
PORTAL_URL       = os.environ.get("PORTAL_URL", "/")   # TODO: 실제 포털 주소로 설정
SLACK_URL        = os.environ.get("SLACK_URL", "/slack/lists.php")  # 공통 헤더 드롭다운의 "업무현황판" 링크
SSO_SECRET_PATH  = os.path.join(BASE, "..", "sso_secret.key")          # 포털 auth.php가 최초 실행 시 생성
ADMIN_EMAIL      = "jian@bluesoft.co.kr"                               # 전체 수정/삭제/완료처리 권한

# 과거(로그인 연동 이전) 데이터의 신청자 이름 → 이메일 백필용. 신원 판별에는 쓰지 않음(SSO 쿠키만 신뢰).
NAME_TO_EMAIL = {
    "김호영": "kimhy@bluesoft.co.kr", "김지안": "jian@bluesoft.co.kr",
    "박성철": "scpark@bluesoft.co.kr", "김태주": "pink@bluesoft.co.kr",
    "안정민": "venus@bluesoft.co.kr", "조성훈": "akddd@bluesoft.co.kr",
    "진소현": "lenda83@bluesoft.co.kr", "김아랑": "amitoa@bluesoft.co.kr",
    "박화랑": "phr@bluesoft.co.kr", "유병문": "bnmmnbhj@bluesoft.co.kr",
    "유승인": "siyu@bluesoft.co.kr", "이한재": "hjlee@bluesoft.co.kr",
    "이준영": "jun0@bluesoft.co.kr",
}


def _sso_secret() -> str:
    with open(SSO_SECRET_PATH, "r", encoding="utf-8") as f:
        return f.read().strip()


def _b64url_decode(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def verify_sso_cookie(raw: str):
    """포털이 심은 blueiwork_id 쿠키를 검증해 {"email":...,"name":...,"color":...} 반환. 무효면 None."""
    try:
        payload, sig = raw.rsplit(".", 1)
        expected = hmac.new(_sso_secret().encode("utf-8"), payload.encode("utf-8"), hashlib.sha256).hexdigest()
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
    if not raw:
        return None
    return verify_sso_cookie(raw)


def require_identity(request: Request) -> dict:
    ident = get_identity(request)
    if not ident:
        raise HTTPException(status_code=401, detail="로그인이 필요합니다")
    return ident

_KYOBO_UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}


def resolve_kyobo_url(title: str, author: str = "") -> Optional[str]:
    """교보문고 도서 검색결과에서 첫 상품의 상품페이지 URL을 반환. 실패 시 None."""
    kw = (title or "").strip()
    if not kw:
        return None
    if author:
        kw = f"{kw} {author}".strip()
    search = "https://search.kyobobook.co.kr/search"
    try:
        r = requests.get(search, params={"keyword": kw}, headers=_KYOBO_UA, timeout=5)
        if r.status_code != 200:
            return None
        m = re.search(r'<ul class="prod_list">[\s\S]*?/detail/(S\d+)', r.text)
        if not m:
            return None
        return f"https://product.kyobobook.co.kr/detail/{m.group(1)}"
    except Exception:
        return None


def _load_kakao_key():
    """KAKAO_REST_API_KEY 환경변수 우선, 없으면 kakao_keys.json 파일에서 로드. 없으면 빈 문자열(기능 비활성)."""
    key = os.environ.get("KAKAO_REST_API_KEY", "")
    if key:
        return key
    path = os.path.join(BASE, "kakao_keys.json")
    if os.path.exists(path):
        try:
            with open(path, encoding="utf-8") as f:
                cfg = json.load(f)
            return cfg.get("rest_api_key", "")
        except Exception:
            pass
    return ""


KAKAO_REST_API_KEY = _load_kakao_key()


def _load_slack_webhook():
    """SLACK_WEBHOOK_URL 환경변수 우선, 없으면 config_local.py 에서 로드. 없으면 빈 문자열(알림 비활성)."""
    url = os.environ.get("SLACK_WEBHOOK_URL", "")
    if url:
        return url
    try:
        from config_local import SLACK_WEBHOOK_URL as local_url
        return local_url
    except ImportError:
        return ""


SLACK_WEBHOOK_URL = _load_slack_webhook()


def notify_slack(rec: dict):
    """신청 1건을 Slack으로 알림. 실패해도 조용히 넘어감."""
    if not SLACK_WEBHOOK_URL:
        return
    price = f"{rec['price']:,}원" if rec.get("price") else "-"
    lines = [
        ":books: *새 도서 신청*",
        f"• 신청자: {rec.get('applicant','')}",
        f"• 도서: {rec.get('title','')}" + (f" / {rec['author']}" if rec.get("author") else ""),
        f"• 구분: {rec.get('gubun','')}  ·  단가: {price}",
        f"• 신청일: {rec.get('date','')}",
    ]
    if rec.get("note"):
        lines.append(f"• 비고: {rec['note']}")
    if rec.get("kyobo_url"):
        lines.append(f"• 교보문고: {rec['kyobo_url']}")
    try:
        requests.post(SLACK_WEBHOOK_URL, json={"text": "\n".join(lines)}, timeout=5)
    except Exception:
        pass  # 알림 실패는 무시(신청은 이미 저장됨)


def search_kakao_books(query: str):
    """카카오 책 검색 API로 제목/저자/출판사/가격 후보를 반환. 키 미설정/실패 시 빈 리스트."""
    if not KAKAO_REST_API_KEY or not (query or "").strip():
        return []
    try:
        r = requests.get(
            "https://dapi.kakao.com/v3/search/book",
            params={"query": query, "size": 10},
            headers={"Authorization": f"KakaoAK {KAKAO_REST_API_KEY}"},
            timeout=5)
        if r.status_code != 200:
            return []
        out = []
        for it in r.json().get("documents", []):
            price = it.get("sale_price") or it.get("price")
            out.append({
                "title": it.get("title", ""),
                "author": ", ".join(it.get("authors") or []),
                "publisher": it.get("publisher", ""),
                "price": price if isinstance(price, int) and price > 0 else None,
                "thumbnail": it.get("thumbnail", ""),
            })
        return out
    except Exception:
        return []


def get_db():
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = get_db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS requests(
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            year      INTEGER,
            date      TEXT,
            applicant TEXT,
            gubun     TEXT,
            title     TEXT,
            author    TEXT,
            publisher TEXT,
            price     INTEGER,
            done      INTEGER DEFAULT 0,
            note      TEXT
        )""")
    conn.commit()

    cols = [r[1] for r in conn.execute("PRAGMA table_info(requests)").fetchall()]
    if "yes24_url" not in cols:
        conn.execute("ALTER TABLE requests ADD COLUMN yes24_url TEXT")
        conn.commit()
    if "kyobo_url" not in cols:
        conn.execute("ALTER TABLE requests ADD COLUMN kyobo_url TEXT")
        conn.commit()
    if "thumbnail" not in cols:
        conn.execute("ALTER TABLE requests ADD COLUMN thumbnail TEXT")
        conn.commit()
    if "applicant_email" not in cols:
        conn.execute("ALTER TABLE requests ADD COLUMN applicant_email TEXT")
        conn.commit()
        # 로그인 연동 이전 기존 행은 이름으로 이메일을 최선 노력 백필(신원 판별 근거로는 안 씀)
        rows = conn.execute(
            "SELECT id, applicant FROM requests WHERE applicant_email IS NULL OR applicant_email=''"
        ).fetchall()
        for r in rows:
            email = NAME_TO_EMAIL.get((r["applicant"] or "").strip())
            if email:
                conn.execute("UPDATE requests SET applicant_email=? WHERE id=?", (email, r["id"]))
        conn.commit()

    empty = conn.execute("SELECT COUNT(*) AS c FROM requests").fetchone()["c"] == 0
    if empty and os.path.exists(SEED):
        with open(SEED, encoding="utf-8") as f:
            rows = json.load(f)
        for r in rows:
            conn.execute(
                "INSERT INTO requests(year,date,applicant,gubun,title,author,publisher,price,done,note)"
                " VALUES(?,?,?,?,?,?,?,?,?,?)",
                (r.get("year"), r.get("date"), r.get("applicant"), r.get("gubun"),
                 r.get("title"), r.get("author"), r.get("publisher"), r.get("price"),
                 1 if r.get("done") else 0, r.get("note") or ""))
        conn.commit()
        print(f"[seed] {len(rows)}건 초기 데이터를 book.db 에 넣었습니다.")
    conn.close()


def row_to_dict(row):
    d = dict(row)
    d["done"] = bool(d["done"])
    return d


app = FastAPI(title="도서구매신청")
init_db()
app.mount("/styles", StaticFiles(directory=os.path.join(BASE, "styles")), name="styles")
# 포털(root)의 styles/ 를 그대로 서빙 — topbar.css 공통 원본을 book이 복사하지 않고 직접 참조.
# book은 SSO 쿠키 특성상 어차피 포털과 같은 호스트에 있어야 하므로 추가 결합은 아님.
app.mount("/shared-styles", StaticFiles(directory=os.path.join(BASE, "..", "styles")), name="shared-styles")


# ---------- 화면 ----------
@app.get("/")
def index(request: Request):
    if not get_identity(request):
        return RedirectResponse(PORTAL_URL)
    return FileResponse(INDEX)


@app.get("/bookapi/whoami")
def whoami(request: Request):
    ident = get_identity(request)
    base = {"email": None, "name": None, "color": None}
    base.update(ident or {})
    base["portal_url"] = PORTAL_URL   # 공통 상단바(로고 클릭·로그아웃 링크)가 참조
    base["slack_url"]  = SLACK_URL    # 공통 상단바 드롭다운의 "업무현황판" 링크
    return base


# ---------- API (전부 로그인 필요) ----------
@app.get("/bookapi/booksearch")
def book_search(q: str = "", identity: dict = Depends(require_identity)):
    return search_kakao_books(q)


@app.get("/bookapi/requests")
def list_requests(identity: dict = Depends(require_identity)):
    conn = get_db()
    rows = conn.execute("SELECT * FROM requests ORDER BY date DESC, id DESC").fetchall()
    conn.close()
    return [row_to_dict(r) for r in rows]


class NewReq(BaseModel):
    date: str
    gubun: str = "직무무관"
    title: str
    author: str = ""
    publisher: str = ""
    price: Optional[int] = None
    note: str = ""
    kyobo_url: Optional[str] = None
    thumbnail: Optional[str] = None


@app.post("/bookapi/requests")
def create(req: NewReq, identity: dict = Depends(require_identity)):
    # 신청자는 항상 로그인한 본인 — 클라이언트가 보낸 값은 신뢰하지 않는다.
    applicant = identity["name"]
    applicant_email = identity["email"]
    year = int(req.date[:4]) if req.date and req.date[:4].isdigit() else None
    kurl = (req.kyobo_url or "").strip() or resolve_kyobo_url(req.title, req.author)
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO requests(year,date,applicant,applicant_email,gubun,title,author,publisher,price,done,note,kyobo_url,thumbnail)"
        " VALUES(?,?,?,?,?,?,?,?,?,0,?,?,?)",
        (year, req.date, applicant, applicant_email, req.gubun, req.title,
         req.author, req.publisher, req.price, req.note, kurl, req.thumbnail))
    conn.commit()
    row = conn.execute("SELECT * FROM requests WHERE id=?", (cur.lastrowid,)).fetchone()
    conn.close()
    result = row_to_dict(row)
    notify_slack(result)
    return result


class UpdateReq(BaseModel):
    title: Optional[str] = None
    author: Optional[str] = None
    publisher: Optional[str] = None
    gubun: Optional[str] = None
    price: Optional[int] = None
    done: Optional[bool] = None
    note: Optional[str] = None
    kyobo_url: Optional[str] = None
    thumbnail: Optional[str] = None


@app.put("/bookapi/requests/{rid}")
def update(rid: int, req: UpdateReq, identity: dict = Depends(require_identity)):
    conn = get_db()
    existing = conn.execute("SELECT * FROM requests WHERE id=?", (rid,)).fetchone()
    if existing is None:
        conn.close()
        raise HTTPException(404, "신청 내역을 찾을 수 없습니다.")

    is_admin = identity["email"] == ADMIN_EMAIL
    is_owner = existing["applicant_email"] == identity["email"]
    if not (is_admin or is_owner):
        conn.close()
        raise HTTPException(403, "본인이 작성한 신청만 수정할 수 있습니다.")

    changes = req.model_dump(exclude_unset=True)
    if "done" in changes and not is_admin:
        conn.close()
        raise HTTPException(403, "완료 처리는 관리자만 할 수 있습니다.")

    if "kyobo_url" not in changes and ("title" in changes or "author" in changes):
        new_title = changes.get("title", existing["title"])
        new_author = changes.get("author", existing["author"])
        changes["kyobo_url"] = resolve_kyobo_url(new_title, new_author)

    if changes:
        sets = ", ".join(f"{k}=?" for k in changes)
        vals = [(1 if v else 0) if k == "done" else v for k, v in changes.items()]
        conn.execute(f"UPDATE requests SET {sets} WHERE id=?", (*vals, rid))
        conn.commit()
    row = conn.execute("SELECT * FROM requests WHERE id=?", (rid,)).fetchone()
    conn.close()
    return row_to_dict(row)


@app.delete("/bookapi/requests/{rid}")
def delete(rid: int, identity: dict = Depends(require_identity)):
    conn = get_db()
    existing = conn.execute("SELECT * FROM requests WHERE id=?", (rid,)).fetchone()
    if existing is None:
        conn.close()
        raise HTTPException(404, "신청 내역을 찾을 수 없습니다.")

    is_admin = identity["email"] == ADMIN_EMAIL
    is_owner = existing["applicant_email"] == identity["email"]
    if not (is_admin or is_owner):
        conn.close()
        raise HTTPException(403, "본인이 작성한 신청만 삭제할 수 있습니다.")

    conn.execute("DELETE FROM requests WHERE id=?", (rid,))
    conn.commit()
    conn.close()
    return {"ok": True}


if __name__ == "__main__":
    import uvicorn
    # host="0.0.0.0" 라야 같은 네트워크의 다른 PC에서 접속됩니다.
    uvicorn.run(app, host="0.0.0.0", port=8000)
