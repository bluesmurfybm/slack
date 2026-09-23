"""gemini CLI 검토 백엔드 — 스텁. GEMINI_CLI 가 설정되고 실행 파일이 있을 때만 available()=True 이고,
실제 검토 호출은 아직 구현하지 않아 review() 가 NotImplementedError 를 낸다(체인은 다음 백엔드로 넘어간다)."""

import shutil
from pathlib import Path


def available(name: str = "", which=shutil.which) -> bool:
    if not name:
        return False
    p = Path(name)
    return bool((p.suffix and p.is_file()) or which(name))


def review(*_a, **_k):
    raise NotImplementedError("gemini 검토 백엔드는 아직 구현되지 않았다")
