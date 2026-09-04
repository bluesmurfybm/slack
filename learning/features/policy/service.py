import time
from datetime import date, timedelta

from fastapi import HTTPException
from sqlmodel import Session, select

from core.config import ACCOUNT_COMPANY
from core.db import POLICY_ID, LearningRequest, RefundPolicy


def current(session: Session) -> RefundPolicy:
    policy = session.get(RefundPolicy, POLICY_ID)
    if not policy:
        # init_db 가 만들어 두지만, 시드 이전에 열린 세션에서도 판정은 돌아야 한다
        policy = RefundPolicy(id=POLICY_ID)
        session.add(policy)
        session.commit()
        session.refresh(policy)
    return policy


def to_dict(policy: RefundPolicy) -> dict:
    return policy.model_dump()


def cap_for_new_request(policy: RefundPolicy) -> int:
    """신청 건에 박아 둘 건당 상한. 부분환급이 꺼져 있으면 0(상한 없음)."""
    return policy.partial_cap if policy.partial_enabled else 0


def refundable(req: LearningRequest) -> bool:
    return not req.is_free and req.account_type != ACCOUNT_COMPANY


def compute_refund(req: LearningRequest) -> int:
    # 상한은 신청 시점 스냅샷을 쓴다 — 관리자가 나중에 상한을 바꿔도 이미 신청한 건이
    # 뒤에서 움직이면 안 된다.
    if not refundable(req):
        return 0
    cap = req.refund_cap_at_request or 0
    return min(req.price, cap) if cap else req.price


def expected_refund(req: LearningRequest) -> int:
    return req.refund_amount or compute_refund(req)


def _this_year_of(email: str, session: Session, year: str) -> list[LearningRequest]:
    # 반려된 건과 무료 건은 세지 않는다. 회사계정은 환급 자체가 없어 한도와 무관하다.
    rows = session.exec(select(LearningRequest).where(
        LearningRequest.applicant_email == email,
        LearningRequest.is_free == 0,
        LearningRequest.account_type != ACCOUNT_COMPANY,
        LearningRequest.rejected_at == "",
        LearningRequest.created_at.startswith(year))).all()
    return list(rows)


def ensure_within_limits(session: Session, policy: RefundPolicy, email: str,
                         incoming: LearningRequest,
                         *, exclude_id: int | None = None) -> None:
    if not refundable(incoming):
        return
    if not (policy.annual_count_enabled or policy.annual_amount_enabled):
        return

    year = time.strftime("%Y")
    mine = [r for r in _this_year_of(email, session, year) if r.id != exclude_id]

    if (policy.annual_count_enabled and policy.annual_count_limit
            and len(mine) + 1 > policy.annual_count_limit):
        raise HTTPException(
            status_code=409,
            detail=f"연간 신청 건수 한도({policy.annual_count_limit}건)를 넘습니다. "
                   f"올해 {len(mine)}건을 신청했습니다")

    if policy.annual_amount_enabled and policy.annual_amount_limit:
        spent = sum(expected_refund(r) for r in mine)
        want = expected_refund(incoming)
        if spent + want > policy.annual_amount_limit:
            left = max(0, policy.annual_amount_limit - spent)
            raise HTTPException(
                status_code=409,
                detail=f"연간 환급 한도({policy.annual_amount_limit:,}원)를 넘습니다. "
                       f"남은 한도는 {left:,}원입니다")


def ensure_claim_deadline(policy: RefundPolicy, req: LearningRequest) -> None:
    if not policy.claim_deadline_enabled or not policy.claim_deadline_days:
        return
    if not req.end_date:
        return
    deadline = date.fromisoformat(req.end_date) + timedelta(days=policy.claim_deadline_days)
    if time.strftime("%Y-%m-%d") > deadline.isoformat():
        raise HTTPException(
            status_code=409,
            detail=f"청구 기한이 지났습니다. 강의 종료일로부터 "
                   f"{policy.claim_deadline_days}일({deadline.isoformat()})까지 청구할 수 있습니다")
