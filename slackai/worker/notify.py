"""알림: Slack 인커밍 웹훅(선택) + 문의 스레드 글(ai_settings.post_to_slack 게이트, 기본 OFF) + 웹 푸시(core/push.php)."""

import json
import logging
import urllib.request

from tools import php_cli

logger = logging.getLogger(__name__)


def webhook(settings, text: str) -> bool:
    url = settings.slack_webhook
    if not url:
        return False
    try:
        data = json.dumps({"text": text}, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
        if not url.startswith("https://"):
            return False
        with urllib.request.urlopen(req, timeout=15) as r:
            return 200 <= r.status < 300
    except Exception as e:
        logger.warning("웹훅 실패: %s", e)
        return False


def thread_post(ctx, request_id: str, text: str) -> dict | None:
    """post_to_slack 이 켜져 있을 때만 문의 스레드에 글을 남긴다."""
    if not ctx.eff.post_to_slack:
        return None
    res = php_cli.post_thread(ctx.runner, ctx.settings, request_id, text, ctx.job_dir)
    if not res.get("ok"):
        ctx.log.warning("스레드 글 실패 %s: %s", request_id, res.get("error"))
    return res




def push(runner, settings, title: str, body: str = "", url: str = "slackai/ai/jobs.php", to=None,
         tag: str = "") -> dict:
    """웹 푸시(종류 slackai.job). 받을지는 사람마다 포털 마이페이지에서 정한다(기본 꺼짐)."""
    res = php_cli.push(runner, settings, "slackai.job", title, body, url=url, to=to, tag=tag)
    if res.get("error"):
        logger.warning("웹 푸시 실패: %s", res.get("error"))
    return res


JOB_LABELS = {"triage": "분류", "plan": "플랜", "execute": "실행", "commit": "커밋", "review": "검토",
              "learn": "학습", "revert": "되돌리기", "check_repo": "저장소 점검", "discover_repos": "저장소 찾기"}


def job_finished(runner, settings, job: dict, status: str, error: str = "") -> None:
    """사람이 직접 건 잡이 끝나면(성공·최종 실패) 건 사람에게. sync·워커가 이어 건 잡은 알리지 않는다."""
    by = str(job.get("requested_by") or "")
    params = job.get("params") or {}
    if "@" not in by or ":" in by or (isinstance(params, dict) and params.get("source") == "sync"):
        return
    label = JOB_LABELS.get(job["kind"], job["kind"])
    rid = job.get("request_id") or ""
    try:
        push(runner, settings, f"AI {label} {'끝' if status == 'done' else '실패'}" + (f" · {rid}" if rid else ""),
             (error or "")[:200] if status != "done" else "",
             url="slackai/ai/jobs.php" + (f"?request_id={rid}" if rid else ""), to=[by], tag=f"slackai-job-{job['id']}")
    except Exception:
        logger.warning("웹 푸시 실패", exc_info=True)
