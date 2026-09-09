from fastapi import APIRouter, Depends, HTTPException, Request
from sqlalchemy import delete
from sqlmodel import Session, select

from core.config import EMAIL_TO_NAME
from core.db import LearningCert, LearningHistory, LearningRequest, LearningSite, get_session
from features.cert import service as cert_service
from features.cert import storage
from features.identity.auth import get_settings, is_admin, require_admin, require_identity
from features.policy import service as policy_service
from features.requests import service
from features.requests.models import ProgressIn, ReasonIn, RequestIn, RequestPatch

router = APIRouter(prefix="/learningapi/requests", tags=["requests"])


def _ensure_site(session: Session, name: str) -> None:
    site = session.exec(select(LearningSite).where(LearningSite.name == name)).first()
    if not site or not site.active:
        raise HTTPException(status_code=422, detail="선택할 수 없는 교육 플랫폼입니다")


def _out(session: Session, req: LearningRequest) -> dict:
    return service.to_dict(req, service.cert_names(session, req.id))


def _save(session: Session, req: LearningRequest) -> dict:
    session.add(req)
    session.commit()
    session.refresh(req)
    return _out(session, req)


@router.get("")
def list_requests(request: Request, session: Session = Depends(get_session),
                  identity: dict = Depends(require_identity)):
    stmt = select(LearningRequest).order_by(LearningRequest.id.desc())
    if not is_admin(request, identity["email"]):
        # 숨김·보관은 관리자 화면에만 있어야 한다. 목록에서 빼는 판정은 서버가 한다.
        stmt = stmt.where(LearningRequest.active == 1, LearningRequest.archived == 0)
    rows = session.exec(stmt).all()
    # 건마다 조회하면 N+1 이 된다 — 한 번에 읽어 request_id 로 묶는다
    names: dict[int, list[str]] = {}
    for rid, name in session.exec(
            select(LearningCert.request_id, LearningCert.name)
            .order_by(LearningCert.id)).all():
        names.setdefault(rid, []).append(name)
    return [service.to_dict(r, names.get(r.id, [])) for r in rows]


@router.get("/{rid}", dependencies=[Depends(require_identity)])
def get_request(rid: int, session: Session = Depends(get_session)):
    req = service.fetch(session, rid)
    return {**_out(session, req),
            "certs": cert_service.listing(session, rid),
            "history": service.history(session, rid)}


@router.post("", status_code=201)
def create_request(body: RequestIn, session: Session = Depends(get_session),
                   identity: dict = Depends(require_identity)):
    _ensure_site(session, body.site)
    policy = policy_service.current(session)

    values = body.model_dump()
    values["is_free"] = int(body.is_free)
    if body.is_free:
        values["price"] = 0

    email = identity["email"]
    stamp = service.now()
    req = LearningRequest(
        **values,
        # 신청자는 클라이언트가 보낸 값을 쓰지 않는다 — SSO 쿠키의 신원으로 강제한다
        applicant_email=email,
        applicant=EMAIL_TO_NAME.get(email) or identity.get("name") or "",
        refund_cap_at_request=policy_service.cap_for_new_request(policy),
        progress_at=stamp,
        created_by=email,
        created_at=stamp)
    policy_service.ensure_within_limits(session, policy, email, req)

    session.add(req)
    session.commit()
    session.refresh(req)
    service.record(session, req, service.derive_status(req), identity)
    session.commit()
    return _out(session, req)


@router.put("/{rid}")
def update_request(rid: int, body: RequestPatch, session: Session = Depends(get_session),
                   identity: dict = Depends(require_identity)):
    req = service.fetch(session, rid)
    service.require_owner(req, identity, "수정할")
    service.ensure_transition(req, service.EDIT)

    patch = body.model_dump(exclude_unset=True, exclude_none=True)
    if "site" in patch:
        _ensure_site(session, patch["site"])
    if "is_free" in patch:
        patch["is_free"] = int(patch["is_free"])
    for name, value in patch.items():
        setattr(req, name, value)
    if req.is_free:
        req.price = 0

    policy = policy_service.current(session)
    policy_service.ensure_within_limits(session, policy, identity["email"], req,
                                        exclude_id=req.id)
    return _save(session, req)


@router.delete("/{rid}")
def delete_request(rid: int, request: Request, session: Session = Depends(get_session),
                   identity: dict = Depends(require_identity)):
    req = service.fetch(session, rid)
    settings = get_settings(request)
    # 관리자는 상태를 가리지 않고 지운다 — 잘못 올라온 건과 이관 실패분을 치우려면 필요하다.
    # 본인은 아직 승인 전(또는 무료)일 때만.
    if not is_admin(request, identity["email"]):
        service.require_owner(req, identity, "삭제할")
        service.ensure_transition(req, service.EDIT)

    certs = session.exec(select(LearningCert)
                         .where(LearningCert.request_id == rid)).all()
    for cert in certs:
        storage.remove(settings, cert.path)
    session.execute(delete(LearningCert).where(LearningCert.request_id == rid))
    session.execute(delete(LearningHistory).where(LearningHistory.request_id == rid))
    session.delete(req)
    session.commit()
    return {"ok": True}


