from vcs.base import cap_diff, changed_files, diff_stat, sum_stat, tracked_changes, untracked
from vcs.git import parse_porcelain
from vcs.svn import parse_commit_revision, parse_status

SVN_STATUS_EN = """M       local/ubattend/lib.php
A       local/ubattend/classes/new.php
?       var/tmp.txt
D       mod/old.php
!       missing.php
        > moved from x
M       local/한글 경로/파일.php
"""


def test_svn_status_parse_codes_and_paths():
    st = parse_status(SVN_STATUS_EN)
    codes = {e.path: e.code for e in st}
    assert codes["local/ubattend/lib.php"] == "M"
    assert codes["local/ubattend/classes/new.php"] == "A"
    assert codes["var/tmp.txt"] == "?"
    assert codes["mod/old.php"] == "D"
    assert codes["missing.php"] == "!"
    assert codes["local/한글 경로/파일.php"] == "M"
    assert untracked(st) == ["var/tmp.txt"]
    assert {e.path for e in tracked_changes(st)} == {"local/ubattend/lib.php", "local/ubattend/classes/new.php",
                                                     "mod/old.php", "missing.php", "local/한글 경로/파일.php"}


def test_svn_status_windows_backslashes_normalized():
    st = parse_status("M       local\\ubattend\\lib.php\r\n")
    assert st[0].path == "local/ubattend/lib.php"


def test_changed_files_excludes_preexisting_untracked():
    st = parse_status("M       a.php\n?       pre.txt\n?       new.txt\n")
    assert changed_files(st, {"pre.txt"}) == ["a.php", "new.txt"]


def test_commit_revision_regex_en_kr():
    assert parse_commit_revision("Sending a.php\nTransmitting file data .\nCommitted revision 4712.\n") == "4712"
    assert parse_commit_revision("전송 중 a.php\n커밋된 리비전 88.\n") == "88"
    assert parse_commit_revision("nothing") is None


GIT_PORCELAIN = """ M local/ubattend/lib.php
A  local/new.php
?? var/tmp.txt
D  mod/old.php
R  old.php -> renamed.php
!! ignored.log
 A intent.php
"""


def test_git_porcelain_parse():
    st = parse_porcelain(GIT_PORCELAIN)
    codes = {e.path: e.code for e in st}
    assert codes["local/ubattend/lib.php"] == "M" and codes["local/new.php"] == "A"
    assert codes["var/tmp.txt"] == "?" and codes["mod/old.php"] == "D" and codes["renamed.php"] == "R"
    assert "ignored.log" not in codes and codes["intent.php"] == "A"
    assert untracked(st) == ["var/tmp.txt"]


SVN_DIFF = """Index: local/ubattend/lib.php
===================================================================
--- local/ubattend/lib.php\t(revision 4711)
+++ local/ubattend/lib.php\t(working copy)
@@ -1,3 +1,4 @@
 a
-b
+b2
+c
Index: local/ubattend/classes/new.php
===================================================================
--- local/ubattend/classes/new.php\t(nonexistent)
+++ local/ubattend/classes/new.php\t(working copy)
@@ -0,0 +1,2 @@
+<?php
+echo 1;
"""

GIT_DIFF = """diff --git a/a.php b/a.php
index 1..2 100644
--- a/a.php
+++ b/a.php
@@ -1 +1 @@
-x
+y
diff --git a/gone.php b/gone.php
deleted file mode 100644
--- a/gone.php
+++ /dev/null
@@ -1,2 +0,0 @@
-1
-2
diff --git a/new.php b/new.php
new file mode 100644
--- /dev/null
+++ b/new.php
@@ -0,0 +1 @@
+n
"""


def test_diff_stat_svn():
    st = diff_stat(SVN_DIFF)
    d = {s.path: s for s in st}
    assert d["local/ubattend/lib.php"].add == 2 and d["local/ubattend/lib.php"].dele == 1 and d["local/ubattend/lib.php"].status == "M"
    assert d["local/ubattend/classes/new.php"].status == "A" and d["local/ubattend/classes/new.php"].add == 2
    assert sum_stat(st) == (2, 4, 1)


def test_diff_stat_git():
    st = diff_stat(GIT_DIFF)
    d = {s.path: s for s in st}
    assert d["a.php"].status == "M" and d["a.php"].add == 1 and d["a.php"].dele == 1
    assert d["gone.php"].status == "D" and d["gone.php"].dele == 2
    assert d["new.php"].status == "A" and d["new.php"].add == 1
    assert [s.to_dict()["path"] for s in st] == ["a.php", "gone.php", "new.php"]


def test_cap_diff():
    big = "x" * 100
    assert cap_diff(big, cap=10).startswith("x" * 10) and "100 자 중 10 자" in cap_diff(big, cap=10)
    assert cap_diff("short", cap=10) == "short"
