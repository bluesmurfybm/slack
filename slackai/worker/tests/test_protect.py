"""환경별 설정 파일 보호(core/protect.py): 규칙 생성 · 플랜/상태/diff 필터 · argv 반영."""

from core import protect
from core.config import Settings
from tools.claude_cli import READ_ONLY, exec_argv, plan_argv, review_argv
from vcs.base import StatusEntry

P = protect.parse("config.php; local/ubion/config.php")


def test_parse_and_default_setting():
    assert P == ["config.php", "local/ubion/config.php"]
    assert protect.parse(r".\local\ubion\config.php,/config.php") == ["local/ubion/config.php", "config.php"]
    assert Settings(_env_file=None).protected_list == ["config.php", "local/ubion/config.php"]


def test_is_protected_exact_paths_only():
    assert protect.is_protected("config.php", P)
    assert protect.is_protected("./local/ubion/config.php", P)
    assert protect.is_protected(r"local\ubion\CONFIG.php", P)
    assert not protect.is_protected("theme/boost/config.php", P)     # 같은 이름의 하위 파일은 대상 아님
    assert not protect.is_protected("local/ubion/lib.php", P)


def test_claude_rules_cover_read_edit_bash():
    r = protect.claude_rules(P, "svn://1.2.3.4/inha/moodle", r"G:\01_Bluesoft\2020_INHA\03_Source\moodle")
    for rule in ("Read(./config.php)", "Edit(./config.php)", "Read(./local/ubion/config.php)",
                 "Edit(./local/ubion/config.php)", "Bash(*local/ubion/config.php*)", "Bash(*:config.php*)",
                 "Bash(* config.php*)", "Bash(*svn://1.2.3.4/inha/moodle/config.php*)", "Bash(svn diff)", "Bash(git diff)"):
        assert rule in r, rule
    assert len(r) == len(set(r))
    assert protect.claude_rules([], "x", "y") == []


def test_argv_includes_protect_rules_and_no_git_grep():
    rules = protect.claude_rules(P)
    for argv in (plan_argv(["claude"], schema={}, session_id="s", model="m", budget=1, system_md="S", protect=rules),
                 exec_argv(["claude"], session_id="s", resume=True, model="m", budget=1, system_md="S", protect=rules),
                 review_argv(["claude"], schema={}, model="m", budget=1, system_md="S", protect=rules)):
        i = argv.index("--disallowedTools")
        assert "Read(./config.php)" in argv[i:]
    assert not any("git grep" in t for t in READ_ONLY)


def test_strip_plan_removes_files_and_notes_risk():
    plan = {"files": [{"path": "config.php", "action": "modify", "why": "x"},
                      {"path": "local/ubion/lib.php", "action": "modify", "why": "y"}],
            "steps": [{"n": 1, "text": "t", "files": ["config.php", "local/ubion/lib.php"]}], "risks": []}
    out = protect.strip_plan(plan, P)
    assert [f["path"] for f in out["files"]] == ["local/ubion/lib.php"]
    assert out["steps"][0]["files"] == ["local/ubion/lib.php"]
    assert out["risks"] and "config.php" in out["risks"][-1]
    assert protect.strip_plan({"files": [], "steps": [], "risks": []}, P)["risks"] == []


def test_filter_entries_and_diff():
    ents = [StatusEntry("M", "config.php"), StatusEntry("M", "local/ubion/config.php"), StatusEntry("M", "course/lib.php")]
    assert [e.path for e in protect.filter_entries(ents, P)] == ["course/lib.php"]
    svn = ("Index: config.php\n===\n--- config.php\n+++ config.php\n@@ -1 +1 @@\n-a\n+SECRET\n"
           "Index: course/lib.php\n===\n--- course/lib.php\n+++ course/lib.php\n@@ -1 +1 @@\n-x\n+y\n")
    out = protect.filter_diff(svn, P)
    assert "SECRET" not in out and "course/lib.php" in out
    git = ("diff --git a/local/ubion/config.php b/local/ubion/config.php\n@@\n+KEY\n"
           "diff --git a/lib/a.php b/lib/a.php\n@@\n+ok\n")
    out = protect.filter_diff(git, P)
    assert "KEY" not in out and "+ok" in out


def test_fingerprint_detects_change(tmp_path):
    (tmp_path / "config.php").write_text("a", encoding="utf-8")
    a = protect.fingerprint(str(tmp_path), P)
    (tmp_path / "config.php").write_text("b", encoding="utf-8")
    b = protect.fingerprint(str(tmp_path), P)
    assert a["config.php"] != b["config.php"] and a["local/ubion/config.php"] is None
