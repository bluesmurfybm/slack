import json

import requests

from collectors import devdocs, github, moodlecom, moodleorg, tracker
from collectors.base import run_safely
from conftest import SINCE, make_ctx

JIRA = "https://moodle.atlassian.net/rest/api/3/search/jql"
GH = "https://api.github.com/repos/moodle/moodle"
DD = "https://api.github.com/repos/moodle/devdocs"


def _issue(key, summary, resolved, itype="Bug", resolution="Fixed", comps=(), fixes=()):
    return {"key": key, "fields": {
        "summary": summary, "resolutiondate": resolved,
        "issuetype": {"name": itype}, "resolution": {"name": resolution},
        "components": [{"name": c} for c in comps], "fixVersions": [{"name": v} for v in fixes],
        "labels": [], "priority": {"name": "Minor"}}}


def test_tracker_paginates_filters_and_marks_focus(settings):
    pages = [
        {"issues": [_issue("MDL-1", "Adopt React for grader", "2026-09-05T10:00:00.000+0800",
                           "Improvement", comps=("JavaScript",), fixes=("5.3",)),
                    _issue("MDL-2", "Fix typo", "2026-09-04T10:00:00.000+0800")],
         "nextPageToken": "t2", "isLast": False},
        {"issues": [_issue("MDL-3", "Old one", "2026-08-30T10:00:00.000+0800"),
                    _issue("MDL-4", "Won't do", "2026-09-03T10:00:00.000+0800",
                           "Task", resolution="Won't Do")],
         "isLast": True},
    ]
    calls = iter(pages)
    ctx = make_ctx(settings, {JIRA: lambda p: next(calls)})
    r = tracker.collect(ctx)

    assert r.status == "ok"
    keys = [i.meta["key"] for i in r.items]
    assert keys == ["MDL-1", "MDL-2", "MDL-4"] # MDL-3 은 구간 밖
    assert [i.is_focus for i in r.items] == [True, False, False] # Won't Do 는 주목 아님
    assert r.stats["resolved"] == 3
    assert r.stats["fixed"] == 2
    assert r.stats["fix_versions"] == {"5.3": 1}
    assert r.items[0].url == "https://moodle.atlassian.net/browse/MDL-1"
    assert ctx.http.calls[1][1]["nextPageToken"] == "t2"


def test_tracker_stops_at_max_pages(settings):
    settings = settings.model_copy(update={"tracker_max_pages": 2})
    ctx = make_ctx(settings, {JIRA: {"issues": [], "nextPageToken": "x", "isLast": False}})
    r = tracker.collect(ctx)
    assert len(ctx.http.calls) == 2
    assert r.stats["truncated"] is True


def _commit(sha, msg, date="2026-09-03T01:00:00Z"):
    return {"sha": sha, "commit": {"message": msg, "committer": {"date": date}}}


def test_github_counts_commits_and_extracts_upgrade_txt(settings):
    routes = {
        f"{GH}/commits": lambda p: ([_commit("u1", "MDL-9 deprecate foo")] if p.get("path")
                                    else [_commit("a", "MDL-1 x"), _commit("b", "MDL-1 y"),
                                          _commit("c", "MDL-2 z")]),
        f"{GH}/commits/u1": {"files": [{"filename": "lib/upgrade.txt",
                                        "patch": "@@ -1 +1,2 @@\n context\n+* foo() is deprecated\n+* bar removed"}]},
        f"{GH}/branches": [{"name": "main"}, {"name": "MOODLE_502_STABLE"},
                           {"name": "MOODLE_503_STABLE"}],
        f"{GH}/tags": [{"name": "v5.3.0-rc1"}, {"name": "v5.2.2"}],
    }
    ctx = make_ctx(settings, routes)
    ctx.state.data["branches"] = ["MOODLE_502_STABLE"]
    ctx.state.data["tags"] = ["v5.2.2"]
    r = github.collect(ctx)

    assert r.stats["main_commits"] == 3
    assert r.stats["main_issue_keys"] == 2
    kinds = [i.kind for i in r.items]
    assert kinds == ["upgrade_txt", "branch", "release"]
    assert r.items[0].excerpt == "* foo() is deprecated\n* bar removed"
    assert r.items[0].meta["issue_keys"] == ["MDL-9"]
    assert "MOODLE_503_STABLE" in r.items[1].title
    assert r.items[2].is_focus is False # rc 태그는 주목 아님
    assert ctx.state.data["branches"] == ["MOODLE_502_STABLE", "MOODLE_503_STABLE"]


