from logging.config import fileConfig

from sqlmodel import SQLModel

from alembic import context
from blue_chatbot.configs.core import config as app_config
from blue_chatbot.repositories import (
    conversation as _models,  # noqa: F401  모델을 metadata에 등록한다
)
from blue_chatbot.repositories import db
from blue_chatbot.repositories import workhub as _workhub_models  # noqa: F401

alembic_config = context.config

if alembic_config.config_file_name is not None:
    fileConfig(alembic_config.config_file_name)

target_metadata = SQLModel.metadata


def run_migrations_offline() -> None:
    context.configure(
        url=app_config.database_url.get_secret_value(),
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    with db.engine.connect() as connection:
        context.configure(connection=connection, target_metadata=target_metadata)
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
