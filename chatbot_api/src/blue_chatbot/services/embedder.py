"""임베딩 관련 예외 및 클라이언트 API 인터페이스"""

from typing import Protocol


class EmbedderClientError(Exception): ...


class EmbedderClient(Protocol):
    """임베딩 API 클라이언트"""

    name: str
    dimension: int

    def embed(self, texts: list[str]) -> list[list[float]]:
        """입력 순서와 같은 순서로 벡터를 돌려준다."""
        ...
