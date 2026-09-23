"""slackai 워커 진입점 — 저장소에서 유일하게 claude/codex/svn/git/php 를 실행하는 프로세스.

    python run_worker.py --serve                          # 상주(기본): 기동 시 1회 증분 동기화 → Socket Mode 수신 → ai_jobs 큐 처리
    python run_worker.py --once                           # 큐에서 잡 1건만 처리하고 종료
    python run_worker.py --once --request Rec… --kind triage [--params '{"force":1}']
    python run_worker.py --dry-run --kind plan --request Rec…   # argv + 프롬프트만 출력(Claude 호출·DB 쓰기 없음)
    python run_worker.py --selftest                       # php/claude/codex/svn/git 해석, DB, 스키마, claude pong
    옵션: --no-sync(기동 동기화 생략) --no-push(Socket Mode 생략) -v

메인 루프(플랜 §2.1): fail_orphans → sync 1회 → push 스레드 → [heartbeat 30초 / recover_stuck 30분 / paused / claim →
Heartbeat 스레드 안에서 HANDLERS[kind](ctx) → finish|fail_or_retry → next_jobs enqueue]. SIGINT/SIGTERM/SIGBREAK 1회 →
현재 잡 마무리 후 종료, 2회 → 서브프로세스 kill.
"""

import argparse
import json
import logging
import logging.handlers
import shutil
import signal
import socket
import sys
import time
from pathlib import Path

from core import costs, store
from core import jobs as jq
from core.clock import now_str
from core.config import BASE, REPO_ROOT, Effective, Settings
from core.db import Db
from core.text import mask_secrets
from jobs import COST_GATED, HANDLERS
from jobs.base import JobContext, Outcome
from llm.factory import build_llm
from tools import claude_cli, codex_cli, php_cli
from tools.runner import SubprocessRunner

log = logging.getLogger("slackai-worker")


