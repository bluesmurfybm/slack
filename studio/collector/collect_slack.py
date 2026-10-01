# -*- coding: utf-8 -*-
"""슬랙 취합 시스템(requests)의 업무 이력을 bs_work_item 으로 적재한다.

┌──────────────────────────────────────────────────────────────────────┐
│ 왜 HTML 파싱이 아닌가                                                  │
│                                                                      │
│ 취합 시스템과 BlueStudio 는 **같은 DB** 를 쓴다.                       │
│   - slack/db.php 가 포털 config.php 의 db 설정을 그대로 읽는다         │
│   - core/db.php 주석: "slack 모듈과 같은 slackapi DB를 그대로 씀"      │
│                                                                      │
│ 그래서 lists.php 를 긁을 이유가 없다. 파싱으로는 못 얻는 것도 많다 —    │
│ 본문(body), 댓글 수(cmt_count), 갱신 unixtime, 이미 채점된 난이도      │
│ (ai_stars), 슬랙 사용자 ID(asg_id) 는 화면에 다 나오지 않는다.         │
└──────────────────────────────────────────────────────────────────────┘

사용법
    python collect_slack.py --dry-run              최근 7일, 콘솔 표로만
    python collect_slack.py --from 2026-01-01 --to 2026-03-31
    python collect_slack.py --days 30
    python collect_slack.py --config /path/config.ini

먼저 --dry-run 으로 눈으로 확인한 뒤 실제 적재하십시오.
"""

from __future__ import annotations

import argparse
import configparser
import sys
import unicodedata
from datetime import date, datetime, timedelta
from pathlib import Path

try:
    import pymysql
except ImportError:
    sys.exit("PyMySQL 이 필요합니다:  pip install PyMySQL")

from normalize import DomainTagger, MemberResolver, OrgNormalizer, rule_difficulty

HERE = Path(__file__).resolve().parent

# ---------------------------------------------------------------------
# 콘솔 출력 보호
#
# 윈도 콘솔 기본 코드페이지는 cp949 인데, 여기에 없는 글자가 섞이면
# UnicodeEncodeError 로 **프로그램이 죽는다.** 실제로 죽었다 —
# requests.asg 의 기본값이 '—'(em dash) 이고 cp949 에 그 글자가 없다.
#
# 인코딩 자체는 콘솔 것을 그대로 둔다(한글은 cp949 로 잘 나온다).
# 못 쓰는 글자만 '?' 로 바꿔 최소한 죽지는 않게 한다.
# UTF-8 로 보고 싶으면 실행 전에  chcp 65001  하면 된다.
# ---------------------------------------------------------------------
for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(errors="replace")
    except (AttributeError, ValueError):
        pass    # 파이프로 넘길 때 등 — 그냥 둔다

# requests.status 중 '개발서버 반영됨' 으로 볼 값들
DEV_DEPLOYED = {"확인요청(개발서버반영)", "확인요청(검토완료)"}
# '운영서버 반영됨' 으로 볼 값들
PROD_DEPLOYED = {"확인요청(운영서버반영)", "운영배포요청", "완료"}
# 끝난 것으로 볼 값들
CLOSED = {"완료", "처리불가"}


# =====================================================================
# 화면 출력
# =====================================================================

def _w(s: str) -> int:
    """한글은 두 칸을 차지한다. 표를 맞추려면 글자 수가 아니라 칸 수를 세야 한다."""
    return sum(2 if unicodedata.east_asian_width(c) in "WF" else 1 for c in str(s))


def _pad(s: str, width: int) -> str:
    s = str(s)
    return s + " " * max(0, width - _w(s))


# cp949 콘솔이 못 쓰는 글자 → 보기용 대체. **적재값은 건드리지 않는다.**
# em dash 는 requests.asg 의 기본값이라 거의 모든 미지정 건에 들어 있다.
_DISPLAY_FIX = {"—": "-", "–": "-", "✗": "x", "✓": "v"}


