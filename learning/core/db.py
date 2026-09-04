import logging
import time
from collections.abc import Iterator
from pathlib import Path

from fastapi import Request
from sqlalchemy import Engine, inspect
from sqlmodel import Field, Session, SQLModel, create_engine, func, select

from core.config import OWNERS, Settings

logger = logging.getLogger(__name__)


class LearningRequest(SQLModel, table=True):
    __tablename__ = "learning_requests"

    id: int | None = Field(default=None, primary_key=True)

    site: str = ""
    category_large: str = ""
    category_medium: str = ""
    level: str = ""
    title: str
    url: str = ""
    account_type: str = "개인계정"

    applicant: str = ""
    applicant_email: str = ""

    duration_min: int = 0

    # price == 0 은 "유료인데 금액 미입력"일 수 있다. 요청상태를 통째로 건너뛸지는
    # is_free 하나로만 판단한다.
    is_free: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    price: int = 0

    start_date: str = ""
    end_date: str = ""

    # server_default 는 raw SQL 이다 — 문자열 기본값은 따옴표까지 넣어야 ALTER 가 깨지지 않는다
    progress: str = Field(default="시작전", sa_column_kwargs={"server_default": "'시작전'"})
    progress_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})

    # 이 타임스탬프들은 derive_status 가 서로 문자열 비교를 한다 — ALTER 로 붙어 NULL 이
    # 남으면 비교에서 터진다. 기존 행까지 빈 문자열로 채워져야 한다.
    approved_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})
    rejected_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})
    reject_reason: str = ""
    claimed_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})
    claim_approved_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})
    claim_rejected_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})
    claim_reject_reason: str = ""
    refunded_at: str = Field(default="", sa_column_kwargs={"server_default": "''"})

    # 신청 시점의 건당 상한 스냅샷. 관리자가 나중에 상한을 바꿔도 이미 신청한 건의
    # 환급액이 뒤에서 움직이면 안 된다. 0 이면 상한 없음.
    refund_cap_at_request: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    refund_amount: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    rating: float | None = None
    recommend: float | None = None
    review_note: str = ""

    active: int = Field(default=1, sa_column_kwargs={"server_default": "1"})
    archived: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    created_by: str = ""
    created_at: str = ""


class LearningSite(SQLModel, table=True):
    __tablename__ = "learning_sites"

    id: int | None = Field(default=None, primary_key=True)
    name: str = Field(unique=True)
    url: str = ""
    sort_order: int = 0
    active: int = Field(default=1, sa_column_kwargs={"server_default": "1"})


class LearningCert(SQLModel, table=True):
    __tablename__ = "learning_certs"

    id: int | None = Field(default=None, primary_key=True)
    request_id: int = Field(foreign_key="learning_requests.id", index=True)
    name: str = ""
    path: str = ""
    uploaded_by: str = ""
    created_at: str = ""


class LearningHistory(SQLModel, table=True):
    __tablename__ = "learning_histories"

    id: int | None = Field(default=None, primary_key=True)
    request_id: int = Field(foreign_key="learning_requests.id", index=True)
    status: str = ""
    memo: str = ""
    actor: str = ""
    actor_email: str = ""
    created_at: str = ""


class CategoryOption(SQLModel, table=True):
    __tablename__ = "learning_categories"

    id: int | None = Field(default=None, primary_key=True)
    site: str = ""
    large: str = ""
    medium: str = ""
    sort_order: int = 0
    recommended: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    active: int = Field(default=1, sa_column_kwargs={"server_default": "1"})


class AdminUser(SQLModel, table=True):
    """관리자 명단. 화면에서 바꾸므로 코드가 아니라 DB 가 원본이다."""
    __tablename__ = "learning_admins"

    email: str = Field(primary_key=True)
    added_by: str = ""
    created_at: str = ""


