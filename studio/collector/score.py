# -*- coding: utf-8 -*-
"""bs_work_item 에서 구성원 역량 점수를 산출해 bs_eval_run / bs_member_* 에 적재한다.

    python score.py --dry-run              최근 6개월, 콘솔 표로만
    python score.py --from 2026-04-01 --to 2026-09-30
    python score.py --months 12

┌──────────────────────────────────────────────────────────────────────┐
│ 명세서 §4 에서 바뀐 것 — docs/scoring-design.md 가 확정본이다          │
│                                                                      │
│  · 점수 단위 : 분야 22개 → **계열(category) 8개**                     │
│                분야로는 (사람×분야) 칸의 82%가 표본 부족으로 버려진다  │
│  · 정규화   : 역할 그룹 내 백분위 → **절대 기준**                     │
│                평가 대상이 10명이라 그룹이 1~3명이 되고, 21건 처리한   │
│                사람이 꼴찌라서 0점이 되는 일이 생긴다                  │
│  · 삭제     : speed_score / comm_score / rework_rate                 │
│                리드타임 중앙값 1일(변별력 없음), 첫 응답 시간 측정     │
│                불가, 재오픈 기록 없음                                  │
│  · 임계선   : 20건 미만 일괄 제외 → **신뢰도 3단계**                  │
│                18건과 21건이 3건 차이로 갈리지 않게                    │
└──────────────────────────────────────────────────────────────────────┘

전사 랭킹은 내지 않는다(명세서 §1.4). 사람끼리 비교하는 수치를 만들지 않는다.
"""

from __future__ import annotations

import argparse
import configparser
import math
import sys
import unicodedata
from collections import defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

try:
    import pymysql
except ImportError:
    sys.exit("PyMySQL 이 필요합니다:  pip install PyMySQL")

HERE = Path(__file__).resolve().parent

for _s in (sys.stdout, sys.stderr):
    try:
        _s.reconfigure(errors="replace")
    except (AttributeError, ValueError):
        pass

FORMULA_VER = "v1.0"

# 점수를 내지 않는 계열 (inc/bootstrap.php 의 BS_CATEGORY_NOT_SCORED 와 같아야 한다)
NOT_SCORED = {"planning"}

# 계열 표시명 (inc/bootstrap.php 의 BS_DOMAIN_CATEGORY 와 같아야 한다)
CAT_LABEL = {
    "activity": "학습활동", "grading": "평가·이수", "enrolment": "사용자·수강",
    "integration": "연동·알림", "presentation": "화면·테마",
    "administration": "운영·관리", "platform": "플랫폼·인프라", "planning": "기획·QA",
}

# 신뢰도 구간 — 점수를 자르는 칼이 아니라 화면 표시를 가른다
CONF_FULL = 20      # 이상이면 그대로 보여준다
CONF_PARTIAL = 10   # 이상이면 '참고' 로 흐리게, 미만이면 점수를 숨긴다


# =====================================================================
# 표 출력
# =====================================================================

def _w(s) -> int:
    return sum(2 if unicodedata.east_asian_width(c) in "WF" else 1 for c in str(s))


def _pad(s, width: int) -> str:
    return str(s) + " " * max(0, width - _w(s))


def _cut(s, width: int) -> str:
    s = "" if s is None else str(s).replace("\n", " ").strip()
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


# =====================================================================
# 점수식 (명세서 §4.4 → scoring-design.md §2)
# =====================================================================

def percentile(values: list[float], p: float) -> float:
    """오름차순 정렬 후 p 분위. numpy 없이 순수 파이썬으로."""
    if not values:
        return 0.0
    vs = sorted(values)
    if len(vs) == 1:
        return float(vs[0])
    k = (len(vs) - 1) * p
    lo, hi = math.floor(k), math.ceil(k)
    if lo == hi:
        return float(vs[int(k)])
    return float(vs[lo]) * (hi - k) + float(vs[hi]) * (k - lo)


