"""discover_repos: school_access.repo 텍스트에서 주소 추출 · 정규화 · 작업사본 URL 매칭."""

from jobs.discover_repos import extract_urls, match_url, norm_url, repo_name


def _index(rows):
    out = []
    for sid, text in rows:
        for u in extract_urls(text):
            n = norm_url(u)
            if n:
                out.append((sid, u, n))
    return out


def test_extract_urls_from_free_text():
    text = ("svn://211.193.3.216/smic2\nsvn://211.188.57.216/smic2\n"
            "git clone http://csms45.moodler.kr/git/kwulms_cm45.git [저장할폴더경로]/moodle 암호는 …\n"
            "(plink 실행 후 svn 체크아웃) plink -ssh -L 3690:127.0.0.1:3690 ubion@203.252.1.1")
    urls = extract_urls(text)
    assert "svn://211.193.3.216/smic2" in urls
    assert "svn://211.188.57.216/smic2" in urls
    assert "http://csms45.moodler.kr/git/kwulms_cm45.git" in urls
    assert all("plink" not in u for u in urls)


def test_norm_url_strips_user_port_trunk_git():
    assert norm_url("svn://user@203.249.126.221:3690/gcucyber_cm3/moodle/") == ("203.249.126.221", "/gcucyber_cm3/moodle")
    assert norm_url("svn://1.2.3.4/repo/trunk") == ("1.2.3.4", "/repo")
    assert norm_url("https://Nihlms.moodler.kr/ubgit/NIHLMS.git") == ("nihlms.moodler.kr", "/ubgit/nihlms")
    assert norm_url("git@github.com:org/Repo.git") == ("github.com", "/org/repo")
    assert norm_url("not a url") is None


def test_match_exact_prefix_and_path_only():
    idx = _index([
        (3, "svn://203.249.126.221/gcucyber_cm3/moodle"),
        (5, "svn://211.193.3.232/knpulms_cm3"),
        (7, "svn://49.50.162.76/hjlms"),
        (8, "svn://10.0.0.1/shared/a\nsvn://10.0.0.2/shared/a"),   # 같은 경로 두 학교 → 경로만으론 모호
        (9, "svn://10.0.0.3/shared/a"),
    ])
    m = match_url("svn://203.249.126.221/gcucyber_cm3/moodle", idx)
    assert (m.school_id, m.how) == (3, "exact")
    m = match_url("svn://211.193.3.232/knpulms_cm3/moodle", idx)          # 작업사본이 학교 주소의 하위
    assert (m.school_id, m.how) == (5, "prefix")
    m = match_url("svn://127.0.0.1/hjlms/moodle", idx)                    # 터널 체크아웃: 호스트 다름, 경로 유일
    assert (m.school_id, m.how) == (7, "path")
    assert match_url("svn://127.0.0.1/shared/a", idx) is None             # 경로가 여러 학교와 맞음 → 사람에게
    assert match_url("svn://1.1.1.1/unknown/moodle", idx) is None


def test_ubgit_without_dot_git_and_repo_name():
    assert extract_urls("https://mwulxp.moodler.kr/ubgit/mwulxp") == ["https://mwulxp.moodler.kr/ubgit/mwulxp"]
    idx = [(1, u, norm_url(u)) for u in extract_urls("https://mwulxp.moodler.kr/ubgit/mwulxp")]
    assert match_url("https://mwulxp.moodler.kr/ubgit/mwulxp.git", idx).how == "exact"
    assert repo_name({"name": "코스모스 3.9", "ver": "3.9"}) == "코스모스 3.9"
    assert repo_name({"name": "항공대", "ver": "4.5"}) == "항공대 4.5"
