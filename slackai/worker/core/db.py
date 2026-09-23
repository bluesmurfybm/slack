"""PyMySQL 연결 래퍼. autocommit=False, utf8mb4, 세션 time_zone=+09:00(KST), 끊기면 재접속."""

import logging
import time

logger = logging.getLogger(__name__)


def connect(params: dict):
    import pymysql
    from pymysql.cursors import DictCursor

    conn = pymysql.connect(**params, charset="utf8mb4", autocommit=False, cursorclass=DictCursor,
                           connect_timeout=10, read_timeout=600, write_timeout=120)
    with conn.cursor() as cur:
        cur.execute("SET time_zone = '+09:00'")
        cur.execute("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci")
    conn.commit()
    return conn


class Db:
    """얇은 헬퍼. 잡 하나 = 여러 문장 + commit(). 연결이 죽으면 다음 문장 전에 재접속한다."""

    def __init__(self, params: dict, conn=None):
        self.params = params
        self.conn = conn or connect(params)

    def ensure(self) -> None:
        try:
            self.conn.ping(reconnect=True)
        except Exception:
            logger.warning("DB 재접속")
            time.sleep(1)
            self.conn = connect(self.params)

    def cursor(self):
        return self.conn.cursor()

    def exec(self, sql: str, args=None) -> int:
        with self.conn.cursor() as cur:
            n = cur.execute(sql, args)
            self.last_id = cur.lastrowid
            return n

    def one(self, sql: str, args=None) -> dict | None:
        with self.conn.cursor() as cur:
            cur.execute(sql, args)
            return cur.fetchone()

    def all(self, sql: str, args=None) -> list[dict]:
        with self.conn.cursor() as cur:
            cur.execute(sql, args)
            return list(cur.fetchall())

    def scalar(self, sql: str, args=None):
        row = self.one(sql, args)
        if not row:
            return None
        return next(iter(row.values()))

    def commit(self) -> None:
        self.conn.commit()

    def rollback(self) -> None:
        try:
            self.conn.rollback()
        except Exception:
            logger.debug("rollback 실패(무시)", exc_info=True)

    def close(self) -> None:
        try:
            self.conn.close()
        except Exception:
            pass
