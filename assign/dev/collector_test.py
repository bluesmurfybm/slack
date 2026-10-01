# -*- coding: utf-8 -*-
"""수집기 검증 — dry-run 출력과 실제 적재 결과를 seed_slack.sql 의 기댓값과 대조한다.

    python assign/dev/collector_test.py

seed_slack.sql 을 먼저 적재해 두어야 합니다. 이 스크립트가 알아서 다시 넣습니다.
"""

from __future__ import annotations

import io
import subprocess
import sys
from pathlib import Path

import pymysql

HERE = Path(__file__).resolve().parent
COLLECTOR = HERE.parent / "collector"
MYSQL = Path("C:/wamp64/bin/mysql/mysql5.7.26/bin/mysql.exe")

DB = dict(host="127.0.0.1", port=3306, user="root", password="",
          database="iworks_local", charset="utf8mb4",
          cursorclass=pymysql.cursors.DictCursor)

# 윈도우 콘솔 기본 코드페이지(cp949)는 '—' 같은 글자를 못 찍는다. 그것 때문에
# 시험이 통째로 죽는 일이 있어서, 찍을 수 없는 글자는 대체 문자로 흘려보낸다.
# 표준 출력만 건드린다 — 저장되는 값에는 손대지 않는다.
for _s in (sys.stdout, sys.stderr):
    try:
        _s.reconfigure(errors="replace")
    except (AttributeError, ValueError):
        pass

_pass = 0
_fail = 0


def ok(what: str, cond: bool, extra: str = "") -> None:
    global _pass, _fail
    if cond:
        _pass += 1
        print(f"  OK   {what}")
    else:
        _fail += 1
        print(f"  FAIL {what}" + (f" -- {extra}" if extra else ""))


def section(s: str) -> None:
    print(f"\n{s}")


def reseed() -> None:
    sql = (HERE / "seed_slack.sql").read_text(encoding="utf-8")
    p = subprocess.run([str(MYSQL), "-u", "root", "--default-character-set=utf8mb4",
                        "iworks_local"],
                       input=sql.encode("utf-8"), capture_output=True)
    if p.returncode != 0:
        sys.exit("시드 적재 실패: " + p.stderr.decode("utf-8", "replace"))


def run_collector(*args: str) -> tuple[int, str]:
    """수집기를 돌리고 (종료코드, 출력) 을 돌려준다. 출력은 제대로 디코딩한다."""
    p = subprocess.run([sys.executable, "collect_slack.py", *args],
                       cwd=str(COLLECTOR), capture_output=True)
    # 파이썬이 콘솔/파이프 인코딩으로 썼으므로 그 인코딩으로 읽는다.
    raw = p.stdout + p.stderr
    for enc in ("cp949", "utf-8"):
        try:
            return p.returncode, raw.decode(enc)
        except UnicodeDecodeError:
            continue
    return p.returncode, raw.decode("utf-8", "replace")


def q(sql: str, params=()) -> list[dict]:
    conn = pymysql.connect(**DB)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            return cur.fetchall()
    finally:
        conn.close()


def one(sql: str, params=()):
    rows = q(sql, params)
    return list(rows[0].values())[0] if rows else None


# =====================================================================
print("\n수집기 검증")
print("=" * 62)
reseed()

# ---------------------------------------------------------------------
section("[A] dry-run — 적재하지 않는다")
code, out = run_collector("--dry-run", "--from", "2026-09-01", "--to", "2026-09-30")
ok("정상 종료", code == 0, f"exit={code}")
ok("DRY-RUN 표시", "DRY-RUN" in out)
ok("적재 안 했다고 안내", "적재하지 않았습니다" in out)
ok("ba_work_item 비어 있음", one("SELECT COUNT(*) FROM ba_work_item") == 0)
ok("ba_sync_log 도 비어 있음", one("SELECT COUNT(*) FROM ba_sync_log") == 0)

section("[B] dry-run 이 거르는 것")
ok("12건 추출 (빈제목·와이오즈·기간밖 제외)", "requests 12건" in out, out[:200])
ok("빈 제목 건 제외", "만들다 만" not in out)
ok("와이오즈 보드 제외", "와이오즈 리스트 항목" not in out)
ok("기간 밖(6월) 건 제외", "지난 분기 통계" not in out)
ok("보관 항목은 포함", "게시판 첨부파일" in out)

