"""통계 화면 확인용 임시 데이터. 앱이 떠 있는 상태에서 돌린다.

    python tools/seed_demo.py            데모 12건 생성
    python tools/seed_demo.py --purge    생성했던 데모 건 전부 삭제

실제 전이는 API 로 밟아 이력이 남고, 날짜만 뒤로 밀어 여러 달에 흩뿌린다.
DEMO_TAG 가 붙은 건만 지우므로 진짜 신청은 건드리지 않는다.
"""

import sqlite3
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import requests

from core.config import Settings
from features.identity.auth import make_cookie

sys.stdout.reconfigure(encoding="utf-8")

BASE = "http://127.0.0.1:8002/learningapi"
DEMO_TAG = "[demo] "
ADMIN = ("venus@bluesoft.co.kr", "안정민")
settings = Settings()


def sess(email, name):
    s = requests.Session()
    s.cookies.set("blueiwork_id", make_cookie(settings, email, name, ttl=1800))
    return s


admin = sess(*ADMIN)

FIELDS = ("이메일 이름 플랫폼 대분류 중분류 수준 강의명 수강료 최종상태 신청월 평가")
ROWS = [
    ("kimhy@bluesoft.co.kr", "김호영", "인프런", "AI 기술", "딥러닝 · 머신러닝", "중급",
     "밑바닥부터 시작하는 딥러닝", 132000, "환급완료", "2026-03", 4.5),
    ("siyu@bluesoft.co.kr", "유승인", "패스트캠퍼스", "개발/데이터", "백엔드 개발", "고급",
     "대용량 트래픽 백엔드 설계", 297000, "환급완료", "2026-04", 5.0),
    ("hjlee@bluesoft.co.kr", "이한재", "인프런", "디자인 · 아트", "UX/UI", "초급",
     "실무자를 위한 UX 라이팅", 55000, "환급완료", "2026-04", 3.5),
    ("amitoa@bluesoft.co.kr", "김아랑", "인프런", "기획 · 경영 · 마케팅", "기획 · PM · PO", "중급",
     "PM 을 위한 데이터 리터러시", 88000, "청구승인", "2026-05", 4.0),
    ("lenda83@bluesoft.co.kr", "진소현", "패스트캠퍼스", "AI TECH", "LLM", "고급",
     "LLM 서비스 개발 올인원", 385000, "청구승인", "2026-06", None),
    ("akddd@bluesoft.co.kr", "조성훈", "인프런", "보안 · 네트워크", "클라우드", "중급",
     "AWS 실전 아키텍처", 121000, "수강료청구", "2026-06", None),
    ("phr@bluesoft.co.kr", "박화랑", "인프런", "업무 생산성", "업무 자동화", "초급",
     "노션으로 업무 자동화하기", 44000, "수강료청구", "2026-07", None),
    ("jun0@bluesoft.co.kr", "이준영", "패스트캠퍼스", "영상/3D", "모션그래픽", "초급",
     "애프터이펙트 모션그래픽", 176000, "수강승인", "2026-07", None),
    ("scpark@bluesoft.co.kr", "박성철", "인프런", "개발 · 프로그래밍", "프론트엔드", "중급",
     "리액트 완전 정복", 99000, "수강승인요청", "2026-08", None),
    ("bnmmnbhj@bluesoft.co.kr", "유병문", "인프런", "개발 · 프로그래밍",
     "알고리즘 · 자료구조", "중급", "코딩테스트 실전 대비", 66000, "수강반려", "2026-08", None),
    ("kimhy@bluesoft.co.kr", "김호영", "인프런", "외국어", "영어", "초급",
     "비즈니스 영어 이메일", 0, "무료", "2026-05", 4.0),
    ("siyu@bluesoft.co.kr", "유승인", "패스트캠퍼스", "금융/투자", "재무/회계/세무", "초급",
     "회계 기초 완성", 132000, "환급불필요", "2026-06", None),
]


def make(row):
    email, name, site, large, medium, level, title, price, final, month, rating = row
    user = sess(email, name)
    body = {
        "site": site, "category_large": large, "category_medium": medium, "level": level,
        "title": DEMO_TAG + title, "url": "", "duration_min": 60 * (3 + len(title) % 9),
        "is_free": final == "무료", "price": 0 if final == "무료" else price,
        "account_type": "회사계정" if final == "환급불필요" else "개인계정",
        "start_date": f"{month}-05", "end_date": f"{month}-25",
    }
    r = user.post(f"{BASE}/requests", json=body)
    if r.status_code != 201:
        print("  실패:", title, r.status_code, r.text[:120])
        return None
    rid = r.json()["id"]

    if final in ("수강승인", "수강료청구", "청구승인", "환급완료", "환급불필요"):
        admin.post(f"{BASE}/requests/{rid}/approve")
    if final == "수강반려":
        admin.post(f"{BASE}/requests/{rid}/reject", json={"reason": "업무 연관성이 낮습니다"})
    if final in ("수강료청구", "청구승인", "환급완료"):
        add_cert(rid)
        user.post(f"{BASE}/requests/{rid}/claim")
    if final in ("청구승인", "환급완료"):
        admin.post(f"{BASE}/requests/{rid}/claim-approve")
    if final == "환급완료":
        admin.post(f"{BASE}/requests/{rid}/refund")
    if rating is not None:
        user.post(f"{BASE}/requests/{rid}/review", json={"rating": rating,
                                                         "recommend": min(5.0, rating + 0.5)})
    return rid, month


def add_cert(rid):
    c = sqlite3.connect(settings.db_path, timeout=10)
    c.execute("insert into learning_certs(request_id,name,path,uploaded_by,created_at)"
              " values(?,?,?,?,datetime('now'))",
              (rid, "이수증.png", f"{rid}_demo.png", "demo"))
    c.commit()
    c.close()


STAMPS = ["created_at", "approved_at", "rejected_at", "claimed_at",
          "claim_approved_at", "claim_rejected_at", "refunded_at", "progress_at"]


def backdate(rid, month):
    """오늘 찍힌 시각을 신청 월로 민다 — 월별 차트가 한 달에 몰리지 않게."""
    c = sqlite3.connect(settings.db_path, timeout=10)
    for i, col in enumerate(STAMPS):
        # 앞 10글자(YYYY-MM-DD)만 갈아끼운다 — substr 은 1부터라 시각은 11번째부터다.
        # col 은 STAMPS 상수에서만 오므로 외부 입력이 SQL 로 들어갈 자리가 없다.
        sql = (f"update learning_requests set {col} = ? || substr({col}, 11) "  # noqa: S608
               f"where id = ? and {col} <> ''")
        c.execute(
            sql,
            (f"{month}-{(rid * 2 + i) % 25 + 1:02d}", rid))
    c.commit()
    c.close()


def purge():
    rows = admin.get(f"{BASE}/requests").json()
    hit = [r for r in rows if r["title"].startswith(DEMO_TAG)]
    for r in hit:
        admin.delete(f"{BASE}/requests/{r['id']}")
    print(f"데모 {len(hit)}건 삭제")


if "--purge" in sys.argv:
    purge()
    raise SystemExit(0)

made = []
for row in ROWS:
    out = make(row)
    if out:
        backdate(*out)
        made.append(out[0])
print(f"{len(made)}건 생성 — id {made}")

body = admin.get(f"{BASE}/requests").json()
print("상태 분포:", dict(Counter(r["status"] or "무료" for r in body)))
print("월 분포  :", dict(sorted(Counter(r["created_at"][:7] for r in body).items())))