def _cut(s: str | None, width: int) -> str:
    """칸 수 기준으로 자른다. 잘린 것은 … 로 표시."""
    s = "" if s is None else str(s).replace("\n", " ").replace("\r", " ").strip()
    for _bad, _good in _DISPLAY_FIX.items():
        s = s.replace(_bad, _good)
    if _w(s) <= width:
        return s
    out, acc = "", 0
    for ch in s:
        cw = 2 if unicodedata.east_asian_width(ch) in "WF" else 1
        if acc + cw > width - 1:
            break
        out += ch
        acc += cw
    return out + "…"


class Log:
    LEVELS = {"debug": 0, "info": 1, "warn": 2}

    def __init__(self, level: str = "info"):
        self.level = self.LEVELS.get(level, 1)

    def debug(self, m: str) -> None:
        if self.level <= 0:
            print(f"  · {m}")

    def info(self, m: str) -> None:
        if self.level <= 1:
            print(m)

    def warn(self, m: str) -> None:
        print(f"  ! {m}", file=sys.stderr)


# =====================================================================
# 설정
# =====================================================================

def load_config(path: Path) -> configparser.ConfigParser:
    if not path.is_file():
        sys.exit(f"설정 파일이 없습니다: {path}\n"
                 f"config.sample.ini 를 config.ini 로 복사해 작성하세요.")
    cp = configparser.ConfigParser()
    # 기관명·비밀번호에 한글이나 특수문자가 들어갈 수 있다
    with path.open(encoding="utf-8") as f:
        cp.read_file(f)
    warn_placeholder_list_url(cp, path)
    return cp


# config.sample.ini 가 들고 있는 가짜 팀/리스트 ID. 아무도 안 고치면 그대로
# 운영까지 따라간다.
DUMMY_LIST_IDS = ("T0000000", "F0000000")


def warn_placeholder_list_url(cp: configparser.ConfigParser, path: Path) -> None:
    """list_url 이 아직 표본 값이면 크게 알린다.

    source_url 은 `{list_url}?record_id={id}` 로 만들어 bs_work_item 에 그대로
    저장된다. 표본 값인 채로 수집하면 **열리지 않는 링크가 DB 에 쌓인다.**
    나중에 고쳐도 이미 적재된 행은 되돌아오지 않는다 — 다시 수집해야 한다.

    그래서 멈추지 않고 경고만 한다. 로컬 개발은 이 값이 가짜인 채로 돌아가야
    하고(시험이 그 전제로 쓰여 있다), 운영에서는 사람이 봐야 하기 때문이다.
    진짜 값은 포털 config.php 의 list_url 과 같다.
    """
    url = cp["source"].get("list_url", "").strip() if cp.has_section("source") else ""
    if not url:
        print(f"[경고] {path.name}: [source] list_url 이 비어 있습니다. "
              f"source_url 없이 적재합니다.", file=sys.stderr)
        return
    if any(d in url for d in DUMMY_LIST_IDS):
        print(f"[경고] {path.name}: [source] list_url 이 아직 표본 값입니다: {url}",
              file=sys.stderr)
        print("        이대로 적재하면 열리지 않는 source_url 이 쌓입니다. "
              "운영에서는 반드시 실제 값으로 바꾸십시오 "
              "(포털 config.php 의 list_url 과 같은 값).", file=sys.stderr)


def _open(sec) -> "pymysql.connections.Connection":
    return pymysql.connect(
        host=sec.get("host", "127.0.0.1"),
        port=sec.getint("port", 3306),
        user=sec.get("user", "root"),
        password=sec.get("password", ""),
        database=sec.get("name"),
        charset=sec.get("charset", "utf8mb4"),
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,      # 트랜잭션은 우리가 직접 연다
    )


def connect(cfg: configparser.ConfigParser):
    """적재할 곳(bs_* 를 쓰는 DB)."""
    return _open(cfg["db"])