# 기준값을 뽑을 분위. 설정 [score] baseline_percentile 로 덮어쓸 수 있다.
#
# 처음엔 0.75(상위 25%)로 뒀는데 **상단이 포화됐다** — 10명 중 3명이 종합 100점이
# 되어 서로 구분이 안 됐다. 배정 엔진이 그 셋을 가를 수 없다는 뜻이다.
# 0.90 으로 올리면 100점이 1명이 되고 아래로 분산이 생긴다.
#
# 주의: 분위를 쓰는 한 기준값은 그 회차 모집단에 의존한다. 사람이 빠지면
# 조금 움직인다(순위 기반처럼 급변하지는 않는다). 완전한 절대 기준을 원하면
# 첫 회차 값을 고정 상수로 못박고 주기적으로 재검토하는 편이 낫다 —
# 그때는 baseline 을 설정에 직접 적는다. 어느 값을 썼는지는 회차마다
# bs_member_category.baseline 에 남는다.
DEFAULT_BASELINE_P = 0.90


def baseline_for(weighted_list: list[float], p: float = DEFAULT_BASELINE_P) -> float:
    """
    계열 기준값 — '충분히 숙련' 으로 볼 난이도 가중 처리량. 점수의 분모다.

    사람끼리의 상대 비교가 아니다. 한 번 정해지면 그 회차 안에서 모두에게
    같은 분모로 쓰이므로, 순위가 바뀐다고 점수가 뒤집히지 않는다.
    """
    if not weighted_list:
        return 0.0
    return max(percentile(weighted_list, p), 1.0)   # 0 으로 나누는 것을 막는다


def confidence_of(case_count: int) -> tuple[str, bool]:
    """@return (신뢰도, insufficient_data)"""
    if case_count >= CONF_FULL:
        return "full", False
    if case_count >= CONF_PARTIAL:
        return "partial", False
    return "none", True


def career_score(months: int) -> float:
    """
    경력 환산 0~100.

    단조 증가하되 완만해진다 — 5년(60개월) 63점, 10년 86점, 15년 95점.
    경력이 두 배라고 역량이 두 배는 아니라는 뜻이다.
    """
    if months <= 0:
        return 0.0
    return round(100.0 * (1.0 - math.exp(-months / 60.0)), 2)


# =====================================================================
# DB
# =====================================================================

def load_config(path: Path) -> configparser.ConfigParser:
    if not path.is_file():
        sys.exit(f"설정 파일이 없습니다: {path}")
    cp = configparser.ConfigParser()
    with path.open(encoding="utf-8") as f:
        cp.read_file(f)
    return cp


def connect(sec) -> "pymysql.connections.Connection":
    return pymysql.connect(
        host=sec.get("host", "127.0.0.1"), port=sec.getint("port", 3306),
        user=sec.get("user", "root"), password=sec.get("password", ""),
        database=sec.get("name"), charset=sec.get("charset", "utf8mb4"),
        cursorclass=pymysql.cursors.DictCursor, autocommit=False,
    )


def fetch_members(conn) -> list[dict]:
    """
    **평가 대상만** 가져온다.

    is_evaluable=0 인 사람까지 돌리면 0점짜리 스냅샷이 쌓이고, 그 0점이
    기준값(75 분위) 계산에 섞여 다른 사람 점수를 밀어 올린다.
    """
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, user_id, emp_name, role_label, career_months"
            "  FROM bs_member WHERE is_evaluable = 1 ORDER BY id"
        )
        return cur.fetchall()


