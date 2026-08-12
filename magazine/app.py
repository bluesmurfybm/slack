import os

from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles

from core.config import Settings
from core.db import init_db
from features import identity, material, topics
from features.identity.auth import get_identity


def create_app(settings: Settings = None) -> FastAPI:
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
        if not settings.dev_login and not get_identity(request):
            return RedirectResponse(settings.portal_url)
        return FileResponse(settings.index_path)

    return app


app = create_app()


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