def test_github_first_run_only_records_baseline(settings):
    routes = {f"{GH}/commits": [], f"{GH}/branches": [{"name": "MOODLE_502_STABLE"}],
              f"{GH}/tags": [{"name": "v5.2.2"}]}
    ctx = make_ctx(settings, routes)
    r = github.collect(ctx)
    assert r.items == []
    assert ctx.state.data["tags"] == ["v5.2.2"]


def test_devdocs_dedupes_commits_and_keeps_only_watched_md(settings):
    c = _commit("d1", "[docs] Adding release notes for 5.2.2")
    routes = {
        f"{DD}/commits": lambda p: [c] if p["path"] in ("general/releases", "general/releases.md") else [],
        f"{DD}/commits/d1": {"files": [
            {"filename": "general/releases.md", "status": "modified", "patch": "@@\n+| 5.2.2 | ..."},
            {"filename": "general/_releases/x.png", "status": "added"},
            {"filename": "general/releases/5.2/5.2.2.md", "status": "added", "patch": "+# 5.2.2\n+Fixes"},
        ]},
    }
    ctx = make_ctx(settings, routes)
    r = devdocs.collect(ctx)

    assert r.stats["commits"] == 1
    assert len(r.items) == 1
    it = r.items[0]
    assert it.meta["files"] == ["general/releases.md", "general/releases/5.2/5.2.2.md"]
    assert "### general/releases.md (modified)\n| 5.2.2 | ..." in it.excerpt
    assert it.is_focus is True
    assert it.url.endswith("/commit/d1")


def test_devdocs_single_md_links_to_the_site(settings):
    c = _commit("d2", "devupdate")
    routes = {f"{DD}/commits": lambda p: [c] if p["path"] == "docs/devupdate.md" else [],
              f"{DD}/commits/d2": {"files": [{"filename": "docs/devupdate.md", "status": "modified",
                                              "patch": "+new"}]}}
    r = devdocs.collect(make_ctx(settings, routes))
    assert r.items[0].url == "https://moodledev.io/devupdate"


RSS = """<?xml version="1.0"?><rss><channel>
<item><title>Moodle 5.2.2 released</title><link>https://moodle.com/news/a</link>
<pubDate>Tue, 02 Sep 2026 09:00:00 +0000</pubDate><category>Releases</category>
<description><![CDATA[<p>Security &amp; fixes</p>]]></description></item>
<item><title>Old</title><link>https://moodle.com/news/b</link>
<pubDate>Tue, 05 Aug 2026 09:00:00 +0000</pubDate><description>x</description></item>
</channel></rss>"""


def test_moodlecom_keeps_only_items_in_period(settings):
    r = moodlecom.collect(make_ctx(settings, {"https://moodle.com/feed/": RSS}))
    assert r.stats == {"feed_items": 2, "in_period": 1}
    it = r.items[0]
    assert it.title == "Moodle 5.2.2 released"
    assert it.excerpt == "Security & fixes"
    assert it.meta["categories"] == ["Releases"]
    assert it.published_at == "2026-09-02T09:00:00+00:00"


def test_moodleorg_is_skipped_without_a_token(settings):
    r = moodleorg.collect(make_ctx(settings, {}))
    assert r.status == "skipped"
    assert "MOODLE_ORG_TOKEN" in r.note


def _ws(fn):
    return lambda p: fn(p) if p["wsfunction"] == fn.__name__ else None


