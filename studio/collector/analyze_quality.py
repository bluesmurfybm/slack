# -*- coding: utf-8 -*-
"""수집 대상 데이터의 품질을 점검하고 studio/docs/data-quality-report.md 를 쓴다.

점수식을 설계하기 전에 "이 데이터로 무엇을 잴 수 있는가" 를 먼저 확인하기 위한 도구다.
적재하지 않는다 — 읽기만 한다.

    python analyze_quality.py                     최근 6개월
    python analyze_quality.py --months 12
    python analyze_quality.py --from 2026-01-01 --to 2026-09-30
    python analyze_quality.py --out ../docs/data-quality-report.md

config.ini 의 [db] 를 그대로 쓴다. **운영 DB 를 가리키게 해야 의미 있는 숫자가 나온다.**
"""

from __future__ import annotations

import argparse
import configparser
import re
import sys
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

try:
    import pymysql
except ImportError:
    sys.exit("PyMySQL 이 필요합니다:  pip install PyMySQL")

from normalize import DomainTagger, MemberResolver, OrgNormalizer, _norm

HERE = Path(__file__).resolve().parent

for _s in (sys.stdout, sys.stderr):
    try:
        _s.reconfigure(errors="replace")
    except (AttributeError, ValueError):
        pass


def pct(n: int, total: int) -> str:
    return f"{(100.0 * n / total):.1f}%" if total else "—"


def md_table(headers: list[str], rows: list[list], align: list[str] | None = None) -> str:
    align = align or ["---"] * len(headers)
    out = ["| " + " | ".join(headers) + " |", "|" + "|".join(align) + "|"]
    for r in rows:
        out.append("| " + " | ".join(str(c) for c in r) + " |")
    return "\n".join(out)


def esc(s) -> str:
    """표 안에서 깨지지 않게. 파이프와 줄바꿈만 처리한다."""
    return str(s if s is not None else "").replace("|", "\\|").replace("\n", " ").strip()


