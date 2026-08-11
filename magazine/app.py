# -*- coding: utf-8 -*-
"""BlueUP-DTI 발표 주제 관리 서버 — 조립만 담당한다.

실행:
    pip install -r requirements.txt
    python app.py            ->  http://localhost:8001

구조:
    core/       config(설정) · db(연결·스키마·시드)
    features/
      identity/ 포털 SSO 쿠키 검증, 관리자 판정, whoami, 개발 로그인
      topics/   주제 등록·수정·삭제와 선점 흐름 (models/service/router)
      material/ 발표 자료 파일·링크 (storage/router)
      notify/   슬랙 알림
    web/        index.html, static(js), styles(css)
    data/       seed.json
    var/        런타임 산출물 — DB, 업로드 파일 (git 제외)
    tools/      xlsx -> seed.json 변환기
    tests/

로그인 화면은 없다. 포털(PHP auth.php)이 심는 쿠키를 검증만 하므로
포털과 같은 호스트에서 서빙되어야 한다(포트는 달라도 된다).
"""
import os

from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles

from core.config import Settings
from core.db import init_db
from features import identity, material, topics
from features.identity.auth import get_identity


def create_app(settings: Settings = None) -> FastAPI:
    """설정을 받아 앱을 만든다.

    팩토리로 둔 이유: 테스트가 모듈을 reload 하지 않고 설정만 바꿔
    앱을 새로 만들 수 있어야 하기 때문이다.
    """
    settings = settings or Settings.from_env()
    init_db(settings)

    app = FastAPI(title="BlueUP-DTI 발표 주제")
    app.state.settings = settings

    app.mount("/styles", StaticFiles(directory=settings.styles_dir), name="styles")
    app.mount("/static", StaticFiles(directory=settings.static_dir), name="static")
    # 포털 공용 스타일(topbar.css). 컨테이너에는 없을 수 있으므로 있을 때만 마운트한다
    # (StaticFiles 는 디렉터리가 없으면 기동 시점에 바로 예외를 던진다).
    if os.path.isdir(settings.shared_styles_dir):
        app.mount("/shared-styles",
                  StaticFiles(directory=settings.shared_styles_dir),
                  name="shared-styles")

    app.include_router(identity.router)
    app.include_router(topics.router)
    app.include_router(material.router)
    if settings.dev_login:
        app.include_router(identity.devlogin_router)

    @app.get("/")
    def index(request: Request):
        # 개발 모드에서는 로그인 전에도 화면을 내준다. 계정 전환 바를 써야 하기 때문.
        if not settings.dev_login and not get_identity(request):
            return RedirectResponse(settings.portal_url)
        return FileResponse(settings.index_path)

    return app


app = create_app()


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