def connect_source(cfg: configparser.ConfigParser):
    """
    원천(requests 를 읽을 곳).

    운영에서는 취합 시스템과 BlueStudio 가 **같은 DB** 라 [db] 하나면 됩니다.
    개발·분석 중에는 다릅니다 — 운영 requests 를 읽되 bs_* 는 로컬에 적재해야
    합니다(운영에 BlueStudio 가 아직 배포되지 않았고, 읽기 전용 계정이라
    쓰지도 못합니다). 그때만 [db_source] 를 둡니다.

    [db_source] 가 없으면 [db] 를 그대로 씁니다.
    """
    return _open(cfg["db_source"]) if "db_source" in cfg else None


# =====================================================================
# 읽기
# =====================================================================

def fetch_requests(conn, cfg, dfrom: date, dto: date, log: Log) -> list[dict]:
    """기간에 걸치는 requests 행을 읽는다.

    기간 기준은 '요청일(date)' 이되, 비어 있으면 생성 시각(created, unixtime)을 본다.
    슬랙에서 날짜 칸을 비워 두는 경우가 흔해서, date 만 보면 통째로 빠진다.
    """
    src = cfg["source"]
    boards = [b.strip() for b in src.get("boards", "블루소프트").split(",") if b.strip()]
    if not boards:
        sys.exit("[source] boards 가 비어 있습니다.")

    where = ["(%s)" % " OR ".join(["board = %s"] * len(boards))]
    params: list = list(boards)

    # 기간: date 가 있으면 date, 없으면 created 로 판단
    where.append(
        "( (`date` IS NOT NULL AND `date` BETWEEN %s AND %s)"
        "  OR (`date` IS NULL AND `created` BETWEEN %s AND %s) )"
    )
    params += [
        dfrom.isoformat(), dto.isoformat(),
        int(datetime.combine(dfrom, datetime.min.time()).timestamp()),
        int(datetime.combine(dto, datetime.max.time()).timestamp()),
    ]

    if not src.getboolean("include_archived", True):
        where.append("archived = 0")
    if src.getboolean("skip_empty_title", True):
        where.append("TRIM(title) <> ''")

    sql = f"""
        SELECT id, title, body, lms, momo, req, req_id, asg, asg_id,
               status, priority, team, board, archived,
               cmt_count, `date`, done, eta, created, updated,
               ai_stars, ai_conf
          FROM requests
         WHERE {' AND '.join(where)}
         ORDER BY created ASC
    """
    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()

    log.info(f"  requests {len(rows)}건 (보드: {', '.join(boards)})")
    return rows


def fetch_local_assignments(conn) -> dict[str, str]:
    """사이트에서 직접 배정한 담당자. 슬랙의 asg 보다 이쪽이 최신일 수 있다."""
    with conn.cursor() as cur:
        cur.execute("SELECT request_id, assignee FROM local_assignments")
        return {r["request_id"]: r["assignee"] for r in cur.fetchall()}


def fetch_schools(conn, log: Log) -> list[dict]:
    """대학 사이트 목록. 없는 설치본도 있으므로 실패해도 넘어간다."""
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT name, ver, dev, ops, log FROM schools WHERE active = 1")
            rows = cur.fetchall()
        log.debug(f"schools {len(rows)}건 참조")
        return rows
    except pymysql.Error as e:
        log.debug(f"schools 표를 읽지 못해 건너뜁니다 ({e.args[0]})")
        return []


def fetch_members(conn) -> list[dict]:
    with conn.cursor() as cur:
        cur.execute("SELECT id, user_id, emp_name FROM bs_member")
        return cur.fetchall()


def fetch_domains(conn) -> list[dict]:
    with conn.cursor() as cur:
        cur.execute("SELECT id, code, name, keywords FROM bs_domain WHERE is_active = 1")
        return cur.fetchall()


# =====================================================================
# 변환
# =====================================================================

def _ts(unix: int | None) -> datetime | None:
    if not unix:
        return None
    try:
        return datetime.fromtimestamp(int(unix))
    except (ValueError, OSError, OverflowError):
        return None


