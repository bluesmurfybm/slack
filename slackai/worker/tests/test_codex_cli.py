import json

from tools import codex_cli
from tools.runner import FakeRunner


def test_review_argv_exact():
    argv = codex_cli.review_argv(["codex"], local_path="G:/wc/x", out_file="C:/t/last.md")
    assert argv == ["codex", "exec", "--sandbox", "read-only", "-C", "G:/wc/x", "--skip-git-repo-check", "--json",
                    "--output-last-message", "C:/t/last.md", "-"]
    argv2 = codex_cli.review_argv(["codex"], local_path="p", out_file="f", model="o3")
    assert argv2[-3:] == ["-m", "o3", "-"]


def test_resolve_codex_missing():
    assert codex_cli.resolve_codex("codex", which=lambda n: None) is None
    assert codex_cli.available("codex", which=lambda n: None) is False
    assert codex_cli.resolve_codex("codex", which=lambda n: "/x/codex") == ["/x/codex"]


def test_run_codex_reads_last_message_file(tmp_path):
    out_file = tmp_path / "last.md"
    data = {"verdict": "warn", "summary_md": "s", "addresses_inquiry": True, "findings": [], "missing": [], "test_suggestions": []}

    def fn(argv, call):
        out_file.write_text("리뷰 결과:\n```json\n" + json.dumps(data) + "\n```\n", encoding="utf-8")
        from tools.runner import Completed

        return Completed(argv, 0, b'{"type":"item.completed","item":{"text":"x"}}\n', b"")

    r = FakeRunner().on("codex", fn=fn)
    res = codex_cli.run_codex(r, ["codex", "exec", "-"], "prompt", cwd=None, env=None, timeout=10, out_file=out_file,
                              job_dir=tmp_path / "job")
    assert res.ok and res.data["verdict"] == "warn"
    assert (tmp_path / "job" / "codex_prompt.txt").read_text(encoding="utf-8") == "prompt"
    assert r.calls[0]["input"] == b"prompt"


def test_run_codex_falls_back_to_jsonl(tmp_path):
    out_file = tmp_path / "none.md"
    data = {"verdict": "pass", "summary_md": "", "addresses_inquiry": True, "findings": [], "missing": [], "test_suggestions": []}
    jsonl = json.dumps({"type": "item.completed", "item": {"type": "agent_message", "text": json.dumps(data)}}) + "\n"
    r = FakeRunner().on("codex", stdout=jsonl)
    res = codex_cli.run_codex(r, ["codex"], "p", cwd=None, env=None, timeout=1, out_file=out_file)
    assert res.ok and res.data["verdict"] == "pass"


def test_run_codex_failure():
    r = FakeRunner().on("codex", rc=1, stderr="not logged in")
    res = codex_cli.run_codex(r, ["codex"], "p", cwd=None, env=None, timeout=1, out_file=__import__("pathlib").Path("nope.md"))
    assert not res.ok and "not logged in" in res.error
