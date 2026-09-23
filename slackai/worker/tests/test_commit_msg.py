import re

from conftest import REQ, FakeDb, FakeLLM, make_settings

from core.config import Effective
from jobs.base import JobContext
from jobs.commit import SUBJECT_RE, area_from_files, build_message, format_subject, template_message

RID = "Rec0TEST00001"


def test_format_subject_keeps_valid():
    s = format_subject("fix(ubattend): 출석 인정 시간 재계산 [Rec0TEST00001]", RID)
    assert s == "fix(ubattend): 출석 인정 시간 재계산 [Rec0TEST00001]"


def test_format_subject_appends_rec_id_and_type():
    s = format_subject("출석 인정 시간 재계산", RID, area_hint="ubattend")
    assert s == "fix(ubattend): 출석 인정 시간 재계산 [Rec0TEST00001]" and SUBJECT_RE.match(s)


def test_format_subject_fixes_wrong_rec_id_and_type():
    s = format_subject("feat(vod): 기능 추가 [RecWRONG1]", RID)
    assert s == "feat(vod): 기능 추가 [Rec0TEST00001]"
    s2 = format_subject("docs(x): 문서", RID, area_hint="misc")
    assert s2.startswith("fix(x): 문서 [")


def test_format_subject_truncates_to_72():
    long = "fix(ubattend): " + "매우 긴 제목 " * 20
    s = format_subject(long, RID)
    assert len(s) <= 72 and s.endswith(" [Rec0TEST00001]") and "…" in s and SUBJECT_RE.match(s)


def test_format_subject_area_capped_20():
    s = format_subject("fix(a_very_long_area_name_over_twenty_chars): t", RID)
    m = SUBJECT_RE.match(s)
    assert m and len(m.group(2)) <= 20


def test_area_from_files():
    assert area_from_files(["local/ubattend/lib.php"]) == "ubattend"
    assert area_from_files(["mod/vod/classes/x.php"]) == "mod"
    assert area_from_files(["config.php"]) == "config"
    assert area_from_files([]) == "misc"


def test_template_message_has_ref_and_files():
    msg = template_message(REQ, ["local/ubattend/lib.php"], [{"path": "local/ubattend/lib.php", "add": 3, "del": 1}],
                           "http://dev.iworks.co.kr", "요약")
    first = msg.splitlines()[0]
    assert SUBJECT_RE.match(first) and first.endswith(f"[{REQ['id']}]") and len(first) <= 72
    assert "- local/ubattend/lib.php (+3 -1)" in msg
    assert f"Ref: http://dev.iworks.co.kr/slackai/lists.php?id={REQ['id']}" in msg


def test_build_message_via_llm_enforces_format(tmp_path):
    s = make_settings(tmp_path)
    llm = FakeLLM()
    ctx = JobContext(db=FakeDb(), settings=s, eff=Effective(s, {}), job={"id": 7, "kind": "commit", "request_id": REQ["id"]},
                     runner=None, llm=llm)
    msg = build_message(ctx, REQ, ["local/ubattend/lib.php"], [], "요약")
    first = msg.splitlines()[0]
    assert first == f"fix(ubattend): 동영상 교체 후 출석 인정 시간 재계산 [{REQ['id']}]"
    assert "Ref: http://dev.iworks.co.kr/slackai/lists.php?id=" in msg
    assert llm.calls[0]["key"] == "COMMITMSG" and llm.calls[0]["model"] == s.llm_model_fast
    assert ctx.cost_usd > 0
    assert (tmp_path / "var" / "jobs" / "7" / "commitmsg_user.md").is_file()


def test_build_message_falls_back_to_template_on_llm_error(tmp_path):
    s = make_settings(tmp_path)

    class Boom(FakeLLM):
        def generate(self, **kw):
            from llm.base import LLMError

            raise LLMError("down")

    ctx = JobContext(db=FakeDb(), settings=s, eff=Effective(s, {}), job={"id": 8, "kind": "commit", "request_id": REQ["id"]},
                     runner=None, llm=Boom())
    msg = build_message(ctx, REQ, ["local/ubattend/lib.php"], [], None)
    assert re.match(r"^fix\(ubattend\): .+ \[Rec0TEST00001\]$", msg.splitlines()[0])