def to_work_item(r: dict, cfg, orgs: OrgNormalizer, members: MemberResolver,
                 local_asg: dict[str, str]) -> dict:
    """requests 한 행 → bs_work_item 한 행."""
    status = (r.get("status") or "").strip()
    updated = _ts(r.get("updated"))
    created = _ts(r.get("created"))

    # 담당자: 사이트에서 직접 배정한 값이 있으면 그쪽을 먼저 본다.
    asg_name = local_asg.get(r["id"]) or r.get("asg")
    m = members.resolve(asg_name, r.get("asg_id"))

    # 요청일
    requested_at = None
    if r.get("date"):
        requested_at = datetime.combine(r["date"], datetime.min.time())
    elif created:
        requested_at = created

    # ── 시점들 ────────────────────────────────────────────────────────
    # requests 는 **현재 상태만** 들고 있고 상태 변경 이력이 없다.
    # 그래서 과거 시점을 정확히 복원할 수 없다. 지금 상태가 어디까지 갔는지로
    # 추정하고, 값은 마지막 갱신 시각을 쓴다. 이 한계는 README 에 적어 두었다.
    dev_at = updated if status in DEV_DEPLOYED or status in PROD_DEPLOYED else None
    prod_at = updated if status in PROD_DEPLOYED else None

    closed_at = None
    if r.get("done"):
        closed_at = datetime.combine(r["done"], datetime.min.time())
    elif status in CLOSED:
        closed_at = updated

    # ── 난이도 ────────────────────────────────────────────────────────
    # 이미 채점된 값(ai_stars)이 있으면 그걸 쓴다. 화면(difficulty.php)도
    # ai_stars 를 먼저 보므로, 같은 건이 화면과 DB 에서 다르게 보이지 않는다.
    if r.get("ai_stars"):
        difficulty, diff_by = int(r["ai_stars"]), "llm"
    else:
        difficulty = rule_difficulty(r.get("title"), r.get("body"), r.get("team"))
        diff_by = "rule"

    body = r.get("body") or ""
    list_url = cfg["source"].get("list_url", "").strip()

    return {
        "source": "slack",
        "source_key": r["id"],
        "source_url": f"{list_url}?record_id={r['id']}" if list_url else None,
        "title": (r.get("title") or "")[:400],
        "body_excerpt": body[:2000] or None,
        "org_name": orgs.resolve(r.get("lms"), r.get("title"), body),
        "member_id": m.member_id,
        "requested_at": requested_at,
        "first_reply_at": None,      # 이력이 없어 복원 불가 (README 참고)
        "dev_deployed_at": dev_at,
        "prod_deployed_at": prod_at,
        "closed_at": closed_at,
        "status_raw": status[:60],
        "reopen_count": 0,           # 이력이 없어 복원 불가
        "msg_count": int(r.get("cmt_count") or 0),
        "body_len": len(body),
        "difficulty": difficulty,
        "difficulty_by": diff_by,
        # 화면 표시용 (적재하지 않는다)
        "_asg_raw": asg_name,
        "_match": m.reason,
        "_team": r.get("team"),
    }


# =====================================================================
# 쓰기
# =====================================================================

