from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles

from core.config import Settings
from core.db import init_db
from features import emotion, fields, identity, material, topics
from features.identity.auth import get_identity


def create_app(settings: Settings = None) -> FastAPI:
    settings = settings or Settings()

    app = FastAPI(title="BlueUP-DTI 발표 아티클")
    app.state.settings = settings
    app.state.engine = init_db(settings)

    app.mount("/styles", StaticFiles(directory=settings.styles_dir), name="styles")
    app.mount("/static", StaticFiles(directory=settings.static_dir), name="static")
    if Path(settings.shared_styles_dir).is_dir():
        app.mount("/shared-styles",
                  StaticFiles(directory=settings.shared_styles_dir),
                  name="shared-styles")

    @app.middleware("http")
    async def _revalidate_static(request: Request, call_next):
        response = await call_next(request)
        if request.url.path.startswith(("/static/", "/styles/", "/shared-styles/")):
            response.headers["cache-control"] = "no-cache"
        return response

    app.include_router(identity.router)
    app.include_router(topics.router)
    app.include_router(material.router)
    app.include_router(emotion.router)
    app.include_router(fields.router)
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
    uvicorn.run(app, host="0.0.0.0", port=8001) # noqa: S104 사내망의 다른 PC 에서 접속한다