def test_moodleorg_collects_new_posts_and_page_changes(settings):
    settings = settings.model_copy(update={"moodle_org_token": "tok"})
    since_ts = int(SINCE.timestamp())
    contents = [{"modules": [
        {"id": 8863, "modname": "forum", "instance": 100, "name": "PAG Announcements"},
        {"id": 8876, "modname": "page", "instance": 7, "name": "Roadmap",
         "url": "https://moodle.org/mod/page/view.php?id=8876",
         "contents": [{"type": "file", "filename": "index.html",
                       "fileurl": "https://moodle.org/webservice/pluginfile.php/1/mod_page/content/1/index.html?forcedownload=1"}]},
    ]}]
    page_html = {"v": "<h1>Roadmap</h1><p>v1</p>"}
    contents[0]["modules"].append(
        {"id": 8868, "modname": "book", "instance": 9, "name": "Architecture",
         "contents": [{"type": "file", "filename": "index.html",
                       "fileurl": "https://moodle.org/webservice/pluginfile.php/2/mod_book/chapter/1/index.html"}]})

    def ws(p):
        fn = p["wsfunction"]
        if fn == "core_course_get_contents":
            return contents
        if fn == "mod_forum_get_forum_discussions":
            assert p["forumid"] == 100
            return {"discussions": [{"id": 900, "discussion": 55, "name": "PAG meeting delay",
                                     "timemodified": since_ts + 1000}]}
        if fn == "mod_page_get_pages_by_courses":
            assert p["courseids[0]"] == 17257
            return {"pages": [{"coursemodule": 8876, "content": page_html["v"], "revision": 1}]}
        if fn == "mod_forum_get_discussion_posts":
            return {"posts": [
                {"id": 900, "subject": "PAG meeting delay", "message": "<p>OAuth 2.0 in 5.3</p>",
                 "timecreated": since_ts + 1000, "author": {"fullname": "Sam"}},
                {"id": 901, "subject": "Re: old", "message": "old", "timecreated": since_ts - 5},
            ]}
        raise AssertionError(fn)

    def blocked(p):
        raise requests.HTTPError("403 Client Error: Forbidden") # Cloudflare 가 pluginfile 을 막는 경우

    routes = {"https://moodle.org/webservice/rest/server.php": ws,
              "https://moodle.org/webservice/pluginfile.php": blocked}

    ctx = make_ctx(settings, routes)
    r1 = moodleorg.collect(ctx)
    assert r1.status == "ok"
    assert [i.kind for i in r1.items] == ["forum_post"] # 첫 실행: 페이지는 기준만
    post = r1.items[0]
    assert post.title == "[PAG Announcements] PAG meeting delay"
    assert post.url == "https://moodle.org/mod/forum/discuss.php?d=55#p900"
    assert post.excerpt == "OAuth 2.0 in 5.3"
    assert post.meta["author"] == "Sam"
    assert post.is_focus is True
    assert r1.stats["baseline"] == 1
    assert r1.stats["pages_checked"] == 2
    assert r1.stats["unavailable"] == ["Architecture: HTTPError"] # 책은 못 받아도 수집은 계속된다
    assert "Architecture" in r1.note
    assert (ctx.settings.data_path / "pages" / "8876.txt").read_text(encoding="utf-8") == "Roadmap\nv1"

    page_html["v"] = "<h1>Roadmap</h1><p>v2 with React</p>"
    ctx2 = make_ctx(settings, routes)
    ctx2.state.data = ctx.state.data
    r2 = moodleorg.collect(ctx2)
    changes = [i for i in r2.items if i.kind == "page_change"]
    assert len(changes) == 1
    assert "-v1" in changes[0].excerpt
    assert "+v2 with React" in changes[0].excerpt
    assert changes[0].url == "https://moodle.org/mod/page/view.php?id=8876"
    assert r2.stats["pages_changed"] == 1


def test_moodleorg_surfaces_ws_errors(settings):
    settings = settings.model_copy(update={"moodle_org_token": "bad"})
    routes = {"https://moodle.org/webservice/rest/server.php":
              {"exception": "moodle_exception", "errorcode": "invalidtoken", "message": "Invalid token"}}
    r = run_safely("moodleorg", moodleorg.collect, make_ctx(settings, routes))
    assert r.status == "failed"
    assert "invalidtoken" in r.note


def test_run_safely_turns_exceptions_into_failed_results(settings):
    def boom(ctx):
        raise ValueError("nope")

    r = run_safely("x", boom, make_ctx(settings, {}))
    assert r.status == "failed"
    assert r.note.startswith("ValueError: nope")
    assert json.dumps(r.to_dict()) # 직렬화 가능
