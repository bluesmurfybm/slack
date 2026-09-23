"""요청 벡터 인덱스의 M을 16으로 올린다

Revision ID: e5f1a2c9d7b3
Revises: b7c2e93f4a10
Create Date: 2026-09-22

"""

from collections.abc import Sequence

from alembic import op

revision: str = "e5f1a2c9d7b3"
down_revision: str | Sequence[str] | None = "b7c2e93f4a10"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

TABLE = "request_vectors_openai_3_small"
INDEX = f"ix_{TABLE}_embedding"


def upgrade() -> None:
    op.execute(f"ALTER TABLE {TABLE} DROP INDEX {INDEX}")
    op.execute(f"ALTER TABLE {TABLE} ADD VECTOR INDEX {INDEX} (embedding) DISTANCE=cosine M=16")


def downgrade() -> None:
    op.execute(f"ALTER TABLE {TABLE} DROP INDEX {INDEX}")
    op.execute(f"ALTER TABLE {TABLE} ADD VECTOR INDEX {INDEX} (embedding) DISTANCE=cosine")
