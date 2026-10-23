"""Claude API의 LangChain 채팅 모델을 생성합니다."""

from langchain_anthropic import ChatAnthropic

from common.configs import CommonConfig, Effort


def build_from_config(config: CommonConfig) -> ChatAnthropic:
    """설정의 기본 모델로 채팅 모델을 생성합니다."""
    return build(
        config, model=config.claude_model, max_tokens=config.max_tokens, effort=config.effort
    )


def build(
    config: CommonConfig, *, model: str, max_tokens: int, effort: Effort | None
) -> ChatAnthropic:
    """지정한 모델과 생성 조건으로 채팅 모델을 생성하며, effort가 없으면 thinking을 끕니다."""
    if config.anthropic_api_key is None:
        raise ValueError("ANTHROPIC_API_KEY가 없습니다.")

    if effort is None:
        return ChatAnthropic(
            model=model,
            api_key=config.anthropic_api_key,
            max_tokens=max_tokens,
            thinking={"type": "disabled"},
        )
    return ChatAnthropic(
        model=model,
        api_key=config.anthropic_api_key,
        max_tokens=max_tokens,
        effort=effort,
        thinking={"type": "adaptive", "display": "summarized"},
    )
