"""블리가 쓰는 본 모델과 경량 모델을 생성합니다."""

from langchain_core.language_models import BaseChatModel

from blui import guard
from blui.configs import BluiConfig
from common.llm import claude, openai


def build_model(config: BluiConfig) -> BaseChatModel:
    """답변 생성에 쓰는 모델을 생성하며, OpenAI 키가 있으면 OpenAI 호환 모델을, 없으면 Claude를 씁니다."""
    model = model_names(config)["model"]
    if config.openai_api_key is not None:
        return openai.build(config, model=model, max_tokens=config.max_tokens, effort=config.effort)
    return claude.build(config, model=model, max_tokens=config.max_tokens, effort=config.effort)


def build_light_model(config: BluiConfig) -> BaseChatModel:
    """금지 판정과 분야 판정에 쓰는 모델을 생성하며, OpenAI 키가 있으면 OpenAI 호환 모델을, 없으면 Claude를 씁니다."""
    model = model_names(config)["light_model"]
    if config.openai_api_key is not None:
        return openai.build(
            config, model=model, max_tokens=guard.MAX_TOKENS, effort=None, temperature=0
        )
    return claude.build(config, model=model, max_tokens=guard.MAX_TOKENS, effort=None)


def model_names(config: BluiConfig) -> dict[str, str]:
    """본 모델과 경량 모델의 이름을 반환합니다."""
    if config.openai_api_key is not None:
        return {"model": config.openai_model, "light_model": config.openai_light_model}
    return {"model": config.claude_model, "light_model": config.claude_light_model}