UPSERT = """
INSERT INTO bs_work_item
  (source, source_key, source_url, title, body_excerpt, org_name, member_id,
   requested_at, first_reply_at, dev_deployed_at, prod_deployed_at, closed_at,
   status_raw, reopen_count, msg_count, body_len, difficulty, difficulty_by,
   collected_at)
VALUES
  (%(source)s, %(source_key)s, %(source_url)s, %(title)s, %(body_excerpt)s,
   %(org_name)s, %(member_id)s, %(requested_at)s, %(first_reply_at)s,
   %(dev_deployed_at)s, %(prod_deployed_at)s, %(closed_at)s, %(status_raw)s,
   %(reopen_count)s, %(msg_count)s, %(body_len)s, %(difficulty)s,
   %(difficulty_by)s, NOW())
ON DUPLICATE KEY UPDATE
  source_url       = VALUES(source_url),
  title            = VALUES(title),
  body_excerpt     = VALUES(body_excerpt),
  org_name         = VALUES(org_name),
  member_id        = VALUES(member_id),
  requested_at     = VALUES(requested_at),
  dev_deployed_at  = VALUES(dev_deployed_at),
  prod_deployed_at = VALUES(prod_deployed_at),
  closed_at        = VALUES(closed_at),
  status_raw       = VALUES(status_raw),
  msg_count        = VALUES(msg_count),
  body_len         = VALUES(body_len),
  -- 사람이 손으로 고친 난이도(manual)는 덮지 않는다.
  difficulty       = IF(difficulty_by = 'manual', difficulty, VALUES(difficulty)),
  difficulty_by    = IF(difficulty_by = 'manual', difficulty_by, VALUES(difficulty_by)),
  collected_at     = NOW()
"""


def upsert_items(conn, items: list[dict], tagger: DomainTagger, log: Log) -> dict:
    """적재. 호출부가 트랜잭션을 연다 — 여기서 커밋하지 않는다."""
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "tagged": 0}

    with conn.cursor() as cur:
        for it in items:
            payload = {k: v for k, v in it.items() if not k.startswith("_")}
            cur.execute(UPSERT, payload)
            # PyMySQL 의 rowcount: 새로 넣으면 1, 값이 바뀌면 2, 그대로면 0
            if cur.rowcount == 1:
                stats["inserted"] += 1
            elif cur.rowcount == 2:
                stats["updated"] += 1
            else:
                stats["skipped"] += 1

            # 방금 넣거나 고친 행의 id
            cur.execute(
                "SELECT id FROM bs_work_item WHERE source = %s AND source_key = %s",
                (it["source"], it["source_key"]),
            )
            row = cur.fetchone()
            if not row:
                continue
            wid = row["id"]

            # 분야 태깅 — 통째로 갈아 끼운다. 키워드가 바뀌면 결과도 바뀌어야 한다.
            tags = tagger.tag(it["title"], it["body_excerpt"])
            cur.execute("DELETE FROM bs_work_item_domain WHERE work_item_id = %s", (wid,))
            for domain_id, conf in tags.items():
                cur.execute(
                    "INSERT INTO bs_work_item_domain (work_item_id, domain_id, confidence)"
                    " VALUES (%s, %s, %s)",
                    (wid, domain_id, conf),
                )
            stats["tagged"] += len(tags)

    return stats


def start_sync(conn) -> int:
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO bs_sync_log (source, started_at, status) VALUES ('slack', NOW(), 'running')"
        )
        return cur.lastrowid


def finish_sync(conn, sync_id: int, counts: dict, status: str, message: str | None) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE bs_sync_log SET finished_at = NOW(), fetched = %s, inserted = %s,"
            " updated = %s, skipped = %s, status = %s, message = %s WHERE id = %s",
            (counts.get("fetched", 0), counts.get("inserted", 0),
             counts.get("updated", 0), counts.get("skipped", 0),
             status, message, sync_id),
        )


# =====================================================================
# dry-run 출력
# =====================================================================

