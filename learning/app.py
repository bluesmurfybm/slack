from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles

from core.config import Settings
from core.db import admin_emails, init_db
from features import identity
from features.admins.router import router as admins_router
from features.categories.router import router as categories_router
from features.cert.router import router as cert_router
from features.identity.auth import get_identity
from features.policy.router import router as policy_router
from features.requests.router import router as requests_router
from features.review.router import router as review_router
from features.sites.router import router as sites_router


def create_app(settings: Settings = None) -> FastAPI:
    settings = settings or Settings()

    app = FastAPI(title="BlueLearn")
    app.state.settings = settings
    app.state.engine = init_db(settings)
    # 관리자 명단은 화면에서 바뀐다 — 기동 때 DB 에서 읽어 두고, 바뀌면 갈아끼운다
    app.state.admins = admin_emails(app.state.engine)

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
    app.include_router(requests_router)
    app.include_router(cert_router)
    app.include_router(review_router)
    app.include_router(sites_router)
    app.include_router(categories_router)
    app.include_router(policy_router)
    app.include_router(admins_router)
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
    uvicorn.run(app, host="0.0.0.0", port=8002) # noqa: S104 사내망의 다른 PC 에서 접속한다
