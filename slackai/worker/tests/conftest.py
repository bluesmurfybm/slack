"""Tier A 공용 픽스처: make_settings / FakeRunner / FakeLLM / FakeDb. DB·네트워크 없이 돈다.

Tier B(환경 있을 때만): SLACKAI_TEST_DSN, svnadmin, PHP_BIN 이 있으면 각 테스트가 스스로 skip 을 해제한다.
"""

import json
import os
import shutil
import sys
from pathlib import Path

import pytest

BASE = Path(__file__).resolve().parent.parent
if str(BASE) not in sys.path:
    sys.path.insert(0, str(BASE))

from core.config import Settings  # noqa: E402
from llm import prompts  # noqa: E402
from llm.base import LLMResult  # noqa: E402
from tools.runner import FakeRunner  # noqa: E402


def make_settings(tmp_path, **over) -> Settings:
    base = {
        "data_dir": str(tmp_path / "var"),
        "php_bin": "php", "claude_cli": "claude", "codex_cli": "codex", "gemini_cli": "",
        "slack_token": None, "slack_bot_token": None, "slack_app_token": None, "slackai_push": False,
        "anthropic_api_key": None, "openai_api_key": None, "claude_use_api_key": False,
        "portal_url": "http://dev.iworks.co.kr", "sync_interval_sec": 0,
    }
    base.update(over)
    return Settings(_env_file=None, **base)


REQ = {
    "id": "Rec0TEST00001", "list_id": "F083TU7F0BZ", "board": "블루소프트", "archived": 0,
    "title": "[항공대] 동영상 원본 파일 변경 후 출석 인정 시간 표시 오류", "body": "재생시간 2분 41초인데 90% 인정이 2분 58초로 표시됨",
    "momo": "", "lms": "https://lxp.kau.ac.kr/local/ubattend/my.php?id=965", "req": "박유나", "asg": "조성훈",
    "status": "등록", "priority": "일반", "team": "", "cmt_count": 2, "eta": None, "date": None, "done": None,
    "created": 1788498511, "updated": 1788498511, "attachments": None,
}

TAGS = [
    {"id": 10, "name": "출석·출결", "slug": "attend", "parent_id": None, "keywords": "출석,출결", "description": None},
    {"id": 15, "name": "동영상·미디어", "slug": "media", "parent_id": None, "keywords": "동영상,재생", "description": None},
    {"id": 53, "name": "화면·UI·페이지", "slug": "ui", "parent_id": None, "keywords": "화면", "description": None},
]

REPOS = [
    {"id": 1, "name": "KAU LXP", "school_id": 7, "vcs": "svn", "remote_url": None, "local_path": "G:/wc/kau",
     "default_branch": None, "version": "4.5", "php_bin": None, "match_rules": None, "notes": "", "active": 1},
    {"id": 2, "name": "csms45", "school_id": None, "vcs": "git", "remote_url": None, "local_path": "G:/wc/csms45",
     "default_branch": "main", "version": "4.5", "php_bin": None,
     "match_rules": json.dumps({"lms_patterns": ["*.moodler.kr*"], "title_keywords": ["csms"]}), "notes": "", "active": 1},
]

SCHOOLS = [
    {"id": 7, "name": "항공대", "ver": "4.5", "dev": "http://kau.moodler.kr/", "ops": "https://lxp.kau.ac.kr"},
    {"id": 8, "name": "가천대", "ver": "2.9", "dev": "http://gcu.moodler.kr/", "ops": "https://cyber.gachon.ac.kr"},
]