def fetch_work(conn, dfrom: date, dto: date) -> list[dict]:
    """
    기간 내 업무 이력 + 분야·계열.

    **member_id IS NOT NULL** 로 거른다. bs_work_item 에는 협력사가 처리한 건이
    member_id=NULL 로 함께 들어 있다(6개월 기준 19.8%). 그것이 분모에 섞이면
    자사 구성원 점수가 낮아진다.

    한 건이 여러 분야에 걸리면 행이 여러 개 나온다. 난이도 가중 처리량을
    계열별로 더할 때 **계열 안에서 중복을 제거**해야 한다(아래 aggregate).
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT w.id, w.member_id, w.difficulty, w.difficulty_by,
                   w.source, w.source_key,
                   w.title, w.source_url, w.org_name, w.status_raw,
                   w.requested_at, w.closed_at,
                   d.id AS domain_id, d.code AS domain_code,
                   d.name AS domain_name, d.category
              FROM bs_work_item w
              JOIN bs_work_item_domain wd ON wd.work_item_id = w.id
              JOIN bs_domain d           ON d.id = wd.domain_id
             WHERE w.member_id IS NOT NULL
               AND w.source IN ('slack', 'rnd')
               AND COALESCE(w.closed_at, w.requested_at) BETWEEN %s AND %s
            """,
            (datetime.combine(dfrom, datetime.min.time()),
             datetime.combine(dto, datetime.max.time())),
        )
        return cur.fetchall()


# =====================================================================
# R&D 과제 적재 (P10-3, 명세서 §4.7)
# =====================================================================

# rnd_category 별 기본 난이도. 명세서 §4.7 의 표 그대로다.
RND_DIFFICULTY = {"poc": 3, "enhance": 3, "new_module": 4, "research": 3}

# 산출물 수 보정 — 많이 남긴 과제를 한 칸 올려 준다. 상한은 5 다.
RND_OUTPUT_BONUS_AT = 3


def rnd_difficulty(category: str | None, output_count: int) -> int:
    """
    R&D 과제의 난이도.

    기본값은 갈래로 정하고, 산출물이 많으면 한 칸 올린다. 내려가지는 않는다 —
    산출물이 적다고 어려운 일이 쉬워지지는 않는다. 1~5 를 벗어나지 않는다.
    """
    base = RND_DIFFICULTY.get((category or "").strip(), 3)
    if output_count >= RND_OUTPUT_BONUS_AT:
        base += 1
    return max(1, min(5, base))


def materialize_rnd(conn, dfrom: date, dto: date) -> dict:
    """
    **종료된** R&D 과제를 bs_work_item 에 source='rnd' 로 적재한다.

    ┌──────────────────────────────────────────────────────────────────┐
    │ 무엇을 넣지 않는가 (명세서 §4.7)                                  │
    │                                                                  │
    │   · status='dropped' — 중단한 과제                                │
    │   · 산출물이 한 건도 없는 과제                                     │
    │   · 분야 태그가 없는 과제 — 계열을 알 수 없다                      │
    │                                                                  │
    │ 중단한 과제까지 세면 "발의만 하고 접기" 가 이득이 된다. 산출물     │
    │ 없이 끝낸 과제도 마찬가지다. 그 길을 열어 두면 반드시 그쪽으로     │
    │ 간다(CLAUDE.md 2항).                                              │
    └──────────────────────────────────────────────────────────────────┘

    한 과제에 참여한 **사람마다 한 건**을 넣는다. 역량은 사람 단위이므로.
    source_key 는 'rnd:<과제>:<구성원>' 이라 여러 번 돌려도 늘지 않는다.
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT p.id, p.code, p.name, p.rnd_category, p.approved_at, p.updated_at,
                   (SELECT COUNT(*) FROM bs_rnd_output o WHERE o.project_id = p.id) AS outputs
              FROM bs_project p
             WHERE p.project_type = 'rnd'
               AND p.status = 'done'
               AND p.deleted_at IS NULL
            """
        )
        projects = cur.fetchall()

    stat = {"projects": 0, "items": 0, "skipped_no_output": 0,
            "skipped_no_domain": 0, "skipped_no_member": 0}

    for p in projects:
        if int(p["outputs"] or 0) == 0:
            stat["skipped_no_output"] += 1
            continue

        with conn.cursor() as cur:
            cur.execute("SELECT domain_id FROM bs_rnd_domain WHERE project_id = %s", (p["id"],))
            domain_ids = [r["domain_id"] for r in cur.fetchall()]
        if not domain_ids:
            stat["skipped_no_domain"] += 1
            continue

        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT member_id FROM bs_rnd_member
                 WHERE project_id = %s AND status IN ('approved', 'done')
                """,
                (p["id"],),
            )
            member_ids = [r["member_id"] for r in cur.fetchall()]
        if not member_ids:
            stat["skipped_no_member"] += 1
            continue

        diff = rnd_difficulty(p["rnd_category"], int(p["outputs"] or 0))
        # 종료 시각을 모르면 승인 시각으로 둔다. 기간 필터가 이 값을 본다.
        closed = p["updated_at"] or p["approved_at"]

        stat["projects"] += 1
        for mid in member_ids:
            key = f"rnd:{p['id']}:{mid}"
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO bs_work_item
                        (source, source_key, source_url, title, member_id,
                         requested_at, closed_at, status_raw,
                         difficulty, difficulty_by, collected_at)
                    VALUES ('rnd', %s, %s, %s, %s, %s, %s, 'done', %s, 'rule', NOW())
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title), closed_at = VALUES(closed_at),
                        difficulty = VALUES(difficulty), collected_at = NOW()
                    """,
                    (key, f"rnd_view.php?id={p['id']}",
                     f"[R&D] {p['code']} {p['name']}", mid,
                     p["approved_at"], closed, diff),
                )
                cur.execute(
                    "SELECT id FROM bs_work_item WHERE source = 'rnd' AND source_key = %s",
                    (key,),
                )
                wid = cur.fetchone()["id"]

                # 분야 태그를 그대로 옮긴다. 계열은 bs_domain 이 들고 있다.
                cur.execute("DELETE FROM bs_work_item_domain WHERE work_item_id = %s", (wid,))
                for d in domain_ids:
                    cur.execute(
                        "INSERT INTO bs_work_item_domain (work_item_id, domain_id) VALUES (%s, %s)",
                        (wid, d),
                    )
            stat["items"] += 1

    conn.commit()
    return stat


