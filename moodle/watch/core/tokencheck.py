"""moodle.org 웹서비스 토큰 상태 확인.

토큰은 만료돼도 알려 주는 곳이 없다. 보안 키 페이지에 유효기간이 안 보이고, 만료·삭제되면
그냥 사라져서 다음 수집이 통째로 실패한다. 그래서 실제로 site_info 를 한 번 불러 살아 있는지
본다. 결과는 var/token_status.json 에 남기고, 뷰어(moodle/index.php)가 관리자에게만 토스트로
보여 준다. 주간 수집(moodleorg 수집기)과 `run_weekly.py --check-token`(금요일 오후 timer)
둘 다 이 파일을 갱신한다.
"""

import json
from datetime import UTC, datetime

from core.config import PAG_COURSE_ID, PAG_COURSE_URL, Settings

WS = "https://moodle.org/webservice/rest/server.php"
STATUS_FILE = "token_status.json"
MANAGE_URL = "https://moodle.org/user/managetoken.php"

# 코스 함수가 이 코드로 실패하면 토큰이 아니라 코스 접근(수강 등록·권한) 문제다.
COURSE_ACCESS_ERRORS = {"accessexception", "errorcoursecontextnotvalid", "requireloginerror",
                        "nopermissions"}


def probe(settings: Settings, http) -> dict:
    """site_info 로 토큰이 살아 있는지 본다. 예외를 밖으로 내지 않고 dict 로 돌려준다.

    kind: ok | missing(토큰 미설정) | token(무효·만료·삭제) | network(연결 실패)
    """
    st = {"checked_at": datetime.now(UTC).isoformat(timespec="seconds"),
          "ok": False, "kind": "missing", "errorcode": None, "message": None,
          "username": None, "userid": None}
    if not settings.moodle_org_token:
        st["message"] = "MOODLE_ORG_TOKEN 이 없다"
        st["note"] = describe(st)
        return st
    try:
        data = http.get_json(WS, params={"wstoken": settings.moodle_org_token,
                                         "wsfunction": "core_webservice_get_site_info",
                                         "moodlewsrestformat": "json"})
    except Exception as e: # noqa: BLE001 연결·HTTP 오류는 종류를 가리지 않고 network 로 본다
        st["kind"] = "network"
        st["errorcode"] = type(e).__name__
        st["message"] = str(e)[:300]
        st["note"] = describe(st)
        return st
    if isinstance(data, dict) and data.get("exception"):
        # site_info 는 유효한 토큰만 있으면 되는 함수라, 여기서 나는 예외는 전부 토큰·계정 문제다.
        st["kind"] = "token"
        st["errorcode"] = data.get("errorcode")
        st["message"] = " ".join(x for x in (data.get("message"), data.get("debuginfo")) if x)
    else:
        st["ok"] = True
        st["kind"] = "ok"
        st["username"] = data.get("username") if isinstance(data, dict) else None
        st["userid"] = data.get("userid") if isinstance(data, dict) else None
    st["note"] = describe(st)
    return st


def describe(st: dict) -> str:
    """리포트 노트·CLI 출력·뷰어 토스트가 같이 쓰는 한 줄 설명."""
    kind = st.get("kind")
    if kind == "ok":
        return f"moodle.org 토큰 정상({st.get('username') or '?'})"
    if kind == "missing":
        return "MOODLE_ORG_TOKEN 이 없어 moodle.org PAG 코스는 건너뛰었다"
    if kind == "network":
        return (f"moodle.org 웹서비스에 연결하지 못함({st.get('errorcode')}: {st.get('message')}). "
                "토큰 문제가 아닐 수 있다. 잠시 뒤 다시 확인")
    return (f"moodle.org 토큰이 무효({st.get('errorcode')}: {st.get('message')}). 만료되거나 "
            f"삭제된 것. {MANAGE_URL} 에서 확인하고 login/token.php 로 재발급해 "
            "MOODLE_ORG_TOKEN 을 바꾼 뒤 요약하기를 다시 누른다")


def course_access_note(st: dict, errorcode: str, message: str) -> str:
    who = st.get("username") or "?"
    return (f"moodle.org 토큰은 유효({who})하지만 PAG 코스({PAG_COURSE_ID})에 접근할 수 없음"
            f"({errorcode}: {message}). 그 계정으로 로그인해 코스 수강 등록(자가등록)이 "
            f"유지되는지 확인: {PAG_COURSE_URL}")


def status_path(settings: Settings):
    return settings.data_path / STATUS_FILE


def record(settings: Settings, st: dict) -> None:
    """뷰어가 읽을 수 있게 var/ 에 남긴다. 실행 계정 기본 umask 면 www-data 도 읽는다."""
    settings.data_path.mkdir(parents=True, exist_ok=True)
    status_path(settings).write_text(json.dumps(st, ensure_ascii=False, indent=1),
                                     encoding="utf-8")


def probe_and_record(settings: Settings, http) -> dict:
    st = probe(settings, http)
    record(settings, st)
    return st
