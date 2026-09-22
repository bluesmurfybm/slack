from sqlalchemy import Engine, create_engine

from blue_chatbot.configs.core import config


def build_engine() -> Engine:
    return create_engine(
        config.database_url.get_secret_value(),
        pool_pre_ping=True,
        connect_args={"binary_prefix": True},
    )


def build_iwork_engine() -> Engine:
    """챗봇이 읽는 iWorks 데이터베이스. IWORK_DB_URL이 있어야 한다."""
    if config.iwork_db_url is None:
        raise ValueError("IWORK_DB_URL이 없습니다")
    return create_engine(config.iwork_db_url.get_secret_value(), pool_pre_ping=True)


engine = build_engine()
