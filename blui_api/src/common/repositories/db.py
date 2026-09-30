from pydantic import SecretStr
from sqlalchemy import Engine, create_engine


def build_engine(url: SecretStr) -> Engine:
    return create_engine(
        url.get_secret_value(),
        pool_pre_ping=True,
        connect_args={"binary_prefix": True},
    )