CANNED = {
    "TRIAGE": {"summary_md": "- 동영상 교체 후 출석 인정 시간이 실제보다 길게 표시\n- 100% 시청 시 출석은 정상", "problem_type": "bug",
               "urgency": "high", "urgency_reason": "출석 마감", "affected_area": "local/ubattend", "difficulty": 3,
               "difficulty_reason": "진도율 재계산 로직", "difficulty_conf": "medium",
               "tags": [{"slug": "attend", "confidence": 0.9}, {"slug": "media", "confidence": 0.6}, {"slug": "nope", "confidence": 1}],
               "new_tag_suggestion": None, "repo_guess": {"repo_id": 1, "confidence": 0.8, "reason": "lms url"},
               "needs_more_info": ["강좌 id"]},
    "RERANK": {"top": [{"id": "RecOLD1", "score": 0.9, "why_similar": "같은 모듈", "resolution_note": "진도율 캐시 초기화"},
                       {"id": "RecOLD2", "score": 0.4, "why_similar": "출석", "resolution_note": "처리 내용 미확인"}]},
    "COMMITMSG": {"subject": "fix(ubattend): 동영상 교체 후 출석 인정 시간 재계산", "body": "출석 인정 시간 오류 수정\n- local/ubattend/lib.php"},
    "LESSONS": {"lessons": [{"kind": "file_miss", "scope": "repo", "tag_slug": None,
                             "lesson_md": "ubattend 수정 시 progress 캐시 테이블도 함께 확인한다.", "evidence_md": "diff", "weight": 0.7}],
                "plan_quality": 4, "notes_md": ""},
    "DISTILL": {"knowledge_md": "## 저장소 개요\n- x\n## 디렉터리·모듈 지도\n- y\n## 코딩 규칙\n- z\n## 자주 놓치는 것\n- w\n## 테스트 방법\n- v"},
    "REVIEW": {"verdict": "pass", "summary_md": "ok", "addresses_inquiry": True,
               "findings": [{"severity": "major", "file": "a.php", "line": 3, "text": "널 체크", "suggestion": "isset"}],
               "missing": [], "test_suggestions": []},
}


class FakeLLM:
    """스키마별 캔 응답. 호출을 기록한다."""

    name = "fake"

    def __init__(self, canned: dict | None = None):
        self.canned = dict(CANNED)
        if canned:
            self.canned.update(canned)
        self.calls: list[dict] = []

    def available(self) -> bool:
        return True

    def generate(self, *, system, user, schema, model, max_tokens=8000, budget_usd=0.5) -> LLMResult:
        key = next((k for k, v in prompts.SCHEMAS.items() if v is schema), None)
        self.calls.append({"key": key, "system": system, "user": user, "model": model})
        if key not in self.canned:
            raise AssertionError(f"캔 응답 없음: {key}")
        return LLMResult(data=json.loads(json.dumps(self.canned[key])), text="", model=model, backend="fake",
                         tokens_in=100, tokens_out=50, cost_usd=0.001)


class FakeDb:
    """SQL 문자열과 인자를 기록하고, 등록된 응답을 돌려준다. one/all/scalar 는 부분 문자열 매칭."""

    def __init__(self):
        self.execs: list[tuple[str, tuple | None]] = []
        self.queries: list[tuple[str, tuple | None]] = []
        self.responses: list[tuple[str, object]] = []
        self.last_id = 1
        self.params = {}

    def on(self, needle: str, value) -> "FakeDb":
        self.responses.append((needle, value))
        return self

    def _find(self, sql):
        for needle, v in self.responses:
            if needle in sql:
                return v
        return None

    def ensure(self):
        pass

    def exec(self, sql, args=None):
        self.execs.append((sql, args))
        return 1

    def one(self, sql, args=None):
        self.queries.append((sql, args))
        v = self._find(sql)
        return v if isinstance(v, dict) or v is None else (v[0] if v else None)

    def all(self, sql, args=None):
        self.queries.append((sql, args))
        v = self._find(sql)
        return list(v) if v else []

    def scalar(self, sql, args=None):
        self.queries.append((sql, args))
        v = self._find(sql)
        if isinstance(v, dict):
            return next(iter(v.values()))
        return v

    def commit(self):
        pass

    def rollback(self):
        pass

    def close(self):
        pass


@pytest.fixture
def settings(tmp_path):
    return make_settings(tmp_path)


@pytest.fixture
def runner():
    return FakeRunner()


@pytest.fixture
def fake_llm():
    return FakeLLM()


def has_php() -> str | None:
    p = os.environ.get("PHP_BIN") or ""
    if p and Path(p).is_file():
        return p
    return shutil.which("php")


def has_svnadmin() -> bool:
    return bool(shutil.which("svnadmin") and shutil.which("svn"))


def test_dsn() -> str | None:
    return os.environ.get("SLACKAI_TEST_DSN")
