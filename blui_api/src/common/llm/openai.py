"""OpenAI 호환 API의 LangChain 채팅 모델을 생성합니다."""

from langchain_openai import ChatOpenAI

from common.configs import CommonConfig, Effort


def build_from_config(config: CommonConfig) -> ChatOpenAI:
    """설정의 기본 모델로 채팅 모델을 생성합니다."""
    return build(
        config, model=config.openai_model, max_tokens=config.max_tokens, effort=config.effort
    )


def build(
    config: CommonConfig,
    *,
    model: str,
    max_tokens: int,
    effort: Effort | None,
    temperature: float | None = None,
) -> ChatOpenAI:
    """지정한 모델과 생성 조건으로 Responses API를 쓰는 채팅 모델을 생성합니다."""
    if config.openai_api_key is None:
        raise ValueError("OPENAI_API_KEY가 없습니다.")

    return ChatOpenAI(
        model=model,
        api_key=config.openai_api_key,
        base_url=config.openai_base_url,
        max_tokens=max_tokens,
        reasoning={"effort": effort} if effort else None,
        temperature=temperature,
        use_responses_api=True,
    )