section("[C] dry-run 표 내용")
ok("기관명 표준화 (한기대 -> 한국기술교육대학교)", "한국기술교육대" in out)
ok("LMS 링크로만 기관 식별 (충북대)", "충북대학교" in out)
ok("미매칭 담당자에 ! 표시", "박모름" in out and "!" in out)
ok("미매칭 담당자를 따로 안내", "member_aliases.json 에 추가" in out)
ok("요약에 매칭 11건", "11건" in out)
ok("난이도 별표 표시", "★" in out)

section("[D] --limit 동작")
code, out2 = run_collector("--dry-run", "--from", "2026-09-01", "--to", "2026-09-30", "--limit", "3")
ok("3건만 보여줌", "그 외 9건" in out2, out2[out2.find("그 외"):][:40] if "그 외" in out2 else "없음")

# ---------------------------------------------------------------------
section("[E] 실제 적재")
code, out = run_collector("--from", "2026-09-01", "--to", "2026-09-30")
ok("정상 종료", code == 0, out[-300:] if code else "")
n = one("SELECT COUNT(*) FROM ba_work_item")
ok("12건 적재", n == 12, f"{n}건")
ok("전부 slack 원천", one("SELECT COUNT(*) FROM ba_work_item WHERE source='slack'") == 12)

section("[F] 필드 매핑")
r = q("SELECT * FROM ba_work_item WHERE source_key='R001'")[0]
ok("source_key = requests.id", r["source_key"] == "R001")
ok("source_url 에 record_id", "record_id=R001" in (r["source_url"] or ""))
ok("기관명 표준화됨", r["org_name"] == "한국기술교육대학교", str(r["org_name"]))
ok("담당자 연결됨", r["member_id"] is not None)
ok("msg_count = cmt_count", r["msg_count"] == 7, str(r["msg_count"]))
ok("body_len 계산됨", r["body_len"] > 0)
ok("closed_at = done", str(r["closed_at"])[:10] == "2026-09-12", str(r["closed_at"]))
ok("status_raw 원문 보존", r["status_raw"] == "완료", str(r["status_raw"]))

r2 = q("SELECT * FROM ba_work_item WHERE source_key='R002'")[0]
ok("ai_stars 있으면 difficulty_by=llm", r2["difficulty_by"] == "llm" and r2["difficulty"] == 4,
   f"{r2['difficulty_by']}/{r2['difficulty']}")
ok("개발서버반영 -> dev_deployed_at 채움", r2["dev_deployed_at"] is not None)
ok("아직 운영 아님 -> prod_deployed_at 비움", r2["prod_deployed_at"] is None)
ok("LMS 링크로 기관 식별", r2["org_name"] == "충북대학교", str(r2["org_name"]))

r3 = q("SELECT * FROM ba_work_item WHERE source_key='R003'")[0]
ok("운영서버반영 -> prod_deployed_at 채움", r3["prod_deployed_at"] is not None)
ok("난이도 5 (ai_stars)", r3["difficulty"] == 5)

r4 = q("SELECT * FROM ba_work_item WHERE source_key='R004'")[0]
ok("ai_stars 없으면 difficulty_by=rule", r4["difficulty_by"] == "rule")
ok("단순 작업은 난이도 낮음(<=2)", r4["difficulty"] <= 2, str(r4["difficulty"]))

r5 = q("SELECT * FROM ba_work_item WHERE source_key='R005'")[0]
ok("마이그레이션은 난이도 높음(>=4)", r5["difficulty"] >= 4, str(r5["difficulty"]))

section("[G] 담당자 매칭")
ok("미매칭 건은 member_id NULL",
   q("SELECT member_id FROM ba_work_item WHERE source_key='R006'")[0]["member_id"] is None)
