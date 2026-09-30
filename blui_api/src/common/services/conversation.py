from collections.abc import Callable
from datetime import datetime
from typing import Protocol
from uuid import uuid4

from common.messages import Role
from common.repositories.conversation import (
    Conversation,
    ConversationMessage,
    ConversationMessageRepository,
    ConversationRepository,
)
from common.support import utc_now


class Answered(Protocol):
    """답변 함수가 반환하는 값으로, 대화에 저장할 본문만 요구합니다."""

    @property
    def content(self) -> str: ...


class ConversationService[A: Answered]:
    def __init__(
        self,
        conversation_repository: ConversationRepository,
        message_repository: ConversationMessageRepository,
        answer: Callable[[list[ConversationMessage]], A],
        max_user_messages: int,
        now: Callable[[], datetime] = utc_now,
    ) -> None:
        self._conversations = conversation_repository
        self._messages = message_repository
        self._answer = answer
        self._max_user_messages = max_user_messages
        self._now = now

    def start_conversation(self) -> Conversation:
        """새 대화를 만들어 저장합니다."""
        conversation = Conversation(key=str(uuid4()), created_at=self._now())
        self._conversations.save(conversation)
        return conversation

    def get_conversation(self, key: str | None) -> tuple[Conversation, list[ConversationMessage]]:
        """대화와 그 메시지를 반환하고, 없으면 새로 만듭니다."""
        loaded = self._load(key)
        if loaded is None:
            return self.start_conversation(), []
        return loaded

    def send_message(self, key: str, content: str) -> A:
        """질문과 답변을 대화에 저장하고 답변 함수의 결과를 반환합니다."""
        loaded = self._load(key)
        if loaded is None:
            raise ConversationNotFoundError(key)
        conversation, stored = loaded
        if sum(message.role == "user" for message in stored) >= self._max_user_messages:
            raise ConversationLimitError(key)

        question = self._message(conversation, "user", content)
        self._messages.save(question)
        answer = self._answer([*stored, question])
        self._messages.save(self._message(conversation, "assistant", answer.content))
        return answer

    def _message(self, conversation: Conversation, role: Role, content: str) -> ConversationMessage:
        return ConversationMessage(
            conversation_id=conversation.id,
            role=role,
            content=content,
            created_at=self._now(),
        )

    def _load(self, key: str | None) -> tuple[Conversation, list[ConversationMessage]] | None:
        if key is None:
            return None
        conversation = self._conversations.find_by_key(key)
        if conversation is None:
            return None
        return conversation, self._messages.find_messages(conversation.persisted_id())


class ConversationNotFoundError(Exception):
    """대화가 없을 때 발생합니다."""


class ConversationLimitError(Exception):
    """대화당 사용자 메시지 상한에 도달했을 때 발생합니다."""
