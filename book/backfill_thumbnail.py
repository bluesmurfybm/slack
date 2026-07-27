# -*- coding: utf-8 -*-
"""기존 신청 내역에 표지 thumbnail을 채우는 1회성 백필 스크립트.
서버(app.py)를 끈 상태에서 실행: python backfill_thumbnail.py
"""
import time, sqlite3
from app import search_kakao_books, KAKAO_REST_API_KEY, DB

if not KAKAO_REST_API_KEY:
    print("KAKAO_REST_API_KEY / kakao_keys.json 이 설정되어 있지 않습니다. 백필을 건너뜁니다.")
    raise SystemExit(1)

conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
rows = conn.execute(
    "SELECT id,title,author FROM requests WHERE thumbnail IS NULL OR thumbnail=''"
).fetchall()
print(f"대상 {len(rows)}건")
for i, r in enumerate(rows, 1):
    kw = f"{r['title']} {r['author']}".strip() if r["author"] else r["title"]
    hits = search_kakao_books(kw)
    thumb = hits[0]["thumbnail"] if hits and hits[0].get("thumbnail") else None
    if thumb:
        conn.execute("UPDATE requests SET thumbnail=? WHERE id=?", (thumb, r["id"]))
        conn.commit()
    print(f"[{i}/{len(rows)}] {r['title']} -> {'OK' if thumb else 'None'}")
    time.sleep(0.2)
conn.close()
print("완료")