ok("미매칭은 1건뿐", one("SELECT COUNT(*) FROM ba_work_item WHERE member_id IS NULL") == 1)
r7 = q("SELECT member_id FROM ba_work_item WHERE source_key='R007'")[0]
ok("local_assignments 가 슬랙 '-' 를 이김", r7["member_id"] is not None)
pk = one("SELECT emp_name FROM ba_member WHERE id=%s", (r7["member_id"],))
ok("R007 담당자는 박성철", pk == "박성철", str(pk))
ok("미매칭 로그 파일 생성", (COLLECTOR / "unmatched_members.log").is_file())

section("[H] 기관명")
ok("기관 식별 9건", one("SELECT COUNT(*) FROM ba_work_item WHERE org_name IS NOT NULL") == 9,
   str(one("SELECT COUNT(*) FROM ba_work_item WHERE org_name IS NOT NULL")))
ok("단서 없으면 NULL (지어내지 않음)",
   q("SELECT org_name FROM ba_work_item WHERE source_key='R008'")[0]["org_name"] is None)
names = {r["org_name"] for r in q("SELECT DISTINCT org_name FROM ba_work_item WHERE org_name IS NOT NULL")}
ok("'한기대'와 '한국기술교육대'가 한 이름으로 합쳐짐",
   "한국기술교육대학교" in names and "한기대" not in names, str(names))

section("[I] 분야 태깅")
tagged = one("SELECT COUNT(DISTINCT work_item_id) FROM ba_work_item_domain")
ok("대부분 분야가 붙음", tagged >= 10, f"{tagged}건")
sso = q("""SELECT d.code FROM ba_work_item_domain wd
             JOIN ba_domain d ON d.id = wd.domain_id
             JOIN ba_work_item w ON w.id = wd.work_item_id
            WHERE w.source_key='R003'""")
ok("SSO 건에 sso 분야", "sso" in {x["code"] for x in sso}, str({x["code"] for x in sso}))
att = q("""SELECT d.code FROM ba_work_item_domain wd
             JOIN ba_domain d ON d.id = wd.domain_id
             JOIN ba_work_item w ON w.id = wd.work_item_id
            WHERE w.source_key='R001'""")
codes = {x["code"] for x in att}
ok("출석부+성적부 건은 여러 분야", len(codes) >= 2, str(codes))
ok("  그중 attendance 포함", "attendance" in codes, str(codes))
conf = one("SELECT MAX(confidence) FROM ba_work_item_domain")
ok("confidence 가 0~1 범위", 0 < float(conf) <= 1, str(conf))

section("[J] ba_sync_log")
log = q("SELECT * FROM ba_sync_log ORDER BY id DESC LIMIT 1")[0]
ok("기록 남음", log["source"] == "slack")
ok("status=ok", log["status"] == "ok", str(log["status"]))
ok("fetched=12", log["fetched"] == 12, str(log["fetched"]))
ok("inserted=12", log["inserted"] == 12, str(log["inserted"]))
ok("finished_at 채워짐", log["finished_at"] is not None)

section("[K] 재실행 — 중복 적재 안 함 (upsert)")
code, out = run_collector("--from", "2026-09-01", "--to", "2026-09-30")
ok("정상 종료", code == 0)
ok("건수 그대로 12건", one("SELECT COUNT(*) FROM ba_work_item") == 12,
   str(one("SELECT COUNT(*) FROM ba_work_item")))
log2 = q("SELECT * FROM ba_sync_log ORDER BY id DESC LIMIT 1")[0]
ok("신규 0건", log2["inserted"] == 0, str(log2["inserted"]))
ok("sync_log 두 줄", one("SELECT COUNT(*) FROM ba_sync_log") == 2)

section("[L] 손으로 고친 난이도는 지키는가")
conn = pymysql.connect(**DB)
with conn.cursor() as cur:
    cur.execute("UPDATE ba_work_item SET difficulty=1, difficulty_by='manual' WHERE source_key='R005'")
conn.commit()
conn.close()
run_collector("--from", "2026-09-01", "--to", "2026-09-30")
r5b = q("SELECT difficulty, difficulty_by FROM ba_work_item WHERE source_key='R005'")[0]
ok("manual 난이도는 덮어쓰지 않음",
   r5b["difficulty"] == 1 and r5b["difficulty_by"] == "manual",
   f"{r5b['difficulty']}/{r5b['difficulty_by']}")