def scoreable_categories(conn) -> list[str]:
    """
    점수 대상 계열 — **데이터가 아니라 분야 마스터에서** 뽑는다.

    전에는 집계에 등장한 계열만 셌다. 그러면 R&D 가 새 계열을 하나 건드리는
    순간 **모든 사람의 breadth 분모가 늘어** 점수가 함께 내려간다.
    "절대 기준이라 남의 점수는 흔들리지 않는다"(명세서 §4.7)가 깨진다.

    scoring-design.md §2 도 분모를 '점수 대상 계열 수' 로 적고 있다 —
    고정된 수다. 그쪽이 맞다.
    """
    with conn.cursor() as cur:
        cur.execute(
            "SELECT DISTINCT category FROM bs_domain "
            " WHERE category IS NOT NULL AND category <> '' ORDER BY category"
        )
        return [r["category"] for r in cur.fetchall() if r["category"] not in NOT_SCORED]


# =====================================================================
# 집계
# =====================================================================

def aggregate(work: list[dict], members: list[dict]) -> dict:
    """
    사람 × (분야 / 계열) 로 모은다.

    난이도는 bs_work_item.difficulty 를 그대로 쓴다. 수집기가 이미 정해 뒀다 —
    사람이 고친 값(manual) > 취합 시스템 채점(ai_stars) > 규칙 기반 순.
    여기서 다시 판정하지 않는다. LLM 은 쓰지 않는다.
    """
    mids = {m["id"] for m in members}

    per_domain: dict = defaultdict(lambda: {"cases": set(), "w": 0.0})
    per_cat: dict = defaultdict(lambda: {"cases": set(), "w": 0.0})
    per_member_items: dict = defaultdict(set)
    evidence: dict = defaultdict(list)

    for r in work:
        mid = r["member_id"]
        if mid not in mids:
            continue                      # 평가 제외자의 건은 통째로 뺀다
        cat = r["category"] or "?"
        diff = int(r["difficulty"] or 3)
        wid = r["id"]

        dk = (mid, r["domain_id"])
        if wid not in per_domain[dk]["cases"]:
            per_domain[dk]["cases"].add(wid)
            per_domain[dk]["w"] += diff

        ck = (mid, cat)
        # 한 건이 같은 계열의 분야 두 개에 걸려도 **한 번만** 센다
        if wid not in per_cat[ck]["cases"]:
            per_cat[ck]["cases"].add(wid)
            per_cat[ck]["w"] += diff

        per_member_items[mid].add(wid)
        evidence[(mid, cat)].append(r)

    return {
        "domain": {k: {"cases": len(v["cases"]), "w": v["w"]} for k, v in per_domain.items()},
        "cat": {k: {"cases": len(v["cases"]), "w": v["w"]} for k, v in per_cat.items()},
        "member_total": {k: len(v) for k, v in per_member_items.items()},
        "evidence": evidence,
    }


