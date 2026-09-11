from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from blue_chatbot.configs.core import config
from blue_chatbot.routes import ask, health
from blue_chatbot.services import faq


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None]:
    app.state.faq = faq.load(config.faq_path)
    yield


app = FastAPI(title="blue-chatbot", lifespan=lifespan)
app.include_router(health.router)
app.include_router(ask.router)