section("[M] 기간 파라미터")
reseed()
run_collector("--from", "2026-06-01", "--to", "2026-06-30")
ok("6월만 뽑으면 1건", one("SELECT COUNT(*) FROM ba_work_item") == 1,
   str(one("SELECT COUNT(*) FROM ba_work_item")))
ok("그 1건은 R015", one("SELECT source_key FROM ba_work_item") == "R015")

code, out = run_collector("--from", "2026-09-30", "--to", "2026-09-01")
ok("기간 뒤집히면 거부", code != 0 and "뒤집" in out, f"exit={code}")

code, out = run_collector("--from", "2026-13-99")
ok("잘못된 날짜 거부", code != 0)

section("[N] 실패 시 롤백")
reseed()
conn = pymysql.connect(**DB)
with conn.cursor() as cur:
    # 적재 도중 반드시 실패하도록 컬럼을 좁힌다(status_raw 는 60자였다).
    cur.execute("ALTER TABLE ba_work_item MODIFY status_raw VARCHAR(3) NOT NULL")
conn.commit()
conn.close()

code, out = run_collector("--from", "2026-09-01", "--to", "2026-09-30")
ok("실패로 종료", code != 0, f"exit={code}")
ok("롤백 안내 출력", "되돌렸습니다" in out, out[-200:])
ok("부분 적재 없음", one("SELECT COUNT(*) FROM ba_work_item") == 0,
   str(one("SELECT COUNT(*) FROM ba_work_item")))
flog = q("SELECT * FROM ba_sync_log ORDER BY id DESC LIMIT 1")
ok("실패도 sync_log 에 남음", bool(flog) and flog[0]["status"] == "fail",
   str(flog[0]["status"]) if flog else "없음")

conn = pymysql.connect(**DB)
with conn.cursor() as cur:
    cur.execute("ALTER TABLE ba_work_item MODIFY status_raw VARCHAR(60) NULL")
conn.commit()
conn.close()

# ---------------------------------------------------------------------
# 뒷정리 — 마지막 시험이 롤백 시험이라 ba_work_item 이 **빈 채로** 끝난다.
# 그대로 두면 다음 사람이 "수집한 데이터가 사라졌다" 로 마주치게 된다.
#
# seed_slack.sql 은 슬랙 쪽 원본 표(requests 등)만 채우고 ba_work_item 은
# 지우기만 한다 — 그 표는 수집기가 채우는 것이라서다. 그래서 시드만 다시
# 깔면 여전히 비어 있다. 수집기를 한 번 더 돌려야 온전한 상태가 된다.
reseed()
_code, _ = run_collector("--from", "2026-09-01", "--to", "2026-09-30")
_n = one("SELECT COUNT(*) FROM ba_work_item")
print(f"\n뒷정리: 시드 재적재 후 ba_work_item {_n}건" + ("" if _code == 0 else f" (수집기 exit={_code})"))

print("\n" + "=" * 62)
print(f"통과 {_pass} / 실패 {_fail}")

# 이 시험은 ba_work_item 을 통째로 갈아 끼운다(seed_slack.sql 의 DELETE).
# 반면 점수표(ba_member_metric / ba_member_category)는 건드리지 않으므로,
# 시험 뒤에는 **점수는 예전 데이터 기준인데 근거는 시드 데이터**인 상태가 된다.
# 화면상 점수가 그럴듯해 보여서 알아채기 어렵다. 그래서 크게 적어 둔다.
print(
    "\n[주의] 이 시험은 ba_work_item 을 시드 데이터로 갈아 끼웠습니다.\n"
    "       운영에서 받아 온 업무 이력이 있었다면 지워졌습니다.\n"
    "       역량 점수(ba_member_metric)는 그대로라 지금은 근거와 어긋납니다.\n"
    "       실제 데이터로 되돌리려면:\n"
    "         python collector/collect_slack.py --config config.pull.ini --from <날짜> --to <날짜>\n"
    "         python collector/score.py --config config.pull.ini\n"
)
sys.exit(1 if _fail else 0)