def print_table(items: list[dict], limit: int, tagger: DomainTagger,
                domains_by_id: dict[int, str]) -> None:
    cols = [
        ("등록일", 10), ("기관", 16), ("담당", 8), ("상태", 20),
        ("난이도", 6), ("분야", 20), ("제목", 46),
    ]
    header = " ".join(_pad(c, w) for c, w in cols)
    print()
    print(header)
    print("-" * _w(header))

    for it in items[:limit]:
        tags = tagger.tag(it["title"], it["body_excerpt"])
        tag_names = ", ".join(domains_by_id.get(d, str(d)) for d in list(tags)[:2])

        asg = it["_asg_raw"] or "-"
        if it["member_id"] is None:
            # ! = bs_member 에 못 붙임 → 적재될 때 member_id 가 NULL 로 들어간다
            asg = f"{_cut(asg, 6)} !"

        req = it["requested_at"]
        cells = [
            req.strftime("%Y-%m-%d") if req else "—",
            _cut(it["org_name"] or "—", 16),
            _cut(asg, 8),
            _cut(it["status_raw"] or "—", 20),
            f"{'★' * it['difficulty']}{'.' if it['difficulty_by'] == 'rule' else '*'}",
            _cut(tag_names or "—", 20),
            _cut(it["title"], 46),
        ]
        print(" ".join(_pad(c, w) for c, (_, w) in zip(cells, cols)))

    if len(items) > limit:
        print(f"... 그 외 {len(items) - limit}건 (--dry-run 은 {limit}건까지 보여줍니다)")

    print()
    print("  난이도 뒤 기호:  * = 이미 채점된 값(ai_stars)    . = 규칙 기반")
    print("  담당 뒤 !     :  bs_member 에 못 붙임. member_id 없이 적재됩니다")


def print_summary(items: list[dict], members: MemberResolver) -> None:
    total = len(items)
    matched = sum(1 for i in items if i["member_id"] is not None)
    with_org = sum(1 for i in items if i["org_name"])
    closed = sum(1 for i in items if i["closed_at"])

    print()
    print("  요약")
    print(f"    전체            {total}건")
    print(f"    담당자 매칭     {matched}건" + (f"  (미매칭 {total - matched}건)" if total else ""))
    print(f"    기관명 식별     {with_org}건" + (f"  (미식별 {total - with_org}건)" if total else ""))
    print(f"    종료된 건       {closed}건")

    if members.unmatched:
        print()
        print("  담당자를 못 붙인 표기 (member_aliases.json 에 추가하세요)")
        for name, cnt in sorted(members.unmatched.items(), key=lambda x: -x[1])[:15]:
            print(f"    {_pad(name, 20)} {cnt}건")


# =====================================================================
# main
# =====================================================================

