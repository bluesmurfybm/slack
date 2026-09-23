"""php slackai/sync.php 호출(cwd=저장소 루트). 기동 시 1회 증분, SYNC_INTERVAL_SEC>0 일 때만 주기 대조.

토큰(SLACK_TOKEN/SLACK_BOT_TOKEN)이 없으면 PHP 를 부르지 않고 no_token 을 돌려준다.
"""

import logging

from tools import php_cli

logger = logging.getLogger(__name__)


def _no_token(settings) -> dict | None:
    if not settings.slack_cli_token:
        return {"ok": False, "error": "no_token", "text": "SLACK_TOKEN/SLACK_BOT_TOKEN 없음 → 동기화 건너뜀"}
    return None


def run_once(settings, runner, *, full: bool = False, enqueue: bool = False) -> dict:
    nt = _no_token(settings)
    if nt:
        return nt
    args = ["json"]
    if full:
        args.insert(0, "full")
    if enqueue:
        args.insert(0, "enqueue")
    res = php_cli.sync(runner, settings, args, timeout=900)
    logger.info("sync.php %s → %s", "full" if full else "incremental", res.get("text") or res.get("error"))
    return res


def run_item(settings, runner, item_id: str, list_id: str = "") -> dict:
    nt = _no_token(settings)
    if nt:
        return nt
    args = [f"item={item_id}"]
    if list_id:
        args.append(f"list={list_id}")
    args.append("json")
    res = php_cli.sync(runner, settings, args, timeout=300)
    logger.info("sync.php item=%s → %s", item_id, res.get("text") or res.get("error"))
    return res


def run_comments(settings, runner, channel: str) -> dict:
    nt = _no_token(settings)
    if nt:
        return nt
    res = php_cli.sync(runner, settings, [f"comments={channel}", "json"], timeout=600)
    logger.info("sync.php comments=%s → %s", channel, res.get("text") or res.get("error"))
    return res
