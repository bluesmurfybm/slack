"""요청 벡터에 상태와 팀과 보관 여부

Revision ID: b7c2e93f4a10
Revises: d4a91c07e5b2
Create Date: 2026-09-21

검색 도구가 이 값으로 거른다. 원본 requests는 다른 데이터베이스에 있어 한 질의로
거를 수 없으므로 색인할 때 벡터와 같은 테이블에 함께 저장한다.
"""

from collections.abc import Sequence

import sqlalchemy as sa
import sqlmodel

from alembic import op

revision: str = "b7c2e93f4a10"
down_revision: str | Sequence[str] | None = "d4a91c07e5b2"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

TABLE = "request_vectors_openai_3_small"


def upgrade() -> None:
    # 이미 쌓인 행에도 값이 있어야 하므로 기본값을 준다. 색인이 실제 값으로 덮어쓴다.
    op.add_column(
        TABLE,
        sa.Column(
            "status",
            sqlmodel.sql.sqltypes.AutoString(length=32),
            nullable=False,
            server_default="",
        ),
    )
    op.add_column(
        TABLE,
        sa.Column(
            "team",
            sqlmodel.sql.sqltypes.AutoString(length=32),
            nullable=False,
            server_default="",
        ),
    )
    op.add_column(
        TABLE,
        sa.Column("archived", sa.Boolean(), nullable=False, server_default=sa.text("0")),
    )


def downgrade() -> None:
    op.drop_column(TABLE, "archived")
    op.drop_column(TABLE, "team")
    op.drop_column(TABLE, "status")
