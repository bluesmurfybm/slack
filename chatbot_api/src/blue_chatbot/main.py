from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from blue_chatbot.repositories import db
from blue_chatbot.routes import ask, conversations, health


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None]:
    with db.engine.connect():
        pass
    try:
        yield
    finally:
        db.engine.dispose()


app = FastAPI(title="blue-chatbot", lifespan=lifespan)
app.include_router(health.router)
app.include_router(ask.router)
app.include_router(conversations.router)
