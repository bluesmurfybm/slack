"""요청 임베딩 벡터 테이블

Revision ID: d4a91c07e5b2
Revises: ab1f3fc8139a
Create Date: 2026-09-18

VECTOR 타입과 VECTOR INDEX는 autogenerate가 옮기지 못해 직접 작성한다.
"""

from collections.abc import Sequence

import sqlalchemy as sa
import sqlmodel

from alembic import op
from blue_chatbot.repositories.base import Vector

revision: str = "d4a91c07e5b2"
down_revision: str | Sequence[str] | None = "ab1f3fc8139a"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

TABLE = "request_vectors_openai_3_small"
DIMENSION = 1536


def upgrade() -> None:
    op.create_table(
        TABLE,
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column("created_at", sa.DateTime(), nullable=False),
        sa.Column(
            "request_id",
            sqlmodel.sql.sqltypes.AutoString(length=32),
            nullable=False,
            comment="slack_db.requests의 id 식별자",
        ),
        sa.Column(
            "source_updated",
            sa.Integer(),
            nullable=False,
            comment="임베딩할 때 원본 requests.updated 값. 색인 위치로도 쓴다",
        ),
        sa.Column("embedding", Vector(DIMENSION), nullable=False),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("request_id"),
    )
    op.create_index(f"ix_{TABLE}_source_updated", TABLE, ["source_updated"], unique=False)
    # DISTANCE를 선언해야 VEC_DISTANCE_COSINE 질의가 이 인덱스를 쓴다. 안 붙이면
    # 기본값 euclidean으로 만들어지고 옵티마이저가 전량 스캔으로 되돌아간다.
    op.execute(
        f"ALTER TABLE {TABLE} ADD VECTOR INDEX ix_{TABLE}_embedding (embedding) DISTANCE=cosine"
    )


def downgrade() -> None:
    op.drop_index(f"ix_{TABLE}_embedding", table_name=TABLE)
    op.drop_index(f"ix_{TABLE}_source_updated", table_name=TABLE)
    op.drop_table(TABLE)
