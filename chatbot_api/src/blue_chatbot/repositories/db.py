from sqlalchemy import Engine, create_engine

from blue_chatbot.configs.core import config


def build_engine() -> Engine:
    return create_engine(
        config.database_url.get_secret_value(),
        pool_pre_ping=True,
    )


engine = build_engine()