class RefundPolicy(SQLModel, table=True):
    __tablename__ = "learning_policy"

    id: int | None = Field(default=1, primary_key=True)

    partial_enabled: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    partial_cap: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    annual_amount_enabled: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    annual_amount_limit: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    annual_count_enabled: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    annual_count_limit: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    claim_deadline_enabled: int = Field(default=0, sa_column_kwargs={"server_default": "0"})
    claim_deadline_days: int = Field(default=0, sa_column_kwargs={"server_default": "0"})

    updated_by: str = ""
    updated_at: str = ""


POLICY_ID = 1

MIGRATED = (LearningRequest, LearningSite, LearningCert, LearningHistory,
            CategoryOption, RefundPolicy, AdminUser)


DEFAULT_SITES = [
    {"name": "인프런", "url": "https://www.inflearn.com"},
    {"name": "패스트캠퍼스", "url": "https://fastcampus.co.kr"},
]

# 두 사이트의 카테고리 페이지에서 그대로 옮긴 값이다. 구분 기호는 일반 가운뎃점(U+00B7)에
# 양쪽 공백을 쓴다 - 다른 문자로 바꾸면 시드 값과 화면에서 고른 값이 서로 다른 문자열이 된다.
DEFAULT_CATEGORIES = {
    "인프런": {
        "AI 기술": [
            "AI에이전트 개발", "딥러닝 · 머신러닝", "컴퓨터 비전", "자연어 처리", "인공지능 기타",
        ],
        "AI 활용(AX)": ["AI 시작하기", "AI 개발 활용", "AI 실무 활용", "AI 크리에이티브"],
        "개발 · 프로그래밍": [
            "웹 개발", "AI 코딩", "프론트엔드", "백엔드", "풀스택", "모바일 앱 개발",
            "프로그래밍 언어", "알고리즘 · 자료구조", "데이터베이스", "데브옵스 · 인프라",
            "소프트웨어 테스트", "개발 도구", "웹 퍼블리싱", "데스크톱 앱 개발", "VR/AR",
            "개발 · 프로그래밍 자격증", "개발 · 프로그래밍 기타",
        ],
        "게임 개발": ["게임 프로그래밍", "게임 기획", "게임 아트 · 그래픽", "게임 개발 기타"],
        "데이터 사이언스": [
            "데이터 분석", "데이터 엔지니어링", "데이터 사이언스 자격증", "데이터 사이언스 기타",
        ],
        "보안 · 네트워크": [
            "보안", "네트워크", "시스템 · 운영체제", "클라우드", "블록체인",
            "보안 · 네트워크 자격증", "보안 · 네트워크 기타",
        ],
        "하드웨어": [
            "컴퓨터 구조", "임베디드 · IoT", "반도체", "로봇공학", "모빌리티", "하드웨어 자격증",
            "하드웨어 기타",
        ],
        "디자인 · 아트": [
            "CAD · 3D 모델링", "UX/UI", "그래픽 디자인", "웹툰 · 이모티콘", "사진 · 영상", "사운드",
            "디자인 자격증", "디자인 기타",
        ],
        "기획 · 경영 · 마케팅": [
            "기획 · PM · PO", "마케팅", "경영 · 전략", "기획 · 경영 · 마케팅 자격증",
            "기획 · 경영 · 마케팅 기타",
        ],
        "외국어": ["영어", "일본어", "중국어", "스페인어", "독일어"],
        "업무 생산성": ["업무 자동화", "오피스", "생산성 도구", "업무 생산성 기타"],
        "커리어 · 자기계발": [
            "취업 · 이직", "창업 · 부업", "개인 브랜딩", "취미", "금융 · 재테크", "교양",
            "커리어 · 자기계발 기타",
        ],
        "대학 교육": ["수학", "공학", "상경", "자연과학", "교육학", "대학 교육 기타"],
    },
    "패스트캠퍼스": {
        "AI TECH": ["LLM", "RAG & AI Agent", "딥러닝/머신러닝", "컴퓨터 비전", "자율주행/로봇"],
        "AI CREATIVE": ["2D/3D 이미지 생성", "영상 생성"],
        "AI/업무생산성": ["AI 생산성", "마케팅", "데이터분석"],
        "개발/데이터": [
            "프론트엔드 개발", "백엔드 개발", "모바일 앱 개발", "게임 개발", "데이터 엔지니어링",
            "DevOps/Infra", "컴퓨터 공학/SW 엔지니어링", "반도체",
        ],
        "디자인": ["UX/UI/BX", "그래픽/타이포/브랜딩"],
        "영상/3D": ["영상/사진", "모션그래픽", "3D", "블렌더", "버튜버"],
        "금융/투자": ["재무/회계/세무", "재테크/주식", "금융 투자 실무", "부동산"],
        "드로잉/일러스트": ["드로잉/이모티콘", "캐릭터일러스트", "웹툰/웹소설", "원화/컨셉아트"],
        "비즈니스/기획": ["PM/PO", "기획/경영/리더십", "부업/창업", "글쓰기"],
    },
}