class MaskFilter(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        try:
            msg = record.getMessage()
            masked = mask_secrets(msg)
            if masked != msg:
                record.msg, record.args = masked, ()
        except Exception:
            pass
        return True


def setup_logging(settings: Settings, verbose: bool) -> None:
    settings.data_path.mkdir(parents=True, exist_ok=True)
    fmt = logging.Formatter("%(asctime)s %(levelname).1s %(name)s: %(message)s", "%m-%d %H:%M:%S")
    root = logging.getLogger()
    root.setLevel(logging.DEBUG if verbose else logging.INFO)
    for h in list(root.handlers):
        root.removeHandler(h)
    sh = logging.StreamHandler(sys.stdout)
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass
    fh = logging.handlers.RotatingFileHandler(settings.data_path / "worker.log", maxBytes=5_000_000, backupCount=5,
                                              encoding="utf-8")
    for h in (sh, fh):
        h.setFormatter(fmt)
        h.addFilter(MaskFilter())
        root.addHandler(h)
    for noisy in ("urllib3", "httpx", "httpcore", "slack_sdk", "anthropic", "openai"):
        logging.getLogger(noisy).setLevel(logging.WARNING)


class Worker:
    def __init__(self, settings: Settings, db: Db, *, no_sync: bool = False, no_push: bool = False):
        self.s = settings
        self.db = db
        self.db_params = db.params
        self.runner = SubprocessRunner(log)
        self.name = jq.worker_name()
        self.actor = f"worker:{socket.gethostname()}"
        self._current_job_dir: Path | None = None
        self.llm = build_llm(settings, self.runner, job_dir_fn=lambda: self._current_job_dir)
        self.no_sync = no_sync
        self.no_push = no_push
        self.stop_requested = 0
        self.push = None
        costs.load_overlay(settings.llm_pricing_json)

    # ---- 신호
    def install_signals(self) -> None:
        def _h(signum, _frame):
            self.stop_requested += 1
            if self.stop_requested == 1:
                log.warning("종료 요청(%s) — 현재 잡을 마무리하고 종료합니다. 한 번 더 누르면 강제 중단", signum)
            else:
                log.warning("강제 중단 — 서브프로세스 kill")
                self.runner.kill_current()

        for name in ("SIGINT", "SIGTERM", "SIGBREAK"):
            sig = getattr(signal, name, None)
            if sig is not None:
                try:
                    signal.signal(sig, _h)
                except (ValueError, OSError):
                    pass

    # ---- 1건 처리
    def effective(self) -> Effective:
        self.db.ensure()
        return Effective(self.s, store.settings_all(self.db))

    def dispatch(self, job: dict, eff: Effective, *, dry_run: bool = False, progress_fn=None) -> tuple[Outcome, JobContext]:
        kind = job["kind"]
        ctx = JobContext(db=self.db, settings=self.s, eff=eff, job=job, runner=self.runner, llm=self.llm,
                         log=logging.getLogger(f"job.{kind}"), dry_run=dry_run, progress_fn=progress_fn)
        self._current_job_dir = ctx.job_dir if job.get("id") else None
        handler = HANDLERS.get(kind)
        if handler is None:
            return Outcome.fail(f"알 수 없는 잡 종류: {kind}"), ctx
        if kind in COST_GATED and not dry_run:
            spent = costs.daily_total(self.db)
            if spent >= eff.max_daily_usd:
                return Outcome.fail(f"daily budget: 오늘 ${spent:.2f} ≥ 상한 ${eff.max_daily_usd:.2f}", retryable=False), ctx
        try:
            out = handler(ctx)
        except Exception:
            log.exception("잡 %s#%s 예외", kind, job.get("id"))
            self.db.rollback()
            out = Outcome.fail(jq.tb_tail(4000), retryable=True, cost_usd=ctx.cost_usd, tokens_in=ctx.tokens_in,
                               tokens_out=ctx.tokens_out, model=ctx.model)
        finally:
            self._current_job_dir = None
        return out, ctx

    def handle(self, job: dict, eff: Effective) -> Outcome:
        jid = int(job["id"])
        log.info("▶ 잡 #%s %s %s attempts=%s", jid, job["kind"], job.get("request_id") or "", job.get("attempts"))
        cancelled = {"v": False}

        def on_cancel():
            cancelled["v"] = True
            self.runner.kill_current()

        with jq.Heartbeat(self.db_params, jid, self.s.heartbeat_sec, on_cancel=on_cancel) as hb:
            out, _ctx = self.dispatch(job, eff, progress_fn=hb.progress)
        self.db.ensure()
        if cancelled["v"] or hb.cancelled:
            jq.finish(self.db, jid, "cancelled", error="사용자 취소", cost_usd=out.cost_usd, tokens_in=out.tokens_in,
                      tokens_out=out.tokens_out, model=out.model, result=out.result or None)
            log.warning("■ 잡 #%s cancelled", jid)
            return out
        if out.status == "done":
            jq.finish(self.db, jid, "done", result=out.result or None, model=out.model, tokens_in=out.tokens_in,
                      tokens_out=out.tokens_out, cost_usd=out.cost_usd, progress=out.progress)
            for n in out.next_jobs:
                nid = jq.enqueue(self.db, n.kind, n.request_id, n.params, f"worker:{socket.gethostname()}",
                                 ref_id=n.ref_id, repo_id=n.repo_id)
                log.info("  → 다음 잡 %s %s → #%s", n.kind, n.request_id or "", nid or "dup")
            self.db.commit()
            log.info("■ 잡 #%s done cost=$%.4f %s", jid, out.cost_usd, out.progress or "")
        else:
            # 실패 시에도 비용은 남긴다
            self.db.exec("UPDATE ai_jobs SET cost_usd = %s, tokens_in = %s, tokens_out = %s, model = COALESCE(%s, model) "
                         "WHERE id = %s", (out.cost_usd or None, out.tokens_in or None, out.tokens_out or None,
                                            out.model, jid))
            st = jq.fail_or_retry(self.db, job, out.error or "실패", retryable=out.retryable)
            if out.result:
                self.db.exec("UPDATE ai_jobs SET result = %s WHERE id = %s",
                             (json.dumps(out.result, ensure_ascii=False)[:1_000_000], jid))
                self.db.commit()
            log.warning("■ 잡 #%s %s: %s", jid, st, (out.error or "")[:500])
        try:
            store.event(self.db, job.get("request_id"), self.actor, f"job.{out.status}", "ai_jobs", jid,
                        {"kind": job["kind"], "cost": out.cost_usd, "error": (out.error or "")[:300] or None})
            self.db.commit()
        except Exception:
            log.debug("이벤트 기록 실패", exc_info=True)
        return out

    # ---- 루프
    def startup(self) -> None:
        self.db.ensure()
        store.ensure_schema(self.db)
        n = jq.fail_orphans(self.db)
        if n:
            log.warning("고아 잡 %d건 → failed(worker restarted)", n)
        store.meta_set(self.db, "ai_worker_heartbeat", int(time.time()))
        self.db.commit()
        if not self.no_sync:
            import sync_trigger

            res = sync_trigger.run_once(self.s, self.runner)
            log.info("기동 동기화: %s", res.get("text") or res.get("error"))
        if not self.no_push:
            from slack_push import PushService

            self.push = PushService(self.s, self.db_params)
            self.push.start()

    def serve(self) -> None:
        self.install_signals()
        self.startup()
        last_hb = 0.0
        last_stuck = 0.0
        last_sync = time.monotonic()
        log.info("워커 시작 %s (poll %ss, sync_interval %ss, push=%s)", self.name, self.s.poll_interval_sec,
                 self.s.sync_interval_sec, "on" if self.push and self.push._thread else "off")
        while not self.stop_requested:
            try:
                eff = self.effective()
                now = time.monotonic()
                if now - last_hb >= self.s.worker_heartbeat_sec:
                    store.meta_set(self.db, "ai_worker_heartbeat", int(time.time()))
                    self.db.commit()
                    last_hb = now
                if now - last_stuck >= 300:
                    n = jq.recover_stuck(self.db, self.s.stuck_minutes)
                    if n:
                        log.warning("stuck 잡 %d건 failed", n)
                    last_stuck = now
                iv = eff.sync_interval_sec
                if iv > 0 and now - last_sync >= iv and not self.no_sync:
                    import sync_trigger

                    sync_trigger.run_once(self.s, self.runner)
                    last_sync = time.monotonic()
                if eff.paused:
                    time.sleep(max(2, eff.poll_interval_sec))
                    continue
                job = jq.claim(self.db, self.name)
                if not job:
                    time.sleep(eff.poll_interval_sec)
                    continue
                self.handle(job, eff)
            except KeyboardInterrupt:
                self.stop_requested += 1
            except Exception:
                log.exception("메인 루프 오류 — 5초 후 계속")
                self.db.rollback()
                time.sleep(5)
                try:
                    self.db.ensure()
                except Exception:
                    log.exception("DB 재접속 실패")
        if self.push:
            self.push.stop()
        log.info("워커 종료")

    def once(self, request_id: str | None, kind: str | None, params: dict | None) -> int:
        # once 는 기동 동기화/푸시 없이 큐만 처리한다
        self.db.ensure()
        store.ensure_schema(self.db)
        eff = self.effective()
        if kind:
            jid = jq.enqueue(self.db, kind, request_id, params or {"source": "manual"}, "cli:once",
                             ref_id=(params or {}).get("ref_id"), repo_id=(params or {}).get("repo_id"))
            if jid is None:
                jid = jq.open_job_id(self.db, kind, request_id, (params or {}).get("ref_id"))
                log.info("같은 잡이 이미 열려 있음 → #%s 처리", jid)
            self.db.commit()
            job = jq.claim_by_id(self.db, int(jid), self.name) if jid else None
            if not job:
                log.error("잡을 claim 하지 못함(이미 running?) id=%s", jid)
                return 2
        else:
            job = jq.claim(self.db, self.name)
            if not job:
                log.info("처리할 queued 잡이 없다")
                return 0
        out = self.handle(job, eff)
        print(json.dumps({"job_id": job["id"], "kind": job["kind"], "status": out.status, "error": out.error,
                          "cost_usd": out.cost_usd, "result": out.result}, ensure_ascii=False, indent=1))
        return 0 if out.status == "done" else 1

    def dry_run(self, request_id: str, kind: str, params: dict | None) -> int:
        self.db.ensure()
        eff = self.effective()
        job = {"id": 0, "kind": kind, "request_id": request_id, "params": params or {}, "attempts": 1,
               "max_attempts": 1, "requested_by": "cli:dry-run"}
        out, ctx = self.dispatch(job, eff, dry_run=True)
        for block in ctx.dry_out:
            print(block)
        print(json.dumps({"status": out.status, "error": out.error, "result": out.result}, ensure_ascii=False, indent=1))
        self.db.rollback()
        return 0 if out.status == "done" else 1


# ----------------------------------------------------------------------------- selftest

def _which(name: str) -> str:
    p = Path(name)
    if p.suffix and p.is_file():
        return str(p)
    return shutil.which(name) or ""


def selftest(settings: Settings) -> int:
    ok = True
    runner = SubprocessRunner(log)
    print(f"[worker] base={BASE}  repo_root={REPO_ROOT}  data={settings.data_path}")
    # 도구
    php = php_cli.resolve_php(settings.php_bin)
    print(f"[php]    {php or '없음'}", end="")
    if php:
        c = runner.run([php, "-v"], timeout=20)
        print("  ", c.out.splitlines()[0] if c.out else c.err[:100])
    else:
        print()
        ok = False
    try:
        cl = claude_cli.resolve_claude(settings.claude_cli, settings.node_bin)
        c = runner.run([*cl, "--version"], timeout=60, env=claude_cli.claude_env())
        print(f"[claude] {cl}  → {c.out.strip() or c.err.strip()[:100]}")
        h = runner.run([*cl, "--help"], timeout=60, env=claude_cli.claude_env())
        has_file = "--append-system-prompt-file" in h.out
        print(f"[claude] --append-system-prompt-file 지원: {has_file} (없으면 인라인 --append-system-prompt 사용)")
    except FileNotFoundError as e:
        print(f"[claude] {e}")
        ok = False
        cl = None
    cx = codex_cli.resolve_codex(settings.codex_cli)
    print(f"[codex]  {cx or '미설치 → 검토는 openai/claude 로 폴백'}")
    for label, binname in (("svn", settings.svn_bin), ("git", settings.git_bin), ("node", settings.node_bin)):
        exe = _which(binname)
        if exe:
            c = runner.run([exe, "--version"], timeout=20)
            print(f"[{label}]    {exe}  → {(c.out or c.err).strip().splitlines()[0][:80] if (c.out or c.err) else ''}")
        else:
            print(f"[{label}]    없음")
    # DB
    try:
        params = settings.db_params()
        shown = {k: v for k, v in params.items() if k != "password"}
        db = Db(params)
        print(f"[db]     연결 OK {shown}")
        store.ensure_schema(db)
        have = set(store.show_tables(db))
        missing = [t for t in store.AI_TABLES + store.BASE_TABLES if t not in have]
        print(f"[schema] 테이블 {len(have)}개, ai_* {sum(1 for t in store.AI_TABLES if t in have)}/{len(store.AI_TABLES)}"
              + (f"  누락: {missing}" if missing else "  (누락 없음)"))
        now_db = db.scalar("SELECT NOW()")
        print(f"[tz]     DB NOW()={now_db}  worker now_kst={now_str()}")
        eff = Effective(settings, store.settings_all(db))
        q = db.one("SELECT COUNT(*) c FROM ai_jobs WHERE status='queued'")["c"]
        r = db.one("SELECT COUNT(*) c FROM ai_jobs WHERE status='running'")["c"]
        print(f"[jobs]   queued={q} running={r} paused={eff.paused} max_daily=${eff.max_daily_usd:.2f} "
              f"today=${costs.daily_total(db):.4f} reviewer={eff.reviewer_chain}")
        print(f"[repos]  활성 {len(store.repos_active(db))}개, 태그 {len(store.tags_active(db))}개")
        db.close()
    except Exception as e:
        print(f"[db]     실패: {e}")
        ok = False
    # LLM
    llm = build_llm(settings, runner)
    print(f"[llm]    백엔드: {llm.names() or '없음'} fast={settings.llm_model_fast} smart={settings.llm_model_smart}")
    # Slack
    print(f"[slack]  cli_token={'있음' if settings.slack_cli_token else '없음'} push={settings.slackai_push} "
          f"app_token={'있음' if settings.slack_app_token else '없음'} bot_token={'있음' if settings.slack_bot_token else '없음'} "
          f"channels={settings.push_channels}")
    # claude pong
    if cl:
        work = settings.data_path / "llm_cwd"
        work.mkdir(parents=True, exist_ok=True)
        argv = [*cl, "-p", "--output-format", "json", "--max-budget-usd", "0.05", "--tools", "",
                "--no-session-persistence", "--setting-sources", "user", "--model", settings.llm_model_fast]
        c = runner.run(argv, cwd=str(work), env=claude_cli.claude_env(use_api_key=settings.claude_use_api_key,
                                                                       oauth_token=settings.claude_code_oauth_token),
                       input=b"pong", timeout=180)
        r = claude_cli.parse_result(c.out, c.rc, c.err, c.timed_out)
        if r.raw is not None:
            print(f"[pong]   rc={c.rc} subtype={r.subtype} model={r.model} cost=${r.total_cost_usd:.4f} "
                  f"turns={r.num_turns} {r.duration_ms}ms result={r.result[:60]!r}")
        else:
            print(f"[pong]   실패 rc={c.rc}: {mask_secrets((c.err or c.out)[-300:])}")
            ok = False
    print("[selftest]", "OK" if ok else "문제 있음")
    return 0 if ok else 1


# ----------------------------------------------------------------------------- main

def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="slackai worker")
    ap.add_argument("--serve", action="store_true", help="상주(기본)")
    ap.add_argument("--once", action="store_true", help="잡 1건만 처리")
    ap.add_argument("--dry-run", action="store_true", help="argv/프롬프트만 출력")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--request", help="Rec… id")
    ap.add_argument("--kind", help=f"잡 종류 {sorted(HANDLERS)}")
    ap.add_argument("--params", help="JSON 파라미터")
    ap.add_argument("--no-sync", action="store_true", help="기동 시 증분 동기화 생략")
    ap.add_argument("--no-push", action="store_true", help="Socket Mode 수신 생략")
    ap.add_argument("-v", "--verbose", action="store_true")
    a = ap.parse_args(argv)

    settings = Settings()
    setup_logging(settings, a.verbose)
    if a.selftest:
        return selftest(settings)
    params = None
    if a.params:
        params = json.loads(a.params)
        if not isinstance(params, dict):
            ap.error("--params 는 JSON 객체여야 한다")
    if a.kind and a.kind not in HANDLERS:
        ap.error(f"--kind 는 {sorted(HANDLERS)} 중 하나")
    db = Db(settings.db_params())
    w = Worker(settings, db, no_sync=a.no_sync, no_push=a.no_push)
    try:
        if a.dry_run:
            if not a.kind:
                ap.error("--dry-run 에는 --kind (와 --request) 가 필요하다")
            return w.dry_run(a.request or "", a.kind, params)
        if a.once:
            return w.once(a.request, a.kind, params)
        w.serve()
        return 0
    finally:
        db.close()


if __name__ == "__main__":
    sys.exit(main())
