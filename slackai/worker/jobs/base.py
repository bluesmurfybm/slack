"""잡 핸들러 공통: JobContext(입력) / Outcome(출력). 핸들러 시그니처는 run(ctx) -> Outcome."""

import json
import logging
import socket
from dataclasses import dataclass, field
from pathlib import Path

from core.text import mask_secrets
from llm.base import LLMError, LLMResult
from llm.prompts import SCHEMAS, SYSTEMS


@dataclass
class NextJob:
    kind: str
    request_id: str | None
    params: dict = field(default_factory=dict)
    ref_id: int | None = None
    repo_id: int | None = None


@dataclass
class Outcome:
    status: str = "done" # done | failed | cancelled
    error: str | None = None
    cost_usd: float = 0.0
    tokens_in: int = 0
    tokens_out: int = 0
    model: str | None = None
    result: dict = field(default_factory=dict)
    next_jobs: list[NextJob] = field(default_factory=list)
    retryable: bool = True
    progress: str | None = None

    @staticmethod
    def fail(error: str, *, retryable: bool = False, **kw) -> "Outcome":
        return Outcome(status="failed", error=error, retryable=retryable, **kw)


class JobContext:
    def __init__(self, *, db, settings, eff, job: dict, runner, llm, log: logging.Logger | None = None,
                 dry_run: bool = False, progress_fn=None):
        self.db = db
        self.settings = settings
        self.eff = eff
        self.job = job
        self.params: dict = dict(job.get("params") or {})
        self.runner = runner
        self.llm = llm
        self.log = log or logging.getLogger(f"job.{job.get('kind')}")
        self.dry_run = dry_run
        self._progress_fn = progress_fn
        self.actor = f"worker:{socket.gethostname()}"
        self.cost_usd = 0.0
        self.tokens_in = 0
        self.tokens_out = 0
        self.model: str | None = None
        self.dry_out: list[str] = [] # dry-run 이 출력할 내용

    @property
    def job_id(self) -> int:
        return int(self.job.get("id") or 0)

    @property
    def request_id(self) -> str | None:
        return self.job.get("request_id")

    @property
    def job_dir(self) -> Path:
        d = self.settings.jobs_path / str(self.job_id or "dry")
        d.mkdir(parents=True, exist_ok=True)
        return d

    def progress(self, text: str) -> None:
        self.log.info("[%s#%s] %s", self.job.get("kind"), self.job_id, mask_secrets(text))
        if self._progress_fn:
            try:
                self._progress_fn(text)
            except Exception:
                self.log.debug("progress 기록 실패", exc_info=True)

    def save(self, name: str, content: str | bytes) -> Path:
        """var/jobs/<id>/<name> 에 프롬프트·출력 사본을 남긴다(gitignore)."""
        p = self.job_dir / name
        if isinstance(content, bytes):
            p.write_bytes(content)
        else:
            p.write_text(content, encoding="utf-8")
        return p

    def llm_call(self, key: str, user: str, *, smart: bool = True, system: str | None = None,
                 budget_usd: float = 0.5, max_tokens: int = 8000, model: str | None = None) -> LLMResult:
        """SCHEMAS[key]/SYSTEMS[key] 로 호출하고 비용을 누적한다. 프롬프트 사본을 남긴다."""
        schema = SCHEMAS[key]
        sysm = system if system is not None else SYSTEMS[key]
        mdl = model or (self.settings.llm_model_smart if smart else self.settings.llm_model_fast)
        self.save(f"{key.lower()}_system.md", sysm)
        self.save(f"{key.lower()}_user.md", user)
        if self.dry_run:
            self.dry_out.append(f"=== LLM {key} model={mdl} ===\n--- system ---\n{sysm}\n--- user ---\n{user}\n")
            raise LLMError("dry-run", retryable=False)
        r = self.llm.generate(system=sysm, user=user, schema=schema, model=mdl, max_tokens=max_tokens,
                              budget_usd=budget_usd)
        self.save(f"{key.lower()}_out.json", json.dumps(r.data, ensure_ascii=False, indent=1))
        self.add_cost(r.cost_usd, r.tokens_in, r.tokens_out, r.model)
        return r

    def add_cost(self, cost: float, tin: int = 0, tout: int = 0, model: str | None = None) -> None:
        self.cost_usd += float(cost or 0)
        self.tokens_in += int(tin or 0)
        self.tokens_out += int(tout or 0)
        if model:
            self.model = model

    def outcome(self, status: str = "done", **kw) -> Outcome:
        o = Outcome(status=status, **kw)
        o.cost_usd = round(self.cost_usd, 4)
        o.tokens_in = self.tokens_in
        o.tokens_out = self.tokens_out
        o.model = o.model or self.model
        return o

    def event(self, action: str, ref_table: str | None = None, ref_id: int | None = None,
              detail: dict | None = None, request_id: str | None = None) -> None:
        from core import store

        store.event(self.db, request_id or self.request_id, self.actor, action, ref_table, ref_id, detail)


def repo_slug(repo: dict) -> str:
    import re

    name = str(repo.get("name") or "")
    s = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
    return s or f"repo{repo.get('id')}"
