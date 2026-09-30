from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from blui import db
from blui.configs import config
from blui.routes import ask, conversations, health
from common.knowledge import load_from_dir


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None]:
    app.state.knowledge = load_from_dir(config.knowledge_dir)
    with db.engine.connect():
        pass
    try:
        yield
    finally:
        db.engine.dispose()


app = FastAPI(title="blui", lifespan=lifespan)
app.include_router(health.router)
app.include_router(ask.router)
app.include_router(conversations.router)