def compute(agg: dict, members: list[dict], base_p: float = DEFAULT_BASELINE_P,
            all_cats: list[str] | None = None, agg_base: dict | None = None) -> dict:
    """
    계열 점수 + 종합 지표. 사람끼리 비교하지 않는다.

    ┌──────────────────────────────────────────────────────────────────┐
    │ R&D 가 들어와도 **남의 점수가 흔들리지 않게** 하는 두 가지        │
    │ (명세서 §4.7, P10-3)                                             │
    │                                                                  │
    │ ① 분모를 고정한다 — all_cats                                     │
    │    전에는 집계에 등장한 계열만 셌다. R&D 가 새 계열을 하나        │
    │    건드리면 모든 사람의 breadth 분모가 늘어 점수가 함께 내려갔다. │
    │                                                                  │
    │ ② 기준값은 R&D 를 빼고 낸다 — agg_base                           │
    │    기준값은 '그 계열을 다룬 사람들의 분위' 라 한 사람의 R&D 가    │
    │    분포를 밀면 **모두의 점수가 바뀐다.** 자[尺]는 평소 업무로만   │
    │    만들고, R&D 는 그 자로 잰다.                                   │
    │                                                                  │
    │ 둘을 안 하면 "절대 기준" 이라는 말이 사실이 아니게 된다.          │
    └──────────────────────────────────────────────────────────────────┘
    """
    cats = list(all_cats) if all_cats else sorted(
        {c for (_m, c) in agg["cat"] if c not in NOT_SCORED and c != "?"}
    )

    # 계열별 기준값 — 그 계열을 다룬 사람들의 분위.
    # **R&D 를 뺀 집계**로 낸다(agg_base). 주지 않으면 종전대로 전체로 낸다.
    src = agg_base if agg_base is not None else agg
    baselines = {}
    for c in cats:
        ws = [v["w"] for (m, cc), v in src["cat"].items() if cc == c and v["cases"] > 0]
        baselines[c] = baseline_for(ws, base_p)

    cat_scores: dict = {}
    for m in members:
        mid = m["id"]
        for c in cats:
            v = agg["cat"].get((mid, c))
            cases = v["cases"] if v else 0
            wq = v["w"] if v else 0.0
            conf, insuf = confidence_of(cases)
            score = None
            if cases > 0:
                score = round(min(100.0, wq / baselines[c] * 100.0), 2)
            cat_scores[(mid, c)] = {
                "case_count": cases, "weighted_qty": round(wq, 2),
                "baseline": round(baselines[c], 2), "score": score,
                "confidence": conf, "insufficient_data": 1 if insuf else 0,
            }

    # 종합 지표
    metrics = {}
    all_total_w = {m["id"]: sum(agg["cat"].get((m["id"], c), {"w": 0.0})["w"] for c in cats)
                   for m in members}
    # cap_score 의 기준값도 같은 이유로 R&D 를 뺀 쪽에서 낸다.
    base_total_w = {m["id"]: sum(src["cat"].get((m["id"], c), {"w": 0.0})["w"] for c in cats)
                    for m in members}
    cap_base = baseline_for([v for v in base_total_w.values() if v > 0], base_p)

    for m in members:
        mid = m["id"]
        total_cases = agg["member_total"].get(mid, 0)
        conf, insuf = confidence_of(total_cases)

        cap = round(min(100.0, all_total_w[mid] / cap_base * 100.0), 2) if total_cases else None
        # 커버리지 — 점수 대상 계열 중 '충분(20건+)' 인 계열의 비율
        covered = sum(1 for c in cats if cat_scores[(mid, c)]["confidence"] == "full")
        breadth = round(100.0 * covered / len(cats), 2) if cats else None

        metrics[mid] = {
            "total_cases": total_cases,
            "cap_score": cap,
            "speed_score": None,   # 삭제 — scoring-design.md §2.4
            "comm_score": None,    # 삭제 — 〃
            "breadth_score": breadth,
            "career_score": career_score(int(m.get("career_months") or 0)),
            "covered": covered,
            "confidence": conf,
            "insufficient_data": 1 if insuf else 0,
        }

    return {"cats": cats, "baselines": baselines, "cat_scores": cat_scores, "metrics": metrics}