@router.post("/{rid}/progress")
def set_progress(rid: int, body: ProgressIn, session: Session = Depends(get_session),
                 identity: dict = Depends(require_identity)):
    req = service.fetch(session, rid)
    service.require_owner(req, identity, "진행상태를 바꿀")
    req.progress = body.progress
    req.progress_at = service.now()
    service.record(session, req, f"진행상태 {body.progress}", identity)
    return _save(session, req)


@router.post("/{rid}/approve")
def approve(rid: int, session: Session = Depends(get_session),
            identity: dict = Depends(require_admin)):
    req = service.fetch(session, rid)
    service.ensure_transition(req, service.APPROVE)
    req.approved_at = service.now()
    service.record(session, req, service.derive_status(req), identity)
    return _save(session, req)


@router.post("/{rid}/reject")
def reject(rid: int, body: ReasonIn, session: Session = Depends(get_session),
           identity: dict = Depends(require_admin)):
    req = service.fetch(session, rid)
    service.ensure_transition(req, service.REJECT)
    req.rejected_at = service.now()
    req.reject_reason = body.reason
    service.record(session, req, service.STATUS_REJECTED, identity, body.reason)
    return _save(session, req)


@router.post("/{rid}/claim")
def claim(rid: int, session: Session = Depends(get_session),
          identity: dict = Depends(require_identity)):
    req = service.fetch(session, rid)
    service.require_owner(req, identity, "청구할")
    service.ensure_transition(req, service.CLAIM)
    if not service.cert_count(session, rid):
        raise HTTPException(status_code=409, detail="이수증을 먼저 등록해 주세요")
    policy_service.ensure_claim_deadline(policy_service.current(session), req)

    req.claimed_at = service.now()
    # 재청구는 지난 반려를 덮는다. 사유는 이력에 남으므로 여기서 지워도 잃는 게 없다.
    req.claim_rejected_at = ""
    req.claim_reject_reason = ""
    # 같은 사실을 두 번 입력시키지 않는다 — 청구했으면 수강은 끝난 것이다
    req.progress = "완료"
    req.progress_at = req.claimed_at
    service.record(session, req, service.STATUS_CLAIMED, identity)
    return _save(session, req)


@router.post("/{rid}/claim-approve")
def claim_approve(rid: int, session: Session = Depends(get_session),
                  identity: dict = Depends(require_admin)):
    req = service.fetch(session, rid)
    service.ensure_transition(req, service.CLAIM_APPROVE)
    req.claim_approved_at = service.now()
    req.refund_amount = policy_service.compute_refund(req)
    memo = (f"환급액 {req.refund_amount:,}원"
            + (f" (수강료 {req.price:,}원, 건당 상한 {req.refund_cap_at_request:,}원)"
               if req.refund_amount < req.price else ""))
    service.record(session, req, service.STATUS_CLAIM_APPROVED, identity, memo)
    return _save(session, req)


@router.post("/{rid}/claim-reject")
def claim_reject(rid: int, body: ReasonIn, session: Session = Depends(get_session),
                 identity: dict = Depends(require_admin)):
    req = service.fetch(session, rid)
    service.ensure_transition(req, service.CLAIM_REJECT)
    req.claim_rejected_at = service.now()
    req.claim_reject_reason = body.reason
    service.record(session, req, service.STATUS_CLAIM_REJECTED, identity, body.reason)
    return _save(session, req)


@router.post("/{rid}/refund")
def refund(rid: int, session: Session = Depends(get_session),
           identity: dict = Depends(require_admin)):
    req = service.fetch(session, rid)
    service.ensure_transition(req, service.REFUND)
    req.refunded_at = service.now()
    service.record(session, req, service.STATUS_REFUNDED, identity)
    return _save(session, req)


@router.post("/{rid}/archive", dependencies=[Depends(require_admin)])
def archive(rid: int, session: Session = Depends(get_session)):
    # 상태를 가리지 않고 보관한다 — 목록에서 내리는 것일 뿐 되돌릴 수 있다
    req = service.fetch(session, rid)
    req.archived = 1
    return _save(session, req)


@router.post("/{rid}/unarchive", dependencies=[Depends(require_admin)])
def unarchive(rid: int, session: Session = Depends(get_session)):
    req = service.fetch(session, rid)
    req.archived = 0
    return _save(session, req)
