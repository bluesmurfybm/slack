import json
from datetime import datetime
from pathlib import Path

import pytest

from core.schedule import next_run

CASES = json.loads((Path(__file__).resolve().parents[2] / "tests" / "schedule_cases.json").read_text(encoding="utf-8"))
FMT = "%Y-%m-%d %H:%M:%S"


def _dt(s):
    return datetime.strptime(s, FMT) if s else None


@pytest.mark.parametrize("case", CASES["cases"], ids=lambda c: c["name"])
def test_same_as_php(case):
    w = {**CASES["base"], **case["w"]}
    w["starts_at"], w["ends_at"] = _dt(w["starts_at"]), _dt(w["ends_at"])
    got = next_run(w, _dt(case["after"]))
    assert (got.strftime(FMT) if got else None) == case["expect"]
