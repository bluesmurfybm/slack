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

    done = excel_date(vals[C_DONE]) if done_sheet else ""
    if done_sheet and not done:
        # 발표완료 시트인데 발표일이 비어 있는 행이 원본에 1건 있다.
        # 상태를 done_date 에서 파생하므로 비워 두면 '발표 완료'가 '미지정'으로 뒤집힌다.
        # 예정일로 대체한다.
        done = excel_date(vals[C_PLANNED])

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
        "done_date":       done,
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
