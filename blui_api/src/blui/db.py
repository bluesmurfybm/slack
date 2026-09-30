from blui.configs import config
from common.repositories.db import build_engine

engine = build_engine(config.database_url)