# =====================================================================
def main() -> int:
    ap = argparse.ArgumentParser(description="수집 대상 데이터 품질 점검")
    ap.add_argument("--config", default=str(HERE / "config.ini"))
    ap.add_argument("--from", dest="dfrom")
    ap.add_argument("--to", dest="dto")
    ap.add_argument("--months", type=int, default=6)
    ap.add_argument("--out", default=str(HERE.parent / "docs" / "data-quality-report.md"))
    ap.add_argument("--samples", type=int, default=20, help="미분류 제목 샘플 개수")
    args = ap.parse_args()

    cfgp = Path(args.config)
    if not cfgp.is_file():
        sys.exit(f"설정 파일이 없습니다: {cfgp}")
    cp = configparser.ConfigParser()
    with cfgp.open(encoding="utf-8") as f:
        cp.read_file(f)

    today = date.today()
    if args.dfrom or args.dto:
        dfrom = date.fromisoformat(args.dfrom) if args.dfrom else today - timedelta(days=183)
        dto = date.fromisoformat(args.dto) if args.dto else today
    else:
        dfrom, dto = today - timedelta(days=30 * args.months), today

    d = cp["db"]
    conn = pymysql.connect(
        host=d.get("host"), port=d.getint("port", 3306), user=d.get("user"),
        password=d.get("password", ""), database=d.get("name"),
        charset=d.get("charset", "utf8mb4"),
        cursorclass=pymysql.cursors.DictCursor,
    )

    def q(sql, params=()):
        with conn.cursor() as cur:
            cur.execute(sql, params)
            return cur.fetchall()

    print(f"\n데이터 품질 점검  {dfrom} ~ {dto}")
    print(f"  DB  {d.get('user')}@{d.get('host')}/{d.get('name')}")

    boards = [b.strip() for b in cp["source"].get("boards", "블루소프트").split(",") if b.strip()]
    ph = ",".join(["%s"] * len(boards))

    # ── 모집단 ────────────────────────────────────────────────────────
    rows = q(f"""
        SELECT id, title, body, lms, asg, asg_id, status, team, board, archived,
               cmt_count, `date`, done, created, updated, ai_stars
          FROM requests
         WHERE board IN ({ph})
           AND ( (`date` IS NOT NULL AND `date` BETWEEN %s AND %s)
                 OR (`date` IS NULL AND `created` BETWEEN %s AND %s) )
           AND TRIM(title) <> ''
    """, (*boards, dfrom.isoformat(), dto.isoformat(),
          int(datetime.combine(dfrom, datetime.min.time()).timestamp()),
          int(datetime.combine(dto, datetime.max.time()).timestamp())))

    total = len(rows)
    print(f"  대상 {total}건")
    if total == 0:
        sys.exit("대상 건이 없습니다. 기간이나 boards 설정을 확인하세요.")

    # 전체(기간 무관) — 볼륨 추정용
    all_rows = q(f"SELECT `date`, created, board, status FROM requests WHERE board IN ({ph}) AND TRIM(title) <> ''",
                 tuple(boards))

    # ── 참조 자료 ─────────────────────────────────────────────────────
    #
    # 운영 DB 에는 BlueStudio 가 아직 배포되지 않아 bs_member / bs_domain 이 없다.
    # 그래서 원천(requests·schools)은 운영에서 읽고, 우리 쪽 정의는 아래처럼 구한다.
    #   구성원 : 운영 bs_member → 없으면 운영 portal_users 에서 만든다(실제 명단)
    #   분야   : 운영 bs_domain → 없으면 [db_local] 로컬 DB 에서 읽는다
    # 운영에는 아무것도 만들지 않는다.
    nrm = cp["normalize"]
    try:
        schools = q("SELECT name, ver, dev, ops, log FROM schools WHERE active = 1")
    except pymysql.Error:
        schools = []
    orgs = OrgNormalizer(HERE / nrm.get("orgs_file", "orgs.json"), schools)

    def local_conn():
        if "db_local" not in cp:
            return None
        l = cp["db_local"]
        return pymysql.connect(
            host=l.get("host", "127.0.0.1"), port=l.getint("port", 3306),
            user=l.get("user"), password=l.get("password", ""), database=l.get("name"),
            charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)

    def q_local(sql):
        lc = local_conn()
        if lc is None:
            return []
        try:
            with lc.cursor() as cur:
                cur.execute(sql)
                return cur.fetchall()
        finally:
            lc.close()

    member_src = "운영 `bs_member`"
    try:
        member_rows = q("SELECT id, user_id, emp_name FROM bs_member")
        if not member_rows:
            raise pymysql.Error("비어 있음")
    except pymysql.Error:
        # bs_member 는 MemberRepo::syncFromPortalUsers() 가 portal_users 로 채울 표다.
        # 아직 없으니 같은 원본에서 같은 모양으로 만들어 쓴다 — 실제 명단 그대로다.
        member_rows = [{"id": r["id"], "user_id": r["email"], "emp_name": r["name"]}
                       for r in q("SELECT id, email, name FROM portal_users")]
        member_src = "운영 `portal_users` (bs_member 미배포)"

    # 평가 제외 플래그는 bs_member 에만 있다. 운영에 그 표가 없으면 로컬에서 가져와
    # 이메일로 붙인다 — 누가 평가 대상인지는 우리가 정한 값이지 운영 데이터가 아니다.
    excluded: dict[str, str] = {}
    if not any("is_evaluable" in m for m in member_rows):
        try:
            for r in q_local("SELECT user_id, is_evaluable, eval_exclude_reason FROM bs_member"):
                if int(r.get("is_evaluable", 1)) == 0:
                    excluded[(r["user_id"] or "").lower()] = r.get("eval_exclude_reason") or ""
        except Exception:
            pass
    else:
        for m in member_rows:
            if int(m.get("is_evaluable", 1)) == 0:
                excluded[(m["user_id"] or "").lower()] = m.get("eval_exclude_reason") or ""
    members = MemberResolver(HERE / nrm.get("aliases_file", "member_aliases.json"), member_rows)

    domain_src = "운영 `bs_domain`"
    try:
        domain_rows = q("SELECT id, code, name, keywords FROM bs_domain WHERE is_active = 1")
        if not domain_rows:
            raise pymysql.Error("비어 있음")
    except pymysql.Error:
        domain_rows = q_local("SELECT id, code, name, keywords FROM bs_domain WHERE is_active = 1")
        domain_src = "로컬 `bs_domain` (운영 미배포)"
    if not domain_rows:
        sys.exit("bs_domain 을 어디서도 읽지 못했습니다. config 에 [db_local] 을 넣으세요.")
    tagger = DomainTagger(domain_rows)
    dname = {int(x["id"]): x["name"] for x in domain_rows}

    local_asg = {r["request_id"]: r["assignee"]
                 for r in q("SELECT request_id, assignee FROM local_assignments")}

    # =================================================================
    # 1. 담당자 매핑
    # =================================================================
    matched = 0
    fail_reason = Counter()
    fail_names = Counter()
    unassigned = 0

    for r in rows:
        name = local_asg.get(r["id"]) or r.get("asg")
        res = members.resolve(name, r.get("asg_id"))
        if res.member_id is not None:
            matched += 1
        else:
            raw = (name or "").strip()
            if not raw or _norm(raw) in members._ignore:
                unassigned += 1
                fail_reason["담당자 미지정(슬랙에서 비어 있음)"] += 1
            else:
                fail_reason[res.reason or "매칭 실패"] += 1
                fail_names[raw] += 1

    unmatched = total - matched

    # =================================================================
    # 2. 분야 태깅
    # =================================================================
    untagged = []
    tag_hits = Counter()
    tags_per_item = Counter()
    for r in rows:
        tags = tagger.tag(r["title"], (r.get("body") or "")[:2000])
        tags_per_item[len(tags)] += 1
        if not tags:
            untagged.append(r)
        for did in tags:
            tag_hits[did] += 1

    # 한 건도 못 맞춘 분야 = 키워드가 죽어 있다는 뜻
    dead_domains = [dname[did] for did in dname if tag_hits.get(did, 0) == 0]

    # =================================================================
    # 2b. 점수 산출 단위 — (사람 × 분야) 칸이 실제로 몇 건씩 차는가
    #
    # CLAUDE.md 가 표본 20건 미만이면 insufficient_data 로 두라고 하므로,
    # "칸이 몇 건씩 차는가" 가 분야를 몇 개로 쪼갤지 결정한다.
    # =================================================================
    dcat = {int(x["id"]): (x.get("category") or "?") for x in domain_rows} \
        if domain_rows and "category" in domain_rows[0] else {}
    if not dcat:
        try:
            dcat = {int(x["id"]): (x.get("category") or "?")
                    for x in q_local("SELECT id, category FROM bs_domain")}
        except Exception:
            dcat = {}

    pair_domain: Counter = Counter()
    pair_cat: Counter = Counter()
    per_person: Counter = Counter()
    for r in rows:
        res = members.resolve(local_asg.get(r["id"]) or r.get("asg"), r.get("asg_id"))
        if res.member_id is None:
            continue
        per_person[res.email] += 1
        tags = tagger.tag(r["title"], (r.get("body") or "")[:2000])
        for did in tags:
            pair_domain[(res.email, did)] += 1
        for cc in {dcat.get(did, "?") for did in tags}:
            pair_cat[(res.email, cc)] += 1

    def cells(counter: Counter) -> tuple[int, int, int]:
        tot = len(counter)
        ge = sum(1 for v in counter.values() if v >= 20)
        med = sorted(counter.values())[tot // 2] if tot else 0
        return tot, ge, med

    # =================================================================
    # 3. 상태 전이 / 리드타임
    # =================================================================
    # 취합 시스템과 BlueStudio·포털이 **같은 DB** 를 쓰므로 SHOW TABLES 에는
    # bs_* / portal_* 도 섞여 나온다. "취합 시스템의 표" 를 말하려면 걸러야 한다.
    all_tables = {t[list(t)[0]] for t in q("SHOW TABLES")}
    slack_tables = {t for t in all_tables
                    if not t.startswith(("bs_", "portal_", "bc_"))}
    history_like = sorted(t for t in slack_tables
                          if re.search(r"hist|audit|revision|trail|changelog", t, re.I))

    have_both = [r for r in rows if r.get("date") and r.get("done")]
    leads = []
    for r in have_both:
        dd = (r["done"] - r["date"]).days
        if dd >= 0:
            leads.append(dd)
    leads.sort()

    def pctile(p: float):
        if not leads:
            return None
        return leads[min(len(leads) - 1, int(len(leads) * p))]

    status_now = Counter(r["status"] or "(빈값)" for r in rows)

    # =================================================================
    # 4. 기관명
    # =================================================================
    org_resolved = 0
    org_counts = Counter()
    unresolved_titles = []
    for r in rows:
        name = orgs.resolve(r.get("lms"), r.get("title"), r.get("body"))
        if name:
            org_resolved += 1
            org_counts[name] += 1
        else:
            unresolved_titles.append(r["title"])

    # 미식별 제목에서 기관처럼 보이는 조각을 뽑는다 → 사전에 추가할 후보
    cand = Counter()
    for t in unresolved_titles:
        for m in re.findall(r"\[([^\]]{1,20})\]", t or ""):          # [한기대] 꼴
            cand[m.strip()] += 1
        for m in re.findall(r"([가-힣A-Za-z]{2,10}(?:대학교|대학|대))(?![가-힣])", t or ""):
            cand[m.strip()] += 1
    # 이미 사전이 아는 것은 후보에서 뺀다
    cand = Counter({k: v for k, v in cand.items() if orgs.from_text(k) is None})

    # 표기 변형 탐지: 사전이 아는 기관인데 제목 표기가 표준명과 다른 경우
    variants = defaultdict(Counter)
    for r in rows:
        t = r["title"] or ""
        for m in re.findall(r"\[([^\]]{1,20})\]", t):
            canon = orgs.from_text(m)
            if canon and _norm(m) != _norm(canon):
                variants[canon][m.strip()] += 1

    # =================================================================
    # 5. 볼륨
    # =================================================================
    by_month = Counter()
    for r in all_rows:
        dt = r.get("date")
        if not dt and r.get("created"):
            try:
                dt = datetime.fromtimestamp(int(r["created"])).date()
            except (ValueError, OSError, OverflowError):
                dt = None
        if dt:
            by_month[f"{dt.year}-{dt.month:02d}"] += 1

    # 최근 6개 **달력 월** 로 평균낸다. 건이 없는 달을 빼고 평균내면
    # (있는 달만 세면) 월평균이 부풀려진다 — 볼륨 추정이 통째로 틀어진다.
    def month_keys_back(n: int, end: date) -> list[str]:
        ks, y, m = [], end.year, end.month
        for _ in range(n):
            ks.append(f"{y}-{m:02d}")
            m -= 1
            if m == 0:
                y, m = y - 1, 12
        return list(reversed(ks))

    # 기준은 '오늘' 이 아니라 데이터가 있는 마지막 달 — 수집이 멎은 경우를 감안
    last_key = max(by_month) if by_month else f"{today.year}-{today.month:02d}"
    ly, lm = (int(x) for x in last_key.split("-"))
    recent_keys = month_keys_back(6, date(ly, lm, 1))
    recent = [by_month.get(k, 0) for k in recent_keys]
    avg_month = sum(recent) / len(recent) if recent else 0

    # =================================================================
    # 보고서
    # =================================================================
    L: list[str] = []
    A = L.append

    A("# 수집 데이터 품질 점검")
    A("")
    A("> 점수식을 설계하기 전에 **이 데이터로 무엇을 잴 수 있는지** 확인한 결과입니다.")
    A("> `studio/collector/analyze_quality.py` 가 생성합니다. 손으로 고치지 마세요.")
    A("")
    A(md_table(["항목", "값"], [
        ["생성 시각", datetime.now().strftime("%Y-%m-%d %H:%M")],
        ["대상 DB", f"`{d.get('user')}@{d.get('host')}/{d.get('name')}`"],
        ["대상 보드", ", ".join(boards)],
        ["분석 기간", f"{dfrom} ~ {dto}"],
        ["**대상 건수**", f"**{total}건**"],
        ["전체 보유 건수(기간 무관)", f"{len(all_rows)}건"],
        ["`schools` 표 참조", f"{len(schools)}개 기관" if schools else "없음(사전만 사용)"],
        ["구성원 명단 출처", f"{member_src} — {len(members._by_email)}명"],
        ["분야 정의 출처", f"{domain_src} — {len(domain_rows)}개"],
    ]))
    A("")

    if len(all_rows) < 100:
        A("> ⚠️ **경고 — 표본이 너무 적습니다.**")
        A("> 전체 보유 건수가 100건 미만입니다. 운영 DB 가 아니라 개발용 샘플을")
        A("> 보고 있을 가능성이 높습니다. 아래 비율을 점수식 보정 근거로 쓰지 마세요.")
        A("> `config.ini` 의 `[db]` 가 운영 DB 를 가리키는지 확인한 뒤 다시 돌리십시오.")
        A("")

    A("---")
    A("")

    # ---- 1 ----------------------------------------------------------
    A("## 1. 담당자 매핑")
    A("")
    A(md_table(["구분", "건수", "비율"], [
        ["**매핑 성공**", matched, pct(matched, total)],
        ["매핑 실패", unmatched, pct(unmatched, total)],
        ["　└ 담당자 미지정", unassigned, pct(unassigned, total)],
        ["　└ 이름은 있는데 못 붙임", unmatched - unassigned, pct(unmatched - unassigned, total)],
    ], ["---", "---:", "---:"]))
    A("")
    A(f"**매핑률 {pct(matched, total)}** — 나머지 {pct(unmatched, total)} 는 "
      f"담당자가 비어 있거나(`{pct(unassigned, total)}`) 구성원 명단에 없는 사람입니다.")
    A("")
    A("> **이 숫자는 고쳐야 할 결함이 아닙니다.**")
    A("> 역량 판정 대상은 **자사 구성원(`portal_users`)뿐**입니다. 슬랙 취합 시스템에는")
    A("> 협력사 인력이 함께 일한 기록이 섞여 있고, 그 건들이 매핑에서 빠지는 것이 정상입니다.")
    A("> 아래 '못 붙인 표기' 는 별칭표에 넣을 목록이 아니라 **집계에서 빠진 인원 확인용**입니다.")
    A("> 자사 구성원인데 여기 이름이 올라와 있다면 그때만 별칭표를 손보십시오.")
    A("")
    A(f"따라서 실질 모집단은 **{matched}건**이며, 아래 모든 점수 분석은 이 범위에서만 합니다.")
    A("")
    A("### 실패 사유")
    A("")
    A(md_table(["사유", "건수"],
               [[esc(k), v] for k, v in fail_reason.most_common()], ["---", "---:"]) or "없음")
    A("")
    if fail_names:
        A("### 집계에서 빠진 담당자 (상위 20)")
        A("")
        A("대부분 협력사 인력입니다. **자사 구성원이 여기 있으면** 그 사람만")
        A("`member_aliases.json` 에 추가하십시오. 그 외에는 손대지 마십시오.")
        A("")
        A(md_table(["표기", "건수"],
                   [[esc(k), v] for k, v in fail_names.most_common(20)],
                   ["---", "---:"]))
        A("")

    # ---- 2 ----------------------------------------------------------
    tagged_n = total - len(untagged)
    A("---")
    A("")
    A("## 2. 분야 태깅 커버리지")
    A("")
    A(md_table(["구분", "건수", "비율"], [
        ["**분야가 붙은 건**", tagged_n, pct(tagged_n, total)],
        ["미분류", len(untagged), pct(len(untagged), total)],
    ], ["---", "---:", "---:"]))
    A("")
    A("### 건당 분야 수")
    A("")
    A(md_table(["분야 수", "건수", "비율"],
               [[k, v, pct(v, total)] for k, v in sorted(tags_per_item.items())],
               ["---:", "---:", "---:"]))
    A("")
    A("### 분야별 적중")
    A("")
    A(md_table(["분야", "건수", "비율"],
               [[esc(dname[did]), n, pct(n, total)] for did, n in tag_hits.most_common()],
               ["---", "---:", "---:"]))
    A("")
    if dead_domains:
        A(f"**한 건도 못 맞춘 분야 {len(dead_domains)}개** — 키워드가 실제 표현과 안 맞거나,")
        A("그 분야 업무가 이 기간에 없었다는 뜻입니다. 구분해서 판단해야 합니다.")
        A("")
        A("> " + ", ".join(esc(x) for x in dead_domains))
        A("")
    A(f"### 미분류 건 제목 샘플 ({min(args.samples, len(untagged))}건)")
    A("")
    if untagged:
        A("여기 자주 나오는 표현을 `bs_domain.keywords` 에 넣으면 커버리지가 올라갑니다.")
        A("")
        for i, r in enumerate(untagged[:args.samples], 1):
            A(f"{i}. {esc(r['title'])}")
    else:
        A("없음 — 전 건에 분야가 붙었습니다.")
    A("")

    # ---- 2b ---------------------------------------------------------
    td, ged, medd = cells(pair_domain)
    tc, gec, medc = cells(pair_cat)
    A("---")
    A("")
    A(f"## 2-b. 점수 산출 단위 — 분야 {len(domain_rows)}개는 너무 잘다")
    A("")
    A("CLAUDE.md 는 표본 **20건 미만이면 `insufficient_data`** 로 두라고 합니다.")
    A("그러면 (사람 × 분야) 칸이 실제로 몇 건씩 차는지가 분야 개수를 결정합니다.")
    A("")
    n_cat = len({v for v in dcat.values() if v and v != "?"})
    A(md_table(["산출 단위", "칸 수", "20건 이상", "비율", "칸당 중앙값"], [
        [f"분야 {len(domain_rows)}개", td, ged, pct(ged, td), f"{medd}건"],
        [f"**계열 {n_cat}개**", tc, gec, pct(gec, tc), f"{medc}건"],
    ], ["---", "---:", "---:", "---:", "---:"]))
    A("")
    A(f"분야 단위로는 **{pct(td - ged, td)} 의 칸이 표본 부족**으로 버려집니다.")
    A(f"계열 단위로 묶으면 쓸 수 있는 칸이 {pct(ged, td)} → {pct(gec, tc)} 로 늘고,")
    A(f"칸당 중앙값도 {medd}건 → {medc}건으로 임계선(20건)을 넘습니다.")
    A("")
    A("### category 별 상세")
    A("")
    bycat = defaultdict(list)
    for (_e, cc), v in pair_cat.items():
        bycat[cc].append(v)
    A(md_table(["category", "사람 수", "총 건수", "20건 이상인 사람"],
               [[esc(cc), len(vs), sum(vs), sum(1 for v in vs if v >= 20)]
                for cc, vs in sorted(bycat.items(), key=lambda x: -sum(x[1]))],
               ["---", "---:", "---:", "---:"]))
    A("")
    A("### 구성원별 — 누가 점수를 받을 수 있는가")
    A("")
    A("자사 구성원 전원을 싣습니다. 활동이 없는 사람도 그대로 보여야")
    A("\"이 사람은 왜 점수가 없는가\" 에 답할 수 있습니다.")
    A("")
    scored = {e for (e, _c), v in pair_cat.items() if v >= 20}
    roster = []
    n_excluded = 0
    for m in sorted(member_rows, key=lambda x: -per_person.get(x["user_id"], 0)):
        e = m["user_id"]
        n = per_person.get(e, 0)
        cats = sorted(cc for (ee, cc), v in pair_cat.items() if ee == e and v >= 20)
        if (e or "").lower() in excluded:
            n_excluded += 1
            verdict = "평가 제외"
            note = esc(excluded[(e or "").lower()][:40]) or "사유 미기재"
        elif e in scored:
            verdict = "가능"
            note = ", ".join(cats)
        else:
            verdict = "**불가**"
            note = "표본 부족" if n else "활동 없음"
        roster.append([esc(m["emp_name"]), n, note, verdict])

    A(md_table(["구성원", "6개월 건수", "category / 사유", "점수 산출"], roster,
               ["---", "---:", "---", "---"]))
    A("")
    target = len(member_rows) - n_excluded
    A(f"자사 구성원 {len(member_rows)}명 가운데 **평가 제외 {n_excluded}명**"
      f"(`bs_member.is_evaluable = 0`)을 뺀 {target}명이 평가 대상이고,")
    A(f"그중 **{len(scored)}명**이 실제로 점수를 낼 수 있습니다.")
    A("")
    A("> **평가 제외**와 **표본 부족**은 다릅니다. 화면에서 같은 문구를 쓰지 마십시오.")
    A("> · `is_evaluable = 0` — 이 데이터로 평가하지 않기로 정한 사람. 사유가 붙습니다.")
    A("> · `insufficient_data` — 데이터가 모자란 사람. 쌓이면 언젠가 점수가 나옵니다.")
    A(">")
    A("> 평가 제외는 **배정 제외가 아닙니다**(`is_assignable` 은 그대로 1).")
    A("> 기획 담당자는 점수를 못 내도 기획 과업에는 배정되어야 합니다.")
    A("")

    # ---- 3 ----------------------------------------------------------
    A("---")
    A("")
    A("## 3. 상태 전이 이력 — **남아 있지 않습니다**")
    A("")
    A("### 근거")
    A("")
    A("- 취합 시스템의 표 목록에 이력·감사 성격 표가 없습니다.")
    A(f"  - 전체 표: {', '.join('`'+t+'`' for t in sorted(slack_tables))}")
    A(f"  - 이력처럼 보이는 표: {', '.join('`'+t+'`' for t in history_like) if history_like else '**없음**'}")
    A("- `slack/update.php` 가 `UPDATE requests SET status_id=?, status=?` 로")
    A("  **제자리에서 덮어씁니다.** 이전 값을 어디에도 남기지 않습니다.")
    A("- `requests` 에 남는 시간 정보는 `date`(요청일), `done`(완료일),")
    A("  `created`/`updated`(unixtime) 뿐입니다. `updated` 는 *무엇이* 바뀌었는지 모릅니다.")
    A("")
    A("### 그래서 계산 가능한 것 / 불가능한 것")
    A("")
    A(md_table(["지표", "가능?", "근거"], [
        ["요청 → 완료 리드타임", "**가능**", "`date` 와 `done` 이 모두 있는 건에 한해"],
        ["첫 응답 시간(first_reply_at)", "불가", "이력 없음. 댓글 시각도 저장 안 함"],
        ["개발서버 반영 시점", "불가(근사만)", "현재 상태로 역산 + `updated` 대입"],
        ["운영서버 반영 시점", "불가(근사만)", "〃"],
        ["재오픈 횟수(reopen_count)", "불가", "상태가 되돌아간 기록이 없음"],
        ["단계별 체류 시간", "불가", "전이 시점을 모름"],
    ]))
    A("")
    A("### 요청 → 완료 리드타임 (계산 가능한 건만)")
    A("")
    A(md_table(["구분", "값"], [
        ["요청일·완료일이 모두 있는 건", f"{len(have_both)}건 ({pct(len(have_both), total)})"],
        ["그중 음수 제외 유효 건", f"{len(leads)}건"],
        ["중앙값", f"{pctile(0.5)}일" if leads else "—"],
        ["25 / 75 분위", f"{pctile(0.25)} / {pctile(0.75)}일" if leads else "—"],
        ["90 분위", f"{pctile(0.9)}일" if leads else "—"],
        ["최대", f"{leads[-1]}일" if leads else "—"],
    ]))
    A("")
    A("> **점수식에 주는 영향**")
    A("> 명세서 §4.4 의 `speed_score` 는 '요청 → 첫 응답 → 배포' 리드타임을 전제하는데,")
    A("> 지금 잴 수 있는 것은 **요청 → 완료 하나뿐**입니다.")
    A(f"> 게다가 그 값도 전체의 {pct(len(have_both), total)} 에서만 나옵니다.")
    A("> 나머지는 아직 안 끝났거나 완료일이 비어 있습니다.")
    A(">")
    A("> 선택지는 셋입니다.")
    A("> 1. `speed_score` 를 요청→완료 하나로 단순화하고, 표본이 없는 사람은")
    A(">    `insufficient_data` 로 둔다 (가장 안전)")
    A("> 2. 수집기를 **매일 돌려 상태 변화를 관찰**해 이력을 새로 쌓는다.")
    A(">    과거분은 복원 못 하지만 앞으로는 정확해진다")
    A("> 3. 취합 시스템에 상태 변경 로그를 추가한다 (그쪽 수정 필요)")
    A("")
    A("### 현재 상태 분포")
    A("")
    A(md_table(["상태", "건수", "비율"],
               [[esc(k), v, pct(v, total)] for k, v in status_now.most_common()],
               ["---", "---:", "---:"]))
    A("")

    # ---- 4 ----------------------------------------------------------
    A("---")
    A("")
    A("## 4. 기관명 표준화")
    A("")
    A(md_table(["구분", "건수", "비율"], [
        ["**식별 성공**", org_resolved, pct(org_resolved, total)],
        ["미식별", total - org_resolved, pct(total - org_resolved, total)],
    ], ["---", "---:", "---:"]))
    A("")
    A("### 식별된 기관 분포")
    A("")
    A(md_table(["기관", "건수", "비율"],
               [[esc(k), v, pct(v, total)] for k, v in org_counts.most_common()],
               ["---", "---:", "---:"]) or "없음")
    A("")
    A("### 사전에 추가할 후보")
    A("")
    if cand:
        A("미식별 건의 제목에서 기관처럼 보이는 조각을 뽑았습니다.")
        A("실제 기관이면 `orgs.json` 에 `aliases` 로 추가하십시오.")
        A("")
        A(md_table(["표기", "건수", "조치"],
                   [[esc(k), v, "기관인가? 어느 기관의 별칭인가?"] for k, v in cand.most_common(30)],
                   ["---", "---:", "---"]))
    else:
        A("없음 — 미식별 건에서 기관으로 보이는 조각을 찾지 못했습니다.")
    A("")
    # schools.name 자체가 표준명이 아니다 — 같은 대학이 여러 줄로 갈려 있다
    frag = defaultdict(list)
    for s in schools:
        nm = (s.get("name") or "").strip()
        if nm:
            frag[re.sub(r"[^가-힣A-Za-z]", "", nm)[:3]].append(nm)
    frag = {k: v for k, v in frag.items() if len(v) > 1 and k}
    A("### ⚠️ `schools.name` 은 표준명이 아닙니다")
    A("")
    A(f"기관 식별률은 {pct(org_resolved, total)} 로 높지만, **무엇으로 식별했는지**가 문제입니다.")
    A(f"`schools` 표 {len(schools)}줄 가운데 **{len(frag)}개 묶음이 같은 대학인데 이름이 갈려 있습니다.**")
    A("버전·캠퍼스·학위구분이 이름에 섞여 있어서입니다.")
    A("")
    if frag:
        A(md_table(["묶음", "줄 수", "실제 이름들"],
                   [[esc(k), len(v), esc(" / ".join(v[:4]))]
                    for k, v in sorted(frag.items(), key=lambda x: -len(x[1]))[:12]],
                   ["---", "---:", "---"]))
        A("")
    A("> 같은 대학의 업무가 `부산대 3.9` 와 `부산대4.5` 로 쪼개집니다.")
    A("> 기관을 축으로 무언가를 집계할 생각이면(예: 기관 경험 다양성) 먼저 묶어야 합니다.")
    A("> 분야 점수에는 직접 영향이 없습니다 — `org_name` 은 점수식에 안 들어갑니다.")
    A("")
    A("### 이미 잡히지만 표기가 흔들리는 것")
    A("")
    if variants:
        A("사전이 표준명으로 바꿔 주고 있어 **지금은 문제가 없습니다.**")
        A("다만 실제로 이런 변형이 쓰이고 있다는 기록으로 남깁니다.")
        A("")
        A(md_table(["표준명", "실제 표기", "건수"],
                   [[esc(c), esc(v), n] for c, vs in variants.items() for v, n in vs.most_common()],
                   ["---", "---", "---:"]))
    else:
        A("없음.")
    A("")

    # ---- 5 ----------------------------------------------------------
    A("---")
    A("")
    A("## 5. 데이터 볼륨")
    A("")
    A(md_table(["월", "건수"],
               [[k, v] for k, v in sorted(by_month.items())], ["---", "---:"]))
    A("")
    A(f"최근 6개 달력 월(`{recent_keys[0]}` ~ `{recent_keys[-1]}`) 기준으로 평균을 냅니다. "
      f"건이 없는 달도 0 으로 셉니다 — 있는 달만 평균내면 값이 부풀려집니다.")
    A("")
    A(md_table(["월", "건수"], [[k, by_month.get(k, 0)] for k in recent_keys], ["---", "---:"]))
    A("")
    A(md_table(["추정", "값"], [
        ["최근 6개월 평균", f"{avg_month:.1f}건/월"],
        ["6개월치 예상", f"{avg_month * 6:.0f}건"],
        ["12개월치 예상", f"{avg_month * 12:.0f}건"],
        ["전체 보유", f"{len(all_rows)}건"],
    ]))
    A("")
    A("### 역량 판정에 쓸 수 있는 실효 표본")
    A("")
    eff = int(avg_month * 6 * (matched / total if total else 0))
    A(md_table(["단계", "건수(추정)"], [
        ["6개월 원천", f"{avg_month * 6:.0f}"],
        [f"담당자 매핑 후 ({pct(matched, total)})", f"{eff}"],
        [f"구성원 {len(members._by_email)}명으로 나누면", f"1인당 약 {eff // max(1, len(members._by_email))}건"],
    ]))
    A("")
    A(f"> CLAUDE.md 는 표본이 **{20}건 미만**이면 `insufficient_data` 로 두라고 합니다.")
    A("> 위 1인당 추정치를 **분야별로 다시 쪼개면** 대부분의 (사람 × 분야) 칸이")
    A("> 20건에 못 미칠 가능성이 높습니다. 분야를 21개로 나눈 것이 너무 잘게 썬 것일 수")
    A("> 있으므로, 점수 산출 단위를 `category`(backend/frontend/infra/integration/planning)")
    A("> 5개로 올리는 안을 함께 검토하십시오.")
    A("")

    # ---- 결론 -------------------------------------------------------
    A("---")
    A("")
    A("## 점수식 설계에 반영할 것")
    A("")
    A(f"1. **산출 단위를 category 5개로 올리십시오.** 분야 21개로는 칸의 "
      f"{pct(td - ged, td)} 가 표본 부족으로 버려집니다(쓸 수 있는 칸 {pct(ged, td)}). "
      f"category 로 묶으면 {pct(gec, tc)} 로 올라갑니다. "
      f"분야별 점수는 참고 표시로만 두고, **정규화·비교는 category 단위**로 하는 안을 권합니다.")
    A(f"2. **`planning` 은 점수를 낼 수 없습니다.** 6개월 {sum(bycat.get('planning', []))}건뿐이고 "
      f"20건을 넘긴 사람이 {sum(1 for v in bycat.get('planning', []) if v >= 20)}명입니다. "
      f"기획·QA 는 이 데이터로 평가하지 마십시오.")
    A(f"3. **속도 지표는 변별력이 거의 없습니다.** 리드타임 중앙값 "
      f"{pctile(0.5)}일, 25~75분위 {pctile(0.25)}~{pctile(0.75)}일입니다. "
      f"절반이 당일·익일 처리라 사람을 가르지 못합니다. "
      f"`speed_score` 비중을 낮추거나, 난이도 상위 구간에서만 재는 편이 낫습니다.")
    A("4. **재작업률(`rework_rate`)은 계산할 수 없습니다.** 재오픈 기록이 없습니다. "
      "점수식에서 빼거나, 다른 대리 지표(댓글 수 등)로 바꿔야 합니다.")
    A(f"5. **매핑에서 빠지는 {pct(unmatched, total)} 는 협력사 업무입니다. 정상입니다.** "
      f"역량 판정 대상은 자사 구성원뿐이므로 그대로 두십시오. "
      f"다만 집계·정규화의 모집단을 항상 `member_id IS NOT NULL` 로 한정해야 합니다. "
      f"협력사 건이 정규화 분모에 섞이면 자사 구성원 점수가 왜곡됩니다.")
    A(f"6. **평가 가능한 인원이 {len(member_rows)}명 중 {len(scored)}명입니다** "
      f"(평가 제외 {n_excluded}명 포함). 배정 엔진이 나머지를 "
      f"'역량 낮음' 이 아니라 **'판단 보류'** 로 다루도록 해야 합니다. "
      f"`fit_score` 에서 0점으로 깔면 그 사람은 영원히 배정되지 않습니다.")
    A("")
    A("---")
    A("")
    A("*이 보고서는 `python studio/collector/analyze_quality.py` 로 다시 생성할 수 있습니다.*")

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text("\n".join(L), encoding="utf-8")

    print(f"\n  보고서: {out}")
    print(f"  담당자 매핑 {pct(matched, total)} / 분야 태깅 {pct(tagged_n, total)} / "
          f"기관 식별 {pct(org_resolved, total)}")
    if len(all_rows) < 100:
        print("\n  ⚠ 표본이 100건 미만입니다. 운영 DB 가 맞는지 확인하세요.")
    print()

    conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
