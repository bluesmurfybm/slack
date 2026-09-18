from collections.abc import Callable
from datetime import datetime, timedelta
from uuid import uuid4

from blue_chatbot.messages import Role
from blue_chatbot.repositories.conversation import (
    Conversation,
    ConversationMessage,
    ConversationMessageRepository,
    ConversationRepository,
)
from blue_chatbot.services import ask
from blue_chatbot.services.faq import FaqEntry
from blue_chatbot.services.llm import LLMClient
from blue_chatbot.support import utc_now


class ConversationNotFoundError(Exception):
    """대화가 없음"""


class ConversationExpiredError(Exception):
    """대화가 만료됨"""


class ConversationService:
    def __init__(
        self,
        conversation_repository: ConversationRepository,
        message_repository: ConversationMessageRepository,
        llm_client: LLMClient,
        faqs: list[FaqEntry],
        expires_after: timedelta,
        now: Callable[[], datetime] = utc_now,
    ) -> None:
        self._conversations = conversation_repository
        self._messages = message_repository
        self._llm_client = llm_client
        self._faqs = faqs
        self._expires_after = expires_after
        self._now = now

    def get_conversation(self, key: str | None) -> tuple[Conversation, list[ConversationMessage]]:
        """유효한 대화와 그 메시지. 없거나 만료된 대화면 새로 만든다."""
        loaded = self._load(key)
        if loaded is None or self._is_expired(*loaded):
            conversation = Conversation(key=str(uuid4()), created_at=self._now())
            self._conversations.save(conversation)
            return conversation, []
        return loaded

    def send_message(self, key: str, content: str) -> ask.LLMAnswer:
        """메시지를 대화에 추가하고 FAQ를 근거로 답한다. 질문과 답을 모두 저장한다."""
        loaded = self._load(key)
        if loaded is None:
            raise ConversationNotFoundError(key)
        conversation, stored = loaded
        if self._is_expired(conversation, stored):
            raise ConversationExpiredError(key)

        question = self._message(conversation, "user", content)
        self._messages.save(question)
        answer = ask.answer(self._llm_client, self._faqs, [*stored, question])
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

    def _is_expired(self, conversation: Conversation, stored: list[ConversationMessage]) -> bool:
        """대화가 만료되었는지 확인"""
        last_activity = stored[-1].created_at if stored else conversation.created_at
        return last_activity < self._now() - self._expires_after

