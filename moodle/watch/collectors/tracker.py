from collections import Counter

from collectors.base import Context, Result
from core.items import Item, clip, is_focus, iso

API = "https://moodle.atlassian.net/rest/api/3/search/jql"
BROWSE = "https://moodle.atlassian.net/browse/"
FIELDS = "summary,resolutiondate,fixVersions,labels,components,issuetype,priority,resolution"

# 트래커는 주당 수백 건이 닫힌다. 전부 저장하되 요약 입력은 이 조건으로 좁힌다(is_focus).
INTERESTING_TYPES = {"Epic", "Improvement", "New Feature", "Task"}


def collect(ctx: Context) -> Result:
    since_day = ctx.since.strftime("%Y-%m-%d")
    jql = f'project = MDL AND resolved >= "{since_day}" ORDER BY resolved DESC'
    issues = []
    token = None
    for _ in range(ctx.settings.tracker_max_pages):
        params = {"jql": jql, "maxResults": 100, "fields": FIELDS}
        if token:
            params["nextPageToken"] = token
        page = ctx.http.get_json(API, params=params, headers={"Accept": "application/json"})
        issues.extend(page.get("issues", []))
        token = page.get("nextPageToken")
        if page.get("isLast", True) or not token:
            break

    since_iso = iso(ctx.since)
    items = []
    by_type, by_component, by_resolution, by_fix = Counter(), Counter(), Counter(), Counter()
    for it in issues:
        f = it.get("fields", {})
        resolved = iso(f.get("resolutiondate"))
        if resolved and resolved < since_iso:
            continue # 날짜 단위 JQL 이 구간 앞 몇 시간을 더 물어온다
        itype = (f.get("issuetype") or {}).get("name", "")
        comps = [c["name"] for c in f.get("components") or []]
        fixes = [v["name"] for v in f.get("fixVersions") or []]
        labels = f.get("labels") or []
        resolution = (f.get("resolution") or {}).get("name", "")
        by_type[itype] += 1
        by_resolution[resolution or "(없음)"] += 1
        for c in comps:
            by_component[c] += 1
        for v in fixes:
            by_fix[v] += 1
        focus = (resolution == "Fixed"
                 and (itype in INTERESTING_TYPES
                      or is_focus(f.get("summary", ""), " ".join(comps), " ".join(labels))))
        items.append(Item(
            source="tracker", kind="issue",
            title=f"{it['key']} {f.get('summary', '')}",
            url=BROWSE + it["key"],
            published_at=resolved,
            excerpt=clip(" · ".join(x for x in [itype, resolution, ", ".join(comps),
                                                 ", ".join(fixes)] if x), 300),
            meta={"key": it["key"], "type": itype, "resolution": resolution,
                  "components": comps, "fix_versions": fixes, "labels": labels,
                  "priority": (f.get("priority") or {}).get("name", "")},
            is_focus=bool(focus)))

    stats = {
        "resolved": len(items),
        "fixed": by_resolution.get("Fixed", 0),
        "by_type": dict(by_type.most_common()),
        "by_resolution": dict(by_resolution.most_common()),
        "top_components": dict(by_component.most_common(15)),
        "fix_versions": dict(by_fix.most_common(10)),
        "truncated": bool(token),
    }
    return Result("tracker", items, stats)
