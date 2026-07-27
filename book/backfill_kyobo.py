# -*- coding: utf-8 -*-
"""기존 신청 내역에 kyobo_url을 채우는 1회성 백필 스크립트.
서버(app.py)를 끈 상태에서 실행: python backfill_kyobo.py
"""
import time, sqlite3
from app import resolve_kyobo_url, DB

conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
rows = conn.execute(
    "SELECT id,title,author FROM requests WHERE kyobo_url IS NULL OR kyobo_url=''"
).fetchall()
print(f"대상 {len(rows)}건")
for i, r in enumerate(rows, 1):
    url = resolve_kyobo_url(r["title"], r["author"] or "")
    if url:
        conn.execute("UPDATE requests SET kyobo_url=? WHERE id=?", (url, r["id"]))
        conn.commit()
    print(f"[{i}/{len(rows)}] {r['title']} -> {url}")
    time.sleep(0.7)
conn.close()
print("완료")
