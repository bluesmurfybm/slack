from tools import import_xlsx as ix


def test_excel_date_uses_1899_12_30_epoch():
    assert ix.excel_date("45838") == "2025-06-30"
    assert ix.excel_date("45845") == "2025-07-07"
    assert ix.excel_date("") == ""
    assert ix.excel_date("발표예정") == ""


def test_numstr_strips_float_artifact():
    # xlsx 숫자 셀의 원시값. 화면 표시는 "20. 2025"
    assert ix.numstr("20.202500000000001") == "20.2025"
    assert ix.numstr("278") == "278"
    assert ix.numstr("275. Oct-Nov") == "275. Oct-Nov"
    assert ix.numstr("") == ""


def test_team_goes_to_team_not_presenter():
    vals = ["Trend", "제목", "키워드", "App", "", "", "DI", "178", "2025", "277", "미지정"]
    t = ix.to_topic(vals, done_sheet=False)
    assert t["team"] == "App"
    assert t["presenter"] == ""
    assert t["presenter_email"] == ""


def test_known_person_gets_email():
    vals = ["UI/UX", "제목", "키워드", "김태주", "45908", "", "DI", "52", "2025", "279", "미지정"]
    t = ix.to_topic(vals, done_sheet=False)
    assert t["presenter"] == "김태주"
    assert t["presenter_email"] == "pink@bluesoft.co.kr"
    assert t["planned_date"] == "2025-09-08"
    assert t["done_date"] == ""


def test_done_sheet_fills_done_date():
    vals = ["Trend", "제목", "키워드", "김호영", "45677", "45677", "MIT TR", "40", "2024", "16. Sep-Oct", "발표 완료"]
    t = ix.to_topic(vals, done_sheet=True)
    assert t["done_date"] == "2025-01-20"


def test_done_sheet_falls_back_to_planned_date_when_done_date_blank():
    # 원본 '발표완료' 시트에 발표일이 비어 있는 행이 1건 있다.
    # 비워 두면 상태 파생이 '발표 완료' -> '미지정'으로 뒤집힌다.
    vals = ["Marketing", "이탈률", "마케팅 검색엔진", "이승민", "45775", "",
            "DI", "142", "2025", "", "발표 완료"]
    assert ix.to_topic(vals, done_sheet=True)["done_date"] == "2025-04-28"


def test_planned_sheet_never_fills_done_date():
    vals = ["UI/UX", "제목", "", "김태주", "45908", "", "DI", "52", "2025", "279", "미지정"]
    assert ix.to_topic(vals, done_sheet=False)["done_date"] == ""


def test_requirement_defaults_to_recommended():
    vals = ["Etc", "제목", "", "", "", "", "Etc", "", "", "", ""]
    assert ix.to_topic(vals, done_sheet=False)["requirement"] == "recommended"


def test_note_preserves_original_text():
    vals = ["UI/UX", "제목", "", "김태주", "45908", "", "DI", "52", "2025", "279", "미지정"]
    # 비고는 상태로 해석하지 않고 원문 보존
    assert ix.to_topic(vals, done_sheet=False)["note"] == "미지정"
