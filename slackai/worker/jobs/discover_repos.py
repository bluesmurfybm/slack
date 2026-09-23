"""discover_repos — 로컬 작업 사본을 훑어 school_access.repo(svn/git 주소)와 맞춰 ai_repos 를 자동 등록/갱신.

흐름
 1. 포털 DB(slack_db)의 school_access 에서 **school_id, repo 두 컬럼만** 읽는다(계정·비번 컬럼은 절대 SELECT 하지 않음).
    repo 칸의 자유 텍스트에서 svn://, svn+ssh://, http(s)://…git, ssh://…git, git@…git 주소를 뽑는다.
 2. REPO_SCAN_ROOTS(기본 G:\\01_Bluesoft) 아래 `*\\03_Source\\*`, `*\\03_Source\\*\\*` 중 .svn/.git 이 있는 폴더의
    원격 URL(svn info --show-item url / git remote get-url origin)과 HEAD 를 읽는다.
 3. 매칭: 정규화(host 소문자·사용자명 제거, 끝의 /, /trunk, .git 제거) 후
    exact → 같은 호스트 prefix(작업사본이 하위 경로거나 상위 경로) → 경로만 일치(학교 1곳일 때; 127.0.0.1 터널 대비).
 4. ai_repos 를 local_path 기준으로 upsert: school_id·remote_url·vcs·version(schools.ver)·match_rules(개발/운영 호스트, 학교명)
    · check_status/head_revision 을 채운다. 사람이 고친 값(이름·notes·match_rules 에 직접 넣은 항목)은 덮어쓰지 않는다.
    같은 학교에 작업 사본이 여럿이면 최근 커밋이 가장 늦은 것만 active=1, 나머지는 active=0(학교는 (이름,버전)으로 구분되므로 중복 체크아웃).
 params: {roots?: [..], dry_run?: bool}
"""

from __future__ import annotations

import glob
import json
import os
import re
from dataclasses import dataclass, field

from core.clock import now_str
from jobs.base import JobContext, Outcome

URL_RE = re.compile(
    r"(svn(?:\+ssh)?://[^\s'\"<>]+|https?://[^\s'\"<>]+?\.git\b|https?://[^\s'\"<>]+/(?:ub)?git/[^\s'\"<>]+"
    r"|ssh://[^\s'\"<>]+?\.git\b|git@[^\s'\"<>]+?\.git\b)",
    re.I)
AUTO_NOTE = "[auto] discover_repos: school_access.repo 와 작업사본 URL 매칭으로 등록"


def extract_urls(text: str | None) -> list[str]:
    out: list[str] = []
    for raw in URL_RE.findall(text or ""):
        u = raw.strip().rstrip("/.,;)")
        if u and u not in out:
            out.append(u)
    return out


def norm_url(u: str | None) -> tuple[str, str] | None:
    """(host, path) 정규화. git@host:path 도 처리."""
    if not u:
        return None
    u = u.strip().rstrip("/")
    m = re.match(r"^git@([^:]+):(.+)$", u, re.I)
    if m:
        host, path = m.group(1).lower(), "/" + m.group(2)
    else:
        m = re.match(r"^([a-z+]+)://([^/]+)(/.*)?$", u, re.I)
        if not m:
            return None
        host, path = m.group(2).lower(), (m.group(3) or "")
    host = host.split("@")[-1]
    host = re.sub(r":(3690|22|80|443)$", "", host)
    path = path.rstrip("/")
    path = re.sub(r"/trunk$", "", path)
    path = re.sub(r"\.git$", "", path)
    return host, path.lower()


@dataclass
class Match:
    school_id: int
    how: str          # exact | prefix | path
    repo_url: str


def match_url(wc_url: str, index: list[tuple[int, str, tuple[str, str]]]) -> Match | None:
    """index: [(school_id, 원문 url, norm)]"""
    n = norm_url(wc_url)
    if not n:
        return None
    host, path = n
    for sid, raw, nn in index:
        if nn == n:
            return Match(sid, "exact", raw)
    pref = [(sid, raw) for sid, raw, (h, p) in index
            if h == host and p and (path.startswith(p + "/") or p.startswith(path + "/"))]
    if pref:
        # 가장 긴 공통 경로(=가장 구체적인 학교 주소)
        pref.sort(key=lambda x: -len(norm_url(x[1])[1]))
        return Match(pref[0][0], "prefix", pref[0][1])
    # 호스트가 달라도(127.0.0.1 터널 체크아웃, 서버 IP 이전) 저장소 경로가 학교 1곳과만 맞으면 채택
    same = [(sid, raw) for sid, raw, (h, p) in index
            if p and (p == path or path.startswith(p + "/") or p.startswith(path + "/"))]
    sids = {s for s, _ in same}
    if len(sids) == 1:
        return Match(same[0][0], "path", same[0][1])
    return None


