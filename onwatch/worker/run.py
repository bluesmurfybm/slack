"""OnWatch 배치. 서버에서는 systemd timer 가 1분마다 부른다(onwatch/README.md 의 배포 절).

    python run.py                 # 한 번 깨어나 할 일을 하고 끝낸다(timer 가 부르는 모양)
    python run.py --serve         # 로컬: 계속 떠서 20초마다 깨어난다
    python run.py --watch 3       # 감시 3 을 지금 한 번 돌린다(일정·요청과 무관, 잠금은 지킨다)
    python run.py --show-prompt 3 # 감시 3 의 조사 지시문을 찍어 보기만 한다(모델을 부르지 않는다)

할 일이 없으면 DB 질의 두세 번으로 끝난다 — 1분마다 불러도 부담이 없다.
'지금 실행' 은 화면이 DB 에 남긴 요청을 다음 깨어남에 집는다(파일·소켓을 쓰지 않는다 — 웹 계정과
배치 계정이 달라도 권한 문제가 없다).
"""

import argparse
import json
import logging
import os
import socket
import sys
import time

import prompts
from core.config import Settings
from engine import make_engine
from notify import Notifier
from pipeline import Ctx, kst_now, run_watch, tick
from store import SchemaMissingError, Store

logger = logging.getLogger("onwatch")


def main() -> int: # noqa: C901, PLR0911 명령줄 갈래를 한곳에서 읽게 둔다
    ap = argparse.ArgumentParser(description="OnWatch 배치")
    ap.add_argument("--serve", action="store_true", help="계속 떠서 주기적으로 깨어난다(로컬 개발용)")
    ap.add_argument("--interval", type=float, default=20.0, help="--serve 의 깨어나는 간격(초)")
    ap.add_argument("--watch", type=int, help="이 감시를 지금 한 번 돌린다")
    ap.add_argument("--show-prompt", type=int, help="이 감시의 조사 지시문을 찍는다")
    args = ap.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    settings = Settings()
    store = Store(settings.db_params())
    try:
        store.check_schema()
    except SchemaMissingError as e:
        logger.error("%s", e) # noqa: TRY400 원인이 분명해 스택은 필요 없다
        return 2
    ctx = Ctx(settings=settings, store=store, engine_factory=make_engine, notifier=Notifier(settings))

    try:
        if args.show_prompt:
            w = store.get_watch(args.show_prompt)
            if not w:
                print("감시가 없습니다")
                return 1
            ctx_text = prompts.context_block(w, now=kst_now(), last_ok=store.last_ok_run(w["id"]),
                                             prior=store.prior_findings(w["id"]),
                                             rejected=store.rejected_findings(w["id"]))
            print(prompts.research_user(ctx_text, int(w["max_searches"])))
            return 0

        if args.watch:
            w = store.get_watch(args.watch)
            if not w:
                print("감시가 없습니다")
                return 1
            if not store.claim(w["id"], kst_now(), settings.lock_minutes, f"{socket.gethostname()}:{os.getpid()}"):
                print("다른 배치가 실행 중입니다")
                return 1
            print(json.dumps(run_watch(ctx, w, trigger="cli"), ensure_ascii=False, default=str))
            return 0

        if args.serve:
            logger.info("대기 시작 — %.0f초마다 깨어납니다(Ctrl+C 로 끝)", args.interval)
            while True:
                result = tick(ctx)
                for r in result["runs"]:
                    logger.info("실행: %s", r)
                time.sleep(args.interval)

        result = tick(ctx)
        for r in result["runs"]:
            logger.info("실행: %s", r)
    except KeyboardInterrupt:
        pass
    finally:
        store.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
