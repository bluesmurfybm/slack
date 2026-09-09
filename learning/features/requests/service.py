import time

from fastapi import HTTPException
from sqlmodel import Session, func, select

from core.config import ACCOUNT_COMPANY
from core.db import LearningCert, LearningHistory, LearningRequest
from features.identity.auth import is_admin
from features.policy import service as policy_service

STATUS_REQUESTED = "수강승인요청"
STATUS_APPROVED = "수강승인"
STATUS_REJECTED = "수강반려"
STATUS_CLAIMED = "수강료청구"
STATUS_CLAIM_APPROVED = "청구승인"
STATUS_CLAIM_REJECTED = "청구반려"
STATUS_REFUNDED = "환급완료"
STATUS_NO_REFUND = "환급불필요"
STATUS_NONE = ""

# 화면 진행 레일. 반려는 레일을 멈추고 배지로만 보여준다.
RAIL = [STATUS_REQUESTED, STATUS_APPROVED, STATUS_CLAIMED,
        STATUS_CLAIM_APPROVED, STATUS_REFUNDED]

APPROVE = "approve"
REJECT = "reject"
CLAIM = "claim"
CLAIM_APPROVE = "claim_approve"
CLAIM_REJECT = "claim_reject"
REFUND = "refund"
EDIT = "edit"
ATTACH = "attach"
REVIEW = "review"

# 이수 이후 언제든 손댈 수 있는 상태들 — 이수증과 강의평가가 여기에 걸린다
_SETTLED = frozenset({STATUS_NONE, STATUS_APPROVED, STATUS_CLAIMED, STATUS_CLAIM_APPROVED,
                      STATUS_CLAIM_REJECTED, STATUS_REFUNDED, STATUS_NO_REFUND})

ALLOWED = {
    EDIT: frozenset({STATUS_REQUESTED, STATUS_NONE}),
    APPROVE: frozenset({STATUS_REQUESTED}),
    REJECT: frozenset({STATUS_REQUESTED}),
    CLAIM: frozenset({STATUS_APPROVED, STATUS_CLAIM_REJECTED}),
    CLAIM_APPROVE: frozenset({STATUS_CLAIMED}),
    CLAIM_REJECT: frozenset({STATUS_CLAIMED}),
    REFUND: frozenset({STATUS_CLAIM_APPROVED}),
    ATTACH: _SETTLED,
    REVIEW: _SETTLED,
}

# 무료 강의는 승인·청구 절차 자체가 없다
_FLOW_ONLY = frozenset({APPROVE, REJECT, CLAIM, CLAIM_APPROVE, CLAIM_REJECT, REFUND})
# 회사계정은 승인까지만 — 환급 절차가 없다
_REFUND_ONLY = frozenset({CLAIM, CLAIM_APPROVE, CLAIM_REJECT, REFUND})


def now() -> str:
    return time.strftime("%Y-%m-%d %H:%M:%S")


def derive_status(req: LearningRequest) -> str: # noqa: PLR0911 상태 수만큼 갈래가 나온다
    # 컬럼으로 저장하지 않는다 — 승인·청구·환급이 각각 별도 시점이라 한 컬럼으로는 계속 어긋난다.
    if req.is_free:
        return STATUS_NONE
    if req.rejected_at:
        return STATUS_REJECTED
    if req.account_type == ACCOUNT_COMPANY:
        return STATUS_NO_REFUND if req.approved_at else STATUS_REQUESTED
    if req.refunded_at:
        return STATUS_REFUNDED
    # 재청구가 반려 기록을 지우므로 두 시각을 비교하지 않는다 — 초 단위 저장이라
    # 같은 초에 청구와 반려가 겹치면 비교가 뒤집힌다. 반려 사유는 이력에 남는다.
    if req.claim_rejected_at:
        return STATUS_CLAIM_REJECTED
    if req.claim_approved_at:
        return STATUS_CLAIM_APPROVED
    if req.claimed_at:
        return STATUS_CLAIMED
    if req.approved_at:
        return STATUS_APPROVED
    return STATUS_REQUESTED


def ensure_transition(req: LearningRequest, action: str) -> None:
    if req.is_free and action in _FLOW_ONLY:
        raise HTTPException(status_code=409,
                            detail="무료 강의는 승인·청구 절차가 없습니다")
    if req.account_type == ACCOUNT_COMPANY and action in _REFUND_ONLY:
        raise HTTPException(status_code=409,
                            detail="회사계정 결제 건은 환급 절차가 없습니다")
    status = derive_status(req)
    if status not in ALLOWED[action]:
        raise HTTPException(status_code=409,
                            detail=f"'{status or '무료'}' 상태에서는 할 수 없는 작업입니다")


def fetch(session: Session, rid: int) -> LearningRequest:
    req = session.get(LearningRequest, rid)
    if not req:
        raise HTTPException(status_code=404, detail="없는 신청입니다")
    return req


def is_owner(req: LearningRequest, identity: dict) -> bool:
    return bool(req.applicant_email) and req.applicant_email == identity["email"]


def require_owner(req: LearningRequest, identity: dict, what: str) -> None:
    if not is_owner(req, identity):
        raise HTTPException(status_code=403, detail=f"본인만 {what} 수 있습니다")


def require_owner_or_admin(request, req: LearningRequest,
                           identity: dict, what: str) -> None:
    if not is_owner(req, identity) and not is_admin(request, identity["email"]):
        raise HTTPException(status_code=403, detail=f"본인이나 관리자만 {what} 수 있습니다")


def cert_count(session: Session, rid: int) -> int:
    return session.exec(select(func.count()).select_from(LearningCert)
                        .where(LearningCert.request_id == rid)).one()


def cert_names(session: Session, rid: int) -> list[str]:
    return list(session.exec(select(LearningCert.name)
                             .where(LearningCert.request_id == rid)
                             .order_by(LearningCert.id)).all())


def record(session: Session, req: LearningRequest, status: str,
           identity: dict, memo: str = "") -> None:
    session.add(LearningHistory(request_id=req.id, status=status, memo=memo,
                                actor=identity.get("name") or "",
                                actor_email=identity.get("email") or "",
                                created_at=now()))


def history(session: Session, rid: int) -> list[dict]:
    rows = session.exec(select(LearningHistory)
                        .where(LearningHistory.request_id == rid)
                        .order_by(LearningHistory.id)).all()
    return [h.model_dump() for h in rows]


# 목록에서 파일명을 팝오버로 띄우므로 개수만이 아니라 이름도 함께 내려준다
def to_dict(req: LearningRequest, certs=()) -> dict:
    names = list(certs)
    # 환급 예정액은 화면 여러 곳에서 쓰는데, 규칙(신청 시점 상한 스냅샷)을 클라이언트에
    # 베끼면 정책이 바뀔 때 두 곳이 갈라진다 — 서버가 계산해 실어 보낸다.
    return {**req.model_dump(), "status": derive_status(req),
            "expected_refund": policy_service.expected_refund(req),
            "cert_count": len(names), "cert_names": names}
