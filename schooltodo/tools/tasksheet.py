import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

BASE_URL = os.environ.get("IWORKS_URL", "http://iworks.bizblue.co.kr").rstrip("/")
CREDENTIALS = Path(os.environ.get("IWORKS_CREDENTIALS", Path.home() / ".config" / "safepush" / "iworks.json"))
TIMEOUT = 20


class Fail(Exception):
    pass


def login():
    if not CREDENTIALS.is_file():
        raise Fail(f"iWorks 계정 파일이 없습니다: {CREDENTIALS}")
    account = json.loads(CREDENTIALS.read_text(encoding="utf-8"))
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    send(opener, "POST", f"{BASE_URL}/api/login.php",
         {"email": account.get("email"), "password": account.get("password"), "remember": False})
    return opener


def send(opener, method, url, body=None):
    data = None if body is None else json.dumps(body).encode()
    request = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json"})
    try:
        with opener.open(request, timeout=TIMEOUT) as response:
            return json.loads(response.read() or b"null")
    except urllib.error.HTTPError as e:
        try:
            payload = json.loads(e.read())
            detail = payload.get("detail") or payload.get("error") or ""
        except ValueError:
            detail = ""
        raise Fail(f"HTTP {e.code} {detail}".strip()) from e
    except OSError as e:
        raise Fail(f"iWorks 에 연결하지 못했습니다: {e!r}") from e


def api(opener, method, path, body=None, query=None):
    params = {"p": path, **(query or {})}
    return send(opener, method, f"{BASE_URL}/schooltodo/api.php?{urllib.parse.urlencode(params)}", body)


def sheet_id(opener, sheet):
    if sheet.isdigit():
        return int(sheet)
    # 이름으로 찾을 때는 보관한 것도 본다 (unarchive 가 이름으로 가리킬 수 있게)
    found = [s for s in api(opener, "GET", "/sheets", query={"all": 1}) if s["title"] == sheet]
    if not found:
        raise Fail(f"없는 시트입니다: {sheet}")
    return found[0]["id"]


def main():
    parser = argparse.ArgumentParser(description="Coursemos TaskSheet 조회·채우기 (iWorks 계정으로 로그인)")
    commands = parser.add_subparsers(dest="command", required=True)

    commands.add_parser("sheets", help="시트 목록과 상태별 대학 수 (보관한 시트는 --all 일 때만)") \
        .add_argument("--all", action="store_true", help="보관한 시트도 포함")

    create = commands.add_parser("create", help="시트 만들기")
    create.add_argument("title")
    create.add_argument("--description", default="")

    rows = commands.add_parser("rows", help="시트의 대학별 상태")
    rows.add_argument("sheet", help="시트 번호 또는 이름")
    rows.add_argument("--status")

    for name, help_text in (("archive", "시트를 보관함으로"), ("unarchive", "보관함에서 꺼내기")):
        commands.add_parser(name, help=help_text).add_argument("sheet", help="시트 번호 또는 이름")

    fill = commands.add_parser("fill", help="대학의 상태·메모 채우기")
    fill.add_argument("sheet", help="시트 번호 또는 이름")
    fill.add_argument("--school", required=True, help="대학 이름")
    fill.add_argument("--ver")
    fill.add_argument("--status")
    fill.add_argument("--note")

    args = parser.parse_args()
    opener = login()

    if args.command == "sheets":
        result = api(opener, "GET", "/sheets", query={"all": 1} if args.all else None)
    elif args.command == "create":
        result = api(opener, "POST", "/sheets", {"title": args.title, "description": args.description})
    elif args.command == "rows":
        query = {"status": args.status} if args.status else None
        result = api(opener, "GET", f"/sheets/{sheet_id(opener, args.sheet)}", query=query)
    elif args.command in ("archive", "unarchive"):
        result = api(opener, "PUT", f"/sheets/{sheet_id(opener, args.sheet)}", {"archived": args.command == "archive"})
    else:
        item = {key: value for key, value in
                {"school": args.school, "ver": args.ver, "status": args.status, "note": args.note}.items()
                if value is not None}
        result = api(opener, "PUT", f"/sheets/{sheet_id(opener, args.sheet)}/cells", {"items": [item]})

    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    try:
        main()
    except Fail as e:
        print(e, file=sys.stderr)
        sys.exit(1)
