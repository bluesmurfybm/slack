import time

from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field, model_validator
from sqlmodel import Session

from core.db import get_session
from features.identity.auth import require_admin, require_identity
from features.policy import service

router = APIRouter(prefix="/learningapi/policy", tags=["policy"])

_PAIRS = [
    ("partial_enabled", "partial_cap", "환급 기준 금액"),
    ("annual_amount_enabled", "annual_amount_limit", "연간 환급 한도"),
    ("annual_count_enabled", "annual_count_limit", "연간 신청 건수"),
    ("claim_deadline_enabled", "claim_deadline_days", "청구 기한"),
]


class PolicyIn(BaseModel):
    partial_enabled: bool = False
    partial_cap: int = Field(default=0, ge=0)

    annual_amount_enabled: bool = False
    annual_amount_limit: int = Field(default=0, ge=0)

    annual_count_enabled: bool = False
    annual_count_limit: int = Field(default=0, ge=0)

    claim_deadline_enabled: bool = False
    claim_deadline_days: int = Field(default=0, ge=0)

    @model_validator(mode="after")
    def _value_needed_when_enabled(self):
        for flag, value, label in _PAIRS:
            if getattr(self, flag) and not getattr(self, value):
                raise ValueError(f"{label}을(를) 입력해 주세요")
        return self


@router.get("", dependencies=[Depends(require_identity)])
def get_policy(session: Session = Depends(get_session)):
    return service.to_dict(service.current(session))


@router.put("")
def update_policy(body: PolicyIn, session: Session = Depends(get_session),
                  identity: dict = Depends(require_admin)):
    policy = service.current(session)
    for name, value in body.model_dump().items():
        setattr(policy, name, int(value) if isinstance(value, bool) else value)
    policy.updated_by = identity["email"]
    policy.updated_at = time.strftime("%Y-%m-%d %H:%M:%S")
    session.add(policy)
    session.commit()
    session.refresh(policy)
    return service.to_dict(policy)
