from sqlalchemy import Text
from sqlmodel import Field, Session, select

from blue_chatbot.repositories.base import BaseRepository, BaseSQLModel


class Conversation(BaseSQLModel, table=True):
    __tablename__ = "conversations"

    key: str = Field(
        max_length=36,
        unique=True,
        sa_column_kwargs={
            "comment": "conversation 조회용 유니크 값, id 추론을 방지하기 위해 식별자로 사용"
        },
    )


class ConversationMessage(BaseSQLModel, table=True):
    __tablename__ = "messages"

    conversation_id: int = Field(index=True)
    role: str = Field(max_length=16)
    content: str = Field(sa_type=Text)


class ConversationRepository(BaseRepository[Conversation]):
    def find_by_key(self, key: str) -> Conversation | None:
        with Session(self._engine) as session:
            return session.exec(select(Conversation).where(Conversation.key == key)).first()


class ConversationMessageRepository(BaseRepository[ConversationMessage]):
    def find_messages(self, conversation_id: int) -> list[ConversationMessage]:
        """대화의 메시지를 순서대로 반환한다."""
        query = (
            select(ConversationMessage)
            .where(ConversationMessage.conversation_id == conversation_id)
            .order_by(ConversationMessage.id)  # type: ignore[arg-type]
        )
        with Session(self._engine) as session:
            return list(session.exec(query).all())
