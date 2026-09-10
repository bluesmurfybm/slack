"""화면의 '다시 가져오기' 요청 처리.

PHP(moodle/refresh.php)가 var/requests/<week>.json 을 남기면, 서버에서는 systemd path 유닛이,
로컬에서는 `run_weekly.py --serve` 가 그 파일을 집어 같은 주차를 갱신한다. PHP 가 Python 을 직접
띄우지 않는 건 php-fpm 사용자와 배치 사용자(claude 로그인 보유)가 다르기 때문이다.

파일 상태로 진행을 알린다: <week>.json(대기) → <week>.running(처리 중) → 삭제(완료) 또는
<week>.failed(실패 사유). 처리 중·실패 파일은 .json 으로 끝나지 않는다 — systemd path 유닛의
PathExistsGlob=*.json 이 그 파일을 새 요청으로 보고 서비스를 계속 다시 띄우지 않게 하려는 것이다.
"""

import json
import logging
import time
from datetime import UTC, datetime
from pathlib import Path

from core.config import Settings

logger = logging.getLogger("moodle-watch.refresh")


def requests_dir(settings: Settings) -> Path:
    return settings.data_path / "requests"


def pending(settings: Settings) -> list[Path]:
    d = requests_dir(settings)
    if not d.is_dir():
        return []
    return sorted(d.glob("*.json"))


def _read(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def process_all(settings: Settings, runner) -> list[dict]:
    """runner(week, requested_by) -> report dict. 요청 하나가 실패해도 다음 요청은 처리한다."""
    done = []
    for req in pending(settings):
        week = req.stem
        info = _read(req)
        running = req.with_name(f"{week}.running")
        failed = req.with_name(f"{week}.failed")
        info["started_at"] = datetime.now(UTC).isoformat(timespec="seconds")
        # 요청 파일은 웹 계정(www-data) 소유라 우리가 내용을 고칠 수 없다. 우리 소유의 처리중 파일을
        # 새로 쓰고 원본은 지운다(폴더에 쓰기 권한만 있으면 다른 계정 파일도 지울 수 있다).
        try:
            running.write_text(json.dumps(info, ensure_ascii=False), encoding="utf-8")
            req.unlink()
        except OSError:
            logger.exception("요청 파일을 처리중으로 바꿀 수 없다(폴더 권한 확인): %s", req)
            running.unlink(missing_ok=True)
            continue
        try:
            report = runner(week, info.get("requested_by", ""))
        except Exception as e:
            logger.exception("갱신 실패: %s", week)
            info.update(error=f"{type(e).__name__}: {e}",
                        failed_at=datetime.now(UTC).isoformat(timespec="seconds"))
            failed.write_text(json.dumps(info, ensure_ascii=False), encoding="utf-8")
            done.append({"week": week, "ok": False, "error": str(e)})
        else:
            failed.unlink(missing_ok=True)
            done.append({"week": week, "ok": True, "status": report.get("status")})
        finally:
            running.unlink(missing_ok=True)
    return done


def serve(settings: Settings, runner, interval: float = 3.0, *, once: bool = False) -> None:
    """로컬 개발용 대기 모드. 요청 파일이 생기면 처리한다(파일 stat 만 본다 — DB 폴링은 없다)."""
    d = requests_dir(settings)
    d.mkdir(parents=True, exist_ok=True)
    logger.info("요청 대기: %s (Ctrl+C 로 종료)", d)
    while True:
        results = process_all(settings, runner)
        for r in results:
            logger.info("처리: %s", r)
        if once:
            return
        time.sleep(interval)
