"""EmbedderClient의 OpenAI 구현"""

import openai

from blue_chatbot.services.embedder import EmbedderClientError


class OpenAIEmbedderClient:
    def __init__(self, client: openai.OpenAI, *, name: str, model: str, dimension: int) -> None:
        self._client = client
        self._model = model
        self.name = name
        self.dimension = dimension

    def embed(self, texts: list[str]) -> list[list[float]]:
        try:
            response = self._client.embeddings.create(
                model=self._model, input=texts, dimensions=self.dimension
            )
        except openai.OpenAIError as exc:
            raise EmbedderClientError(str(exc)) from exc
        return [item.embedding for item in sorted(response.data, key=lambda item: item.index)]