def main() -> int:
    ap = argparse.ArgumentParser(
        description="슬랙 취합 시스템 → bs_work_item 적재",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument("--config", default=str(HERE / "config.ini"), help="설정 파일 경로")
    ap.add_argument("--from", dest="dfrom", help="수집 시작일 YYYY-MM-DD")
    ap.add_argument("--to", dest="dto", help="수집 종료일 YYYY-MM-DD")
    ap.add_argument("--days", type=int, help="최근 N일 (--from/--to 대신)")
    ap.add_argument("--dry-run", action="store_true",
                    help="적재하지 않고 콘솔에 표로만 보여준다")
    ap.add_argument("--limit", type=int, help="--dry-run 에서 보여줄 건수")
    args = ap.parse_args()

    cfg = load_config(Path(args.config))
    log = Log(cfg["log"].get("level", "info"))

    # ── 기간 정하기 ───────────────────────────────────────────────────
    today = date.today()
    if args.dfrom or args.dto:
        try:
            dfrom = date.fromisoformat(args.dfrom) if args.dfrom else today - timedelta(days=7)
            dto = date.fromisoformat(args.dto) if args.dto else today
        except ValueError as e:
            sys.exit(f"날짜 형식이 잘못됐습니다(YYYY-MM-DD): {e}")
    else:
        days = args.days if args.days else cfg["collect"].getint("default_days", 7)
        dfrom, dto = today - timedelta(days=days), today

    if dfrom > dto:
        sys.exit(f"기간이 뒤집혔습니다: {dfrom} ~ {dto}")

    mode = "DRY-RUN (적재하지 않음)" if args.dry_run else "적재"
    print()
    print("BlueStudio 슬랙 수집기")
    print("=" * 62)
    print(f"  모드    {mode}")
    print(f"  기간    {dfrom} ~ {dto}")
    print(f"  DB      {cfg['db'].get('user')}@{cfg['db'].get('host')}/{cfg['db'].get('name')}")
    print()

    conn = connect(cfg)            # 적재할 곳 (bs_*)
    src_conn = connect_source(cfg) # 원천 (requests). 없으면 conn 과 같은 DB
    reader = src_conn or conn
    if src_conn is not None:
        sc = cfg["db_source"]
        print(f"  원천    {sc.get('user')}@{sc.get('host')}/{sc.get('name')}  (읽기 전용)")
    sync_id = None

    try:
        # ── 참조 자료 ─────────────────────────────────────────────────
        nrm = cfg["normalize"]
        schools = fetch_schools(reader, log) if nrm.getboolean("use_schools_table", True) else []
        orgs = OrgNormalizer(HERE / nrm.get("orgs_file", "orgs.json"), schools)
        members = MemberResolver(HERE / nrm.get("aliases_file", "member_aliases.json"),
                                 fetch_members(conn))
        domain_rows = fetch_domains(conn)
        tagger = DomainTagger(domain_rows)
        domains_by_id = {int(d["id"]): d["name"] for d in domain_rows}
        log.debug(f"분야 {len(domain_rows)}개 / 구성원 {len(members._by_email)}명")

        # ── 읽기 ──────────────────────────────────────────────────────
        rows = fetch_requests(reader, cfg, dfrom, dto, log)
        local_asg = fetch_local_assignments(reader)
        items = [to_work_item(r, cfg, orgs, members, local_asg) for r in rows]

        # ── dry-run ───────────────────────────────────────────────────
        if args.dry_run:
            limit = args.limit or cfg["collect"].getint("dry_run_limit", 100)
            print_table(items, limit, tagger, domains_by_id)
            print_summary(items, members)
            print()
            print("  적재하지 않았습니다. 확인되면 --dry-run 을 빼고 다시 실행하세요.")
            print()
            return 0

        if not items:
            log.info("  적재할 항목이 없습니다.")
            return 0

        # ── 적재 (전부 아니면 전무) ───────────────────────────────────
        # bs_sync_log 는 실패해도 남아야 하므로 본 트랜잭션과 분리한다.
        sync_id = start_sync(conn)
        conn.commit()

        conn.begin()
        stats = upsert_items(conn, items, tagger, log)
        stats["fetched"] = len(items)
        conn.commit()

        finish_sync(conn, sync_id, stats, "ok", None)
        conn.commit()

        print(f"  신규 {stats['inserted']}  갱신 {stats['updated']}  "
              f"변화없음 {stats['skipped']}  분야태그 {stats['tagged']}")
        print_summary(items, members)

        # 미매칭 담당자를 파일로 남긴다 — 별칭표를 채울 때 쓴다
        unmatched_file = cfg["log"].get("unmatched_file", "").strip()
        if unmatched_file and members.unmatched:
            p = HERE / unmatched_file
            with p.open("a", encoding="utf-8") as f:
                f.write(f"# {datetime.now():%Y-%m-%d %H:%M}  {dfrom}~{dto}\n")
                for name, cnt in sorted(members.unmatched.items(), key=lambda x: -x[1]):
                    f.write(f"{name}\t{cnt}\n")
            log.info(f"  미매칭 담당자 {len(members.unmatched)}종을 {p.name} 에 남겼습니다.")

        print()
        return 0

    except Exception as e:
        # 부분 적재를 남기지 않는다. 절반만 들어간 상태가 가장 다루기 어렵다.
        conn.rollback()
        if sync_id is not None:
            try:
                finish_sync(conn, sync_id, {"fetched": 0}, "fail", f"{type(e).__name__}: {e}"[:1000])
                conn.commit()
            except Exception:
                pass
        print(f"\n[실패] {type(e).__name__}: {e}", file=sys.stderr)
        print("적재를 되돌렸습니다. DB 는 실행 전 상태입니다.", file=sys.stderr)
        return 1

    finally:
        conn.close()
        if src_conn is not None:
            src_conn.close()


if __name__ == "__main__":
    sys.exit(main())
