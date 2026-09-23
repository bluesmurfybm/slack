import json

from conftest import REPOS, REQ, SCHOOLS

from core.config import Effective, Settings
from jobs.repo_resolve import RepoGuess, host_of, resolve, title_customer
from jobs.triage import decide_repo, should_plan


def test_host_of():
    assert host_of("https://www.LXP.kau.ac.kr/local/x.php?a=1") == "lxp.kau.ac.kr"
    assert host_of("kau.moodler.kr") == "kau.moodler.kr"
    assert host_of("") == "" and host_of(None) == ""


def test_title_customer():
    assert title_customer("[항공대] 출석 오류") == "항공대"
    assert title_customer("출석 오류") == ""


def test_lms_url_maps_to_single_repo():
    g = resolve(REQ, REPOS, SCHOOLS)
    assert g.repo_id == 1 and g.confidence == 1.0 and g.reason == "lms_url"
    assert g.candidates[0]["id"] == 1


def test_url_pattern_rule():
    req = dict(REQ, lms="http://gcu.moodler.kr/course/view.php", title="강좌 오류")
    g = resolve(req, REPOS, SCHOOLS)
    assert g.repo_id == 2 and g.reason == "url_pattern" and g.confidence == 0.95


def test_title_customer_rule_without_lms():
    req = dict(REQ, lms="", title="[항공대] 시험 오류")
    g = resolve(req, REPOS, SCHOOLS)
    assert g.repo_id == 1 and g.reason == "title_customer" and g.confidence == 0.9


def test_title_keyword_rule():
    req = dict(REQ, lms="", title="csms 공통 이슈")
    g = resolve(req, REPOS, SCHOOLS)
    assert g.repo_id == 2 and g.confidence == 0.85


def test_ambiguous_school_with_two_repos_gives_candidates_only():
    repos = REPOS + [dict(REPOS[0], id=3, name="KAU LXP dev")]
    g = resolve(dict(REQ, title="x"), repos, SCHOOLS)
    assert g.repo_id is None and {c["id"] for c in g.candidates} == {1, 3}


def test_no_match():
    g = resolve(dict(REQ, lms="https://unknown.example.com", title="아무거나"), REPOS, SCHOOLS)
    assert g.repo_id is None and g.candidates == []
    assert resolve(REQ, [], SCHOOLS).repo_id is None


def test_inactive_repo_ignored():
    repos = [dict(REPOS[0], active=0), REPOS[1]]
    assert resolve(dict(REQ, title="x"), repos, SCHOOLS).repo_id is None


def test_decide_repo_deterministic_beats_llm():
    g = RepoGuess(1, 1.0, "lms_url", [{"id": 1, "score": 1.0, "reason": "lms_url"}])
    rid, conf, reason, _ = decide_repo(g, {"repo_id": 2, "confidence": 0.99, "reason": "x"}, {1, 2}, 0.75)
    assert (rid, conf, reason) == (1, 1.0, "lms_url")


def test_decide_repo_llm_threshold_and_unknown_id():
    rid, conf, reason, cands = decide_repo(RepoGuess(), {"repo_id": 2, "confidence": 0.8, "reason": "r"}, {1, 2}, 0.75)
    assert rid == 2 and reason == "llm" and cands[-1]["reason"] == "llm"
    rid2, _, reason2, _ = decide_repo(RepoGuess(), {"repo_id": 2, "confidence": 0.5, "reason": "r"}, {1, 2}, 0.75)
    assert rid2 is None and reason2 is None
    rid3, _, _, cands3 = decide_repo(RepoGuess(), {"repo_id": 99, "confidence": 0.99, "reason": "r"}, {1, 2}, 0.75)
    assert rid3 is None and cands3 == []


def test_should_plan_gating():
    s = Settings(_env_file=None)
    eff_on = Effective(s, {"auto_plan": "1"})
    eff_off = Effective(s, {"auto_plan": "0"})
    # 분석하면 플랜까지: 상태·문제유형과 무관하게 레포만 확정되면 실행
    assert should_plan(eff_on, {"status": "등록"}, 1)
    assert should_plan(eff_on, {"status": "진행중"}, 1)
    assert should_plan(eff_on, {"status": "등록"}, 1, "draft")        # 초안은 새 버전으로 대체
    assert should_plan(eff_on, {"status": "등록"}, 1, "rejected")
    assert not should_plan(eff_on, {"status": "등록"}, None)           # 레포 미확정
    assert not should_plan(eff_on, {"status": "등록", "archived": 1}, 1)
    assert not should_plan(eff_on, {"status": "등록"}, 1, "approved")  # 사람이 진행 중
    assert not should_plan(eff_on, {"status": "등록"}, 1, "committed")
    assert not should_plan(eff_off, {"status": "등록"}, 1)


def test_effective_overlay_types():
    s = Settings(_env_file=None, max_daily_usd=30, reviewer="codex,claude")
    e = Effective(s, {"paused": "1", "max_daily_usd": "12.5", "reviewer": "openai, claude", "poll_interval_sec": "0",
                      "budget_plan_usd": "abc"})
    assert e.paused and e.max_daily_usd == 12.5 and e.reviewer_chain == ["openai", "claude"]
    assert e.poll_interval_sec == 1 and e.budget_plan_usd == s.max_budget_plan_usd
    assert json.dumps(e.raw("nope", "d")) == '"d"'


def test_plan_model_choice():
    s = Settings(_env_file=None)
    e = Effective(s, {})
    assert e.plan_model_auto == "claude-haiku-4-5"                       # 자동 플랜 기본 = haiku
    assert e.plan_model_for(None) == "claude-haiku-4-5"
    assert e.plan_model_for("claude-opus-5") == "claude-opus-5"          # 사람이 고른 모델
    assert e.plan_model_for("gpt-5") == "claude-haiku-4-5"               # 허용 목록 밖 → 기본
    assert Effective(s, {"plan_model_auto": "claude-sonnet-5"}).plan_model_for(None) == "claude-sonnet-5"
    assert Effective(s, {"plan_model_auto": "nope"}).plan_model_auto == "claude-haiku-4-5"