# =====================================================================
# 적재
# =====================================================================

def start_run(conn, dfrom: date, dto: date, source_stat: str) -> int:
    """
    새 판정 회차를 연다.

    **과거 회차를 덮어쓰지 않는다.** 언제나 새 id 를 만든다. 지난 점수가 왜
    그랬는지 되짚을 수 있어야 하고, 배정안이 그때의 eval_ver 를 가리키고 있다.
    """
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO bs_eval_run (started_at, period_from, period_to,"
            " source_stat, formula_ver, status)"
            " VALUES (NOW(), %s, %s, %s, %s, 'running')",
            (dfrom.isoformat(), dto.isoformat(), source_stat, FORMULA_VER),
        )
        return cur.lastrowid


def finish_run(conn, eval_ver: int, status: str, note: str | None) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE bs_eval_run SET finished_at = NOW(), status = %s, note = %s WHERE id = %s",
            (status, (note or "")[:300], eval_ver),
        )


def persist(conn, eval_ver: int, agg: dict, res: dict, members: list[dict]) -> dict:
    """호출부가 트랜잭션을 연다 — 여기서 커밋하지 않는다."""
    n_skill = n_cat = n_metric = 0
    with conn.cursor() as cur:
        for m in members:
            mid = m["id"]

            # 분야별 — 근거 표시용. score 는 채우지 않는다(계열 단위로만 점수를 낸다)
            for (mm, did), v in agg["domain"].items():
                if mm != mid:
                    continue
                _, insuf = confidence_of(v["cases"])
                cur.execute(
                    "INSERT INTO bs_member_skill"
                    " (member_id, domain_id, eval_ver, case_count, weighted_qty,"
                    "  score, is_primary, insufficient_data)"
                    " VALUES (%s,%s,%s,%s,%s,NULL,0,%s)",
                    (mid, did, eval_ver, v["cases"], round(v["w"], 2), 1 if insuf else 0),
                )
                n_skill += 1

            # 계열별 — 점수
            for c in res["cats"]:
                s = res["cat_scores"][(mid, c)]
                cur.execute(
                    "INSERT INTO bs_member_category"
                    " (member_id, category, eval_ver, case_count, weighted_qty,"
                    "  baseline, score, confidence, insufficient_data)"
                    " VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                    (mid, c, eval_ver, s["case_count"], s["weighted_qty"],
                     s["baseline"], s["score"], s["confidence"], s["insufficient_data"]),
                )
                n_cat += 1

            # 종합 지표
            mt = res["metrics"][mid]
            cur.execute(
                "INSERT INTO bs_member_metric"
                " (member_id, eval_ver, period_from, period_to, total_cases,"
                "  cap_score, speed_score, comm_score, breadth_score, career_score,"
                "  manual_adjust, insufficient_data)"
                " VALUES (%s,%s,(SELECT period_from FROM bs_eval_run WHERE id=%s),"
                "         (SELECT period_to FROM bs_eval_run WHERE id=%s),"
                "         %s,%s,%s,%s,%s,%s,0,%s)",
                (mid, eval_ver, eval_ver, eval_ver, mt["total_cases"],
                 mt["cap_score"], mt["speed_score"], mt["comm_score"],
                 mt["breadth_score"], mt["career_score"], mt["insufficient_data"]),
            )
            n_metric += 1

    return {"skill": n_skill, "category": n_cat, "metric": n_metric}


