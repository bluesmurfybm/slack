from typing import Literal

from pydantic import BaseModel

Role = Literal["user", "assistant"]


class Message(BaseModel):
    """채팅 메시지입니다."""

    role: Role
    content: str