def init_db(settings: Settings) -> Engine:
    Path(settings.upload_dir).mkdir(parents=True, exist_ok=True)
    Path(settings.db_path).resolve().parent.mkdir(parents=True, exist_ok=True)
    engine = create_engine(f"sqlite:///{settings.db_path}",
                           connect_args={"check_same_thread": False})
    SQLModel.metadata.create_all(engine)
    _add_missing_columns(engine)
    _seed_sites(engine)
    _seed_categories(engine)
    _seed_policy(engine)
    _seed_admins(engine, settings)
    return engine


def get_session(request: Request) -> Iterator[Session]:
    with Session(request.app.state.engine) as session:
        yield session


def _add_missing_columns(engine: Engine) -> None:
    # create_all 은 이미 있는 테이블에 컬럼을 붙이지 않는다. 먼저 만들어진 DB 는 이 경로로 온다.
    inspector = inspect(engine)
    for model in MIGRATED:
        table = model.__tablename__
        if not inspector.has_table(table):
            continue
        have = {c["name"] for c in inspector.get_columns(table)}
        missing = [c for c in model.__table__.columns if c.name not in have]
        if not missing:
            continue
        with engine.begin() as conn:
            for col in missing:
                decl = col.type.compile(engine.dialect)
                if col.server_default is not None:
                    decl += f" DEFAULT {col.server_default.arg}"
                conn.exec_driver_sql(
                    f"ALTER TABLE {table} ADD COLUMN {col.name} {decl}")


def _seed_sites(engine: Engine) -> None:
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(LearningSite)).one():
            return
        session.add_all([LearningSite(name=s["name"], url=s["url"], sort_order=i)
                         for i, s in enumerate(DEFAULT_SITES)])
        session.commit()


def _seed_categories(engine: Engine) -> None:
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(CategoryOption)).one():
            return
        order = 0
        rows = []
        for site, larges in DEFAULT_CATEGORIES.items():
            for large, mediums in larges.items():
                rows.append(CategoryOption(site=site, large=large, medium="",
                                           sort_order=order))
                order += 1
                for medium in mediums:
                    rows.append(CategoryOption(site=site, large=large, medium=medium,
                                               sort_order=order))
                    order += 1
        session.add_all(rows)
        session.commit()
        logger.info("[seed] 분류 %d건을 적재했습니다.", len(rows))


def _seed_admins(engine: Engine, settings: Settings) -> None:
    with Session(engine) as session:
        if session.exec(select(func.count()).select_from(AdminUser)).one():
            return
        now = time.strftime("%Y-%m-%d %H:%M:%S")
        session.add_all([AdminUser(email=e, added_by="seed", created_at=now)
                         for e in sorted(settings.admin_emails)])
        session.commit()


def admin_emails(engine: Engine) -> frozenset[str]:
    """DB 명단 + 고정 관리자. 고정 관리자는 DB 에 없어도 항상 포함된다."""
    with Session(engine) as session:
        rows = session.exec(select(AdminUser.email)).all()
    return frozenset({e.lower() for e in rows} | OWNERS)


def _seed_policy(engine: Engine) -> None:
    with Session(engine) as session:
        if session.get(RefundPolicy, POLICY_ID):
            return
        session.add(RefundPolicy(id=POLICY_ID,
                                 updated_at=time.strftime("%Y-%m-%d %H:%M:%S")))
        session.commit()