# =====================================================================
# dry-run 출력
# =====================================================================

def print_result(members: list[dict], agg: dict, res: dict, base_p: float = DEFAULT_BASELINE_P) -> None:
    cats = res["cats"]

    print()
    print(f"계열 기준값 (그 계열을 다룬 사람들의 상위 {(1-base_p)*100:.0f}% 지점 = 점수의 분모)")
    print("  " + "  ".join(f"{CAT_LABEL.get(c, c)} {res['baselines'][c]:.0f}" for c in cats))
    print()

    head = [("구성원", 8), ("건수", 5)] + [(CAT_LABEL.get(c, c), 10) for c in cats] + \
           [("종합", 5), ("커버", 5)]
    line = " ".join(_pad(h, w) for h, w in head)
    print(line)
    print("-" * _w(line))

    for m in sorted(members, key=lambda x: -agg["member_total"].get(x["id"], 0)):
        mid = m["id"]
        mt = res["metrics"][mid]
        cells = [_cut(m["emp_name"], 8), mt["total_cases"]]
        for c in cats:
            s = res["cat_scores"][(mid, c)]
            if s["case_count"] == 0:
                cells.append("-")
            elif s["confidence"] == "none":
                cells.append(f"({s['case_count']}건)")
            else:
                mark = "" if s["confidence"] == "full" else "~"
                cells.append(f"{s['score']:.0f}{mark} {s['case_count']}건")
        cells.append(f"{mt['cap_score']:.0f}" if mt["cap_score"] is not None else "-")
        cells.append(f"{mt['covered']}/{len(cats)}")
        print(" ".join(_pad(c, w) for c, (_, w) in zip(cells, head)))

    print()
    print("  숫자 뒤 ~ = 표본 10~19건(참고)   (N건) = 10건 미만이라 점수를 내지 않음")
    print("  '커버' = 20건 이상 다룬 계열 수 / 점수 대상 계열 수")
    print(f"  점수 제외 계열: {', '.join(CAT_LABEL.get(c, c) for c in sorted(NOT_SCORED))}")