@dataclass
class WorkingCopy:
    path: str
    vcs: str
    url: str = ""
    head: str = ""
    last_changed: str = ""       # 정렬용(최근 커밋)
    extra: dict = field(default_factory=dict)


def find_working_copies(roots: list[str]) -> list[str]:
    seen: list[str] = []
    for root in roots:
        for pat in (os.path.join(root, "*", "03_Source", "*"), os.path.join(root, "*", "03_Source", "*", "*")):
            for d in sorted(glob.glob(pat)):
                if not os.path.isdir(d):
                    continue
                if os.path.isdir(os.path.join(d, ".svn")) or os.path.exists(os.path.join(d, ".git")):
                    nd = os.path.normpath(d)
                    # 상위 폴더가 이미 작업사본이면 하위는 건너뜀
                    if any(nd.startswith(s + os.sep) for s in seen):
                        continue
                    seen.append(nd)
    return seen


def read_wc(ctx: JobContext, path: str) -> WorkingCopy:
    s = ctx.settings
    if os.path.isdir(os.path.join(path, ".svn")):
        wc = WorkingCopy(path, "svn")
        c = ctx.runner.run([s.svn_bin, "info", "--non-interactive", "--show-item", "url", path], timeout=60)
        wc.url = (c.stdout or b"").decode("utf-8", "replace").strip() if c.rc == 0 else ""
        c = ctx.runner.run([s.svn_bin, "info", "--non-interactive", "--show-item", "revision", path], timeout=60)
        wc.head = (c.stdout or b"").decode("utf-8", "replace").strip() if c.rc == 0 else ""
        c = ctx.runner.run([s.svn_bin, "info", "--non-interactive", "--show-item", "last-changed-date", path], timeout=60)
        wc.last_changed = (c.stdout or b"").decode("utf-8", "replace").strip() if c.rc == 0 else ""
        return wc
    wc = WorkingCopy(path, "git")
    c = ctx.runner.run([s.git_bin, "-C", path, "remote", "get-url", "origin"], timeout=60)
    wc.url = (c.stdout or b"").decode("utf-8", "replace").strip() if c.rc == 0 else ""
    c = ctx.runner.run([s.git_bin, "-C", path, "log", "-1", "--format=%H|%cI"], timeout=60)
    out = (c.stdout or b"").decode("utf-8", "replace").strip() if c.rc == 0 else ""
    if "|" in out:
        wc.head, wc.last_changed = out.split("|", 1)
    return wc


def repo_name(school: dict) -> str:
    """학교명 + 버전. 학교명에 이미 버전이 들어 있으면('코스모스 3.9') 다시 붙이지 않는다."""
    nm, ver = (school.get("name") or "").strip(), (school.get("ver") or "").strip()
    return nm if not ver or ver in nm else f"{nm} {ver}"


def _bad_auto_name(school: dict) -> str:
    return f"{school.get('name') or ''} {school.get('ver') or ''}".strip()


def _host(u: str | None) -> str:
    n = norm_url(u if u and "://" in u else ("http://" + u if u else ""))
    h = n[0] if n else ""
    return h[4:] if h.startswith("www.") else h


