"""임베딩 이름으로 구현을 고른다. 모델을 추가하면 EMBEDDING_MODELS에 한 줄 더한다."""

import openai

from blue_chatbot.configs.core import config
from blue_chatbot.embedders.openai_embedder import OpenAIEmbedderClient
from blue_chatbot.services.embedder import EmbedderClient, EmbedderClientError

EMBEDDING_MODELS: dict[str, tuple[str, int]] = {
    "openai-3-small": ("text-embedding-3-small", 1536),
    "openai-3-large": ("text-embedding-3-large", 3072),
}


def build_embedder(name: str) -> EmbedderClient:
    if name not in EMBEDDING_MODELS:
        raise EmbedderClientError(f"알 수 없는 임베딩 모델: {name}")
    if config.openai_api_key is None:
        raise EmbedderClientError("OPENAI_API_KEY가 없습니다.")
    model, dimension = EMBEDDING_MODELS[name]
    return OpenAIEmbedderClient(
        openai.OpenAI(api_key=config.openai_api_key.get_secret_value()),
        name=name,
        model=model,
        dimension=dimension,
    )