# =====================================================================
def main() -> int:
    ap = argparse.ArgumentParser(description="구성원 역량 점수 산출")
    ap.add_argument("--config", default=str(HERE / "config.ini"))
    ap.add_argument("--from", dest="dfrom")
    ap.add_argument("--to", dest="dto")
    ap.add_argument("--months", type=int, default=6)
    ap.add_argument("--dry-run", action="store_true", help="적재하지 않고 표만 출력")
    args = ap.parse_args()

    cfg = load_config(Path(args.config))

    today = date.today()
    if args.dfrom or args.dto:
        try:
            dfrom = date.fromisoformat(args.dfrom) if args.dfrom else today - timedelta(days=183)
            dto = date.fromisoformat(args.dto) if args.dto else today
        except ValueError as e:
            sys.exit(f"날짜 형식이 잘못됐습니다(YYYY-MM-DD): {e}")
    else:
        dfrom, dto = today - timedelta(days=30 * args.months), today
    if dfrom > dto:
        sys.exit(f"기간이 뒤집혔습니다: {dfrom} ~ {dto}")

    mode = "DRY-RUN (적재하지 않음)" if args.dry_run else "적재"
    print()
    print("BlueStudio 역량 점수 산출")
    print("=" * 66)
    print(f"  모드      {mode}")
    print(f"  기간      {dfrom} ~ {dto}")
    print(f"  점수식    {FORMULA_VER}  (절대 기준 · 계열 단위)")
    _bp = cfg["score"].getfloat("baseline_percentile", DEFAULT_BASELINE_P) if "score" in cfg else DEFAULT_BASELINE_P
    print(f"  기준값    상위 {(1-_bp)*100:.0f}% 지점 ({_bp:.2f} 분위)")
    print(f"  DB        {cfg['db'].get('user')}@{cfg['db'].get('host')}/{cfg['db'].get('name')}")

    conn = connect(cfg["db"])
    eval_ver = None
    try:
        members = fetch_members(conn)
        if not members:
            sys.exit("평가 대상 구성원이 없습니다. bs_member 를 확인하세요.")
        # 종료된 R&D 과제를 먼저 bs_work_item 으로 옮긴다 (P10-3, 명세서 §4.7).
        # dry-run 에서도 옮긴다 — 적재한 뒤의 점수를 미리 보기 위해서다.
        rnd_stat = materialize_rnd(conn, dfrom, dto)
        if rnd_stat["items"]:
            print(f"  R&D      과제 {rnd_stat['projects']}건 → 업무 이력 {rnd_stat['items']}건")
        skipped = (rnd_stat["skipped_no_output"] + rnd_stat["skipped_no_domain"]
                   + rnd_stat["skipped_no_member"])
        if skipped:
            print(f"           제외 {skipped}건 "
                  f"(산출물 없음 {rnd_stat['skipped_no_output']} · "
                  f"분야 태그 없음 {rnd_stat['skipped_no_domain']} · "
                  f"참여자 없음 {rnd_stat['skipped_no_member']})")

        work = fetch_work(conn, dfrom, dto)
        n_rnd = sum(1 for w in work if w.get("source") == "rnd")
        print(f"  대상      구성원 {len(members)}명 / 업무-분야 행 {len(work)}건"
              + (f" (R&D {n_rnd}건 포함)" if n_rnd else ""))

        base_p = DEFAULT_BASELINE_P
        if "score" in cfg:
            base_p = cfg["score"].getfloat("baseline_percentile", DEFAULT_BASELINE_P)

        agg = aggregate(work, members)
        # 기준값(자)은 **R&D 를 뺀 평소 업무로만** 만든다. 한 사람의 R&D 가
        # 분포를 밀어 모두의 점수를 바꾸면 '절대 기준' 이 아니다 (명세서 §4.7).
        agg_base = aggregate([w for w in work if w.get("source") != "rnd"], members)
        res = compute(agg, members, base_p,
                      all_cats=scoreable_categories(conn), agg_base=agg_base)

        if not res["cats"]:
            sys.exit("점수를 낼 계열이 없습니다. 수집기를 먼저 돌렸는지 확인하세요.")

        print_result(members, agg, res, base_p)

        if args.dry_run:
            print()
            print("  적재하지 않았습니다. 확인되면 --dry-run 을 빼고 다시 실행하세요.")
            print()
            return 0

        stat = f'{{"work_items":{len(set(r["id"] for r in work))},"members":{len(members)}}}'
        eval_ver = start_run(conn, dfrom, dto, stat)
        conn.commit()

        conn.begin()
        n = persist(conn, eval_ver, agg, res, members)
        conn.commit()

        finish_run(conn, eval_ver, "ok", None)
        conn.commit()

        print()
        print(f"  적재 완료  eval_ver={eval_ver}  "
              f"분야 {n['skill']}행 / 계열 {n['category']}행 / 종합 {n['metric']}행")
        print()
        return 0

    except Exception as e:
        conn.rollback()
        if eval_ver is not None:
            try:
                finish_run(conn, eval_ver, "fail", f"{type(e).__name__}: {e}")
                conn.commit()
            except Exception:
                pass
        print(f"\n[실패] {type(e).__name__}: {e}", file=sys.stderr)
        print("적재를 되돌렸습니다.", file=sys.stderr)
        return 1
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