def run(ctx: JobContext) -> Outcome:
    s = ctx.settings
    roots = ctx.params.get("roots") or [r.strip() for r in (s.repo_scan_roots or "").split(";") if r.strip()]
    dry = bool(ctx.params.get("dry_run")) or ctx.dry_run
    portal_db = s.portal_db_name()
    if not re.fullmatch(r"[A-Za-z0-9_]+", portal_db or ""):
        return Outcome.fail(f"포털 DB 이름이 올바르지 않음: {portal_db!r}")

    # 1) school_access — school_id, repo 두 컬럼만
    ctx.progress("school_access 주소 읽기")
    rows = ctx.db.all(f"SELECT sa.school_id, sa.repo FROM `{portal_db}`.school_access sa WHERE sa.repo IS NOT NULL AND sa.repo <> ''")
    index: list[tuple[int, str, tuple[str, str]]] = []
    for r in rows:
        for u in extract_urls(r["repo"]):
            n = norm_url(u)
            if n:
                index.append((int(r["school_id"]), u, n))
    schools = {int(x["id"]): x for x in ctx.db.all("SELECT id, name, ver, dev, ops FROM schools")}

    # 2) 작업사본 스캔
    ctx.progress(f"작업사본 스캔 {', '.join(roots)}")
    paths = find_working_copies(roots)
    wcs = []
    for i, p in enumerate(paths, 1):
        if i % 10 == 0:
            ctx.progress(f"작업사본 URL 확인 {i}/{len(paths)}")
        wcs.append(read_wc(ctx, p))

    # 3) 매칭
    matched: list[tuple[WorkingCopy, Match]] = []
    unmatched: list[dict] = []
    for wc in wcs:
        m = match_url(wc.url, index) if wc.url else None
        if m and m.school_id in schools:
            matched.append((wc, m))
        else:
            unmatched.append({"path": wc.path, "url": wc.url})

    # 같은 학교 중복 → 최근 커밋이 가장 늦은 것만 active
    best: dict[int, WorkingCopy] = {}
    for wc, m in matched:
        cur = best.get(m.school_id)
        if cur is None or (wc.last_changed or "") > (cur.last_changed or ""):
            best[m.school_id] = wc

    # 4) upsert (local_path 기준, 대소문자 무시)
    existing = {os.path.normcase(os.path.normpath(r["local_path"])): r
                for r in ctx.db.all("SELECT * FROM ai_repos")}
    names = {r["name"] for r in existing.values()}
    created = updated = 0
    report: list[dict] = []
    for wc, m in matched:
        sc = schools[m.school_id]
        active = 1 if best.get(m.school_id) is wc else 0
        hosts = [h for h in (_host(sc.get("dev")), _host(sc.get("ops"))) if h]
        key = os.path.normcase(os.path.normpath(wc.path))
        old = existing.get(key)
        item = {"path": wc.path, "school": sc["name"], "ver": sc.get("ver"), "how": m.how, "active": active}
        if old:
            rules = {}
            try:
                rules = json.loads(old.get("match_rules") or "{}") or {}
            except (TypeError, ValueError):
                rules = {}
            lms = list(dict.fromkeys((rules.get("lms_patterns") or []) + hosts))
            kws = list(dict.fromkeys((rules.get("title_keywords") or []) + [sc["name"]]))
            # 예전 규칙으로 버전이 두 번 붙은 자동 이름('코스모스 3.9 3.9')은 바로잡는다(사람이 바꾼 이름은 그대로)
            fixed_name = old["name"]
            if old.get("notes") == AUTO_NOTE and old["name"] == _bad_auto_name(sc) and repo_name(sc) != old["name"]                     and repo_name(sc) not in names:
                fixed_name = repo_name(sc)
                names.add(fixed_name)
            if not dry:
                ctx.db.exec("UPDATE ai_repos SET name = %s WHERE id = %s", (fixed_name, old["id"]))
                ctx.db.exec(
                    "UPDATE ai_repos SET school_id = COALESCE(school_id, %s), vcs = %s, remote_url = COALESCE(NULLIF(remote_url,''), %s), "
                    "version = COALESCE(NULLIF(version,''), %s), match_rules = %s, last_checked = %s, check_status = 'ok', "
                    "check_message = %s, head_revision = %s, updated_at = %s WHERE id = %s",
                    (m.school_id, wc.vcs, wc.url, sc.get("ver"), json.dumps({"lms_patterns": lms, "title_keywords": kws}, ensure_ascii=False),
                     now_str(), f"{wc.vcs} @ {wc.head} (auto, {m.how})", wc.head or None, now_str(), old["id"]))
            updated += 1
            item["id"] = old["id"]
            item["action"] = "updated"
        else:
            name = repo_name(sc)
            if name in names:
                name = f"{name} ({os.path.basename(os.path.dirname(os.path.dirname(wc.path)))})"
            names.add(name)
            if not dry:
                ctx.db.exec(
                    "INSERT INTO ai_repos (name, school_id, vcs, remote_url, local_path, version, match_rules, notes, active, "
                    "last_checked, check_status, check_message, head_revision, created_at) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'ok',%s,%s,%s)",
                    (name, m.school_id, wc.vcs, wc.url, wc.path, sc.get("ver"),
                     json.dumps({"lms_patterns": hosts, "title_keywords": [sc["name"]]}, ensure_ascii=False),
                     AUTO_NOTE, active, now_str(), f"{wc.vcs} @ {wc.head} (auto, {m.how})", wc.head or None, now_str()))
                item["id"] = ctx.db.last_id
            created += 1
            item["action"] = "created"
        report.append(item)

    schools_with_repo = {m.school_id for _, m in matched}
    no_wc = len({sid for sid, _, _ in index} - schools_with_repo)
    res = {"roots": roots, "scanned": len(wcs), "matched": len(matched), "created": created, "updated": updated,
           "unmatched": unmatched, "schools_without_wc": no_wc, "dry_run": dry, "items": report[:300]}
    ctx.save("discover_repos.json", json.dumps(res, ensure_ascii=False, indent=1))
    if not dry:
        ctx.event("repo.discover", "ai_repos", None, {k: res[k] for k in ("scanned", "matched", "created", "updated", "schools_without_wc")}
                  | {"unmatched": [u["path"] for u in unmatched][:50]})
        ctx.db.commit()
    msg = f"스캔 {len(wcs)} · 매칭 {len(matched)} (신규 {created}, 갱신 {updated}) · 미매칭 {len(unmatched)}"
    return ctx.outcome("done", result=res, progress=msg)
